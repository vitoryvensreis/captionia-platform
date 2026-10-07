<?php
declare(strict_types=1);

namespace CaptionIA\Core;

use CaptionIA\Services\ApiTokenService;
use CaptionIA\Services\AuthService;
use CaptionIA\Services\CheckoutService;
use CaptionIA\Services\LicenseService;
use CaptionIA\Services\PagBankService;
use RuntimeException;
use Throwable;

final class App
{
    private AuthService $auth;
    private LicenseService $licenses;
    private PagBankService $pagBank;
    private CheckoutService $checkout;
    private ApiTokenService $tokens;

    public function __construct(private readonly string $basePath)
    {
        Env::load($basePath);
        date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Fortaleza') ?: 'America/Fortaleza');
        Session::start();

        $this->auth = new AuthService();
        $this->licenses = new LicenseService();
        $this->pagBank = new PagBankService($basePath);
        $this->checkout = new CheckoutService($basePath, $this->pagBank, $this->licenses);
        $this->tokens = new ApiTokenService();
    }

    public function run(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }

        try {
            if ($path === '/health') {
                Json::send([
                    'ok' => true,
                    'app' => 'Caption IA Platform',
                    'time' => date(DATE_ATOM),
                    'pagbank_configured' => $this->pagBank->configured(),
                ]);
            }

            if ($method === 'POST' && $path === '/webhooks/pagbank') {
                $this->pagBankWebhook();
                return;
            }

            if (str_starts_with($path, '/api/')) {
                $this->api($method, $path);
                return;
            }

            if ($method === 'GET' && $path === '/') {
                $this->home();
                return;
            }
            if ($method === 'GET' && $path === '/entrar') {
                $this->loginPage();
                return;
            }
            if ($method === 'POST' && $path === '/entrar') {
                $this->loginSubmit();
                return;
            }
            if ($method === 'GET' && $path === '/criar-conta') {
                $this->registerPage();
                return;
            }
            if ($method === 'POST' && $path === '/criar-conta') {
                $this->registerSubmit();
                return;
            }
            if ($method === 'POST' && $path === '/sair') {
                Csrf::validate($_POST['_csrf'] ?? null);
                $this->auth->logout();
                $this->redirect('/');
            }

            if ($method === 'GET' && preg_match('#^/comprar/(mensal|vitalicio)$#', $path, $m)) {
                $this->checkoutPage($m[1]);
                return;
            }
            if ($method === 'POST' && preg_match('#^/comprar/(mensal|vitalicio)/pix$#', $path, $m)) {
                $this->createPix($m[1]);
                return;
            }
            if ($method === 'GET' && preg_match('#^/pagamento/([A-Z0-9-]+)$#', $path, $m)) {
                $this->paymentPage($m[1]);
                return;
            }
            if ($method === 'GET' && preg_match('#^/pagamento/([A-Z0-9-]+)/status$#', $path, $m)) {
                $this->paymentStatus($m[1]);
                return;
            }
            if ($method === 'GET' && preg_match('#^/pagamento/([A-Z0-9-]+)/qrcode$#', $path, $m)) {
                $this->paymentQr($m[1]);
                return;
            }

            if ($method === 'GET' && $path === '/minha-conta') {
                $this->accountPage();
                return;
            }

            if ($method === 'GET' && $path === '/admin') {
                $this->adminPage();
                return;
            }
            if ($method === 'POST' && $path === '/admin/licencas/criar') {
                $this->adminGrantLicense();
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/licencas/(\d+)/(revogar|reativar|resetar-dispositivos)$#', $path, $m)) {
                $this->adminLicenseAction((int) $m[1], $m[2]);
                return;
            }

            $this->render('404', ['title' => 'Página não encontrada'], 404);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'AUTH_REQUIRED') {
                $this->redirect('/entrar?next=' . rawurlencode($path));
            }
            if ($e->getMessage() === 'ADMIN_REQUIRED') {
                $this->render('message', [
                    'title' => 'Acesso negado',
                    'message' => 'Você não tem permissão para acessar esta área.',
                ], 403);
                return;
            }

            if (str_starts_with($path, '/api/')) {
                Json::send(['ok' => false, 'error' => $e->getMessage()], 400);
            }

            $this->render('message', [
                'title' => 'Não foi possível concluir',
                'message' => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            error_log('[Caption IA Platform] ' . $e);
            if (str_starts_with($path, '/api/')) {
                Json::send(['ok' => false, 'error' => 'Erro interno.'], 500);
            }

            $this->render('message', [
                'title' => 'Erro interno',
                'message' => Env::bool('APP_DEBUG', false) ? $e->getMessage() : 'Tente novamente em alguns instantes.',
            ], 500);
        }
    }

