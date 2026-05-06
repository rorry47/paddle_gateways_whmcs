<?php
/**
 * Paddle Billing v2 — Helper: create transaction via API
 *
 * price_id logic:
 *   - On first payment for a new amount+currency, create a product+price in Paddle
 *   - Cache price_id in the mod_paddle_prices table (amount+currency → price_id)
 *   - On repeat payment for the same amount — retrieve from cache (1 request instead of 3)
 *
 * URL: /modules/gateways/paddle/create_transaction.php
 */

require_once __DIR__ . '/../../../init.php';

require_once __DIR__ . '/../../../includes/gatewayfunctions.php';

if (!function_exists('paddle_getEnvConfig')) {
    require_once __DIR__ . '/../paddle.php';
}

header('Content-Type: application/json');

// ── Basic checks ─────────────────────────────────────────────────────────────

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Authorization ────────────────────────────────────────────────────────────

$loggedInClientId = 0;

if (session_status() === PHP_SESSION_ACTIVE) {
    $loggedInClientId = (int) ($_SESSION['uid'] ?? $_SESSION['userid'] ?? 0);
}

if (!$loggedInClientId) {
    try {
        $currentUser = \WHMCS\Auth\CurrentUser::client();
        if ($currentUser && !empty($currentUser->id)) {
            $loggedInClientId = (int) $currentUser->id;
        }
    } catch (\Throwable $e) {}
}

$isAdmin = !empty($_SESSION['adminid']);
if (!$loggedInClientId && $isAdmin) {
    $loggedInClientId = -1;
}

if (!$loggedInClientId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized — please log in']);
    exit;
}

// ── Module settings ──────────────────────────────────────────────────────────

$gatewayParams = getGatewayVariables('paddle');

if (!$gatewayParams['type']) {
    http_response_code(503);
    echo json_encode(['error' => 'Module not activated']);
    exit;
}

$env       = paddle_getEnvConfig($gatewayParams);
$apiKey    = $env['apiKey'];
$apiBase   = $env['apiBase'];
$isSandbox = $env['isSandbox'];

// ── Input validation ─────────────────────────────────────────────────────────

$input = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$invoiceId = (int)   ($input['invoice_id'] ?? 0);
$amount    = (int)   ($input['amount']     ?? 0);
$currency  = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($input['currency'] ?? '')));
$email     = trim((string) ($input['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

if ($invoiceId <= 0 || $amount <= 0 || strlen($currency) < 3) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid required fields']);
    exit;
}

// ── Invoice verification ─────────────────────────────────────────────────────

try {
    $invoiceQuery = \WHMCS\Database\Capsule::table('tblinvoices')
        ->where('id', $invoiceId);

    if ($loggedInClientId !== -1) {
        $invoiceQuery->where('userid', $loggedInClientId);
    }

    $invoice = $invoiceQuery->first();

    if (!$invoice) {
        http_response_code(403);
        echo json_encode(['error' => 'Invoice not found or access denied']);
        exit;
    }

    if ($loggedInClientId === -1) {
        $loggedInClientId = (int) $invoice->userid;
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}

// ── Initialize cache table ───────────────────────────────────────────────────

try {
    if (!\WHMCS\Database\Capsule::schema()->hasTable('mod_paddle_prices')) {
        \WHMCS\Database\Capsule::schema()->create('mod_paddle_prices', function ($table) {
            $table->increments('id');
            $table->string('environment', 10);
            $table->string('cache_key', 20);
            $table->string('price_id', 50);
            $table->string('product_id', 50);
            $table->timestamps();
        });
    } else {
    }
} catch (\Exception $e) {
}

// ── Get or create price_id ────────────────────────────────────────────────────

$environment = $isSandbox ? 'sandbox' : 'live';
$cacheKey    = $amount . '_' . $currency;

$priceId = paddle_getOrCreatePrice($amount, $currency, $cacheKey, $environment, $apiKey, $apiBase);

if (!$priceId) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to get or create Paddle price']);
    exit;
}

// ── Create transaction ───────────────────────────────────────────────────────

$transactionResult = paddle_apiRequest('POST', '/transactions', [
    'items' => [
        [
            'price_id' => $priceId,
            'quantity' => 1,
        ],
    ],
    'currency_code'   => $currency,
    'customer'        => ['email' => $email],
    'custom_data'     => ['whmcs_invoice_id' => $invoiceId],
    'collection_mode' => 'automatic',
], $apiKey, $apiBase);

if (isset($transactionResult['data']['id'])) {
    echo json_encode(['transaction_id' => $transactionResult['data']['id']]);
} else {
    $err = $transactionResult['error']['detail'] ?? json_encode($transactionResult['error'] ?? $transactionResult);
    http_response_code(502);
    echo json_encode(['error' => 'Transaction creation failed: ' . $err]);
}

// ════════════════════════════════════════════════════════════════════════════
// Function: get price_id from cache or create a new one in Paddle
// ════════════════════════════════════════════════════════════════════════════

function paddle_getOrCreatePrice(int $amount, string $currency, string $cacheKey, string $environment, string $apiKey, string $apiBase): string
{

    // Check cache
    try {
        $cached = \WHMCS\Database\Capsule::table('mod_paddle_prices')
            ->where('environment', $environment)
            ->where('cache_key', $cacheKey)
            ->first();

        if ($cached) {
            return $cached->price_id;
        }
    } catch (\Exception $e) {
    }

    $amountFormatted = number_format($amount / 100, 2) . ' ' . $currency;

    // Step 1: create product
    $productResult = paddle_apiRequest('POST', '/products', [
        'name'         => 'WHMCS Payment ' . $amountFormatted,
        'tax_category' => 'standard',
    ], $apiKey, $apiBase);

    if (!isset($productResult['data']['id'])) {
        return '';
    }

    $productId = $productResult['data']['id'];

    // Step 2: create price
    $priceResult = paddle_apiRequest('POST', '/prices', [
        'product_id'  => $productId,
        'description' => $amountFormatted,
        'unit_price'  => [
            'amount'        => (string) $amount,
            'currency_code' => $currency,
        ],
        'quantity' => ['minimum' => 1, 'maximum' => 1],
    ], $apiKey, $apiBase);

    if (!isset($priceResult['data']['id'])) {
        return '';
    }

    $priceId = $priceResult['data']['id'];

    // Save to cache
    try {
        \WHMCS\Database\Capsule::table('mod_paddle_prices')->insert([
            'environment' => $environment,
            'cache_key'   => $cacheKey,
            'price_id'    => $priceId,
            'product_id'  => $productId,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (\Exception $e) {
    }

    return $priceId;
}