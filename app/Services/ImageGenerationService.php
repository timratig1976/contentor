<?php

namespace App\Services;

use App\Models\AgentLog;
use App\Models\ContentItem;
use App\Models\ContentMedia;
use App\Models\Setting;
use App\Models\Strategy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Bild-Generierung über EdenAI (google/gemini-2.5-flash-image liefert
 * Base64-PNG im Chat-Completions-Response: choices.0.message.images).
 *
 * Workflow "Ideen statt Blindflug":
 * 1. briefIdeas()  — LLM entwirft N konkrete Bildideen passend zum FINALEN
 *                    Post-Content (Motiv, Komposition, Stimmung, Prompt)
 * 2. Nutzer wählt eine Idee (oder bearbeitet den Prompt)
 * 3. generate()    — erzeugt das Bild lokal (storage/app/public/media),
 *                    legt ContentMedia mit URL an
 *
 * Bildstile kommen aus der Strategie: media_logic (Style-Presets) und
 * brand_voice (Persönlichkeit/Tonalität) fließen in jeden Prompt.
 */
class ImageGenerationService
{
    private const ENDPOINT = 'https://api.edenai.run/v3/chat/completions';
    private const IMAGE_MODEL = 'google/gemini-2.5-flash-image';
    private const IDEAS_PER_POST = 3;

    public function __construct(private LlmService $llm) {}

    /**
     * ── Stufe 1: Bildideen aus dem finalen Content ableiten ─────────────
     *
     * @return array{ideas: array<int,array{title:string,concept:string,mood:string,prompt:string}>, error:?string}
     */
    public function briefIdeas(ContentItem $item, ?Strategy $strategy = null): array
    {
        $strategy ??= $item->strategy;
        $styleBlock = $this->styleDirective($strategy);

        $system = 'Du bist ein Art Director für B2B-Content. Du entwickelst Bildideen für einen fertigen Post. '
            . 'Antworte AUSSCHLIESSLICH mit validem JSON in genau diesem Schema: '
            . '{"ideas": [{"title": "<3-5 Wörter>", "concept": "<1-2 Sätze: was das Bild zeigt und warum es zum Text passt>", "mood": "<Stichworte: Stimmung/Wirkung>", "prompt": "<fertiger englischer Image-Generation-Prompt inkl. Stil-Vorgaben, ohne Text-Overlay>"}]}. '
            . 'Liefere genau ' . self::IDEAS_PER_POST . ' UNTERSCHIEDLICHE Konzepte (z. B. abstrakt/metaphorisch, situativ/menschlich, datengetrieben/diagrammartig).';

        $user = "POST (Format: {$item->format}):\n```\n" . mb_substr($item->content ?? '', 0, 4000) . "\n```\n\n"
            . ($item->icp ? "ZIELGRUPPE: {$item->icp}\n" : '')
            . ($item->pain_cluster ? "THEMENFELD: {$item->pain_cluster}\n" : '')
            . "\nSTIL-VORGABEN DER MARKE:\n{$styleBlock}";

        $result = $this->llm->chat('production', $system, [
            ['role' => 'user', 'content' => $user],
        ], [
            'timeout' => 90, 'agent_log' => 'image_briefing',
            'temperature' => 0.8, 'max_tokens' => 2000, 'reasoning_effort' => 'low',
        ]);

        if ($result['status'] !== 'success' || ! $result['text']) {
            return ['ideas' => [], 'error' => $result['error'] ?? 'Keine Antwort vom Ideen-Modell.'];
        }

        $ideas = $this->extractJson($result['text'])['ideas'] ?? [];
        if (! is_array($ideas) || $ideas === []) {
            return ['ideas' => [], 'error' => 'Ideen-Antwort nicht parsebar.'];
        }

        return ['ideas' => array_slice($ideas, 0, self::IDEAS_PER_POST), 'error' => null];
    }

