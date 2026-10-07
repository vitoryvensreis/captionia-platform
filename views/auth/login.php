<?php use CaptionIA\Core\View; ?>
<main class="auth-wrap">
<section class="auth-card">
  <div class="eyebrow">MINHA CONTA</div>
  <h1>Entrar</h1>
  <p>Acesse suas licenças, dispositivos e pagamentos.</p>
  <form method="post" action="/entrar" class="form-stack">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="next" value="<?= View::e($next ?? '/minha-conta') ?>">
    <label>E-mail<input name="email" type="email" autocomplete="email" required></label>
    <label>Senha<input name="password" type="password" autocomplete="current-password" required></label>
    <button class="button button-wide" type="submit">Entrar</button>
  </form>
  <p class="muted">Ainda não tem conta? <a href="/criar-conta">Criar conta</a></p>
</section>
</main>
