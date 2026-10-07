<?php
declare(strict_types=1);

namespace CaptionIA\Core;

use RuntimeException;

final class View
{
    public static function render(string $view, array $data = [], int $status = 200): void
    {
        http_response_code($status);

        $basePath = $data['_basePath'] ?? null;
        if (!$basePath) {
            throw new RuntimeException('Base path ausente.');
        }

        $viewFile = $basePath . '/views/' . $view . '.php';
        $layoutFile = $basePath . '/views/layout.php';

        if (!is_file($viewFile) || !is_file($layoutFile)) {
            throw new RuntimeException('View não encontrada: ' . $view);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        require $layoutFile;
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
