<?php use CaptionIA\Core\View; ?>
<main class="dashboard-wrap admin-wrap">
<section class="dashboard-head">
  <div>
    <div class="eyebrow">ADMIN</div>
    <h1>Controle do Caption IA</h1>
    <p>Clientes, pagamentos, licenças, dispositivos e forma de cobrança.</p>
  </div>
</section>

<div class="stats-grid">
  <article><small>Clientes</small><b><?= (int) $stats['users'] ?></b></article>
  <article><small>Licenças ativas</small><b><?= (int) $stats['licenses'] ?></b></article>
  <article><small>Mensais ativas</small><b><?= (int) $stats['monthly'] ?></b></article>
  <article><small>Vitalícias</small><b><?= (int) $stats['lifetime'] ?></b></article>
  <article><small>Recebido</small><b>R$ <?= number_format(((int) $stats['paid_cents']) / 100, 2, ',', '.') ?></b></article>
</div>

<section class="panel payment-settings-panel">
  <div class="panel-title-row">
    <div><h2>Forma de pagamento</h2><p>Troque entre PagBank hospedado e Pix direto sem alterar o site.</p></div>
    <span class="status <?= ($paymentSettings['gateway'] ?? '') === 'pagbank' ? 'status-paid' : 'status-waiting' ?>">
      <?= ($paymentSettings['gateway'] ?? '') === 'pagbank' ? 'PagBank' : 'Pix direto' ?>
    </span>
  </div>

  <form method="post" action="/admin/configuracoes/pagamentos" class="settings-form">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">

    <label>Gateway
      <select name="gateway">
        <option value="bacen_pix" <?= ($paymentSettings['gateway'] ?? '') === 'bacen_pix' ? 'selected' : '' ?>>Pix direto · BR Code</option>
        <option value="pagbank" <?= ($paymentSettings['gateway'] ?? '') === 'pagbank' ? 'selected' : '' ?>>Checkout PagBank hospedado</option>
      </select>
    </label>

    <div class="form-grid">
      <label>Chave Pix
        <input name="pix_key" value="<?= View::e($paymentSettings['pix_key'] ?? '') ?>" placeholder="CPF, e-mail, celular ou chave aleatória">
      </label>
      <label>Nome do recebedor
        <input name="pix_name" maxlength="25" value="<?= View::e($paymentSettings['pix_name'] ?? 'CAPTION IA') ?>" required>
      </label>
    </div>

    <label>Cidade do recebedor
      <input name="pix_city" maxlength="15" value="<?= View::e($paymentSettings['pix_city'] ?? 'FORTALEZA') ?>" required>
    </label>

    <div class="settings-help">
      <b>Pix direto:</b> o site gera o BR Code e o QR Code localmente. A confirmação do recebimento é manual pelo Admin.
      <br><b>PagBank:</b> o cliente é redirecionado ao checkout hospedado e a confirmação ocorre por webhook.
      <?php if (!$pagbankConfigured): ?><br><span class="danger-text">O token PagBank não está configurado.</span><?php endif; ?>
    </div>

    <button class="button" type="submit">Salvar forma de pagamento</button>
  </form>
</section>

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
  <h2>Últimos pagamentos</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Cliente</th><th>Plano</th><th>Gateway</th><th>Valor</th><th>Status</th><th>Data</th><th>Ação</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $order): ?>
        <tr>
          <td><b><?= View::e($order['name']) ?></b><small><?= View::e($order['email']) ?></small></td>
          <td><?= View::e($order['plan_name']) ?></td>
          <td><?= View::e(match($order['provider_mode'] ?? '') {
              'bacen_static_pix' => 'Pix direto',
              'hosted_checkout' => 'PagBank',
              default => 'PagBank PIX'
          }) ?></td>
          <td>R$ <?= number_format(((int) $order['amount_cents']) / 100, 2, ',', '.') ?></td>
          <td><span class="status status-<?= View::e($order['status']) ?>"><?= View::e($order['status']) ?></span></td>
          <td><?= View::e(date('d/m/Y H:i', strtotime($order['created_at']))) ?></td>
          <td>
            <?php if (($order['provider_mode'] ?? '') === 'bacen_static_pix' && $order['status'] === 'waiting'): ?>
              <form method="post" action="/admin/pagamentos/<?= View::e($order['reference_id']) ?>/confirmar-pix" onsubmit="return confirm('Confirma que o Pix foi recebido? A licença será liberada.');">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                <button class="mini-button confirm" type="submit">Confirmar Pix</button>
              </form>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
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
</main>