    private function home(): void
    {
        $this->render('home', [
            'title' => 'Caption IA — Edição inteligente dentro do Premiere',
            'plans' => $this->plans(),
            'user' => $this->auth->currentUser(),
        ]);
    }

    private function loginPage(): void
    {
        if ($this->auth->currentUser()) {
            $this->redirect('/minha-conta');
        }

        $this->render('auth/login', [
            'title' => 'Entrar — Caption IA',
            'next' => $this->safeNext($_GET['next'] ?? '/minha-conta'),
        ]);
    }

    private function loginSubmit(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $this->auth->login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        $this->redirect($this->safeNext($_POST['next'] ?? '/minha-conta'));
    }

    private function registerPage(): void
    {
        if ($this->auth->currentUser()) {
            $plan = $this->planCodeFromSlug((string) ($_GET['plano'] ?? ''));
            $this->redirect($plan ? '/comprar/' . $this->slugFromPlanCode($plan) : '/minha-conta');
        }

        $plan = $this->planCodeFromSlug((string) ($_GET['plano'] ?? ''));
        $this->render('auth/register', [
            'title' => 'Criar conta — Caption IA',
            'plan' => $plan,
        ]);
    }

    private function registerSubmit(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $this->auth->register(
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['cpf'] ?? ''),
            (string) ($_POST['phone'] ?? ''),
            (string) ($_POST['password'] ?? '')
        );

