<?php
use CaptionIA\Core\View;
?>
<main class="dashboard-wrap">
<section class="dashboard-head">
  <div>
    <div class="eyebrow">MINHA CONTA</div>
    <h1>Olá, <?= View::e(explode(' ', trim($user['name']))[0]) ?>.</h1>
    <p><?= View::e($user['email']) ?></p>
  </div>
  <?php if ($license): ?>
    <div class="license-badge active">● Licença ativa</div>
  <?php else: ?>
    <div class="license-badge">Sem licença ativa</div>
  <?php endif; ?>
</section>

<div class="dashboard-grid">
  <section class="panel">
    <h2>Licença</h2>
    <?php if ($license): ?>
      <div class="license-plan"><?= View::e($license['plan_name']) ?></div>
      <dl class="data-list">
        <div><dt>Status</dt><dd>Ativa</dd></div>
        <div><dt>Dispositivos</dt><dd><?= count(array_filter($devices, fn($d) => $d['revoked_at'] === null)) ?> / <?= (int) $license['max_devices'] ?></dd></div>
        <div><dt>Atualizações</dt><dd><?= (int) $license['includes_updates'] === 1 ? 'Incluídas' : 'Até a geração ' . View::e($license['max_major_version']) . '.x' ?></dd></div>
        <div><dt>Validade</dt><dd><?= $license['expires_at'] ? View::e(date('d/m/Y H:i', strtotime($license['expires_at']))) : 'Vitalícia' ?></dd></div>
      </dl>
    <?php else: ?>
      <p>Você ainda não tem uma licença ativa.</p>
      <div class="account-actions">
        <a class="button" href="/comprar/mensal">Mensal R$ 30</a>
        <a class="button button-ghost" href="/comprar/vitalicio">Vitalício R$ 70</a>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2>Dispositivos</h2>
    <?php if (!$devices): ?>
      <p class="muted">Nenhum dispositivo ativado pelo plugin ainda.</p>
    <?php else: ?>
      <div class="device-list">
        <?php foreach ($devices as $device): ?>
          <article class="<?= $device['revoked_at'] ? 'revoked' : '' ?>">
            <div><b><?= View::e($device['device_name'] ?: 'Computador') ?></b><small><?= View::e($device['os'] ?: 'Sistema não informado') ?></small></div>
            <span><?= $device['revoked_at'] ? 'Removido' : 'Ativo' ?></span>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<section class="panel orders-panel">
  <h2>Pagamentos</h2>
  <?php if (!$orders): ?>
    <p class="muted">Nenhum pagamento gerado.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Plano</th><th>Valor</th><th>Status</th><th>Data</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
            <tr>
              <td><?= View::e($order['plan_name']) ?></td>
              <td>R$ <?= number_format(((int) $order['amount_cents']) / 100, 2, ',', '.') ?></td>
              <td><span class="status status-<?= View::e($order['status']) ?>"><?= View::e($order['status']) ?></span></td>
              <td><?= View::e(date('d/m/Y H:i', strtotime($order['created_at']))) ?></td>
              <td><a href="/pagamento/<?= View::e($order['reference_id']) ?>">Ver</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
</main>
