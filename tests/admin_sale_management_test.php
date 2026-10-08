<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use CaptionIA\Core\View;
use CaptionIA\Services\FinanceService;
use CaptionIA\Services\SaleManagementService;
use CaptionIA\Services\SettingsService;
use CaptionIA\Services\OrderAdjustmentService;

Env::load(dirname(__DIR__));
date_default_timezone_set('America/Fortaleza');
$pdo=Database::connection();
$check=static function(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException('Sale admin regression: '.$message);
};

$id=bin2hex(random_bytes(5));
$users=$pdo->prepare(
    "INSERT INTO users(name,email,cpf,phone,password_hash,role,status)
     VALUES (?,?,?,'0000000000','test-hash',?,'active')"
);
$users->execute(['Sale Admin','sale-admin-'.$id.'@example.test','70000000001','admin']);
$admin=(int)$pdo->lastInsertId();
$users->execute(['Sale Buyer','sale-client-'.$id.'@example.test','70000000002','customer']);
$buyer=(int)$pdo->lastInsertId();
$plan=(int)$pdo->query("SELECT id FROM plans WHERE code='monthly'")->fetchColumn();
$reference='CIA-'.strtoupper(bin2hex(random_bytes(8)));

$orderStmt=$pdo->prepare(
    "INSERT INTO checkout_orders
     (reference_id,user_id,plan_id,status,amount_cents,provider_mode,license_applied,expires_at,paid_at)
     VALUES (?,?,?,'paid',3000,'bacen_static_pix',1,DATE_ADD(NOW(),INTERVAL 1 DAY),NOW())"
);
$orderStmt->execute([$reference,$buyer,$plan]);
$orderId=(int)$pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO payment_transactions
     (user_id,provider,provider_payment_id,amount_cents,status)
     VALUES (?,'bacen_static_pix',?,3000,'paid')"
)->execute([$buyer,'BACEN-'.$reference]);

$sales=new SaleManagementService();
$finance=new FinanceService(new SettingsService());
$data=$sales->details($reference);
$check((int)$data['sale']['amount_cents']===3000,'original receipt reads');
$check($data['sale']['display_amount_cents']===null,'no override initially');

$sales->updateValue($reference,'59,90','Cliente recebeu ajuste de valor comercial',$admin);
$data=$sales->details($reference);
$check((int)$data['sale']['display_amount_cents']===5990,'managerial override');
$check(count($data['history'])===1 && $data['history'][0]['action']==='adjust_value','audit first correction');

$receipt=$pdo->query("SELECT amount_cents,status,plan_id FROM checkout_orders WHERE id=$orderId")->fetch();
$check((int)$receipt['amount_cents']===3000 && $receipt['status']==='paid','original checkout unchanged');
$payment=$pdo->query("SELECT amount_cents FROM payment_transactions WHERE provider_payment_id='BACEN-$reference'")->fetch();
$check((int)$payment['amount_cents']===3000,'provider record unchanged');

$summary=$finance->summary(30);
$check((int)$summary['managerial']['visible_paid_cents']>=5990,'corrected totals');
$check((int)$summary['managerial']['correction_delta_cents']>=2990,'correction delta');
$check(array_any($summary['latest'], static fn($x)=>$x['reference_id']===$reference && (int)$x['display_amount_cents']===5990),'latest shows correction');

$reject=static function(callable $fn,string $expected)use($check):void{
    try{$fn();throw new RuntimeException('UNEXPECTED PASS');}
    catch(RuntimeException $e){
        $check(!str_contains($e->getMessage(),'UNEXPECTED PASS'),'must reject '.$expected);
        $check(str_contains(mb_strtolower($e->getMessage()),mb_strtolower($expected)),
            'validation message '.$expected.': '.$e->getMessage());
    }
};
$reject(fn()=> $sales->updateValue($reference,'59,90','Mesmo valor não pode ser usado',$admin),'igual');
$reject(fn()=> $sales->updateValue($reference,'-23','Tentativa de valor inválido negativo',$admin),'inválido');
$reject(fn()=> $sales->updateValue($reference,'0','Tentativa de valor zero inválida',$admin),'entre');
$reject(fn()=> $sales->archive($reference,'curta',$admin),'10 a 500');

$sales->archive($reference,'Registro gerencial de teste para arquivar',$admin);
$archived=$sales->details($reference);
$check($archived['sale']['archived_at']!==null,'archive flag persisted');
$summary=$finance->summary(30);
$check(!array_any($summary['latest'],static fn($x)=>$x['reference_id']===$reference),'archived hidden from latest');
$check((int)$summary['archived_sales']>=1,'archived total');

$reject(fn()=> $sales->updateValue($reference,'120,00','Não permitir correção se arquivado',$admin),'Restaure');
$reject(fn()=> $sales->archive($reference,'Não arquivar uma segunda vez',$admin),'já está');

$sales->restore($reference,'Revisão do cliente encerrou o teste',$admin);
$check($sales->details($reference)['sale']['archived_at']===null,'restore sale');
$sales->resetValue($reference,'Restaurar valor original após revisão',$admin);
$final=$sales->details($reference);
$check($final['sale']['display_amount_cents']===null,'restore original managerial amount');
$check(count($final['history'])===4,'every change audited');
$check((int)$pdo->query("SELECT amount_cents FROM checkout_orders WHERE id=$orderId")->fetchColumn()===3000,'receipt remains immutable after restore');

$orderView=(new OrderAdjustmentService())->findForAdmin($reference);
ob_start();
try{
    $_SERVER['REQUEST_METHOD']='GET';
    $_SERVER['REQUEST_URI']='/admin/pagamentos/'.$reference.'/editar';
    View::render('admin/order-edit',[
        '_basePath'=>dirname(__DIR__),'title'=>'Venda',
        'orderEdit'=>$orderView,'saleInfo'=>$final,
        'currentUser'=>['name'=>'Sale Admin','role'=>'admin'],
        'csrf'=>'test-csrf','flashSuccess'=>null,'flashError'=>null,
    ]);
    $html=(string)ob_get_clean();
}catch(Throwable $e){ob_end_clean();throw $e;}
$check(str_contains($html,'Apagar venda (arquivar)'),'archive button visible');
$check(str_contains($html,'Salvar valor gerencial'),'value form visible');
$check(str_contains($html,'Histórico financeiro'),'history visible');
$check(str_contains($html,'R$ 30,00'),'original value visible');

echo "Sale management PASS: adjustable price, immutable receipt, archival/restore, financial reporting, auditable changes, validation and UI\n";
