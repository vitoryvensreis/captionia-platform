<?php
use CaptionIA\Core\View;
$status = $order['status'];
$isPaid = $status === 'paid';
$isFinal = in_array($status, ['paid','declined','cancelled','expired','failed'], true);
$isHosted = ($order['provider_mode'] ?? '') === 'hosted_checkout';
?>
<main class="checkout-wrap">
<section class="checkout-card payment-card" data-payment-reference="<?= View::e($order['reference_id']) ?>" data-payment-final="<?= $isFinal ? '1' : '0' ?>">
  <div class="eyebrow">PAGAMENTO</div>
  <h1><?= $isPaid ? 'Pagamento confirmado' : 'Aguardando pagamento' ?></h1>

  <?php if ($isPaid): ?>
    <div class="success-icon">✓</div>
    <p>Seu pagamento foi confirmado e a licença já foi atualizada.</p>
    <a class="button button-wide" href="/minha-conta">Ir para minha conta</a>
  <?php elseif (in_array($status, ['declined','cancelled','expired','failed'], true)): ?>
    <div class="warning">Este pagamento não está mais disponível. Gere um novo checkout para continuar.</div>
    <a class="button button-wide" href="/comprar/<?= $order['plan_code'] === 'lifetime' ? 'vitalicio' : 'mensal' ?>">Gerar novo checkout</a>
  <?php elseif ($isHosted): ?>
    <div class="checkout-summary">
      <div><span>Plano</span><b><?= View::e($order['plan_name']) ?></b></div>
      <div><span>Valor</span><b>R$ <?= number_format(((int) $order['amount_cents']) / 100, 2, ',', '.') ?></b></div>
      <div><span>Status</span><b>Aguardando PagBank</b></div>
    </div>
    <?php if (!empty($order['hosted_checkout_url'])): ?>
      <a class="button button-wide" href="<?= View::e($order['hosted_checkout_url']) ?>" rel="noopener noreferrer">Voltar ao Checkout PagBank</a>
    <?php endif; ?>
    <div class="payment-wait">
      <span class="spinner"></span>
      <div><b>Aguardando confirmação...</b><small>Esta tela consulta o status automaticamente.</small></div>
    </div>
  <?php else: ?>
    <div class="pix-grid">
      <div class="qr-frame">
        <?php if (!empty($order['qr_code_image_url'])): ?>
          <img src="/pagamento/<?= View::e($order['reference_id']) ?>/qrcode" alt="QR Code PIX">
        <?php else: ?>
          <div class="qr-placeholder">QR</div>
        <?php endif; ?>
      </div>
      <div class="pix-info">
        <div class="payment-amount">R$ <?= number_format(((int) $order['amount_cents']) / 100, 2, ',', '.') ?></div>
        <p>Escaneie o QR Code ou use o PIX Copia e Cola.</p>
        <label>PIX Copia e Cola</label>
        <textarea id="pix-code" readonly><?= View::e($order['qr_code_text'] ?? '') ?></textarea>
        <button class="button button-wide" type="button" data-copy-pix>Copiar código PIX</button>
        <small>Validade: <?= View::e(date('d/m/Y H:i', strtotime((string) $order['expires_at']))) ?></small>
      </div>
    </div>
    <div class="payment-wait">
      <span class="spinner"></span>
      <div><b>Aguardando confirmação...</b><small>Esta tela atualiza automaticamente.</small></div>
    </div>
  <?php endif; ?>
</section>
</main>
