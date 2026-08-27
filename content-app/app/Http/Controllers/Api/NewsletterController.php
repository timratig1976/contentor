<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    public function bkDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit' => 'required|string|exists:units,key',
            'topic' => 'nullable|string',
            'sources' => 'nullable|array',
        ]);

        $unit = Unit::where('key', $validated['unit'])->firstOrFail();
        $bk = $unit->config['rules']['bk'] ?? [];

        $topic = $validated['topic'] ?? $bk['defaultTopic'] ?? 'BK Newsletter';
        $preheader = $bk['preheader'] ?? '';
        $mainCta = $bk['mainCta'] ?? '';

        // Build newsletter draft content
        $content = "## {$topic}\n\n";
        $content .= "Preheader: {$preheader}\n\n";
        $content .= "---\n\n";
        $content .= "[Newsletter Body hier]\n\n";
        $content .= "---\n\n";
        $content .= "CTA: {$mainCta}\n";

        $item = ContentItem::create([
            'unit_id' => $unit->id,
            'type' => 'newsletter',
            'format' => 'newsletter_bk',
            'title' => $topic,
            'content' => $content,
            'status' => 'idee',
            'owner' => $unit->config['rules']['defaultOwner'] ?? $unit->key,
        ]);

        return response()->json([
            'message' => "BK-Newsletter Draft erstellt: {$topic}",
            'content_item' => $item->load('unit'),
        ], 201);
    }

    public function bkNewsletter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit' => 'required|string|exists:units,key',
            'topic' => 'required|string',
            'content' => 'required|string',
        ]);

        $unit = Unit::where('key', $validated['unit'])->firstOrFail();

        $item = ContentItem::create([
            'unit_id' => $unit->id,
            'type' => 'newsletter',
            'format' => 'newsletter_bk',
            'title' => $validated['topic'],
            'content' => $validated['content'],
            'status' => 'review',
            'owner' => $unit->config['rules']['defaultOwner'] ?? $unit->key,
        ]);

        return response()->json([
            'message' => "BK-Newsletter erstellt: {$validated['topic']}",
            'content_item' => $item->load('unit'),
        ], 201);
    }
}
