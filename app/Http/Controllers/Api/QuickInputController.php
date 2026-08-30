<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Angle;
use App\Models\Persona;
use App\Models\Source;
use App\Models\Strategy;
use App\Services\ContentRulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Smalot\PdfParser\Parser;

class QuickInputController extends Controller
{
    public function __construct(
        private ContentRulesService $rulesService,
    ) {}

    /**
     * Schnell-Eingabe: Text, PDF oder URL einfügen → Source + optional Angle erstellen.
     * Unterstützt: Blog-Artikel, LinkedIn-Posts, PDFs, URLs, Notizen, Kundenzitate.
     */
    public function store(Request $request)
    {
        // Handle PDF upload
        if ($request->hasFile('file')) {
            return $this->storeFromFile($request);
        }

        $validated = $request->validate([
            'content' => 'required|string|min:10',
            'title' => 'nullable|string|max:255',
            'strategy' => 'required|string|exists:strategies,key',
            'type' => 'nullable|string|in:auto,blog,linkedin,url,interview,note,quote,pdf',
            'batch_key' => 'nullable|string',
            'create_angles' => 'boolean',
            'num_angles' => 'nullable|integer|min:1|max:10',
        ]);
        $validated = $request->validate([
            'content' => 'required|string|min:10',
            'title' => 'nullable|string|max:255',
            'strategy' => 'required|string|exists:strategies,key',
            'type' => 'nullable|string|in:auto,blog,linkedin,url,interview,note,quote',
            'batch_key' => 'nullable|string',
            'create_angles' => 'boolean',
            'num_angles' => 'nullable|integer|min:1|max:10',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();
        $content = $validated['content'];
        $type = $validated['type'] ?? 'auto';

        // Auto-detect content type
        if ($type === 'auto') {
            $type = $this->detectType($content);
        }

        // Create source
        $title = $validated['title'] ?? mb_substr($content, 0, 80) . (mb_strlen($content) > 80 ? '...' : '');
        $source = Source::create([
            'title' => $title,
            'type' => $this->mapTypeToSource($type),
            'strategy_id' => $strategy->id,
            'visibility' => 'intern',
            'batch_key' => $validated['batch_key'] ?? 'quick-' . now()->format('Ymd'),
        ]);

        $result = ['source' => $source->load('strategy'), 'angles' => []];

        // Optionally create angles
        if ($validated['create_angles'] ?? true) {
            $angles = $this->extractAngles($content, $strategy, $source);
            $result['angles'] = $angles;
        }

        if ($request->header('X-Inertia')) {
            return redirect()->route('quellen');
        }

        return response()->json($result, 201);
    }

    /**
     * Handle PDF file upload — extract text and process.
     */
    private function storeFromFile(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,txt,md,doc,docx|max:10240',
            'title' => 'nullable|string|max:255',
            'strategy' => 'required|string|exists:strategies,key',
            'batch_key' => 'nullable|string',
            'create_angles' => 'boolean',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        // Extract text from file
        if ($extension === 'pdf') {
            $parser = new Parser();
            $pdf = $parser->parseFile($file->getPathname());
            $content = $pdf->getText();
        } else {
            $content = file_get_contents($file->getPathname());
        }

        $content = trim($content);
        if (empty($content)) {
            return response()->json(['error' => 'Kein Text aus der Datei extrahiert.'], 422);
        }

        $title = $validated['title'] ?? $file->getClientOriginalName();

        $source = Source::create([
            'title' => $title,
            'type' => 'pdf',
            'strategy_id' => $strategy->id,
            'visibility' => 'intern',
            'file_ref' => $file->getClientOriginalName(),
            'batch_key' => $validated['batch_key'] ?? 'pdf-' . now()->format('Ymd'),
        ]);

        $result = ['source' => $source->load('strategy'), 'angles' => []];

        if ($validated['create_angles'] ?? true) {
            $angles = $this->extractAngles($content, $strategy, $source);
            $result['angles'] = $angles;
        }

        if ($request->header('X-Inertia')) {
            return redirect()->route('quellen');
        }

        return response()->json($result, 201);
    }

    private function detectType(string $content): string
    {
        // URL detection
        if (preg_match('/^https?:\/\//i', trim($content))) {
            return 'url';
        }
        // LinkedIn post detection (shorter, hashtags, informal)
        if (mb_strlen($content) < 3000 && preg_match('/#\w+/i', $content) && !preg_match('/[.;]{2,}/', $content)) {
            return 'linkedin';
        }
        // Quote detection
        if (preg_match('/["„"]/', $content) && mb_strlen($content) < 500) {
            return 'quote';
        }
        // Blog detection (longer, structured)
        if (mb_strlen($content) > 500) {
            return 'blog';
        }
        return 'note';
    }

    private function mapTypeToSource(string $type): string
    {
        return match ($type) {
            'url' => 'url',
            'blog', 'linkedin' => 'research',
            'quote' => 'interview',
            'note' => 'intern',
            default => 'research',
        };
    }

    private function extractAngles(string $content, Strategy $strategy, Source $source): array
    {
        $angles = [];

        // 1. Try to find key statements (sentences with strong claims)
        $sentences = preg_split('/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);

        // Score sentences by "angle-likeness"
        $scored = [];
        foreach ($sentences as $sentence) {
            $score = 0;
            $lower = mb_strtolower($sentence);
            // Provocative/opinion words = angle-worthy
            if (preg_match('/ist|sind|sollte|müssen|kann nicht|ohne|nie|immer|falsch|richtig|problem|lösung|fehler/i', $sentence)) $score += 2;
            if (preg_match('/die meisten|alle|niemand|jeder|immer|nie/i', $lower)) $score += 2;
            if (mb_strlen($sentence) > 30 && mb_strlen($sentence) < 200) $score += 1;
            if (preg_match('/\d+%|\d+x|\d+\s*(€|\$|prozent|fach|mal)/i', $sentence)) $score += 3; // Has metrics
            if ($score >= 3) $scored[] = ['text' => $sentence, 'score' => $score];
        }

        // Sort by score, take top ones
        usort($scored, fn ($a, $b) => $b['score'] - $a['score']);
        $top = array_slice($scored, 0, 5);

        foreach ($top as $item) {
            $icp = $this->rulesService->guessIcp($item['text'], null, $strategy);
            $cluster = $this->rulesService->pickPainCluster($item['text'], $strategy);
            $statementType = $this->rulesService->pickStatementType(null, 'text', $item['text']);

            $angle = Angle::create([
                'angle' => $item['text'],
                'strategy_id' => $strategy->id,
                'source_id' => $source->id,
                'batch_key' => $source->batch_key,
                'icp' => $icp,
                'pain_cluster' => $cluster ? "{$cluster['code']} · {$cluster['name']}" : null,
                'statement_type' => $statementType,
            ]);
            $angles[] = $angle;
        }

        // Fallback: if no angles extracted, create one from the content itself
        if (empty($angles)) {
            $angle = Angle::create([
                'angle' => mb_substr($content, 0, 200),
                'strategy_id' => $strategy->id,
                'source_id' => $source->id,
                'batch_key' => $source->batch_key,
            ]);
            $angles[] = $angle;
        }

        return $angles;
    }
}