<?php
declare(strict_types=1);

namespace CaptionIA\Core;

use CaptionIA\Services\AffiliateService;
use CaptionIA\Services\AsaasService;
use CaptionIA\Services\AsaasCheckoutService;
use CaptionIA\Services\FinanceService;
use CaptionIA\Services\ApiTokenService;
use CaptionIA\Services\AuthService;
use CaptionIA\Services\CheckoutService;
use CaptionIA\Services\LicenseService;
use CaptionIA\Services\OrderAdjustmentService;
use CaptionIA\Services\SaleManagementService;
use CaptionIA\Services\PagBankService;
use CaptionIA\Services\PixBacenService;
use CaptionIA\Services\ReleaseService;
use CaptionIA\Services\SettingsService;
use RuntimeException;
use Throwable;

final class App
{
    private AuthService $auth;
    private LicenseService $licenses;
    private PagBankService $pagBank;
    private AsaasService $asaas;
    private AsaasCheckoutService $asaasCheckout;
    private CheckoutService $checkout;
    private ApiTokenService $tokens;
    private SettingsService $settings;
    private PixBacenService $pixBacen;
    private ReleaseService $releases;
    private AffiliateService $affiliates;
    private FinanceService $finance;
    private OrderAdjustmentService $orderAdjustments;
    private SaleManagementService $saleManagement;