        $plan = $this->planCodeFromSlug((string) ($_POST['plan'] ?? ''));
        $this->redirect($plan ? '/comprar/' . $this->slugFromPlanCode($plan) : '/minha-conta');
    }

    private function checkoutPage(string $slug): void
    {
        $user = $this->auth->currentUser();
        if (!$user) {
            $this->redirect('/criar-conta?plano=' . rawurlencode($slug));
        }

        $plan = $this->checkout->planByCode($this->planCodeFromSlug($slug) ?: '');
        if (!$plan) {
            throw new RuntimeException('Plano não encontrado.');
        }

        $this->render('checkout', [
            'title' => 'Finalizar compra — ' . $plan['name'],
            'user' => $user,
            'plan' => $plan,
            'slug' => $slug,
            'pagbankConfigured' => $this->pagBank->configured(),
        ]);
    }

    private function createPix(string $slug): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $user = $this->auth->requireUser();
        $plan = $this->checkout->planByCode($this->planCodeFromSlug($slug) ?: '');
        if (!$plan) {
            throw new RuntimeException('Plano não encontrado.');
        }

        $order = $this->checkout->createPixForUser($user, $plan);
        $this->redirect('/pagamento/' . rawurlencode($order['reference_id']));
    }

    private function paymentPage(string $reference): void
    {
        $user = $this->auth->requireUser();
        $order = $this->checkout->sync($reference);
        $this->assertOwnOrder($order, $user);

        $this->render('payment', [
            'title' => 'Pagamento PIX — Caption IA',
            'order' => $order,
            'user' => $user,
        ]);
    }

    private function paymentStatus(string $reference): void
    {
        $user = $this->auth->requireUser();
        $order = $this->checkout->sync($reference);
        $this->assertOwnOrder($order, $user);

        Json::send([
            'ok' => true,
            'status' => $order['status'],
            'paid' => $order['status'] === 'paid',
            'redirect' => $order['status'] === 'paid' ? '/minha-conta' : null,
        ]);
    }

    private function paymentQr(string $reference): void
    {
        $user = $this->auth->requireUser();
        $order = $this->checkout->findByReference($reference);
        $this->assertOwnOrder($order, $user);

        $binary = $this->checkout->qrImage($order);
        header('Content-Type: ' . $binary['content_type']);
        header('Cache-Control: private, max-age=120');
        echo $binary['body'];
    }

    private function accountPage(): void
    {
        $user = $this->auth->requireUser();
        $license = $this->licenses->activeLicenseForUser((int) $user['id']);

        $devices = [];
        if ($license) {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM license_devices WHERE license_id=? ORDER BY revoked_at IS NULL DESC,last_seen_at DESC'
            );
            $stmt->execute([$license['id']]);
            $devices = $stmt->fetchAll();
        }

        $this->render('account', [
            'title' => 'Minha conta — Caption IA',
            'user' => $user,
            'license' => $license,
            'devices' => $devices,
            'orders' => $this->checkout->ordersForUser((int) $user['id']),
        ]);
    }

    private function adminPage(): void
    {
        $admin = $this->auth->requireAdmin();
        $pdo = Database::connection();

        $stats = [
            'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'licenses' => (int) $pdo->query("SELECT COUNT(*) FROM licenses WHERE status='active' AND (expires_at IS NULL OR expires_at>NOW())")->fetchColumn(),
            'monthly' => (int) $pdo->query("SELECT COUNT(*) FROM licenses l JOIN plans p ON p.id=l.plan_id WHERE l.status='active' AND p.code='monthly' AND l.expires_at>NOW()")->fetchColumn(),
            'lifetime' => (int) $pdo->query("SELECT COUNT(*) FROM licenses l JOIN plans p ON p.id=l.plan_id WHERE l.status='active' AND p.code='lifetime'")->fetchColumn(),
            'paid_cents' => (int) $pdo->query("SELECT COALESCE(SUM(amount_cents),0) FROM payment_transactions WHERE status='paid'")->fetchColumn(),
        ];

        $users = $pdo->query(
            'SELECT u.id,u.name,u.email,u.status,u.created_at,
                    l.id AS license_id,l.status AS license_status,l.expires_at,
                    p.code AS plan_code,p.name AS plan_name
             FROM users u
             LEFT JOIN licenses l ON l.id=(
                 SELECT l2.id FROM licenses l2
                 WHERE l2.user_id=u.id
                 ORDER BY l2.created_at DESC LIMIT 1
             )
             LEFT JOIN plans p ON p.id=l.plan_id
             ORDER BY u.created_at DESC
             LIMIT 100'
        )->fetchAll();

        $orders = $pdo->query(
            'SELECT co.reference_id,co.status,co.amount_cents,co.created_at,co.paid_at,
                    u.name,u.email,p.name AS plan_name
             FROM checkout_orders co
             JOIN users u ON u.id=co.user_id
             JOIN plans p ON p.id=co.plan_id
             ORDER BY co.created_at DESC LIMIT 50'
        )->fetchAll();

        $this->render('admin/dashboard', [
            'title' => 'Admin — Caption IA',
            'user' => $admin,
            'stats' => $stats,
            'users' => $users,
            'orders' => $orders,
        ]);
    }

    private function adminGrantLicense(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $this->auth->requireAdmin();

        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $plan = (string) ($_POST['plan'] ?? '');

        if (!in_array($plan, ['monthly', 'lifetime'], true)) {
            throw new RuntimeException('Plano inválido.');
        }

        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $userId = (int) $stmt->fetchColumn();
        if (!$userId) {
            throw new RuntimeException('Usuário não encontrado.');
        }

        $this->licenses->grantManual($userId, $plan);
        Session::flash('success', 'Licença criada com sucesso.');
        $this->redirect('/admin');
    }

    private function adminLicenseAction(int $licenseId, string $action): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $this->auth->requireAdmin();

        if ($action === 'resetar-dispositivos') {
            $this->licenses->resetDevices($licenseId);
            Session::flash('success', 'Dispositivos removidos da licença.');
        } elseif ($action === 'revogar') {
            $this->licenses->setStatus($licenseId, 'revoked');
            Session::flash('success', 'Licença revogada.');
        } elseif ($action === 'reativar') {
            $this->licenses->setStatus($licenseId, 'active');
            Session::flash('success', 'Licença reativada.');
        }

        $this->redirect('/admin');
    }

    private function pagBankWebhook(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $signature = (string) ($_SERVER['HTTP_X_PAYLOAD_SIGNATURE'] ?? '');

        try {
            $result = $this->checkout->processWebhook($raw, $signature);
            Json::send(['ok' => true] + $result);
        } catch (RuntimeException $e) {
            error_log('[PagBank webhook] ' . $e->getMessage());
            Json::send(['ok' => false, 'error' => 'Webhook rejeitado.'], 401);
        }
    }

    private function api(string $method, string $path): void
    {
        if ($method === 'POST' && $path === '/api/v1/auth/login') {
            $data = Json::input();
            $user = $this->auth->authenticateCredentials(
                (string) ($data['email'] ?? ''),
                (string) ($data['password'] ?? '')
            );
            $deviceUuid = trim((string) ($data['device_uuid'] ?? ''));
            if (strlen($deviceUuid) < 8 || strlen($deviceUuid) > 128) {
                throw new RuntimeException('device_uuid inválido.');
            }

            $license = $this->licenses->activeLicenseForUser((int) $user['id']);
            if ($license) {
                $license = $this->licenses->activateDevice(
                    (int) $user['id'],
                    $deviceUuid,
                    trim((string) ($data['device_name'] ?? 'Computador')),
                    trim((string) ($data['os'] ?? ''))
                );
            }

            $tokens = $this->tokens->issue((int) $user['id'], $deviceUuid);
            Json::send([
                'ok' => true,
                'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']],
                'license' => $this->licensePayload($license),
            ] + $tokens);
        }

        if ($method === 'POST' && $path === '/api/v1/auth/refresh') {
            $data = Json::input();
            $tokens = $this->tokens->refresh(
                (string) ($data['refresh_token'] ?? ''),
                (string) ($data['device_uuid'] ?? '')
            );
            Json::send(['ok' => true] + $tokens);
        }

        if ($method === 'POST' && $path === '/api/v1/auth/logout') {
            $data = Json::input();
            $this->tokens->revokeRefresh((string) ($data['refresh_token'] ?? ''));
            Json::send(['ok' => true]);
        }

        if (($method === 'GET' || $method === 'POST') && $path === '/api/v1/license/check') {
            $payload = $this->tokens->verifyAccessToken($this->bearerToken());
            $license = $this->licenses->activeLicenseForUser((int) $payload['sub']);
            $version = (string) ($_GET['version'] ?? (Json::input()['version'] ?? Env::get('CAPTIONIA_CURRENT_VERSION', '3.2.3')));
            $allowed = $license ? $this->licenses->versionAllowed($license, $version) : false;

            if ($license) {
                Database::connection()->prepare(
                    'UPDATE license_devices SET last_seen_at=NOW()
                     WHERE license_id=? AND device_uuid=? AND revoked_at IS NULL'
                )->execute([$license['id'], $payload['device']]);
            }

            Json::send([
                'ok' => true,
                'allowed' => $allowed,
                'license' => $this->licensePayload($license),
                'version' => $version,
            ]);
        }

        Json::send(['ok' => false, 'error' => 'Endpoint não encontrado.'], 404);
    }

    private function plans(): array
    {
        try {
            return Database::connection()
                ->query('SELECT * FROM plans WHERE active=1 ORDER BY price_cents ASC')
                ->fetchAll();
        } catch (Throwable) {
            return [
                [
                    'code' => 'monthly',
                    'name' => 'Caption IA Mensal',
                    'type' => 'subscription',
                    'price_cents' => 3000,
                    'includes_updates' => 1,
                ],
                [
                    'code' => 'lifetime',
                    'name' => 'Caption IA Vitalício',
                    'type' => 'lifetime',
                    'price_cents' => 7000,
                    'includes_updates' => 0,
                ],
            ];
        }
    }

    private function render(string $view, array $data = [], int $status = 200): void
    {
        View::render($view, $data + [
            '_basePath' => $this->basePath,
            'currentUser' => $this->auth->currentUser(),
            'csrf' => Csrf::token(),
            'flashSuccess' => Session::pull('success'),
            'flashError' => Session::pull('error'),
        ], $status);
    }

    private function assertOwnOrder(?array $order, array $user): void
    {
        if (!$order) {
            throw new RuntimeException('Pagamento não encontrado.');
        }
        if ((int) $order['user_id'] !== (int) $user['id'] && ($user['role'] ?? '') !== 'admin') {
            throw new RuntimeException('Pagamento não encontrado.');
        }
    }

    private function planCodeFromSlug(string $slug): ?string
    {
        return match (strtolower(trim($slug))) {
            'mensal', 'monthly' => 'monthly',
            'vitalicio', 'vitalício', 'lifetime' => 'lifetime',
            default => null,
        };
    }

    private function slugFromPlanCode(string $code): string
    {
        return $code === 'lifetime' ? 'vitalicio' : 'mensal';
    }

    private function safeNext(mixed $next): string
    {
        $next = (string) $next;
        return str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/minha-conta';
    }

    private function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    private function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', (string) $header, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function licensePayload(?array $license): ?array
    {
        if (!$license) {
            return null;
        }

        return [
            'id' => (int) $license['id'],
            'status' => $license['status'],
            'plan' => $license['plan_code'],
            'plan_name' => $license['plan_name'],
            'expires_at' => $license['expires_at'],
            'max_devices' => (int) $license['max_devices'],
            'includes_updates' => (bool) $license['includes_updates'],
            'purchased_version' => $license['purchased_version'],
            'max_major_version' => $license['max_major_version'] !== null ? (int) $license['max_major_version'] : null,
        ];
    }
}
