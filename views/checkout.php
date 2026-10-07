<?php
use CaptionIA\Core\View;
$price = number_format(((int) $plan['price_cents']) / 100, 2, ',', '.');
$isDirectPix = ($paymentGateway ?? 'bacen_pix') === 'bacen_pix';
?>
<main class="checkout-wrap">
<section class="checkout-card">
  <div class="eyebrow">FINALIZAR COMPRA</div>
  <h1><?= View::e($plan['name']) ?></h1>
  <div class="checkout-price">R$ <?= View::e($price) ?></div>
  <p><?php
      $planDescription = trim((string) ($plan['description'] ?? ''));
      if ($planDescription !== '') {
          echo View::e($planDescription);
      } elseif (($plan['type'] ?? '') === 'lifetime') {
          echo 'Pagamento único para a geração principal adquirida.';
      } elseif (($plan['billing_interval'] ?? '') === 'year') {
          echo 'Licença por assinatura anual.';
      } else {
          echo 'Licença por assinatura mensal.';
      }
  ?></p>

  <div class="checkout-summary">
    <div><span>Conta</span><b><?= View::e($user['email']) ?></b></div>
    <div><span>Dispositivos</span><b>até <?= (int) $plan['max_devices'] ?></b></div>
    <div><span>Pagamento</span><b><?= $isDirectPix ? 'Pix direto · BR Code' : 'Checkout PagBank · PIX' ?></b></div>
  </div>

  <?php if (!$isDirectPix && !$pagbankConfigured): ?>
    <div class="warning">O PagBank ainda não está configurado no servidor.</div>
  <?php else: ?>
    <form method="post" action="/comprar/<?= View::e($slug) ?>/checkout">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
      <button class="button button-wide" type="submit">
        <?= $isDirectPix ? 'Gerar Pix' : 'Continuar no PagBank' ?>
      </button>
    </form>
  <?php endif; ?>

  <p class="secure-note">
    <?= $isDirectPix
      ? 'O Pix direto usa um BR Code estático com valor e identificador da compra. A licença é liberada após a confirmação pelo Admin.'
      : 'Você será redirecionado ao ambiente seguro do PagBank e a licença será liberada quando o pagamento for confirmado.' ?>
  </p>
</section>
</main>
