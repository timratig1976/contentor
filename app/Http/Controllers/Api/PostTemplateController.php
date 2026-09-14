<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentStrategy;
use App\Models\PostTemplate;
use App\Models\Strategy;
use App\Services\LlmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Zentraler Template-Katalog: CRUD + KI-Identifikation aus Beispieltext.
 *
 * Ersetzt die vorherige Dopplung (identische Template-Listen hardcodiert
 * in Strategie/Index.vue UND Templates/Index.vue). Ab jetzt: 1 Katalog
 * (post_templates-Tabelle), Strategien wählen nur noch per ID-Liste aus,
 * welche Templates für sie aktiv sind.
 */
class PostTemplateController extends Controller
{
    public function __construct(private LlmService $llm) {}

    /**
     * Katalog + je Strategie die aktuelle Auswahl (falls strategy übergeben).
     */
    public function index(Request $request): JsonResponse
    {
        $templates = PostTemplate::orderBy('format')->orderBy('name')->get();

        $selected = null;
        if ($request->filled('strategy')) {
            $strategy = Strategy::where('key', $request->input('strategy'))->first();
            if ($strategy) {
                $cs = ContentStrategy::where('strategy_id', $strategy->id)
                    ->where('key', 'post_templates')->first();
                $selected = $cs?->content['selected'] ?? [];
            }
        }

        return response()->json([
            'templates' => $templates,
            'selected' => $selected,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'format' => 'required|string|in:' . implode(',', array_keys(PostTemplate::FORMATS)),
            'description' => 'nullable|string|max:500',
            'structure' => 'required|string',
            'example' => 'nullable|string',
            'best_for' => 'nullable|array',
        ]);

        $template = PostTemplate::create(array_merge($validated, ['source' => 'custom', 'active' => true]));

        return response()->json($template, 201);
    }

    public function update(Request $request, PostTemplate $postTemplate): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'format' => 'sometimes|string|in:' . implode(',', array_keys(PostTemplate::FORMATS)),
            'description' => 'nullable|string|max:500',
            'structure' => 'sometimes|string',
            'example' => 'nullable|string',
            'best_for' => 'nullable|array',
            'active' => 'sometimes|boolean',
        ]);

        $postTemplate->update($validated);

        return response()->json($postTemplate);
    }

    public function destroy(PostTemplate $postTemplate): JsonResponse
    {
        $postTemplate->delete();

        return response()->json(['deleted' => $postTemplate->id]);
    }

    /**
     * Setzt die Template-Auswahl einer Strategie (welche Templates sind aktiv).
     */
    public function updateSelection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'strategy' => 'required|string|exists:strategies,key',
            'selected' => 'required|array',
            'selected.*' => 'integer|exists:post_templates,id',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();

        $cs = ContentStrategy::updateOrCreate(
            ['strategy_id' => $strategy->id, 'key' => 'post_templates'],
            []
        );
        $cs->content = ['selected' => $validated['selected']];
        $cs->version = ($cs->version ?? 0) + 1;
        $cs->save();

        return response()->json(['selected' => $validated['selected']]);
    }

    /**
     * KI-Identifikation: aus einem Beispiel-Post ein neues Template ableiten
     * (Name, Struktur, Beschreibung) — statt Templates nur manuell anzulegen.
     */
    public function identify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'example' => 'required|string|min:20|max:4000',
            'format' => 'nullable|string|in:' . implode(',', array_keys(PostTemplate::FORMATS)),
        ]);

        $format = $validated['format'] ?? 'linkedin_post';

        $system = 'Du bist ein Content-Struktur-Analyst. Du bekommst einen echten Post-Text und leitest daraus ein '
            . 'wiederverwendbares TEMPLATE (Muster) ab — kein Duplikat des Textes, sondern die zugrunde liegende Struktur. '
            . 'Antworte AUSSCHLIESSLICH mit validem JSON in diesem Schema: '
            . '{"name": "<kurzer, einprägsamer Template-Name, max 4 Wörter>", '
            . '"description": "<1 Satz: wofür eignet sich dieses Muster>", '
            . '"structure": "<Schritt 1\\nSchritt 2\\n... — die Bausteine des Posts als Zeilen, OHNE den Original-Inhalt>", '
            . '"best_for": ["<ICP-Vermutung falls erkennbar, sonst leeres Array>"]}';

        $user = "FORMAT: {$format}\n\nBEISPIEL-POST:\n```\n{$validated['example']}\n```\n\nLeite das Template ab.";

        $result = $this->llm->chat('assistant', $system, [
            ['role' => 'user', 'content' => $user],
        ], ['timeout' => 60, 'temperature' => 0.3, 'max_tokens' => 600]);

        if ($result['status'] !== 'success' || ! $result['text']) {
            return response()->json(['error' => $result['error'] ?? 'Keine Antwort vom Modell.'], 502);
        }

        $text = trim($result['text']);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $m)) {
            $text = trim($m[1]);
        }
        if (! str_starts_with($text, '{') && preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $text = $m[0];
        }

        $data = json_decode($text, true);
        if (! is_array($data) || empty($data['name']) || empty($data['structure'])) {
            return response()->json(['error' => 'KI-Antwort konnte nicht als Template interpretiert werden.'], 502);
        }

        return response()->json([
            'name' => $data['name'],
            'format' => $format,
            'description' => $data['description'] ?? '',
            'structure' => $data['structure'],
            'example' => $validated['example'],
            'best_for' => array_values(array_filter((array) ($data['best_for'] ?? []))),
        ]);
    }
}
