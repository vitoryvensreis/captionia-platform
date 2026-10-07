<?php
use CaptionIA\Core\View;
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark">
<title><?= View::e($title ?? 'Caption IA') ?></title>
<meta name="description" content="Caption IA — automação inteligente para edição de vídeo no Adobe Premiere Pro.">
<link rel="stylesheet" href="/assets/app.css?v=4">
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
  <a class="rail-item" href="/"><span>⌂</span><b>Home</b></a>
  <a class="rail-item" href="/#recursos"><span>⌘</span><b>Recursos</b></a>
  <a class="rail-item" href="/#planos"><span>◆</span><b>Planos</b></a>

  <?php if (!empty($currentUser)): ?>
    <a class="rail-item" href="/minha-conta"><span>◉</span><b>Conta</b></a>
    <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
      <a class="rail-item" href="/admin"><span>⚙</span><b>Admin</b></a>
    <?php endif; ?>
    <form method="post" action="/sair" class="rail-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf ?? '') ?>">
      <button class="rail-item rail-button" type="submit"><span>↪</span><b>Sair</b></button>
    </form>
  <?php else: ?>
    <a class="rail-item" href="/criar-conta"><span>＋</span><b>Conta</b></a>
  <?php endif; ?>
</aside>

<div class="suite-page">
  <?php if (!empty($flashSuccess)): ?><div class="flash flash-success"><?= View::e($flashSuccess) ?></div><?php endif; ?>
  <?php if (!empty($flashError)): ?><div class="flash flash-error"><?= View::e($flashError) ?></div><?php endif; ?>

  <?= $content ?>

  <footer class="footer">
    <strong>Caption IA</strong>
    <span>Escotilha Produtora · <?= date('Y') ?></span>
  </footer>
</div>

<script src="/assets/app.js?v=2" defer></script>
</body>
</html>