    public function __construct(private readonly string $basePath)
    {
        Env::load($basePath);
        date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Fortaleza') ?: 'America/Fortaleza');
        Session::start();

        $this->auth = new AuthService();
        $this->licenses = new LicenseService();
        $this->pagBank = new PagBankService($basePath);
        $this->asaas = new AsaasService();
        $this->asaasCheckout = new AsaasCheckoutService($this->asaas, $this->licenses);
        $this->settings = new SettingsService();
        $this->affiliates = new AffiliateService($this->settings);
        $this->finance = new FinanceService($this->settings);
        $this->orderAdjustments = new OrderAdjustmentService();
        $this->saleManagement = new SaleManagementService();
        $this->pixBacen = new PixBacenService();
        $this->releases = new ReleaseService($basePath);
        $this->checkout = new CheckoutService(
            $basePath,
            $this->pagBank,
            $this->licenses,
            $this->settings,
            $this->pixBacen,
            $this->asaasCheckout
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
                    'asaas_configured' => $this->asaas->configured(),
                    'pagbank_checkout_mode' => $this->pagBank->checkoutMode(),
                    'payment_gateway' => $this->settings->paymentGateway(),
                ]);
            }

            if ($method === 'POST' && $path === '/webhooks/asaas') {
                $this->asaasWebhook();
                return;
            }
            if ($method === 'POST' && $path === '/webhooks/pagbank') {
                $this->pagBankWebhook();
                return;
            }

            if (str_starts_with($path, '/api/')) {
                $this->api($method, $path);
                return;
            }

            if ($method === 'GET' && preg_match('#^/r/([A-F0-9]{16})$#D', $path, $m)) {
                $this->affiliates->registerVisit($m[1]);
                header('Cache-Control: no-store, private');
                $this->redirect('/');
                return;
            }
            if ($method === 'POST' && $path === '/metricas/evento') {
                $this->recordMetric();
                return;
            }
            if ($method === 'GET' && $path === '/privacidade') {
                $this->render('privacy', ['title' => 'Privacidade — Caption IA']);
                return;
            }
            if ($method === 'GET' && $path === '/afiliados') {
                $this->affiliatePage();
                return;
            }
            if ($method === 'POST' && $path === '/afiliados/saques') {
                $this->requestAffiliatePayout();
                return;
            }
            if ($method === 'GET' && $path === '/admin/financeiro') {
                $this->financePage();
                return;
            }
            if ($method === 'GET' && $path === '/admin/financeiro/exportar') {
                $this->exportFinanceCsv();
                return;
            }
            if ($method === 'GET' && $path === '/admin/afiliados') {
                $this->adminAffiliatePage();
                return;
            }
            if ($method === 'POST' && $path === '/admin/afiliados/configurar') {
                $this->adminAffiliateSettings();
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/afiliados/usuario/(\d+)/(ativar|desativar)$#D', $path, $m)) {
                $this->adminAffiliateUser((int)$m[1], $m[2]);
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/afiliados/saques/(\d+)/(pago|recusar)$#D', $path, $m)) {
                $this->adminAffiliateWithdrawal((int)$m[1], $m[2]);
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

            if ($method === 'GET' && $path === '/tutoriais') {
                $this->render('tutorials', ['title' => 'Tutoriais — Caption IA']);
                return;
            }
            if ($method === 'POST' && $path === '/minha-conta/groq') {
                $this->saveAccountGroq();
                return;
            }
            if ($method === 'GET' && $path === '/minha-conta') {
                $this->accountPage();
                return;
            }
            if ($method === 'GET' && preg_match('#^/minha-conta/baixar/([0-9A-Za-z.+-]+)$#D', $path, $match)) {
                $this->downloadCustomerRelease($match[1]);
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
            if ($method === 'POST' && $path === '/admin/releases/publicar') {
                $this->adminPublishRelease();
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/releases/(\d+)/(ativar|desativar)$#', $path, $m)) {
                $this->adminReleaseAction((int) $m[1], $m[2]);
                return;
            }
            if ($method === 'POST' && $path === '/admin/configuracoes/pagamentos') {
                $this->adminSavePaymentSettings();
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/pagamentos/([A-Z0-9-]+)/(ajustar-valor|restaurar-valor|arquivar|restaurar-venda)$#D', $path, $m)) {
                $this->adminSaleManagementAction($m[1], $m[2]);
                return;
            }
            if ($method === 'GET' && preg_match('#^/admin/pagamentos/([A-Z0-9-]+)/editar$#D', $path, $m)) {
                $this->adminEditPaidOrder($m[1]);
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/pagamentos/([A-Z0-9-]+)/ajustar-acesso$#D', $path, $m)) {
                $this->adminSaveOrderAccess($m[1]);
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
            'asaasConfigured' => $this->asaas->configured(),
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
        if ($this->affiliates->enabled()) {
            $this->affiliates->attributeNewOrder($order, (int)$user['id'], $_COOKIE['captionia_ref'] ?? null);
            $this->affiliates->creditPaidOrder($order);
        }

        if (
            in_array(($order['provider_mode'] ?? ''), ['hosted_checkout', 'asaas_payment', 'asaas_subscription'], true)
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
        if ($order && $order['status'] === 'paid') $this->affiliates->creditPaidOrder($order);

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
        if ($order && $order['status'] === 'paid') $this->affiliates->creditPaidOrder($order);

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

    /** Web form: secret is only accepted through POST+CSRF and never returned to the browser. */
    private function saveAccountGroq(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $user = $this->auth->requireUser();
        $service = new \CaptionIA\Services\UserGroqService();
        try {
            $action = (string)($_POST['action'] ?? 'save');
            if ($action === 'remove') {
                $service->remove((int)$user['id']);
                Session::flash('success', 'Chave Groq removida da sua conta.');
            } else {
                $service->save((int)$user['id'], (string)($_POST['groq_key'] ?? ''));
                Session::flash('success', 'Chave Groq validada e conectada à sua conta.');
            }
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect('/minha-conta#integracao-groq');
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

        $release = $this->releases->latestActive();

        $this->render('account', [
            'title' => 'Minha conta — Caption IA',
            'user' => $user,
            'license' => $license,
            'release' => $release,
            'release_allowed' => $license && $release
                ? $this->licenses->versionAllowed($license, (string)$release['version'])
                : false,
            'devices' => $devices,
            'orders' => $this->checkout->ordersForUser((int) $user['id']),
            'plans' => $this->plans(),
            'affiliateVisible' => $this->affiliates->enabled() && $license !== null,
            'groq' => (new \CaptionIA\Services\UserGroqService())->status((int)$user['id']),
        ]);
    }

    /**
     * O link de download do painel web é protegido pela sessão e pela licença,
     * sem expor token Bearer em URL ou HTML. O ZIP fica fora de public/.
     */
    private function downloadCustomerRelease(string $version): void
    {
        $user = $this->auth->requireUser();
        $license = $this->licenses->activeLicenseForUser((int)$user['id']);
        $release = $this->releases->findActiveByVersion($version);

        if (!$release) {
            Json::send(['ok'=>false,'error'=>'Versão não disponível.'], 404);
        }
        if (!$license || !$this->licenses->versionAllowed($license, (string)$release['version'])) {
            Json::send(['ok'=>false,'error'=>'Licença sem acesso a esta versão.'], 403);
        }

        $file = $this->releases->absolutePath($release);
        header('Content-Type: application/zip');
        header('Content-Length: ' . (string)filesize($file));
        header('Content-Disposition: attachment; filename="CaptionIA-' . $release['version'] . '.zip"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
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
            'SELECT co.reference_id,co.status,co.license_applied,co.provider_mode,co.amount_cents,co.created_at,co.paid_at,
                    ov.display_amount_cents,ov.archived_at,
                    u.name,u.email,p.name AS plan_name,ap.name AS adjusted_access_plan
             FROM checkout_orders co
             LEFT JOIN order_admin_overrides ov ON ov.order_id=co.id
             JOIN users u ON u.id=co.user_id
             JOIN plans p ON p.id=co.plan_id
             LEFT JOIN license_access_adjustments adj ON adj.id=(
                 SELECT MAX(a.id) FROM license_access_adjustments a WHERE a.order_id=co.id
             )
             LEFT JOIN plans ap ON ap.id=adj.new_plan_id
             WHERE ov.archived_at IS NULL
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
            'releases' => $this->releases->listAll(),
            'paymentSettings' => $this->settings->paymentSettings(),
            'pagbankConfigured' => $this->pagBank->configured(),
            'asaasConfigured' => $this->asaas->configured(),
        ]);
    }

    private function adminEditPaidOrder(string $reference): void
    {
        $this->auth->requireAdmin();
        $orderEdit = $this->orderAdjustments->findForAdmin($reference);
        if (!$orderEdit) throw new RuntimeException('Compra não encontrada.');
        $saleInfo = $this->saleManagement->details($reference);

        $this->render('admin/order-edit', [
            'title' => 'Ajustar acesso do cliente — Caption IA',
            'orderEdit' => $orderEdit,
            'saleInfo' => $saleInfo,
        ]);
    }

    private function adminSaleManagementAction(string $reference,string $action): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $admin=$this->auth->requireAdmin();
        $reason=(string)($_POST['reason']??'');
        switch($action){
            case 'ajustar-valor':
                $this->saleManagement->updateValue($reference,(string)($_POST['display_amount']??''),$reason,(int)$admin['id']);
                $message='Valor gerencial atualizado. A cobrança efetiva permanece inalterada.';
                break;
            case 'restaurar-valor':
                $this->saleManagement->resetValue($reference,$reason,(int)$admin['id']);
                $message='Valor gerencial revertido ao valor original.';
                break;
            case 'arquivar':
                $this->saleManagement->archive($reference,$reason,(int)$admin['id']);
                $message='Venda arquivada na gestão. Registros financeiros e direitos do cliente preservados.';
                break;
            case 'restaurar-venda':
                $this->saleManagement->restore($reference,$reason,(int)$admin['id']);
                $message='Venda restaurada nas listagens gerenciais.';
                break;
            default:throw new RuntimeException('Ação inválida.');
        }
        Session::flash('success',$message);
        $this->redirect('/admin/pagamentos/'.rawurlencode($reference).'/editar');
    }

    private function adminSaveOrderAccess(string $reference): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $admin = $this->auth->requireAdmin();
        $this->orderAdjustments->changeAccess(
            $reference,
            (int)($_POST['license_id'] ?? 0),
            (int)($_POST['new_plan_id'] ?? 0),
            isset($_POST['expiry_date']) ? (string)$_POST['expiry_date'] : null,
            (string)($_POST['reason'] ?? ''),
            (int)$admin['id'],
            (int)($_POST['expected_plan_id'] ?? 0),
            isset($_POST['expected_expiry']) && $_POST['expected_expiry'] !== ''
                ? (string)$_POST['expected_expiry'] : null
        );
        Session::flash('success','Acesso da licença atualizado. O recibo da compra, o valor recebido e o histórico de pagamentos foram preservados.');
        $this->redirect('/admin/pagamentos/' . rawurlencode($reference) . '/editar');
    }

    private function adminSavePaymentSettings(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $this->auth->requireAdmin();

        if (($_POST['gateway'] ?? '') === 'asaas' && !$this->asaas->configured()) {
            throw new RuntimeException('Configure ASAAS_API_KEY e ASAAS_WEBHOOK_TOKEN no .env antes de ativar.');
        }
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

        $paidOrder = $this->checkout->confirmManualBacenPayment($reference, (int) $admin['id']);
        $this->affiliates->creditPaidOrder($paidOrder);
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

    private function adminPublishRelease(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $admin = $this->auth->requireAdmin();

        $release = $this->releases->publishUploaded(
            $_FILES['release_zip'] ?? [],
            (string) ($_POST['version'] ?? ''),
            (string) ($_POST['notes'] ?? '')
        );

        Database::connection()->prepare(
            'INSERT INTO audit_logs (user_id,event_type,metadata_json,created_at)
             VALUES (?,\'release.published\',?,NOW())'
        )->execute([
            $admin['id'],
            json_encode([
                'version' => $release['version'],
                'sha256' => $release['sha256'],
                'file_size' => (int) $release['file_size'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        Session::flash('success', 'Atualização ' . $release['version'] . ' publicada para o atualizador automático.');
        $this->redirect('/admin');
    }

    private function adminReleaseAction(int $releaseId, string $action): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $admin = $this->auth->requireAdmin();

        $active = $action === 'ativar';
        $this->releases->setActive($releaseId, $active);

        Database::connection()->prepare(
            'INSERT INTO audit_logs (user_id,event_type,metadata_json,created_at)
             VALUES (?,\'release.status_changed\',?,NOW())'
        )->execute([
            $admin['id'],
            json_encode([
                'release_id' => $releaseId,
                'active' => $active,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        Session::flash('success', $active ? 'Release ativada.' : 'Release desativada.');
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


    private function recordMetric(): void
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $expected = 'https://caption.escotilhaprodutora.com';
        if ($origin !== $expected) {
            Json::send(['ok' => false, 'error' => 'Origem não autorizada.'], 403);
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1500) {
            Json::send(['ok' => false, 'error' => 'Evento muito grande.'], 413);
        }
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            Json::send(['ok' => false, 'error' => 'Evento inválido.'], 400);
        }
        $user = $this->auth->currentUser();
        $this->finance->track($payload, $user ? (int)$user['id'] : null);
        Json::send(['ok' => true]);
    }

    private function affiliatePage(): void
    {
        $user = $this->auth->requireUser();
        $info = $this->affiliates->myDashboard((int)$user['id']);
        $this->render('affiliate/dashboard', [
            'title' => 'Meu programa de afiliados — Caption IA',
            'user' => $user,
            'affiliate' => $info,
            'config' => $this->affiliates->config(),
        ]);
    }

    private function requestAffiliatePayout(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $user = $this->auth->requireUser();
        $amount = AffiliateService::priceCents((string)($_POST['amount'] ?? ''));
        $this->affiliates->requestWithdrawal(
            (int)$user['id'], $amount, (string)($_POST['pix_key'] ?? '')
        );
        Session::flash('success','Saque solicitado. O valor ficou reservado até a conferência do administrador.');
        $this->redirect('/afiliados');
    }

    private function adminAffiliatePage(): void
    {
        $this->auth->requireAdmin();
        $this->affiliates->reconcilePaidOrders();
        $this->render('admin/affiliates', [
            'title' => 'Afiliados e saques — Admin Caption IA',
            'affiliateReport' => $this->affiliates->adminDashboard(),
        ]);
    }

    private function adminAffiliateSettings(): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $this->auth->requireAdmin();
        $this->affiliates->saveConfig([
            'enabled' => isset($_POST['enabled']),
            'percent' => $_POST['percent'] ?? '15',
            'minimum' => $_POST['minimum'] ?? '50',
            'hold_days' => $_POST['hold_days'] ?? '14',
            'auto_enable_buyers' => isset($_POST['auto_enable_buyers']),
        ]);
        Session::flash('success','Configurações do programa de afiliados atualizadas.');
        $this->redirect('/admin/afiliados');
    }

    private function adminAffiliateUser(int $userId, string $action): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $this->auth->requireAdmin();
        $this->affiliates->adminSetEnabled($userId, $action === 'ativar');
        Session::flash('success','Acesso do afiliado atualizado.');
        $this->redirect('/admin/afiliados');
    }

    private function adminAffiliateWithdrawal(int $withdrawalId, string $action): void
    {
        Csrf::validate($_POST['_csrf'] ?? null);
        $admin=$this->auth->requireAdmin();
        $this->affiliates->decideWithdrawal(
            $withdrawalId, (int)$admin['id'], $action === 'pago' ? 'paid' : 'rejected',
            (string)($_POST['note'] ?? '')
        );
        Session::flash('success', $action === 'pago' ? 'Transferência registrada como paga.' : 'Solicitação recusada; valor devolvido ao saldo disponível.');
        $this->redirect('/admin/afiliados');
    }

    private function financePage(): void
    {
        $this->auth->requireAdmin();
        $days=(int)($_GET['dias']??30);
        $this->affiliates->reconcilePaidOrders(150);
        $this->render('admin/finance', [
            'title'=>'Financeiro e funil de vendas — Caption IA',
            'report'=>$this->finance->summary($days),
        ]);
    }

    private function exportFinanceCsv(): void
    {
        $this->auth->requireAdmin();
        $pdo=Database::connection();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="captionia-vendas.csv"');
        header('Cache-Control: private, no-store');
        $stream=fopen('php://output','wb');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream,['Pedido','Cliente','E-mail','Plano','Valor original (centavos)','Valor gerencial (centavos)','Arquivada','Status','Criado em','Pago em'],';');
        $stmt=$pdo->query(
            'SELECT co.reference_id,u.name,u.email,p.name AS plan_name,
             co.amount_cents,ov.display_amount_cents,ov.archived_at,
             co.status,co.created_at,co.paid_at
             FROM checkout_orders co JOIN users u ON u.id=co.user_id
             JOIN plans p ON p.id=co.plan_id
             LEFT JOIN order_admin_overrides ov ON ov.order_id=co.id
             ORDER BY co.id DESC LIMIT 3000'
        );
        while ($r=$stmt->fetch()) {
            $safe=static function(string $value): string {
                return preg_match('/^[=+\-@\t\r]/u', $value) ? "'" . $value : $value;
            };
            fputcsv($stream,[
                $safe((string)$r['reference_id']),$safe((string)$r['name']),
                $safe((string)$r['email']),$safe((string)$r['plan_name']),
                $r['amount_cents'],$r['display_amount_cents']??$r['amount_cents'],
                $r['archived_at']?'SIM':'NÃO',$r['status'],$r['created_at'],$r['paid_at']??''
            ],';');
        }
        fclose($stream);
    }

    private function asaasWebhook(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $token = (string) ($_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '');
        if (!$this->asaas->verifyWebhook($token)) {
            Json::send(['ok' => false, 'error' => 'Token Asaas inválido.'], 401);
            return;
        }
        try {
            $result = $this->asaasCheckout->processWebhook($raw, $token);
            if (($result['order']['status'] ?? '') === 'paid') {
                try {
                    $this->affiliates->creditPaidOrder($result['order']);
                } catch (Throwable $error) {
                    error_log('[CaptionIA Asaas affiliates] reconcile: ' . $error->getMessage());
                }
            }
            Json::send(['ok' => true, 'duplicate' => $result['duplicate'] ?? false]);
        } catch (Throwable $e) {
            error_log('[CaptionIA Asaas webhook] ' . $e->getMessage());
            Json::send(['ok' => false, 'error' => 'Processamento pendente.'], 503);
        }
    }

    private function pagBankWebhook(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $signature = (string) ($_SERVER['HTTP_X_PAYLOAD_SIGNATURE'] ?? '');

        try {
            $result = $this->checkout->processWebhook($raw, $signature);
            if (($result['order']['status'] ?? null) === 'paid') {
                try {
                    $this->affiliates->creditPaidOrder($result['order']);
                } catch (Throwable $error) {
                    // Payment validation must not be rolled back by analytics/commission storage.
                    // Admin finance screen reconciles unapplied credits safely and idempotently.
                    error_log('[Caption IA affiliates] reconciliation required: ' . $error->getMessage());
                }
            }
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
            $currentRelease = $this->releases->currentVersionInfo();
            Json::send([
                'ok' => true,
                'api_version' => 'v1',
                'current_version' => $currentRelease['version'],
                'current_major' => $currentRelease['major'],
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

        $groqForUser = new \CaptionIA\Services\UserGroqService();
        if ($method === 'GET' && $path === '/api/v1/groq/status') {
            $this->assertGroqAccess($context, true, 'status');
            $userId = (int)$context['user']['id'];
            $state = $groqForUser->status($userId);
            if ($state['connected']) {
                try {
                    // A stored key can be revoked later; revalidate it before unlocking the plugin.
                    $groqForUser->validate($groqForUser->keyForUser($userId));
                } catch (\RuntimeException $e) {
                    $denied = str_contains($e->getMessage(), 'recusou') || str_contains($e->getMessage(), 'acesso');
                    Json::send(['ok'=>false,'connected'=>false,'error'=>$e->getMessage()], $denied ? 403 : 503);
                }
            }
            Json::send(['ok'=>true] + $state);
        }

        // Keep old endpoint aliases for already-installed clients, but never use the shared server Groq key.
        if ($method === 'POST' && in_array($path, ['/api/v1/groq/narrative/analyze', '/api/v1/core/narrative/analyze'], true)) {
            $this->assertGroqAccess($context, true, 'narrative');
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 120000) Json::send(['ok'=>false,'error'=>'Análise muito grande.'], 413);
            try {
                $key = $groqForUser->keyForUser((int)$context['user']['id']);
                Json::send(['ok'=>true] + (new \CaptionIA\Services\CloudNarrativeService($key))->analyze(Json::input()));
            } catch (\InvalidArgumentException $e) {
                Json::send(['ok'=>false,'error'=>$e->getMessage()],422);
            } catch (\CaptionIA\Services\GroqGatewayException $e) {
                if ($e->retryAfter>0) header('Retry-After: '.$e->retryAfter);
                Json::send(['ok'=>false,'error'=>$e->getMessage(),'retry_after_seconds'=>$e->retryAfter],$e->httpStatus);
            } catch (\RuntimeException $e) {
                Json::send(['ok'=>false,'error'=>$e->getMessage()],503);
            }
        }

        if ($method === 'POST' && in_array($path, ['/api/v1/groq/transcription', '/api/v1/core/transcription'], true)) {
            $this->assertGroqAccess($context, true, 'transcribe');
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 25000000) Json::send(['ok'=>false,'error'=>'Áudio muito grande.'],413);
            try {
                $key = $groqForUser->keyForUser((int)$context['user']['id']);
                Json::send(['ok'=>true] + (new \CaptionIA\Services\CloudTranscriptionService($key))->transcribe($_FILES['file'] ?? [], trim((string)($_POST['language'] ?? ''))));
            } catch (\InvalidArgumentException $e) {
                Json::send(['ok'=>false,'error'=>$e->getMessage()],422);
            } catch (\CaptionIA\Services\GroqGatewayException $e) {
                if ($e->retryAfter>0) header('Retry-After: '.$e->retryAfter);
                Json::send(['ok'=>false,'error'=>$e->getMessage(),'retry_after_seconds'=>$e->retryAfter],$e->httpStatus);
            } catch (\RuntimeException $e) {
                Json::send(['ok'=>false,'error'=>$e->getMessage()],503);
            }
        }

        // Authenticated cloud capabilities: opt-in rollout, no legacy timeline changes.
        if ($method === 'GET' && $path === '/api/v1/core/config') {
            $enabled = Env::bool('CAPTIONIA_CLOUD_CORE_ENABLED', false);
            Json::send([
                'ok' => true,
                'cloud_core_enabled' => $enabled,
                'api_schema_version' => 2,
                'features' => [
                    'caption_plan' => $enabled,
                    'edit_plan' => $enabled,
                    'assets' => $enabled && Env::bool('CAPTIONIA_CLOUD_ASSETS_ENABLED', false),
                    'cloud_transcription' => $groqForUser->status((int)$context['user']['id'])['connected'],
                    'cloud_narrative' => $groqForUser->status((int)$context['user']['id'])['connected'],
                ],
            ]);
        }

        if ($method === 'POST' && $path === '/api/v1/core/captions/plan') {
            $this->assertCloudAccess($context, 'captions');
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
                Json::send(['ok'=>false,'error'=>'Payload muito grande.'], 413);
            }
            try {
                $result = (new \CaptionIA\Services\CloudCaptionPlanner())->plan(Json::input());
                Json::send(['ok'=>true] + $result);
            } catch (\InvalidArgumentException $e) {
                Json::send(['ok'=>false,'error'=>$e->getMessage()], 422);
            }
        }

        if ($method === 'POST' && $path === '/api/v1/core/edit/plan') {
            $this->assertCloudAccess($context, 'edit');
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
                Json::send(['ok'=>false,'error'=>'Payload muito grande.'], 413);
            }
            try {
                $plan = (new \CaptionIA\Services\CloudEditPlanner())->plan(Json::input());
                Json::send(['ok'=>true] + $plan);
            } catch (\InvalidArgumentException $e) {
                Json::send(['ok'=>false,'error'=>$e->getMessage()], 422);
            }
        }

        if ($method === 'GET' && $path === '/api/v1/core/assets') {
            $this->assertCloudAccess($context, 'asset_catalog');
            if (!Env::bool('CAPTIONIA_CLOUD_ASSETS_ENABLED', false)) {
                Json::send(['ok'=>false,'error'=>'Biblioteca na nuvem ainda indisponível.'], 503);
            }
            Json::send(['ok'=>true,'assets'=>(new \CaptionIA\Services\CloudAssetService($this->basePath))->catalog()]);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/core/assets/([a-f0-9]{64})$#D', $path, $assetMatch)) {
            $this->assertCloudAccess($context, 'asset_download');
            if (!Env::bool('CAPTIONIA_CLOUD_ASSETS_ENABLED', false)) {
                Json::send(['ok'=>false,'error'=>'Biblioteca na nuvem ainda indisponível.'], 503);
            }
            $payload = (new \CaptionIA\Services\CloudAssetService($this->basePath))->fetch($assetMatch[1]);
            header('Content-Type: ' . $payload['mime']);
            header('Content-Length: ' . $payload['bytes']);
            header('Content-Disposition: attachment; filename="captionia-' . $assetMatch[1] . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            echo $payload['data'];
            exit;
        }

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
                    'release' => null,
                ]);
            }

            $release = $this->releases->latestActive();
            if (!$release) {
                Json::send([
                    'ok' => true,
                    'available' => false,
                    'reason' => 'no_release',
                    'release' => null,
                ]);
            }

            $allowed = $this->licenses->versionAllowed($license, (string) $release['version']);

            Json::send([
                'ok' => true,
                'available' => $allowed,
                'reason' => $allowed ? null : 'version_not_allowed',
                'release' => [
                    'version' => $release['version'],
                    'major_version' => (int) $release['major_version'],
                    'download_url' => $allowed
                        ? rtrim(Env::get('APP_URL', '') ?: '', '/') . '/api/v1/releases/' . rawurlencode((string) $release['version']) . '/download'
                        : null,
                    'sha256' => $allowed ? (string) ($release['sha256'] ?? '') : null,
                    'file_size' => $allowed ? (int) ($release['file_size'] ?? 0) : null,
                    'notes' => $allowed ? ($release['notes'] ?? null) : null,
                    'published_at' => $release['published_at'],
                ],
            ]);
        }

        if (
            $method === 'GET'
            && preg_match('#^/api/v1/releases/([0-9A-Za-z.+-]+)/download$#', $path, $m)
        ) {
            $release = $this->releases->findActiveByVersion(rawurldecode($m[1]));
            if (!$release) {
                Json::send(['ok' => false, 'error' => 'Release não encontrada.'], 404);
            }

            $license = $context['license'];
            if (!$license || !$this->licenses->versionAllowed($license, (string) $release['version'])) {
                Json::send(['ok' => false, 'error' => 'Sua licença não permite baixar esta versão.'], 403);
            }

            $file = $this->releases->absolutePath($release);
            header('Content-Type: application/zip');
            header('Content-Length: ' . (string) filesize($file));
            header('Content-Disposition: attachment; filename="CaptionIA-' . $release['version'] . '.zip"');
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($file);
            return;
        }

        Json::send(['ok' => false, 'error' => 'Endpoint não encontrado.', 'code' => 'not_found'], 404);
    }

    /**
     * Entitlement is based on the SERVER core generation, never solely on
     * the client-supplied version (which can be forged).
     */
    private function assertCloudAccess(array $context, string $feature): void
    {
        if (!Env::bool('CAPTIONIA_CLOUD_CORE_ENABLED', false)) {
            Json::send(['ok'=>false,'error'=>'Cloud Core ainda não foi habilitado.'], 503);
        }
        $license = $context['license'];
        $major = max(1, Env::int('CAPTIONIA_CLOUD_CORE_MAJOR', 3));
        if (!$license || !$this->licenses->versionAllowed($license, $major . '.0.0')) {
            Json::send(['ok'=>false,'error'=>'Licença não permite esta geração do Cloud Core.'], 403);
        }
        $device = (string)($context['token']['device'] ?? '');
        $deviceStmt = Database::connection()->prepare(
            'SELECT 1 FROM license_devices
             WHERE license_id=? AND device_uuid=? AND revoked_at IS NULL LIMIT 1'
        );
        $deviceStmt->execute([(int)$license['id'], $device]);
        if (!$deviceStmt->fetchColumn()) {
            Json::send(['ok'=>false,'error'=>'Dispositivo não autorizado.'], 403);
        }
        // Transcrição não possui quota própria do Caption IA. O provedor pode aplicar
        // limites da conta/chave upstream; os demais recursos Cloud mantêm proteção por minuto.
        if ($feature !== 'transcribe') {
            $limit = max(1, min(120, Env::int('CAPTIONIA_CLOUD_MAX_REQUESTS_PER_MINUTE', 30)));
            if (!(new \CaptionIA\Services\CloudRequestLimiter())->allow((int)$context['user']['id'], $feature, $limit)) {
                header('Retry-After: 60');
                Json::send(['ok'=>false,'error'=>'Muitas requisições. Tente novamente em um minuto.'], 429);
            }
        }
    }

    /** Enforces license/device entitlement independently from optional Cloud Core features. */
    private function assertGroqAccess(array $context, bool $metered, string $feature = ''): void
    {
        $license = $context['license'];
        if (!$license || !$this->licenses->versionAllowed($license, '3.0.0')) {
            Json::send(['ok'=>false,'error'=>'É necessária uma licença Caption IA ativa.'],403);
        }
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM license_devices WHERE license_id=? AND device_uuid=? AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute([(int)$license['id'], (string)$context['token']['device']]);
        if (!$stmt->fetchColumn()) Json::send(['ok'=>false,'error'=>'Dispositivo não autorizado.'],403);
        if ($metered) {
            $limit = max(1,min(120, Env::int('CAPTIONIA_GROQ_BYOK_REQUESTS_PER_MINUTE',30)));
            if (!(new \CaptionIA\Services\CloudRequestLimiter())->allow((int)$context['user']['id'], 'byok_'.$feature, $limit)) {
                header('Retry-After: 60');
                Json::send(['ok'=>false,'error'=>'Aguarde um minuto antes de solicitar mais análises.','retry_after_seconds'=>60],429);
            }
        }
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
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
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
