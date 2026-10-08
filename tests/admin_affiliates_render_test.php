<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CaptionIA\Core\View;

// Explicitly reproduce the admin context without a live database or session.
// The previous bug passed the view payload as "data", which collided with
// View::render()'s local $data argument and broke midway through rendering.
$root = dirname(__DIR__);
$fixtures = [
    'config' => [
        'enabled' => false,
        'rate_percent' => '15.00',
        'minimum_cents' => 5000,
        'hold_days' => 14,
        'auto_enable_buyers' => true,
    ],
    'summary' => [
        'total_orders' => 7,
        'earned_cents' => 12345,
        'matured_cents' => 6789,
    ],
    'withdrawals' => [
        [
            'id' => 1,
            'name' => 'Cliente Exemplo',
            'email' => 'pessoa@example.test',
            'amount_cents' => 5000,
            'pix_key' => 'redacted-test-only',
            'status' => 'requested',
            'requested_at' => date('Y-m-d H:i:s'),
            'admin_note' => null,
        ],
    ],
    'users' => [
        ['id' => 1, 'name' => 'Cliente Exemplo', 'email' => 'pessoa@example.test',
         'paid_orders' => 1, 'code' => 'A0B1C2D3E4F50607',
         'affiliate_id' => 1, 'enabled' => 1],
    ],
];

ob_start();
try {
    View::render('admin/affiliates', [
        '_basePath' => $root,
        'title' => 'Afiliados — Caption IA',
        'affiliateReport' => $fixtures,
        'csrf' => 'csrf-for-smoke',
        'currentUser' => ['name' => 'Administrador', 'role' => 'admin'],
        'flashSuccess' => null,
        'flashError' => null,
    ]);
    $html = (string) ob_get_clean();
} catch (Throwable $e) {
    ob_end_clean();
    throw $e;
}

$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(str_contains($html, 'Gestão de afiliados'), 'Admin title missing');
$assert(str_contains($html, 'R$ 123,45'), 'Commission amount lost');
$assert(str_contains($html, 'R$ 67,89'), 'Matured amount lost');
$assert(preg_match('/Saques aguardando<\/small><b>1<\/b>/u', $html) === 1, 'Pending withdrawal count incorrect');
$assert(str_contains($html, 'Cliente Exemplo'), 'Customer table not rendered');
$assert(str_contains($html, 'A0B1C2D3E4F50607'), 'Affiliate code missing');
$assert(str_contains($html, '/assets/app.css?v=7'), 'CSS cache-bust missing');
$assert(!str_contains($html, 'Erro interno'), 'Nested 500 page in admin dashboard');
$assert(substr_count($html, '<html') === 1, 'HTML document rendered more than once');
$assert(str_contains($html, '</html>'), 'Incomplete HTML document');
echo "Affiliate admin rendering PASS: stats, withdrawals, customer list and CSS version.\n";
