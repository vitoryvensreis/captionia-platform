<?php use CaptionIA\Core\View; ?>
<main class="auth-wrap">
<section class="auth-card">
  <div class="eyebrow">COMEÇAR</div>
  <h1>Criar conta</h1>
  <p>Crie sua conta para gerar o PIX e receber a licença automaticamente após a confirmação.</p>
  <form method="post" action="/criar-conta" class="form-stack">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="plan" value="<?= View::e($plan ?? '') ?>">
    <label>Nome completo<input name="name" autocomplete="name" required></label>
    <label>E-mail<input name="email" type="email" autocomplete="email" required></label>
    <div class="form-grid">
      <label>CPF<input name="cpf" inputmode="numeric" autocomplete="off" placeholder="Somente números" required></label>
      <label>Celular com DDD<input name="phone" inputmode="tel" autocomplete="tel" placeholder="85999999999" required></label>
    </div>
    <label>Senha<input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
    <button class="button button-wide" type="submit">Criar minha conta</button>
  </form>
  <p class="muted">Já tem conta? <a href="/entrar">Entrar</a></p>
</section>
</main>
