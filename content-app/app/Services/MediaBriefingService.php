<?php

namespace App\Services;

use App\Models\Unit;

class MediaBriefingService
{
    /**
     * Build media briefings for a given format (ported from content-media.js).
     */
    public function buildBriefings(string $format, array $args, ?array $strategyCtx = null): array
    {
        $briefings = [];
        $mediaLogic = $strategyCtx['media_logic']['rules'] ?? [];

        if ($format === 'linkedin_post') {
            $rule = collect($mediaLogic)->firstWhere('format', 'Bild')
                ?? collect($mediaLogic)->firstWhere('format', 'image');

            $briefings[] = [
                'type' => 'image',
                'position' => 0,
                'briefing' => [
                    'prompt_hint' => $this->buildImagePrompt($args, $strategyCtx),
                    'generation_params' => [
                        'style' => $rule['style'] ?? 'Dunkel, reduziert, Brand Colors',
                        'aspect_ratio' => '1.91:1',
                        'model' => 'flux-pro',
                    ],
                    'notes' => 'Hero-Image für LinkedIn-Post. Optional — Text-only ist auch möglich.',
                ],
            ];
        }

        if ($format === 'ad_copy') {
            $briefings[] = [
                'type' => 'image',
                'position' => 0,
                'briefing' => [
                    'prompt_hint' => $this->buildImagePrompt($args, $strategyCtx),
                    'generation_params' => ['aspect_ratio' => '1:1', 'model' => 'flux-pro'],
                    'notes' => 'Ad Creative Image. 1:1 für Meta/LinkedIn Feed.',
                ],
            ];
        }

        if (in_array($format, ['newsletter_acquisition', 'newsletter_bk'])) {
            $briefings[] = [
                'type' => 'image',
                'position' => 0,
                'briefing' => [
                    'prompt_hint' => $this->buildImagePrompt($args, $strategyCtx),
                    'generation_params' => ['aspect_ratio' => '1.91:1', 'model' => 'flux-pro'],
                    'notes' => 'Header-Bild für Newsletter.',
                ],
            ];
        }

        return $briefings;
    }

    public function buildImagePrompt(array $args, ?array $strategyCtx = null): string
    {
        $style = $strategyCtx['brand_voice']['personality'] ?? 'professionell, clean';
        $angle = $args['angle'] ?? '';
        $icp = $args['icp'] ?? '';

        return "Corporate style: {$style}. Context: {$angle}. Target: {$icp}. No text overlay. Dark, muted tones.";
    }
}
