<?php
use CaptionIA\Core\View;

$publicSiteUrl = 'https://caption.escotilhaprodutora.com/';
$isPublicHome = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
    && (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/') === '/'
    && http_response_code() === 200;

$publicTitle = 'Caption IA | Legendas com IA e AutoEdit no Premiere Pro';
$publicDescription = 'Crie legendas dinâmicas, automatize cortes, adicione efeitos sonoros e acelere suas edições no Adobe Premiere Pro com o Caption IA.';
$pageTitle = $isPublicHome ? $publicTitle : (string) ($title ?? 'Caption IA');
$pageDescription = $isPublicHome ? $publicDescription : 'Caption IA — plataforma de automação de edição para Adobe Premiere Pro.';
$previewImage = $publicSiteUrl . 'assets/og-captionia.png?v=1';
$structuredData = [
    '@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    'name' => 'Caption IA',
    'url' => $publicSiteUrl,
    'image' => $previewImage,
    'description' => $publicDescription,
    'applicationCategory' => 'MultimediaApplication',
    'operatingSystem' => 'Windows',
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'Escotilha Produtora',
    ],
];
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#111111">
<title><?= View::e($pageTitle) ?></title>
<meta name="description" content="<?= View::e($pageDescription) ?>">
<meta name="robots" content="<?= $isPublicHome ? 'index,follow,max-image-preview:large' : 'noindex,nofollow,noarchive' ?>">
<link rel="icon" type="image/svg+xml" href="/favicon.svg?v=1">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png?v=1">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=1">
<?php if ($isPublicHome): ?>
<link rel="canonical" href="<?= View::e($publicSiteUrl) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="pt_BR">
<meta property="og:site_name" content="Caption IA">
<meta property="og:title" content="<?= View::e($publicTitle) ?>">
<meta property="og:description" content="<?= View::e($publicDescription) ?>">
<meta property="og:url" content="<?= View::e($publicSiteUrl) ?>">
<meta property="og:image" content="<?= View::e($previewImage) ?>">
<meta property="og:image:secure_url" content="<?= View::e($previewImage) ?>">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Caption IA — Legendas com IA, AutoEdit e ferramentas para Adobe Premiere Pro">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= View::e($publicTitle) ?>">
<meta name="twitter:description" content="<?= View::e($publicDescription) ?>">
<meta name="twitter:image" content="<?= View::e($previewImage) ?>">
<script type="application/ld+json"><?= json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<link rel="stylesheet" href="/assets/icons/bootstrap-icons.min.css?v=1">
<link rel="stylesheet" href="/assets/app.css?v=7">
</head>
<body>
<header class="suite-topbar">
  <a class="suite-brand" href="/">
    <span class="suite-logo">C</span>
    <span>Caption IA</span>
  </a>

  <div class="suite-actions">
    <?php if (!empty($currentUser)): ?>
      <a class="top-buy" href="/#planos">Comprar</a>
      <a href="/minha-conta" class="suite-user"><?= View::e(mb_substr($currentUser['name'] ?? 'Conta', 0, 1)) ?></a>
    <?php else: ?>
      <a class="top-login" href="/entrar">Entrar</a>
      <a class="top-buy" href="/#planos">Comprar agora</a>
    <?php endif; ?>
  </div>
</header>

<aside class="suite-rail" aria-label="Navegação principal">
  <a class="rail-item" href="/"><i class="bi bi-house"></i><b>Home</b></a>
  <a class="rail-item" href="/#recursos"><i class="bi bi-grid"></i><b>Recursos</b></a>
  <a class="rail-item" href="/#planos"><i class="bi bi-credit-card"></i><b>Planos</b></a>
  <?php if (!empty($currentUser)): ?>
    <a class="rail-item" href="/afiliados"><i class="bi bi-people"></i><b>Afiliados</b></a>
  <?php endif; ?>

  <?php if (!empty($currentUser)): ?>
    <a class="rail-item" href="/minha-conta"><i class="bi bi-person-circle"></i><b>Conta</b></a>
    <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
      <a class="rail-item" href="/admin"><i class="bi bi-gear"></i><b>Admin</b></a>
      <a class="rail-item" href="/admin/financeiro"><i class="bi bi-bar-chart"></i><b>Financeiro</b></a>
    <?php endif; ?>
    <form method="post" action="/sair" class="rail-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf ?? '') ?>">
      <button class="rail-item rail-button" type="submit"><i class="bi bi-box-arrow-right"></i><b>Sair</b></button>
    </form>
  <?php else: ?>
    <a class="rail-item" href="/criar-conta"><i class="bi bi-person-plus"></i><b>Conta</b></a>
  <?php endif; ?>
</aside>

<div class="suite-page">
  <?php if (!empty($flashSuccess)): ?><div class="flash flash-success"><?= View::e($flashSuccess) ?></div><?php endif; ?>
  <?php if (!empty($flashError)): ?><div class="flash flash-error"><?= View::e($flashError) ?></div><?php endif; ?>

  <?= $content ?>

  <footer class="footer">
    <strong>Caption IA</strong>
    <span>Escotilha Produtora · <?= date('Y') ?></span>
    <a href="/privacidade" class="privacy-link">Privacidade e métricas</a>
  </footer>
</div>

<script src="/assets/app.js?v=3" defer></script>
<script src="/assets/analytics.js?v=1" defer></script>
</body>
</html>
