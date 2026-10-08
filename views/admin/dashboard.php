<?php use CaptionIA\Core\View; ?>
<main class="dashboard-wrap admin-wrap">
<section class="dashboard-head">
  <div>
    <div class="eyebrow">ADMIN</div>
    <h1>Controle do Caption IA</h1>
    <p>Clientes, pagamentos, planos, licenças, dispositivos e forma de cobrança.</p>
  </div>
</section>

<div class="stats-grid">
  <article><small>Clientes</small><b><?= (int) $stats['users'] ?></b></article>
  <article><small>Licenças ativas</small><b><?= (int) $stats['licenses'] ?></b></article>
  <article><small>Assinaturas</small><b><?= (int) $stats['subscriptions'] ?></b></article>
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
  <div class="panel-title-row">
    <div>
      <h2>Planos</h2>
      <p>Crie ofertas novas e altere preço, dispositivos, atualizações, destaque, ordem e disponibilidade.</p>
    </div>
    <span class="status"><?= count($plans) ?> plano(s)</span>
  </div>

  <details class="admin-create-plan">
    <summary><i class="bi bi-plus-circle"></i> Criar novo plano</summary>
    <form method="post" action="/admin/planos/criar" class="plan-admin-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">

      <div class="plan-form-grid">
        <label>Código da URL
          <input name="code" maxlength="50" placeholder="ex: anual-pro" pattern="[a-z0-9][a-z0-9_-]{1,49}" required>
          <small>Não poderá ser editado depois.</small>
        </label>
        <label>Nome
          <input name="name" maxlength="100" placeholder="Caption IA Anual" required>
        </label>
        <label>Preço
          <input name="price" inputmode="decimal" placeholder="99,90" required>
        </label>
        <label>Tipo
          <select name="type" data-plan-type>
            <option value="subscription">Assinatura</option>
            <option value="lifetime">Vitalício</option>
          </select>
        </label>
        <label>Intervalo
          <select name="billing_interval" data-plan-interval>
            <option value="month">Mensal</option>
            <option value="year">Anual</option>
            <option value="once">Pagamento único</option>
          </select>
        </label>
        <label>Máx. dispositivos
          <input name="max_devices" type="number" min="1" max="20" value="2" required>
        </label>
        <label>Ordem
          <input name="sort_order" type="number" min="-999" max="999" value="0">
        </label>
      </div>

      <label>Descrição
        <textarea name="description" maxlength="255" rows="3" placeholder="Texto comercial exibido no card do plano."></textarea>
      </label>

      <div class="check-row">
        <label class="check-label"><input type="checkbox" name="includes_updates" value="1"> Inclui atualizações</label>
        <label class="check-label"><input type="checkbox" name="featured" value="1"> Destacar no site</label>
        <label class="check-label"><input type="checkbox" name="active" value="1" checked> Plano ativo</label>
      </div>

      <button class="button" type="submit">Criar plano</button>
    </form>
  </details>

  <div class="admin-plan-list">
    <?php foreach ($plans as $plan): ?>
      <details class="admin-plan-editor">
        <summary>
          <span>
            <b><?= View::e($plan['name']) ?></b>
            <small>/comprar/<?= View::e($plan['code']) ?> · R$ <?= number_format(((int) $plan['price_cents']) / 100, 2, ',', '.') ?></small>
          </span>
          <span class="status <?= (int) $plan['active'] === 1 ? 'status-paid' : '' ?>"><?= (int) $plan['active'] === 1 ? 'Ativo' : 'Inativo' ?></span>
        </summary>

        <form method="post" action="/admin/planos/<?= (int) $plan['id'] ?>/editar" class="plan-admin-form">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">

          <div class="plan-code-lock"><span>Código</span><code><?= View::e($plan['code']) ?></code></div>

          <div class="plan-form-grid">
            <label>Nome
              <input name="name" maxlength="100" value="<?= View::e($plan['name']) ?>" required>
            </label>
            <label>Preço
              <input name="price" inputmode="decimal" value="<?= View::e(number_format(((int) $plan['price_cents']) / 100, 2, ',', '.')) ?>" required>
            </label>
            <label>Tipo
              <select name="type" data-plan-type>
                <option value="subscription" <?= $plan['type'] === 'subscription' ? 'selected' : '' ?>>Assinatura</option>
                <option value="lifetime" <?= $plan['type'] === 'lifetime' ? 'selected' : '' ?>>Vitalício</option>
              </select>
            </label>
            <label>Intervalo
              <select name="billing_interval" data-plan-interval>
                <option value="month" <?= $plan['billing_interval'] === 'month' ? 'selected' : '' ?>>Mensal</option>
                <option value="year" <?= $plan['billing_interval'] === 'year' ? 'selected' : '' ?>>Anual</option>
                <option value="once" <?= $plan['billing_interval'] === 'once' ? 'selected' : '' ?>>Pagamento único</option>
              </select>
            </label>
            <label>Máx. dispositivos
              <input name="max_devices" type="number" min="1" max="20" value="<?= (int) $plan['max_devices'] ?>" required>
            </label>
            <label>Ordem
              <input name="sort_order" type="number" min="-999" max="999" value="<?= (int) ($plan['sort_order'] ?? 0) ?>">
            </label>
          </div>

          <label>Descrição
            <textarea name="description" maxlength="255" rows="3"><?= View::e($plan['description'] ?? '') ?></textarea>
          </label>

          <div class="check-row">
            <label class="check-label"><input type="checkbox" name="includes_updates" value="1" <?= (int) $plan['includes_updates'] === 1 ? 'checked' : '' ?>> Inclui atualizações</label>
            <label class="check-label"><input type="checkbox" name="featured" value="1" <?= (int) ($plan['featured'] ?? 0) === 1 ? 'checked' : '' ?>> Destacar no site</label>
            <label class="check-label"><input type="checkbox" name="active" value="1" <?= (int) $plan['active'] === 1 ? 'checked' : '' ?>> Plano ativo</label>
          </div>

          <button class="button" type="submit">Salvar alterações</button>
        </form>
      </details>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel">
  <div class="panel-title-row">
    <div>
      <h2>Atualizações do plugin</h2>
      <p>Publique o ZIP oficial para que o Caption IA baixe e aplique a nova versão automaticamente.</p>
    </div>
    <span class="status"><?= count($releases) ?> release(s)</span>
  </div>

  <form method="post" action="/admin/releases/publicar" enctype="multipart/form-data" class="settings-form release-admin-form">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">

    <div class="form-grid">
      <label>Versão
        <input name="version" placeholder="3.4.0" pattern="[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?" required>
      </label>
      <label>ZIP da versão
        <input name="release_zip" type="file" accept=".zip,application/zip" required>
      </label>
    </div>

    <label>Notas da atualização
      <textarea name="notes" maxlength="4000" rows="4" placeholder="Ex.: melhorias no AutoEdit, correções no Karaoke e ajustes de interface."></textarea>
    </label>

    <div class="settings-help">
      O ZIP deve ser o pacote oficial gerado pelo repositório do plugin e conter a pasta <code>CaptionIA/</code>.
      O servidor calcula SHA-256 e o plugin valida a integridade antes de preparar a atualização.
    </div>

    <button class="button" type="submit">Publicar atualização</button>
  </form>

  <?php if ($releases): ?>
    <div class="table-wrap">
      <table class="responsive-table">
        <thead><tr><th>Versão</th><th>Tamanho</th><th>SHA-256</th><th>Publicada</th><th>Status</th><th>Ação</th></tr></thead>
        <tbody>
        <?php foreach ($releases as $release): ?>
          <tr>
            <td data-label="Versão"><b><?= View::e($release['version']) ?></b></td>
            <td data-label="Tamanho"><?= number_format(((int) ($release['file_size'] ?? 0)) / 1048576, 1, ',', '.') ?> MB</td>
            <td data-label="SHA-256"><code title="<?= View::e($release['sha256'] ?? '') ?>"><?= View::e(substr((string) ($release['sha256'] ?? ''), 0, 12)) ?>…</code></td>
            <td data-label="Publicada"><?= View::e(date('d/m/Y H:i', strtotime($release['published_at']))) ?></td>
            <td data-label="Status"><span class="status <?= (int) $release['active'] === 1 ? 'status-paid' : '' ?>"><?= (int) $release['active'] === 1 ? 'Ativa' : 'Inativa' ?></span></td>
            <td data-label="Ação">
              <form method="post" action="/admin/releases/<?= (int) $release['id'] ?>/<?= (int) $release['active'] === 1 ? 'desativar' : 'ativar' ?>">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                <button class="mini-button" type="submit"><?= (int) $release['active'] === 1 ? 'Desativar' : 'Ativar' ?></button>
              </form>
            </td>
          </tr>
          <?php if (!empty($release['notes'])): ?>
            <tr class="release-notes-row">
              <td colspan="6"><small><?= nl2br(View::e($release['notes'])) ?></small></td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="muted">Nenhuma atualização publicada ainda.</p>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Liberar licença manualmente</h2>
  <p class="muted">Crie uma licença para qualquer cliente cadastrado usando qualquer plano existente.</p>
  <form method="post" action="/admin/licencas/criar" class="admin-inline-form">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <input name="email" type="email" placeholder="cliente@email.com" required>
    <select name="plan" required>
      <?php foreach ($plans as $plan): ?>
        <option value="<?= View::e($plan['code']) ?>"><?= View::e($plan['name']) ?><?= (int) $plan['active'] === 0 ? ' · inativo' : '' ?></option>
      <?php endforeach; ?>
    </select>
    <button class="button" type="submit">Liberar</button>
  </form>
