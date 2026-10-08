<?php use CaptionIA\Core\View;
$config=$data['config'];$money=static fn(int $n):string=>'R$ '.number_format($n/100,2,',','.');
?>
<main class="dashboard-wrap admin-wrap affiliate-admin">
<section class="dashboard-head"><div><div class="eyebrow">ADMIN · COMISSÕES</div><h1>Gestão de afiliados</h1>
<p>Configure percentuais, libere clientes e valide solicitações de saque.</p></div>
<div class="action-row"><a class="mini-button" href="/admin/financeiro">Financeiro</a><a class="mini-button" href="/admin">Painel geral</a></div></section>
<div class="stats-grid affiliate-stats">
<article><small>Vendas com comissão</small><b><?= (int)$data['summary']['total_orders'] ?></b></article>
<article><small>Comissões geradas</small><b><?= $money((int)$data['summary']['earned_cents']) ?></b></article>
<article><small>Já liberadas</small><b><?= $money((int)$data['summary']['matured_cents']) ?></b></article>
<article><small>Saques aguardando</small><b><?= count(array_filter($data['withdrawals'],static fn($w)=>$w['status']==='requested')) ?></b></article>
</div>
<section class="panel"><div class="panel-title-row"><div><h2>Configurações do programa</h2>
<p>As novas compras recebem a taxa vigente na criação do pedido. Comissões antigas não mudam.</p></div>
<span class="status <?= $config['enabled']?'status-paid':'status-waiting' ?>"><?= $config['enabled']?'Programa ativo':'Programa desligado' ?></span></div>
<form method="post" action="/admin/afiliados/configurar" class="settings-form">
<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
<div class="form-grid">
<label>Comissão sobre venda (%)<input name="percent" inputmode="decimal" value="<?= View::e($config['rate_percent']) ?>" required></label>
<label>Saque mínimo (R$)<input name="minimum" inputmode="decimal" value="<?= View::e(number_format($config['minimum_cents']/100,2,',','.')) ?>" required></label>
<label>Prazo para liberar comissão (dias)<input type="number" name="hold_days" min="0" max="90" value="<?= (int)$config['hold_days'] ?>"></label>
</div>
<div class="check-row">
<label class="check-label"><input type="checkbox" name="auto_enable_buyers" value="1" <?= $config['auto_enable_buyers']?'checked':'' ?>> Ativar automaticamente clientes após compra paga</label>
<label class="check-label"><input type="checkbox" name="enabled" value="1" <?= $config['enabled']?'checked':'' ?>> Programa de afiliados habilitado</label>
</div>
<p class="muted">Importante: antes de habilitar, configure AFFILIATE_PAYOUT_KEY no servidor. A plataforma não realiza transferências bancárias automaticamente.</p>
<button class="button" type="submit">Salvar configurações</button>
</form></section>
<section class="panel"><h2>Solicitações de saque</h2>
<div class="table-wrap"><table class="responsive-table"><thead><tr><th>Afiliado</th><th>Valor</th><th>Chave Pix</th><th>Status</th><th>Data</th><th>Conferência</th></tr></thead><tbody>
<?php foreach($data['withdrawals'] as $w): ?>
<tr><td><b><?= View::e($w['name']) ?></b><small><?= View::e($w['email']) ?></small></td>
<td><?= $money((int)$w['amount_cents']) ?></td>
<td><?= $w['status']==='requested'?View::e($w['pix_key']):'Oculta após análise' ?></td>
<td><?= View::e(match($w['status']){'requested'=>'Pendente','paid'=>'Pago','rejected'=>'Recusado',default=>$w['status']}) ?></td>
<td><?= View::e(date('d/m/Y H:i',strtotime($w['requested_at']))) ?></td>
<td><?php if($w['status']==='requested'): ?>
<form method="post" action="/admin/afiliados/saques/<?= (int)$w['id'] ?>/pago"
onsubmit="return confirm('Confirma que a transferência Pix JÁ FOI REALIZADA?')">
<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
<button class="mini-button confirm" type="submit">Registrar como pago</button></form>
<form method="post" action="/admin/afiliados/saques/<?= (int)$w['id'] ?>/recusar" class="affiliate-reject">
<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><input name="note" required maxlength="500" placeholder="Motivo da recusa">
<button class="mini-button" type="submit">Recusar</button></form>
<?php else: ?><small><?= View::e($w['admin_note']?:'Concluído') ?></small><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if(!$data['withdrawals']): ?><tr><td colspan="6">Ainda não existem solicitações.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="panel"><h2>Quem pode divulgar?</h2>
<p class="muted">Libere individualmente ou utilize a ativação automática para clientes com compras confirmadas.</p>
<div class="table-wrap"><table class="responsive-table"><thead><tr><th>Cliente</th><th>Compras pagas</th><th>Código</th><th>Permissão</th><th>Ação</th></tr></thead><tbody>
<?php foreach($data['users'] as $u): ?>
<tr><td><b><?= View::e($u['name']) ?></b><small><?= View::e($u['email']) ?></small></td>
<td><?= (int)$u['paid_orders'] ?></td><td><code><?= View::e($u['code']??'—') ?></code></td>
<td><?= $u['affiliate_id']?((int)$u['enabled']===1?'Ativo':'Bloqueado'):'Não cadastrado' ?></td>
<td><form method="post" action="/admin/afiliados/usuario/<?= (int)$u['id'] ?>/<?= $u['affiliate_id'] && (int)$u['enabled']===1?'desativar':'ativar' ?>">
<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
<button class="mini-button" type="submit"><?= $u['affiliate_id']&&(int)$u['enabled']===1?'Desativar':'Ativar' ?></button>
</form></td></tr><?php endforeach; ?></tbody></table></div></section>
</main>