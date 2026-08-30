<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    public function bkDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'strategy' => 'required|string|exists:strategies,key',
            'topic' => 'nullable|string',
            'sources' => 'nullable|array',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();
        $bk = $strategy->config['rules']['bk'] ?? [];

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
            'strategy_id' => $strategy->id,
            'type' => 'newsletter',
            'format' => 'newsletter_bk',
            'title' => $topic,
            'content' => $content,
            'status' => 'idee',
            'owner' => $strategy->config['rules']['defaultOwner'] ?? $strategy->key,
        ]);

        return response()->json([
            'message' => "BK-Newsletter Draft erstellt: {$topic}",
            'content_item' => $item->load('strategy'),
        ], 201);
    }

    public function bkNewsletter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'strategy' => 'required|string|exists:strategies,key',
            'topic' => 'required|string',
            'content' => 'required|string',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();

        $item = ContentItem::create([
            'strategy_id' => $strategy->id,
            'type' => 'newsletter',
            'format' => 'newsletter_bk',
            'title' => $validated['topic'],
            'content' => $validated['content'],
            'status' => 'review',
            'owner' => $strategy->config['rules']['defaultOwner'] ?? $strategy->key,
        ]);

        return response()->json([
            'message' => "BK-Newsletter erstellt: {$validated['topic']}",
            'content_item' => $item->load('strategy'),
        ], 201);
    }
}