</section>

<section class="panel">
  <h2>Últimos pagamentos</h2>
  <div class="table-wrap">
    <table class="responsive-table">
      <thead><tr><th>Cliente</th><th>Plano</th><th>Gateway</th><th>Valor</th><th>Status</th><th>Data</th><th>Ação</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $order): ?>
        <tr>
          <td data-label="Cliente"><b><?= View::e($order['name']) ?></b><small><?= View::e($order['email']) ?></small></td>
          <td data-label="Plano"><?= View::e($order['plan_name']) ?></td>
          <td data-label="Gateway"><?= View::e(match($order['provider_mode'] ?? '') {
              'bacen_static_pix' => 'Pix direto',
              'hosted_checkout' => 'PagBank',
              default => 'PagBank PIX'
          }) ?></td>
          <td data-label="Valor">R$ <?= number_format(((int) $order['amount_cents']) / 100, 2, ',', '.') ?></td>
          <td data-label="Status"><span class="status status-<?= View::e($order['status']) ?>"><?= View::e($order['status']) ?></span></td>
          <td data-label="Data"><?= View::e(date('d/m/Y H:i', strtotime($order['created_at']))) ?></td>
          <td data-label="Ação">
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
  <h2>Clientes e licenças</h2>
  <div class="table-wrap">
    <table class="responsive-table">
      <thead><tr><th>Cliente</th><th>Plano</th><th>Licença</th><th>Validade</th><th>Ações</th></tr></thead>
      <tbody>
      <?php foreach ($users as $row): ?>
        <tr>
          <td data-label="Cliente"><b><?= View::e($row['name']) ?></b><small><?= View::e($row['email']) ?></small></td>
          <td data-label="Plano"><?= View::e($row['plan_name'] ?: '—') ?></td>
          <td data-label="Licença"><?= View::e($row['license_status'] ?: '—') ?></td>
          <td data-label="Validade"><?= $row['expires_at'] ? View::e(date('d/m/Y', strtotime($row['expires_at']))) : (($row['plan_code'] ?? '') ? 'Sem vencimento' : '—') ?></td>
          <td data-label="Ações">
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
