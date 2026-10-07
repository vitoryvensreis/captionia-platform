<?php
declare(strict_types=1);

namespace CaptionIA\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $days = max(1, Env::int('SESSION_LIFETIME_DAYS', 30));
        $lifetime = $days * 86400;

        session_name(Env::get('SESSION_COOKIE', 'captionia_session') ?: 'captionia_session');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'domain' => '',
            'secure' => Env::bool('SESSION_SECURE', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.gc_maxlifetime', (string) $lifetime);
        session_start();

        if (!isset($_SESSION['_started_at'])) {
            session_regenerate_id(true);
            $_SESSION['_started_at'] = time();
        }
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }
}
