<?php
declare(strict_types=1);

namespace CaptionIA\Core;

use CaptionIA\Services\ApiTokenService;
use CaptionIA\Services\AuthService;
use CaptionIA\Services\CheckoutService;
use CaptionIA\Services\LicenseService;
use CaptionIA\Services\PagBankService;
use CaptionIA\Services\PixBacenService;
use CaptionIA\Services\SettingsService;
use RuntimeException;
use Throwable;

final class App
{
    private AuthService $auth;
    private LicenseService $licenses;
    private PagBankService $pagBank;
    private CheckoutService $checkout;
    private ApiTokenService $tokens;
    private SettingsService $settings;
    private PixBacenService $pixBacen;

    public function __construct(private readonly string $basePath)
    {
        Env::load($basePath);
        date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Fortaleza') ?: 'America/Fortaleza');
        Session::start();

        $this->auth = new AuthService();
        $this->licenses = new LicenseService();
        $this->pagBank = new PagBankService($basePath);
        $this->settings = new SettingsService();
        $this->pixBacen = new PixBacenService();
        $this->checkout = new CheckoutService(
            $basePath,
            $this->pagBank,
            $this->licenses,
            $this->settings,
            $this->pixBacen
        );
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

        if (str_starts_with($path, '/api/')) {
            $this->sendApiCorsHeaders();
            if ($method === 'OPTIONS') {
                http_response_code(204);
                return;
            }
        }

        try {
            if ($path === '/health') {
                Json::send([
                    'ok' => true,
                    'app' => 'Caption IA Platform',
                    'time' => date(DATE_ATOM),
                    'pagbank_configured' => $this->pagBank->configured(),
                    'pagbank_checkout_mode' => $this->pagBank->checkoutMode(),
                    'payment_gateway' => $this->settings->paymentGateway(),
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

            if ($method === 'GET' && preg_match('#^/comprar/([a-z0-9][a-z0-9_-]{1,49})$#', $path, $m)) {
                $this->checkoutPage($m[1]);
                return;
            }
            if ($method === 'POST' && preg_match('#^/comprar/([a-z0-9][a-z0-9_-]{1,49})/(checkout|pix)$#', $path, $m)) {
                $this->createCheckout($m[1]);
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
            if ($method === 'POST' && $path === '/admin/planos/criar') {
                $this->adminCreatePlan();
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/planos/(\d+)/editar$#', $path, $m)) {
                $this->adminUpdatePlan((int) $m[1]);
                return;
            }
            if ($method === 'POST' && $path === '/admin/configuracoes/pagamentos') {
                $this->adminSavePaymentSettings();
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/pagamentos/([A-Z0-9-]+)/confirmar-pix$#', $path, $m)) {
                $this->adminConfirmBacenPix($m[1]);
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
            'paymentGateway' => $this->settings->paymentGateway(),
        ]);
    }

    private function createCheckout(string $slug): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $user = $this->auth->requireUser();
        $plan = $this->checkout->planByCode($this->planCodeFromSlug($slug) ?: '');
        if (!$plan) {
            throw new RuntimeException('Plano não encontrado.');
        }

        $order = $this->checkout->createForUser($user, $plan);

        if (
            ($order['provider_mode'] ?? '') === 'hosted_checkout'
            && !empty($order['hosted_checkout_url'])
        ) {
            $this->redirectExternal((string) $order['hosted_checkout_url']);
        }

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
            'plans' => $this->plans(),
        ]);
    }

    private function adminPage(): void
    {
        $admin = $this->auth->requireAdmin();
        $pdo = Database::connection();

        $stats = [
            'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'licenses' => (int) $pdo->query("SELECT COUNT(*) FROM licenses WHERE status='active' AND (expires_at IS NULL OR expires_at>NOW())")->fetchColumn(),
            'subscriptions' => (int) $pdo->query("SELECT COUNT(*) FROM licenses l JOIN plans p ON p.id=l.plan_id WHERE l.status='active' AND p.type='subscription' AND l.expires_at>NOW()")->fetchColumn(),
            'lifetime' => (int) $pdo->query("SELECT COUNT(*) FROM licenses l JOIN plans p ON p.id=l.plan_id WHERE l.status='active' AND p.type='lifetime'")->fetchColumn(),
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
            'SELECT co.reference_id,co.status,co.provider_mode,co.amount_cents,co.created_at,co.paid_at,
                    u.name,u.email,p.name AS plan_name
             FROM checkout_orders co
             JOIN users u ON u.id=co.user_id
             JOIN plans p ON p.id=co.plan_id
             ORDER BY co.created_at DESC LIMIT 50'
        )->fetchAll();

        $plans = $pdo->query(
            'SELECT * FROM plans ORDER BY active DESC,sort_order ASC,price_cents ASC,id ASC'
        )->fetchAll();

        $this->render('admin/dashboard', [
            'title' => 'Admin — Caption IA',
            'user' => $admin,
            'stats' => $stats,
            'users' => $users,
            'orders' => $orders,
            'plans' => $plans,
            'paymentSettings' => $this->settings->paymentSettings(),
            'pagbankConfigured' => $this->pagBank->configured(),
        ]);
    }

    private function adminSavePaymentSettings(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $this->auth->requireAdmin();

        $this->settings->savePaymentSettings(
            (string) ($_POST['gateway'] ?? ''),
            (string) ($_POST['pix_key'] ?? ''),
            (string) ($_POST['pix_name'] ?? ''),
            (string) ($_POST['pix_city'] ?? '')
        );

        Session::flash('success', 'Forma de pagamento atualizada.');
        $this->redirect('/admin');
    }

    private function adminConfirmBacenPix(string $reference): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $admin = $this->auth->requireAdmin();

        $this->checkout->confirmManualBacenPayment($reference, (int) $admin['id']);
        Session::flash('success', 'Pix confirmado e licença liberada.');
        $this->redirect('/admin');
    }

