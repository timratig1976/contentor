<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\ContentMedia;
use App\Models\Strategy;
use App\Services\MediaBriefingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    public function __construct(
        private MediaBriefingService $mediaService,
    ) {}

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

        // Placeholder: In production, this triggers image generation via fal.ai or similar
        // For now, mark as "generiert" with a placeholder URL
        $media->update([
            'status' => 'generiert',
            'url' => 'https://placeholder.viminds.de/media/' . $media->id,
        ]);

        return response()->json([
            'message' => "Media {$media->id} generiert (placeholder).",
            'media' => $media,
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