    /**
     * ── Stufe 2: Bild aus gewähltem Prompt generieren ───────────────────
     *
     * @return array{media:?ContentMedia, error:?string}
     */
    public function generate(ContentItem $item, string $prompt, array $meta = []): array
    {
        $key = Setting::where('key', 'llm_keys')->first()?->value['edenai_key'] ?? null;
        if (! $key) {
            return ['media' => null, 'error' => 'EdenAI-Key nicht konfiguriert.'];
        }

        // Stil-Anreicherung: Marken-Stil anhängen, falls im Prompt noch nicht genannt
        $strategy = $item->strategy;
        $styleBlock = $this->styleDirective($strategy);

        // Visual-Typ (Bild / Grafik / Schema) als klare Prompt-Direktive
        $visualType = $meta['visual_type'] ?? 'image';
        $typeBlock = $this->typeDirective($visualType);

        // Ziel-Seitenverhältnis (wird nach der Generierung per Crop/Pad erzwungen,
        // da das Modell nur quadratisch liefert)
        $aspectRatio = $meta['aspect_ratio'] ?? '1:1';

        $fullPrompt = "Generate an image. Type: {$typeBlock}\nPrompt: {$prompt}\n\nMandatory style: {$styleBlock}\n"
            . ($visualType === 'schema'
                ? 'Diagram/flowchart style: clean shapes, arrows and short labels ARE allowed and encouraged here.'
                : 'No text overlay, no words, no letters in the image.');

        $start = microtime(true);
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ])->timeout(240)->post(self::ENDPOINT, [
                'model' => self::IMAGE_MODEL,
                'messages' => [['role' => 'user', 'content' => $fullPrompt]],
            ]);

            $duration = (int) ((microtime(true) - $start) * 1000);
            $images = $response->json('choices.0.message.images') ?? [];

            if (! $response->successful() || ! $images) {
                $err = $response->json('error.message') ?? $response->json('detail.error') ?? 'HTTP ' . $response->status();
                $this->logImageCall('error', mb_substr((string) $err, 0, 2000), $duration, $response->json('cost'));
                return ['media' => null, 'error' => is_string($err) ? $err : json_encode($err)];
            }

            $dataUrl = $images[0]['image_url']['url'] ?? null;
            if (! is_string($dataUrl) || ! str_starts_with($dataUrl, 'data:image')) {
                $this->logImageCall('error', 'Unerwartetes Bildformat', $duration, $response->json('cost'));
                return ['media' => null, 'error' => 'Bild kam nicht als data:image an.'];
            }

            $this->logImageCall('success', 'image generated (' . strlen($dataUrl) . ' chars base64)', $duration, $response->json('cost'));

            // Base64 → Binärdaten
            $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
            if ($binary === false) {
                return ['media' => null, 'error' => 'Base64-Decodierung fehlgeschlagen.'];
            }

            // Auf Ziel-Seitenverhältnis zuschneiden (zentrierter Crop), falls nicht quadratisch
            $binary = $this->cropToAspectRatio($binary, $aspectRatio);

            $filename = 'media/' . strtolower($item->id) . '-' . now()->format('Ymd-His') . '.png';
            Storage::disk('public')->put($filename, $binary);

            $media = ContentMedia::create([
                'content_item_id' => $item->id,
                'strategy_id' => $item->strategy_id,
                // DB-CHECK erlaubt image|video|graphic|carousel_slide|ad_creative.
                // 'schema' wird als 'graphic' gespeichert (Original-Typ bleibt im briefing).
                'type' => $visualType === 'image' ? 'image' : 'graphic',
                'url' => Storage::disk('public')->url($filename),
                'format' => $aspectRatio,
                'status' => 'generiert',
                'briefing' => [
                    'prompt_hint' => $prompt,
                    'idea_title' => $meta['title'] ?? null,
                    'idea_concept' => $meta['concept'] ?? null,
                    'style' => $meta['style'] ?? null,
                    'visual_type' => $visualType,
                    'model' => self::IMAGE_MODEL,
                    'generated_at' => now()->toIso8601String(),
                ],
                'position' => $meta['position'] ?? 0,
            ]);

            return ['media' => $media, 'error' => null];
        } catch (\Throwable $e) {
            $this->logImageCall('error', 'Exception: ' . $e->getMessage(), (int) ((microtime(true) - $start) * 1000), null);
            return ['media' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Prompt-Direktive pro Visual-Typ (Bild / Grafik / Schema).
     */
    private function typeDirective(string $visualType): string
    {
        return match ($visualType) {
            'graphic' => 'Flat vector-style graphic / illustration. Bold simple shapes, brand colors, minimal detail, no photo-realism.',
            'schema'  => 'Explanatory diagram / flowchart / schema. Clean geometric shapes connected by arrows, minimal background.',
            default   => 'Photorealistic or stylized hero image.',
        };
    }

    /**
     * Schneidet das (quadratische) Bild zentriert auf das Ziel-Seitenverhältnis zu.
     * Nutzt GD. Gibt die Original-Binärdaten zurück, wenn GD fehlt oder Ratio 1:1.
     */
    private function cropToAspectRatio(string $binary, string $aspectRatio): string
    {
        if (! preg_match('/^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/', trim($aspectRatio), $m)) {
            return $binary;
        }
        $target = (float) $m[1] / (float) $m[2];
        if (abs($target - 1.0) < 0.01 || ! function_exists('imagecreatefromstring')) {
            return $binary; // quadratisch oder GD nicht verfügbar → Original
        }

        $src = @imagecreatefromstring($binary);
        if (! $src) {
            return $binary;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $current = $w / $h;

        if ($current > $target) {
            // zu breit → seitlich beschneiden
            $newW = (int) round($h * $target);
            $newH = $h;
            $srcX = (int) round(($w - $newW) / 2);
            $srcY = 0;
        } else {
            // zu hoch → oben/unten beschneiden
            $newW = $w;
            $newH = (int) round($w / $target);
            $srcX = 0;
            $srcY = (int) round(($h - $newH) / 2);
        }

        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $newW, $newH, $newW, $newH);

        ob_start();
        imagepng($dst);
        $out = ob_get_clean();

        return $out !== false ? $out : $binary;
    }

    /**
     * Alle generierten Bilder einer Strategie (Media-Galerie).
     *
     * @return Collection<int,ContentMedia>
     */
    public function gallery(?Strategy $strategy = null): Collection
    {
        $query = ContentMedia::with('contentItem:id,title,format,status')
            ->where('type', 'image')
            ->whereNotNull('url');

        if ($strategy) {
            $query->where('strategy_id', $strategy->id);
        }

        return $query->latest()->get();
    }

    /**
     * Stil-Direktive aus Strategie-Daten: media_logic-Presets + brand_voice.
     */
    private function styleDirective(?Strategy $strategy): string
    {
        $parts = [];

        if ($strategy) {
            // media_logic: kuratierte Style-Presets pro Format (Templates-Seite)
            $mediaLogic = \App\Models\ContentStrategy::where('strategy_id', $strategy->id)
                ->where('key', 'media_logic')
                ->value('content');

            foreach ((array) ($mediaLogic['rules'] ?? []) as $rule) {
                if (! empty($rule['style'])) {
                    $parts[] = $rule['style'];
                }
            }

            // Brand Voice als Tonalitäts-Anker
            $brandVoice = \App\Models\ContentStrategy::where('strategy_id', $strategy->id)
                ->where('key', 'brand_voice')
                ->value('content');

            if (! empty($brandVoice['personality'])) {
                $parts[] = 'Brand personality: ' . $brandVoice['personality'];
            }
        }

        if ($parts === []) {
            return 'Professional corporate look: dark, muted tones, reduced and clean composition, subtle depth.';
        }

        return implode('. ', array_slice($parts, 0, 4));
    }

    /**
     * JSON aus LLM-Antwort extrahieren (robust gegen Codeblöcke/Beitext).
     */
    private function extractJson(string $text): array
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $m)) {
            $text = trim($m[1]);
        }
        if (! str_starts_with($text, '{') && preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $text = $m[0];
        }

        return json_decode($text, true) ?? [];
    }

    private function logImageCall(string $status, string $output, int $durationMs, ?float $cost): void
    {
        try {
            AgentLog::create([
                'agent' => 'image_generation',
                'provider' => 'google',
                'model' => self::IMAGE_MODEL,
                'input' => mb_substr('image generation request', 0, 2000),
                'output' => mb_substr($output, 0, 2000) . ($cost !== null ? " | cost: \${$cost}" : ''),
                'status' => $status,
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable) {
            // Logging darf nie den Hauptpfad blockieren
        }
    }
}
