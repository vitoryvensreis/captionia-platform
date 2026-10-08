<?php
declare(strict_types=1);

// Public marketing pages must be indexable and have a stable rich preview.
// Account, checkout, payment and error pages must never leak into Google.
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$render = static function (string $uri, int $status) use ($root): string {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $uri;
    http_response_code($status);
    $title = 'Página de teste';
    $currentUser = null;
    $csrf = '';
    $flashSuccess = null;
    $flashError = null;
    $content = '<main>Conteúdo temporário de teste</main>';
    ob_start();
    require $root . '/views/layout.php';
    return (string) ob_get_clean();
};

$home = $render('/', 200);
$assert(str_contains($home, 'name="robots" content="index,follow,max-image-preview:large"'), 'Homepage precisa ser indexável');
$assert(str_contains($home, 'rel="canonical" href="https://caption.escotilhaprodutora.com/"'), 'Canonical ausente');
$assert(str_contains($home, 'property="og:image" content="https://caption.escotilhaprodutora.com/assets/og-captionia.png?v=1"'), 'Imagem Open Graph ausente');
$assert(str_contains($home, 'property="og:image:width" content="1200"'), 'Largura OG incorreta');
$assert(str_contains($home, 'property="og:image:height" content="630"'), 'Altura OG incorreta');
$assert(str_contains($home, 'name="twitter:card" content="summary_large_image"'), 'Twitter Card ausente');
$assert(str_contains($home, 'rel="icon" type="image/svg+xml"'), 'Favicon SVG ausente');
$assert(str_contains($home, 'rel="apple-touch-icon"'), 'Apple icon ausente');
$assert(str_contains($home, 'application/ld+json'), 'Dados estruturados ausentes');
$assert(str_contains($home, 'Legendas com IA e AutoEdit'), 'Título de SEO ausente');

foreach (['/minha-conta', '/entrar', '/admin', '/pagamento/ABC-123', '/comprar/mensal'] as $path) {
    $html = $render($path, 200);
    $assert(str_contains($html, 'name="robots" content="noindex,nofollow,noarchive"'), "Página privada indexável: $path");
    $assert(!str_contains($html, 'property="og:image"'), "Imagem pública aplicada a página privada: $path");
    $assert(!str_contains($html, 'rel="canonical"'), "Canonical público aplicado a página privada: $path");
}
$error = $render('/', 404);
$assert(str_contains($error, 'name="robots" content="noindex,nofollow,noarchive"'), 'Erros não devem ser indexados');

$image = $root . '/public/assets/og-captionia.png';
$size = getimagesize($image);
$assert(is_array($size) && $size[0] === 1200 && $size[1] === 630 && ($size['mime'] ?? '') === 'image/png', 'Imagem OG inválida');
foreach (['favicon-32.png' => 32, 'apple-touch-icon.png' => 180] as $filename => $dimension) {
    $icon = getimagesize($root . '/public/' . $filename);
    $assert(is_array($icon) && $icon[0] === $dimension && $icon[1] === $dimension, "Ícone inválido: $filename");
}

$robots = file_get_contents($root . '/public/robots.txt');
$sitemap = file_get_contents($root . '/public/sitemap.xml');
$assert(str_contains((string) $robots, 'Sitemap: https://caption.escotilhaprodutora.com/sitemap.xml'), 'Robots sem sitemap');
$assert(str_contains((string) $sitemap, '<loc>https://caption.escotilhaprodutora.com/</loc>'), 'Sitemap sem homepage');

echo "SEO smoke PASS: homepage crawlable, OG 1200x630, favicons, structured data, private pages noindex\n";