    private function adminCreatePlan(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $this->auth->requireAdmin();

        $data = $this->validatedPlanInput(true);
        $pdo = Database::connection();

        $exists = $pdo->prepare('SELECT id FROM plans WHERE code=? LIMIT 1');
        $exists->execute([$data['code']]);
        if ($exists->fetchColumn()) {
            throw new RuntimeException('Já existe um plano com esse código.');
        }

        $pdo->prepare(
            'INSERT INTO plans
             (code,name,description,type,price_cents,billing_interval,max_devices,includes_updates,featured,sort_order,active,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())'
        )->execute([
            $data['code'],
            $data['name'],
            $data['description'],
            $data['type'],
            $data['price_cents'],
            $data['billing_interval'],
            $data['max_devices'],
            $data['includes_updates'],
            $data['featured'],
            $data['sort_order'],
            $data['active'],
        ]);

        Session::flash('success', 'Plano criado com sucesso.');
        $this->redirect('/admin');
    }

    private function adminUpdatePlan(int $planId): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $this->auth->requireAdmin();

        $pdo = Database::connection();
        $exists = $pdo->prepare('SELECT id FROM plans WHERE id=? LIMIT 1');
        $exists->execute([$planId]);
        if (!$exists->fetchColumn()) {
            throw new RuntimeException('Plano não encontrado.');
        }

        $data = $this->validatedPlanInput(false);
        $pdo->prepare(
            'UPDATE plans
             SET name=?,description=?,type=?,price_cents=?,billing_interval=?,max_devices=?,
                 includes_updates=?,featured=?,sort_order=?,active=?
             WHERE id=?'
        )->execute([
            $data['name'],
            $data['description'],
            $data['type'],
            $data['price_cents'],
            $data['billing_interval'],
            $data['max_devices'],
            $data['includes_updates'],
            $data['featured'],
            $data['sort_order'],
            $data['active'],
            $planId,
        ]);

