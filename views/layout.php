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
<link rel="stylesheet" href="/assets/app.css?v=1">
</head>
<body>
<header class="site-header">
  <a class="brand" href="/">Caption <span>IA</span></a>
  <nav class="nav">
    <a href="/#planos">Planos</a>
    <?php if (!empty($currentUser)): ?>
      <a href="/minha-conta">Minha conta</a>
      <?php if (($currentUser['role'] ?? '') === 'admin'): ?><a href="/admin">Admin</a><?php endif; ?>
      <form method="post" action="/sair" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf ?? '') ?>">
        <button class="nav-button" type="submit">Sair</button>
      </form>
    <?php else: ?>
      <a href="/entrar">Entrar</a>
      <a class="button button-small" href="/criar-conta">Criar conta</a>
    <?php endif; ?>
  </nav>
</header>

<?php if (!empty($flashSuccess)): ?><div class="flash flash-success"><?= View::e($flashSuccess) ?></div><?php endif; ?>
<?php if (!empty($flashError)): ?><div class="flash flash-error"><?= View::e($flashError) ?></div><?php endif; ?>

<?= $content ?>

<footer class="footer">
  <strong>Caption IA</strong>
  <span>Escotilha Produtora · <?= date('Y') ?></span>
</footer>
<script src="/assets/app.js?v=1" defer></script>
</body>
</html>
