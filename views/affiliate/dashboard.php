<?php use CaptionIA\Core\View;
$p=$affiliate['profile']??null; $b=$affiliate['balance']??null;
$active=$config['enabled']&&$p&&(int)$p['enabled']===1;
$money=static fn(int $n):string => 'R$ '.number_format($n/100,2,',','.');
?>
<main class="dashboard-wrap affiliate-wrap">
<section class="dashboard-head"><div><div class="eyebrow">AFILIADOS</div><h1>Minhas indicações</h1>
<p>Compartilhe seu link e acompanhe as comissões das compras confirmadas.</p></div>
<a class="mini-button" href="/minha-conta">Voltar à conta</a></section>
<?php if (!$config['enabled']): ?>
<section class="panel"><h2>Programa pausado</h2><p>O administrador ainda não liberou novas indicações.</p></section>
<?php elseif (!$p): ?>
<section class="panel"><h2>Aguardando liberação</h2>
<p>Clientes com compras confirmadas podem receber seu link automaticamente, conforme a configuração do administrador.</p></section>
<?php else: ?>
<section class="panel"><div class="panel-title-row"><div><h2>Seu link exclusivo</h2><p>Envie para outras pessoas conhecerem o Caption IA.</p></div>
<span class="status <?= $active?'status-paid':'status-waiting' ?>"><?= $active?'Ativo':'Desativado' ?></span></div>
<?php if ($active): ?>
<div class="affiliate-copy"><input id="affiliate-link" readonly value="https://caption.escotilhaprodutora.com/r/<?= View::e($p['code']) ?>">
<button type="button" class="button" data-copy-affiliate>Copiar link</button></div>
<p class="muted">Comissão atual nas novas vendas: <?= View::e($config['rate_percent']) ?>%.
Prazo de retenção: <?= (int)$config['hold_days'] ?> dias após o pagamento.</p>
<?php else: ?><p class="muted">Seu link está desativado. Seus saldos anteriores continuam registrados.</p><?php endif; ?>
</section>
<?php endif; ?>
<?php if ($p): ?>
<div class="stats-grid affiliate-stats">
<article><small>Comissões acumuladas</small><b><?= $money((int)$b['earned_cents']) ?></b></article>
<article><small>Aguardando liberação</small><b><?= $money((int)$b['holding_cents']) ?></b></article>
<article><small>Disponível para saque</small><b><?= $money((int)$b['available_cents']) ?></b></article>
<article><small>Reservado ou pago</small><b><?= $money((int)$b['reserved_paid_cents']) ?></b></article>
</div>
<section class="panel"><h2>Solicitar saque</h2>
<p class="muted">Saque mínimo de <?= $money((int)$config['minimum_cents']) ?>. O administrador realiza a transferência manualmente e confirma no painel.</p>
<?php if ($active): ?><form method="post" action="/afiliados/saques" class="settings-form">
<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
<div class="form-grid"><label>Valor (R$)<input name="amount" inputmode="decimal" placeholder="50,00" required></label>
<label>Chave Pix para receber<input name="pix_key" autocomplete="off" minlength="5" maxlength="140" required></label></div>
<p class="muted">A chave Pix fica criptografada no servidor e visível apenas ao responsável pelos saques.</p>
<button class="button" type="submit" <?= (int)$b['available_cents']<(int)$config['minimum_cents']?'disabled':'' ?>>Solicitar saque</button></form>
<?php else: ?><p>Saques indisponíveis enquanto o acesso estiver desativado.</p><?php endif; ?></section>
<section class="panel"><h2>Histórico de saques</h2><div class="table-wrap"><table class="responsive-table">
<thead><tr><th>Valor</th><th>Status</th><th>Solicitado</th><th>Observação</th></tr></thead><tbody>
<?php foreach ($affiliate['withdrawals'] as $w): ?>
<tr><td><?= $money((int)$w['amount_cents']) ?></td>
<td><?= View::e(match($w['status']){'paid'=>'Pago','requested'=>'Aguardando','rejected'=>'Recusado',default=>$w['status']}) ?></td>
<td><?= View::e(date('d/m/Y H:i',strtotime($w['requested_at']))) ?></td><td><?= View::e($w['admin_note']?:'—') ?></td></tr>
<?php endforeach; ?>
<?php if (!$affiliate['withdrawals']): ?><tr><td colspan="4">Nenhum saque solicitado.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="panel"><h2>Comissões por venda</h2><div class="table-wrap"><table class="responsive-table">
<thead><tr><th>Pedido</th><th>Valor</th><th>Situação</th><th>Disponível em</th></tr></thead><tbody>
<?php foreach ($affiliate['commissions'] as $c): ?><tr>
<td><?= View::e($c['reference_id']) ?></td><td><?= $money((int)$c['amount_cents']) ?></td>
<td><?= View::e($c['status']==='reversed'?'Estornada':(strtotime($c['available_at'])<=time()?'Liberada':'Em retenção')) ?></td>
<td><?= View::e(date('d/m/Y',strtotime($c['available_at']))) ?></td></tr><?php endforeach; ?>
<?php if (!$affiliate['commissions']): ?><tr><td colspan="4">Nenhuma comissão registrada ainda.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php endif; ?>
</main>