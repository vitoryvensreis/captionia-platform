<?php
declare(strict_types=1);
namespace CaptionIA\Services;

use InvalidArgumentException;

/**
 * Server-only caption layout planner.
 * Plans are JSON data: only the Adobe adapter may mutate a timeline.
 * Never guess timestamps for untimed text.
 */
final class CloudCaptionPlanner
{
    private const MAX_TEXT = 12000;
    private const MAX_WORDS = 4000;

    public function plan(array $input): array
    {
        $aspect = (string)($input['aspect_ratio'] ?? '9:16');
        if (!in_array($aspect, ['9:16', '16:9', '1:1', '4:5'], true)) {
            throw new InvalidArgumentException('Proporção inválida.');
        }
        $maxWords = max(1, min(8, (int)($input['max_words'] ?? 4)));
        $maxChars = max(12, min(60, (int)($input['max_chars'] ?? ($aspect === '9:16' ? 26 : 42))));
        $font = max(20, min(120, (int)($input['font_size'] ?? 72)));
        $maxDuration = max(0.5, min(6.0, (float)($input['max_duration'] ?? 3.5)));
        $words = $this->normalizeWords($input);
        $segments = [];
        $chunk = [];
        $startIndex = 0;
        foreach ($words as $i => $word) {
            $candidate = array_merge($chunk, [$word]);
            $length = mb_strlen(implode(' ', array_column($candidate, 'text')));
            $gap = $chunk && $word['start'] !== null && $chunk[count($chunk)-1]['end'] !== null
                ? $word['start'] - $chunk[count($chunk)-1]['end'] : 0.0;
            $duration = $candidate[0]['start'] !== null && $word['end'] !== null
                ? $word['end'] - $candidate[0]['start'] : 0.0;
            if ($chunk && (count($candidate) > $maxWords || $length > $maxChars
                || $gap > 0.75 || $duration > $maxDuration)) {
                $segments[] = $this->segment($chunk, $startIndex, $i-1, $maxChars, $font, $aspect);
                $chunk = [];
                $startIndex = $i;
            }
            $chunk[] = $word;
        }
        if ($chunk) {
            $segments[] = $this->segment($chunk, $startIndex, count($words)-1, $maxChars, $font, $aspect);
        }
        return [
            'schema_version' => 2,
            'engine' => 'cloud-caption-planner',
            'segments' => $segments,
            'layout' => ['aspect_ratio' => $aspect, 'safe_margin_percent' => 10, 'max_lines' => 2],
            'note' => 'Ajuste a fonte conforme a largura visual real no Premiere. Sem timings de entrada, nenhuma duração foi inventada.'
        ];
    }

    private function normalizeWords(array $input): array
    {
        if (isset($input['words'])) {
            if (!is_array($input['words']) || count($input['words']) > self::MAX_WORDS || !$input['words']) {
                throw new InvalidArgumentException('Lista de palavras inválida ou excessiva.');
            }
            $normalized = [];
            foreach ($input['words'] as $index => $word) {
                if (!is_array($word) || !is_string($word['text'] ?? null)
                    || !is_numeric($word['start'] ?? null) || !is_numeric($word['end'] ?? null)) {
                    throw new InvalidArgumentException('Cada palavra precisa de texto, start e end.');
                }
                $label = trim(preg_replace('/\s+/u', ' ', $word['text']) ?? '');
                $start = (float)$word['start'];
                $end = (float)$word['end'];
                if ($label === '' || mb_strlen($label) > 100 || !is_finite($start) || !is_finite($end)
                    || $start < 0 || $end <= $start || $end > 21600) {
                    throw new InvalidArgumentException('Palavra ou timecode inválido na posição ' . ($index + 1) . '.');
                }
                $normalized[] = ['text'=>$label, 'start'=>$start, 'end'=>$end, '_order'=>$index];
            }
            // A Groq pode retornar palavras fora de ordem em trechos de fala rápida.
            // Reordenar os pares palavra+tempo é seguro: não inventa timestamps,
            // não apaga palavras e mantém a associação do texto com o tempo original.
            usort($normalized, static function(array $a, array $b): int {
                $time = $a['start'] <=> $b['start'];
                return $time !== 0 ? $time : ($a['_order'] <=> $b['_order']);
            });
            foreach ($normalized as &$item) unset($item['_order']);
            unset($item);
            if (mb_strlen(implode(' ', array_column($normalized, 'text'))) > self::MAX_TEXT) {
                throw new InvalidArgumentException('Texto muito longo.');
            }
            return $normalized;
        }
        $text = trim((string)($input['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            throw new InvalidArgumentException('Texto deve conter entre 1 e 12000 caracteres.');
        }
        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) > self::MAX_WORDS) {
            throw new InvalidArgumentException('Texto inválido ou com palavras demais.');
        }
        return array_map(static fn(string $w): array=> ['text'=>$w, 'start'=>null, 'end'=>null], $parts);
    }

    private function segment(array $words, int $from, int $to, int $maxChars, int $font, string $aspect): array
    {
        $label = implode(' ', array_column($words, 'text'));
        $risk = mb_strlen($label) > $maxChars;
        $half = (int)ceil(count($words)/2);
        $lines = count($words) > 1 && mb_strlen($label) > (int)floor($maxChars * 0.65)
            ? [implode(' ', array_column(array_slice($words, 0, $half), 'text')),
               implode(' ', array_column(array_slice($words, $half), 'text'))]
            : [$label];
        foreach ($lines as $line) {
            if (mb_strlen($line) > $maxChars) $risk = true;
        }
        return [
            'text'=>$label, 'lines'=>$lines,
            'start_time'=>$words[0]['start'],
            // Sobreposição leve de palavras é permitida. Nunca encerrar a legenda
            // antes de uma palavra anterior do mesmo bloco terminar.
            'end_time'=>max(array_column($words, 'end')),
            'source_word_range'=>[$from, $to],
            'font_size_hint'=>min($font, max(20, (int)floor($font * min(1.0, $maxChars / max(1, mb_strlen($label)))))),
            'overflow_risk'=>$risk,
            'safe_area_percent'=>10,
            'aspect_ratio'=>$aspect,
        ];
    }
}
