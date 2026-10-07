<?php
use CaptionIA\Core\View;
$price = number_format(((int) $plan['price_cents']) / 100, 2, ',', '.');
?>
<main class="checkout-wrap">
<section class="checkout-card">
  <div class="eyebrow">FINALIZAR COMPRA</div>
  <h1><?= View::e($plan['name']) ?></h1>
  <div class="checkout-price">R$ <?= View::e($price) ?></div>
  <p><?= $plan['code'] === 'monthly'
      ? 'A licença mensal vale por 30 dias. A renovação é feita por um novo PIX quando você quiser continuar.'
      : 'Pagamento único. A licença permanece ativa para a geração principal adquirida.' ?></p>

  <div class="checkout-summary">
    <div><span>Conta</span><b><?= View::e($user['email']) ?></b></div>
    <div><span>Dispositivos</span><b>até <?= (int) $plan['max_devices'] ?></b></div>
    <div><span>Pagamento</span><b>Checkout PagBank · PIX</b></div>
  </div>

  <?php if (!$pagbankConfigured): ?>
    <div class="warning">O PagBank ainda não está configurado no servidor.</div>
  <?php else: ?>
    <form method="post" action="/comprar/<?= View::e($slug) ?>/checkout">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
      <button class="button button-wide" type="submit">Continuar no PagBank</button>
    </form>
  <?php endif; ?>

  <p class="secure-note">Você será redirecionado ao ambiente seguro do PagBank. O checkout foi configurado para PIX e a licença só é liberada após a confirmação do pagamento.</p>
</section>
</main>
