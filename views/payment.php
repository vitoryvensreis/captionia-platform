<?php
use CaptionIA\Core\View;
$status = $order['status'];
$isPaid = $status === 'paid';
$isFinal = in_array($status, ['paid','declined','cancelled','expired','failed'], true);
?>
<main class="checkout-wrap">
<section class="checkout-card payment-card" data-payment-reference="<?= View::e($order['reference_id']) ?>" data-payment-final="<?= $isFinal ? '1' : '0' ?>">
  <div class="eyebrow">PAGAMENTO PIX</div>
  <h1><?= $isPaid ? 'Pagamento confirmado' : 'Pague pelo seu banco' ?></h1>

  <?php if ($isPaid): ?>
    <div class="success-icon">✓</div>
    <p>Seu pagamento foi confirmado e a licença já foi atualizada.</p>
    <a class="button button-wide" href="/minha-conta">Ir para minha conta</a>
  <?php elseif (in_array($status, ['declined','cancelled','expired','failed'], true)): ?>
    <div class="warning">Este PIX não está mais disponível. Gere um novo pagamento para continuar.</div>
    <a class="button button-wide" href="/comprar/<?= $order['plan_code'] === 'lifetime' ? 'vitalicio' : 'mensal' ?>">Gerar novo PIX</a>
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
