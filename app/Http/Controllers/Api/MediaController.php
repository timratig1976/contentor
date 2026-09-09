<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\ContentMedia;
use App\Models\Strategy;
use App\Services\ImageGenerationService;
use App\Services\MediaBriefingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    public function __construct(
        private MediaBriefingService $mediaService,
        private ImageGenerationService $imageService,
    ) {}

    /**
     * Bildideen für einen existierenden Post ableiten (Stufe 1 der Bild-Pipeline).
     * Der Nutzer wählt danach eine Idee → generateFromIdea().
     */
    public function briefIdeas(Request $request, ContentItem $contentItem): JsonResponse
    {
        $result = $this->imageService->briefIdeas($contentItem);

        if ($result['error']) {
            return response()->json(['error' => $result['error'], 'ideas' => []], 502);
        }

        return response()->json([
            'ideas' => $result['ideas'],
            'content_item_id' => $contentItem->id,
        ]);
    }

    /**
     * Bild aus gewählter Idee/Prompt generieren (Stufe 2) — Base64 von
     * Gemini wird als PNG in storage/app/public/media gespeichert.
     */
    public function generateImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => 'required|string|exists:content_items,id',
            'prompt' => 'required|string|max:3000',
            'title' => 'nullable|string|max:255',
            'concept' => 'nullable|string|max:1000',
            'style' => 'nullable|string|max:255',
            'aspect_ratio' => 'nullable|string|max:20',
            'position' => 'nullable|integer|min:0',
        ]);

        $item = ContentItem::with('strategy')->findOrFail($validated['item_id']);

        $result = $this->imageService->generate($item, $validated['prompt'], [
            'title' => $validated['title'] ?? null,
            'concept' => $validated['concept'] ?? null,
            'style' => $validated['style'] ?? null,
            'aspect_ratio' => $validated['aspect_ratio'] ?? null,
            'position' => $validated['position'] ?? 0,
        ]);

        if ($result['error']) {
            return response()->json(['error' => $result['error']], 502);
        }

        return response()->json([
            'media' => $result['media']->load('contentItem:id,title,format'),
            'message' => 'Bild generiert und gespeichert.',
        ], 201);
    }

    /**
     * Galerie aller generierten Bilder einer Strategie.
     */
    public function gallery(Request $request): JsonResponse
    {
        $strategy = $request->filled('strategy')
            ? Strategy::where('key', $request->input('strategy'))->first()
            : null;

        return response()->json($this->imageService->gallery($strategy));
    }

    public function index(Request $request): JsonResponse
    {
        $query = ContentMedia::with(['contentItem', 'strategy']);

        if ($request->filled('strategy')) {
            $query->whereHas('strategy', fn ($q) => $q->where('key', $request->input('strategy')));
        }
        if ($request->filled('item_id')) {
            $query->where('content_item_id', $request->input('item_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $media = $query->latest()->paginate($request->input('per_page', 50));

        return response()->json($media);
    }

    public function storeBriefing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => 'required|string|exists:content_items,id',
            'media_type' => 'required|string|in:image,video,graphic,carousel_slide,ad_creative',
            'prompt' => 'nullable|string',
            'generation_params' => 'nullable|array',
            'position' => 'integer',
            'notes' => 'nullable|string',
            'strategy' => 'nullable|string|exists:strategies,key',
        ]);

        $item = ContentItem::with('strategy')->findOrFail($validated['item_id']);

        $media = ContentMedia::create([
            'content_item_id' => $item->id,
            'strategy_id' => $item->strategy_id,
            'type' => $validated['media_type'],
            'briefing' => [
                'prompt_hint' => $validated['prompt'] ?? null,
                'generation_params' => $validated['generation_params'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ],
            'position' => $validated['position'] ?? 0,
        ]);

        return response()->json($media->load(['contentItem', 'strategy']), 201);
    }

    public function generieren(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'media_id' => 'required|string|exists:content_media,id',
        ]);

        $media = ContentMedia::with('contentItem')->findOrFail($validated['media_id']);

        // Echte Generierung über das gespeicherte Briefing (prompt_hint)
        $prompt = $media->briefing['prompt_hint'] ?? null;
        if (! $prompt) {
            return response()->json(['error' => 'Kein Prompt im Briefing hinterlegt.'], 422);
        }

        $result = $this->imageService->generate($media->contentItem, $prompt, [
            'title' => $media->briefing['idea_title'] ?? null,
            'concept' => $media->briefing['idea_concept'] ?? null,
            'style' => $media->briefing['generation_params']['style'] ?? null,
            'aspect_ratio' => $media->format,
            'position' => $media->position,
        ]);

        if ($result['error']) {
            return response()->json(['error' => $result['error']], 502);
        }

        // Ursprüngliches Briefing-Element mit dem Ergebnis verknüpfen
        $media->update([
            'status' => 'generiert',
            'url' => $result['media']->url,
            'briefing' => array_merge($media->briefing ?? [], $result['media']->briefing ?? []),
        ]);
        $result['media']->delete(); // Duplikat entfernen — Briefing-Record ist der kanonische

        return response()->json([
            'message' => "Media {$media->id} generiert.",
            'media' => $media->fresh(),
        ]);
    }

    public function update(Request $request, ContentMedia $media): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:' . implode(',', ContentMedia::STATUSES),
            'url' => 'sometimes|string',
            'drive_file_id' => 'sometimes|string',
            'format' => 'sometimes|string',
            'briefing' => 'sometimes|array',
            'position' => 'sometimes|integer',
        ]);

        $media->update($validated);

        return response()->json($media->load(['contentItem', 'strategy']));
    }

    public function destroy(ContentMedia $media): JsonResponse
    {
        $media->delete();

        return response()->json(['deleted' => true]);
    }
}
