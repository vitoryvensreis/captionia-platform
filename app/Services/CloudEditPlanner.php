<?php
declare(strict_types=1);
namespace CaptionIA\Services;

use InvalidArgumentException;

/**
 * Server-side decision support. Never edits an Adobe project.
 * Client must preview/validate the plan and perform every timeline mutation.
 */
final class CloudEditPlanner
{
    public function plan(array $input): array
    {
        $duration = (float)($input['duration'] ?? 0);
        $fps = (float)($input['fps'] ?? 0);
        if (!is_finite($duration) || $duration <= 0 || $duration > 21600) {
            throw new InvalidArgumentException('Duração inválida.');
        }
        if (!is_finite($fps) || $fps < 23 || $fps > 120) {
            throw new InvalidArgumentException('FPS inválido.');
        }
        $minSilence = max(0.35, min(3.0, (float)($input['min_silence'] ?? 0.65)));
        $marginBefore = max(0.0, min(0.4, (float)($input['margin_before'] ?? 0.1)));
        $marginAfter = max(0.0, min(0.4, (float)($input['margin_after'] ?? 0.12)));
        $silences = $input['silences'] ?? [];
        if (!is_array($silences) || count($silences) > 5000) {
            throw new InvalidArgumentException('Lista de silêncios inválida.');
        }
        $ordered = [];
        foreach ($silences as $s) {
            if (!is_array($s) || !is_numeric($s['start'] ?? null) || !is_numeric($s['end'] ?? null)) {
                throw new InvalidArgumentException('Silêncio sem timecodes.');
            }
            $a = (float)$s['start']; $b = (float)$s['end'];
            if (!is_finite($a) || !is_finite($b) || $a < 0 || $b <= $a || $b > $duration + 0.001) {
                throw new InvalidArgumentException('Intervalo de silêncio inválido.');
            }
            $ordered[] = [$a, min($duration, $b)];
        }
        usort($ordered, static fn(array $a,array $b): int => $a[0] <=> $b[0]);
        $cuts = [];
        $lastEnd = 0.0;
        $removed = 0.0;
        foreach ($ordered as [$start, $end]) {
            if ($end - $start < $minSilence) continue;
            $cutStart = $this->snap(max($lastEnd, $start + $marginBefore), $fps, true);
            $cutEnd = $this->snap(min($duration, $end - $marginAfter), $fps, false);
            if ($cutEnd - $cutStart < 2/$fps) continue;
            // Protect against pathological metadata that would remove most footage.
            if (($removed + $cutEnd - $cutStart) / $duration > 0.65) break;
            $cuts[] = ['start'=>$cutStart, 'end'=>$cutEnd, 'reason'=>'silence'];
            $lastEnd = $cutEnd;
            $removed += $cutEnd - $cutStart;
        }
        return [
            'schema_version'=>1,
            'engine'=>'cloud-edit-planner',
            'cuts'=>$cuts,
            'zoom_suggestions'=>[],
            'sfx_suggestions'=>[],
            'removed_seconds'=>round($removed, 4),
            'requires_user_review'=>true,
            'note'=>'Sugestões conservadoras. Validar gaps, trilhas vinculadas, timebase real e undo antes de executar no Premiere.'
        ];
    }

    private function snap(float $time, float $fps, bool $ceil): float
    {
        return round(($ceil ? ceil($time * $fps - 1e-7) : floor($time * $fps + 1e-7)) / $fps, 6);
    }
}
