<?php
declare(strict_types=1);
namespace CaptionIA\Services;
use CaptionIA\Core\Database;

/** Atomic DB-backed request quota: never depend on untrusted client counters. */
final class CloudRequestLimiter
{
    public function allow(int $userId, string $feature, int $perMinute = 30): bool
    {
        $minute = gmdate('Y-m-d H:i:00');
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO cloud_rate_windows (user_id,feature,window_start,hits)
             VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE hits=hits+1'
        );
        $stmt->execute([$userId, $feature, $minute]);
        $read = $pdo->prepare(
            'SELECT hits FROM cloud_rate_windows WHERE user_id=? AND feature=? AND window_start=?'
        );
        $read->execute([$userId, $feature, $minute]);
        return (int)$read->fetchColumn() <= $perMinute;
    }
}