        Session::flash('success', 'Plano atualizado.');
        $this->redirect('/admin');
    }

    private function adminGrantLicense(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $this->auth->requireAdmin();

        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $plan = strtolower(trim((string) ($_POST['plan'] ?? '')));

        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,49}$/', $plan)) {
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

    private function validatedPlanInput(bool $requireCode): array
    {
        $code = strtolower(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $type = (string) ($_POST['type'] ?? 'subscription');
        $billingInterval = (string) ($_POST['billing_interval'] ?? 'month');
        $priceCents = $this->moneyToCents((string) ($_POST['price'] ?? '0'));
        $maxDevices = (int) ($_POST['max_devices'] ?? 2);
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $includesUpdates = isset($_POST['includes_updates']) ? 1 : 0;
        $featured = isset($_POST['featured']) ? 1 : 0;
        $active = isset($_POST['active']) ? 1 : 0;

        if ($requireCode && !preg_match('/^[a-z0-9][a-z0-9_-]{1,49}$/', $code)) {
            throw new RuntimeException('Use um código de plano com 2 a 50 caracteres: letras minúsculas, números, hífen ou underline.');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw new RuntimeException('O nome do plano deve ter entre 1 e 100 caracteres.');
        }
        if (mb_strlen($description) > 255) {
            throw new RuntimeException('A descrição do plano deve ter no máximo 255 caracteres.');
        }
        if (!in_array($type, ['subscription', 'lifetime'], true)) {
            throw new RuntimeException('Tipo de plano inválido.');
        }
        if ($type === 'lifetime') {
            $billingInterval = 'once';
        } elseif (!in_array($billingInterval, ['month', 'year'], true)) {
            throw new RuntimeException('Assinaturas devem ser mensais ou anuais.');
        }
        if ($priceCents < 100) {
            throw new RuntimeException('O plano deve custar pelo menos R$ 1,00.');
        }
        if ($maxDevices < 1 || $maxDevices > 20) {
            throw new RuntimeException('O limite de dispositivos deve ficar entre 1 e 20.');
        }
        if ($sortOrder < -999 || $sortOrder > 999) {
            throw new RuntimeException('A ordem do plano deve ficar entre -999 e 999.');
        }

        return [
            'code' => $code,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'type' => $type,
            'price_cents' => $priceCents,
            'billing_interval' => $billingInterval,
            'max_devices' => $maxDevices,
            'includes_updates' => $includesUpdates,
            'featured' => $featured,
            'sort_order' => $sortOrder,
            'active' => $active,
        ];
    }

    private function moneyToCents(string $value): int
    {
        $value = trim($value);
        $value = preg_replace('/[^0-9,.]/', '', $value) ?? '';

        if ($value === '') {
            return 0;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
        }
        $value = str_replace(',', '.', $value);

        if (!is_numeric($value)) {
            return 0;
        }

        return (int) round(((float) $value) * 100);
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
        if ($method === 'GET' && $path === '/api/v1/health') {
            Json::send([
                'ok' => true,
                'api' => 'Caption IA',
                'version' => 'v1',
                'server_time' => date(DATE_ATOM),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/config') {
            Json::send([
                'ok' => true,
                'api_version' => 'v1',
                'current_version' => Env::get('CAPTIONIA_CURRENT_VERSION', '3.2.3'),
                'current_major' => Env::int('CAPTIONIA_CURRENT_MAJOR', 3),
                'endpoints' => [
                    'login' => '/api/v1/auth/login',
                    'refresh' => '/api/v1/auth/refresh',
                    'logout' => '/api/v1/auth/logout',
                    'me' => '/api/v1/me',
                    'license' => '/api/v1/license/check',
                    'devices' => '/api/v1/devices',
                    'latest_release' => '/api/v1/releases/latest',
                    'plans' => '/api/v1/plans',
                ],
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/plans') {
            $plans = array_map(static fn(array $plan): array => [
                'code' => $plan['code'],
                'name' => $plan['name'],
                'description' => $plan['description'] ?? null,
                'type' => $plan['type'],
                'price_cents' => (int) $plan['price_cents'],
                'billing_interval' => $plan['billing_interval'],
                'max_devices' => (int) $plan['max_devices'],
                'includes_updates' => (bool) $plan['includes_updates'],
                'featured' => (bool) ($plan['featured'] ?? false),
            ], $this->plans());

            Json::send(['ok' => true, 'plans' => $plans]);
        }

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
                    mb_substr(trim((string) ($data['device_name'] ?? 'Computador')), 0, 160),
                    mb_substr(trim((string) ($data['os'] ?? '')), 0, 80)
                );
            }

            $tokens = $this->tokens->issue((int) $user['id'], $deviceUuid);

            Json::send([
                'ok' => true,
                'user' => $this->apiUserPayload($user),
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

        $context = $this->apiAccessContext();

        if ($method === 'GET' && $path === '/api/v1/me') {
            Json::send([
                'ok' => true,
                'user' => $this->apiUserPayload($context['user']),
                'license' => $this->licensePayload($context['license']),
                'device_uuid' => $context['token']['device'],
            ]);
        }

        if (($method === 'GET' || $method === 'POST') && $path === '/api/v1/license/check') {
            $input = $method === 'POST' ? Json::input() : $_GET;
            $version = (string) ($input['version'] ?? Env::get('CAPTIONIA_CURRENT_VERSION', '3.2.3'));
            $license = $context['license'];
            $allowed = $license ? $this->licenses->versionAllowed($license, $version) : false;

            if ($license) {
                Database::connection()->prepare(
                    'UPDATE license_devices SET last_seen_at=NOW()
                     WHERE license_id=? AND device_uuid=? AND revoked_at IS NULL'
                )->execute([$license['id'], $context['token']['device']]);
            }

            Json::send([
                'ok' => true,
                'allowed' => $allowed,
                'license' => $this->licensePayload($license),
                'version' => $version,
                'reason' => $allowed ? null : ($license ? 'version_not_allowed' : 'no_active_license'),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/devices') {
            $devices = [];
            if ($context['license']) {
                $stmt = Database::connection()->prepare(
                    'SELECT device_uuid,device_name,os,first_seen_at,last_seen_at,revoked_at
                     FROM license_devices
                     WHERE license_id=?
                     ORDER BY revoked_at IS NULL DESC,last_seen_at DESC'
                );
                $stmt->execute([$context['license']['id']]);
                $devices = $stmt->fetchAll();
            }

            Json::send([
                'ok' => true,
                'max_devices' => (int) ($context['license']['max_devices'] ?? 0),
                'devices' => $devices,
            ]);
        }

        if ($method === 'DELETE' && preg_match('#^/api/v1/devices/([^/]+)$#', $path, $m)) {
            if (!$context['license']) {
                throw new RuntimeException('Nenhuma licença ativa encontrada.');
            }

            $deviceUuid = rawurldecode($m[1]);
            if ($deviceUuid === (string) $context['token']['device']) {
                throw new RuntimeException('Não é possível remover o dispositivo da sessão atual.');
            }

            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'UPDATE license_devices
                 SET revoked_at=NOW()
                 WHERE license_id=? AND device_uuid=? AND revoked_at IS NULL'
            );
            $stmt->execute([$context['license']['id'], $deviceUuid]);

            $pdo->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at=NOW()
                 WHERE user_id=? AND device_uuid=? AND revoked_at IS NULL'
            )->execute([$context['user']['id'], $deviceUuid]);

            Json::send(['ok' => true, 'revoked' => $stmt->rowCount() > 0]);
        }

        if ($method === 'GET' && $path === '/api/v1/releases/latest') {
            $license = $context['license'];
            if (!$license) {
                Json::send([
                    'ok' => true,
                    'available' => false,
                    'reason' => 'no_active_license',
                ]);
            }

            $stmt = Database::connection()->query(
                'SELECT version,major_version,file_path,published_at
                 FROM releases
                 WHERE active=1
                 ORDER BY published_at DESC,id DESC
                 LIMIT 1'
            );
            $release = $stmt->fetch();

            if (!$release) {
                $release = [
                    'version' => Env::get('CAPTIONIA_CURRENT_VERSION', '3.2.3'),
                    'major_version' => Env::int('CAPTIONIA_CURRENT_MAJOR', 3),
                    'file_path' => null,
                    'published_at' => null,
                ];
            }

            $allowed = $this->licenses->versionAllowed($license, (string) $release['version']);

            Json::send([
                'ok' => true,
                'available' => $allowed,
                'release' => [
                    'version' => $release['version'],
                    'major_version' => (int) $release['major_version'],
                    'download_url' => $allowed && !empty($release['file_path'])
                        ? rtrim(Env::get('APP_URL', '') ?: '', '/') . '/downloads/' . rawurlencode((string) $release['version'])
                        : null,
                    'published_at' => $release['published_at'],
                ],
            ]);
        }

        Json::send(['ok' => false, 'error' => 'Endpoint não encontrado.', 'code' => 'not_found'], 404);
    }

    private function apiAccessContext(): array
    {
        $token = $this->tokens->verifyAccessToken($this->bearerToken());
        $stmt = Database::connection()->prepare(
            'SELECT id,name,email,role,status,created_at FROM users WHERE id=? LIMIT 1'
        );
        $stmt->execute([(int) $token['sub']]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'active') {
            throw new RuntimeException('Usuário indisponível.');
        }

        return [
            'token' => $token,
            'user' => $user,
            'license' => $this->licenses->activeLicenseForUser((int) $user['id']),
        ];
    }

    private function apiUserPayload(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => (string) ($user['role'] ?? 'customer'),
        ];
    }

    private function sendApiCorsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Client-Version');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 86400');
        header('Vary: Origin');
    }

    private function plans(): array
    {
        try {
            return Database::connection()
                ->query('SELECT * FROM plans WHERE active=1 ORDER BY featured DESC,sort_order ASC,price_cents ASC,id ASC')
                ->fetchAll();
        } catch (Throwable) {
            return [
                [
                    'id' => 0,
                    'code' => 'monthly',
                    'name' => 'Caption IA Mensal',
                    'description' => 'Acesso por assinatura mensal.',
                    'type' => 'subscription',
                    'price_cents' => 3000,
                    'billing_interval' => 'month',
                    'max_devices' => 2,
                    'includes_updates' => 1,
                    'featured' => 1,
                    'sort_order' => 10,
                    'active' => 1,
                ],
                [
                    'id' => 1,
                    'code' => 'lifetime',
                    'name' => 'Caption IA Vitalício',
                    'description' => 'Pagamento único para a geração adquirida.',
                    'type' => 'lifetime',
                    'price_cents' => 7000,
                    'billing_interval' => 'once',
                    'max_devices' => 2,
                    'includes_updates' => 0,
                    'featured' => 0,
                    'sort_order' => 20,
                    'active' => 1,
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
        $slug = strtolower(trim($slug));

        if ($slug === 'mensal') {
            return 'monthly';
        }
        if (in_array($slug, ['vitalicio', 'vitalício'], true)) {
            return 'lifetime';
        }

        return preg_match('/^[a-z0-9][a-z0-9_-]{1,49}$/', $slug) ? $slug : null;
    }

    private function slugFromPlanCode(string $code): string
    {
        return match ($code) {
            'monthly' => 'mensal',
            'lifetime' => 'vitalicio',
            default => $code,
        };
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

    private function redirectExternal(string $url): never
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Link de pagamento inválido.');
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = ($parts['scheme'] ?? '') === 'https'
            && (
                $host === 'pagbank.com.br'
                || str_ends_with($host, '.pagbank.com.br')
                || $host === 'pagseguro.com.br'
                || str_ends_with($host, '.pagseguro.com.br')
                || $host === 'pagseguro.uol.com.br'
                || str_ends_with($host, '.pagseguro.uol.com.br')
            );

        if (!$allowed) {
            throw new RuntimeException('O PagBank retornou um endereço de pagamento inesperado.');
        }

        header('Location: ' . $url, true, 303);
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
