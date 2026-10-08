<?php
declare(strict_types=1);
namespace CaptionIA\Services;

use InvalidArgumentException;

/**
 * Pure, server-only caption layout planner. No Premiere/Adobe dependencies.
 * The client receives decisions, not the segmentation algorithm.
 */
final class CloudCaptionPlanner
{
    public function plan(array $input): array
    {
        $text = trim((string)($input['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 12000) {
            throw new InvalidArgumentException('Texto deve conter entre 1 e 12000 caracteres.');
        }
        $aspect = (string)($input['aspect_ratio'] ?? '9:16');
        if (!in_array($aspect, ['9:16', '16:9', '1:1', '4:5'], true)) {
            throw new InvalidArgumentException('Proporção inválida.');
        }
        $maxWords = max(2, min(8, (int)($input['max_words'] ?? 5)));
        $maxChars = max(12, min(60, (int)($input['max_chars'] ?? ($aspect === '9:16' ? 26 : 42))));
        $font = max(20, min(120, (int)($input['font_size'] ?? 72)));
        $words = preg_split('/\s+/u', preg_replace('/\s+/u', ' ', $text)) ?: [];
        $segments = [];
        $chunk = [];
        foreach ($words as $word) {
            // Long tokens remain intact. Client must clamp by measured rendered width.
            $candidate = array_merge($chunk, [$word]);
            if ($chunk && (count($candidate) > $maxWords || mb_strlen(implode(' ', $candidate)) > $maxChars)) {
                $segments[] = $this->makeSegment($chunk, $maxChars, $font, $aspect);
                $chunk = [];
            }
            $chunk[] = $word;
        }
        if ($chunk) $segments[] = $this->makeSegment($chunk, $maxChars, $font, $aspect);
        return [
            'schema_version' => 1,
            'engine' => 'cloud-caption-planner',
            'segments' => $segments,
            'layout' => ['aspect_ratio' => $aspect, 'safe_margin_percent' => 10, 'max_lines' => 2],
            'note' => 'O adaptador Adobe deve medir os limites visuais antes de aplicar os textos.'
        ];
    }

    private function makeSegment(array $words, int $maxChars, int $font, string $aspect): array
    {
        $label = implode(' ', $words);
        $effective = min($font, max(20, (int)floor($font * min(1, $maxChars / max(1, mb_strlen($label))))));
        $half = (int)ceil(count($words) / 2);
        $lines = mb_strlen($label) > (int)($maxChars * 0.65) && count($words) > 1
            ? [implode(' ', array_slice($words, 0, $half)), implode(' ', array_slice($words, $half))]
            : [$label];
        return [
            'text' => $label, 'lines' => $lines, 'font_size_hint' => $effective,
            'safe_area_percent' => 10, 'aspect_ratio' => $aspect
        ];
    }
}
