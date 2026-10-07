<?php use CaptionIA\Core\View; ?>
<main class="dashboard-wrap admin-wrap">
<section class="dashboard-head">
  <div>
    <div class="eyebrow">ADMIN</div>
    <h1>Caption IA</h1>
    <p>Clientes, pagamentos, licenças e dispositivos.</p>
  </div>
</section>

<div class="stats-grid">
  <article><small>Clientes</small><b><?= (int) $stats['users'] ?></b></article>
  <article><small>Licenças ativas</small><b><?= (int) $stats['licenses'] ?></b></article>
  <article><small>Mensais ativas</small><b><?= (int) $stats['monthly'] ?></b></article>
  <article><small>Vitalícias</small><b><?= (int) $stats['lifetime'] ?></b></article>
  <article><small>Recebido</small><b>R$ <?= number_format(((int) $stats['paid_cents']) / 100, 2, ',', '.') ?></b></article>
</div>

<section class="panel">
  <h2>Liberar licença manualmente</h2>
  <form method="post" action="/admin/licencas/criar" class="admin-inline-form">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <input name="email" type="email" placeholder="cliente@email.com" required>
    <select name="plan"><option value="monthly">Mensal — 30 dias</option><option value="lifetime">Vitalícia</option></select>
    <button class="button" type="submit">Liberar</button>
  </form>
</section>

<section class="panel">
  <h2>Clientes</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Cliente</th><th>Plano</th><th>Licença</th><th>Validade</th><th>Ações</th></tr></thead>
      <tbody>
      <?php foreach ($users as $row): ?>
        <tr>
          <td><b><?= View::e($row['name']) ?></b><small><?= View::e($row['email']) ?></small></td>
          <td><?= View::e($row['plan_name'] ?: '—') ?></td>
          <td><?= View::e($row['license_status'] ?: '—') ?></td>
          <td><?= $row['expires_at'] ? View::e(date('d/m/Y', strtotime($row['expires_at']))) : ($row['plan_code'] === 'lifetime' ? 'Vitalícia' : '—') ?></td>
          <td>
            <?php if (!empty($row['license_id'])): ?>
              <div class="action-row">
                <form method="post" action="/admin/licencas/<?= (int) $row['license_id'] ?>/<?= $row['license_status'] === 'revoked' ? 'reativar' : 'revogar' ?>">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                  <button class="mini-button" type="submit"><?= $row['license_status'] === 'revoked' ? 'Reativar' : 'Revogar' ?></button>
                </form>
                <form method="post" action="/admin/licencas/<?= (int) $row['license_id'] ?>/resetar-dispositivos">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                  <button class="mini-button" type="submit">Resetar PCs</button>
                </form>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <h2>Últimos pagamentos</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Cliente</th><th>Plano</th><th>Valor</th><th>Status</th><th>Data</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $order): ?>
        <tr>
          <td><b><?= View::e($order['name']) ?></b><small><?= View::e($order['email']) ?></small></td>
          <td><?= View::e($order['plan_name']) ?></td>
          <td>R$ <?= number_format(((int) $order['amount_cents']) / 100, 2, ',', '.') ?></td>
          <td><?= View::e($order['status']) ?></td>
          <td><?= View::e(date('d/m/Y H:i', strtotime($order['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
</main>
