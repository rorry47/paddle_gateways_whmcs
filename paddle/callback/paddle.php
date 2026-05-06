<?php
/**
 * Paddle Billing v2 — Webhook Callback Handler
 *
 * URL for Paddle Dashboard → Developer Tools → Notifications:
 *   https://your-whmcs.com/modules/gateways/callback/paddle.php
 *
 * Subscribe to events:
 *   - transaction.completed
 *   - transaction.paid
 *   - adjustment.created  (refunds)
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'paddle';
$gatewayParams     = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(503);
    die('Module not activated');
}

// FIX #5: include paddle.php only for helper functions;
// the guard inside paddle.php via function_exists prevents double declaration
require_once __DIR__ . '/../paddle.php';

$env           = paddle_getEnvConfig($gatewayParams);
$webhookSecret = $env['webhookSecret'];
$apiBase       = $env['apiBase'];

// ── Step 1: Read request body ────────────────────────────────────────────────
$rawBody   = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_PADDLE_SIGNATURE'] ?? '';

if ($rawBody === '') {
    http_response_code(400);
    die('Empty request body');
}

// ── Step 2: Verify signature ─────────────────────────────────────────────────
if (!paddle_verifyWebhookSignature($rawBody, $signature, $webhookSecret)) {
    logTransaction($gatewayModuleName, [
        'error'     => 'Invalid signature',
        'signature' => $signature,
    ], 'Signature Verification Failed');
    http_response_code(401);
    die('Unauthorized');
}

// ── Step 3: Parse payload ────────────────────────────────────────────────────
$payload = json_decode($rawBody, true);

if (!is_array($payload) || !isset($payload['event_type'], $payload['data'])) {
    logTransaction($gatewayModuleName, ['raw' => substr($rawBody, 0, 500)], 'Invalid Payload');
    http_response_code(400);
    die('Invalid payload');
}

$eventType = $payload['event_type'];
$data      = $payload['data'];

logTransaction($gatewayModuleName, $payload, 'Webhook: ' . $eventType);

// ── Step 4: Handle events ────────────────────────────────────────────────────
switch ($eventType) {

    case 'transaction.completed':
    case 'transaction.paid':
        paddle_handleTransactionCompleted($data, $gatewayParams);
        break;

    case 'adjustment.created':
        if (($data['action'] ?? '') === 'refund') {
            paddle_handleRefund($data, $gatewayParams);
        }
        break;

    default:
        // Unknown event — respond with 200 so Paddle does not retry
        break;
}

http_response_code(200);
echo 'OK';
exit;


// ════════════════════════════════════════════════════════════════════════════
// Event handlers
// ════════════════════════════════════════════════════════════════════════════

/**
 * Handle successful payment.
 *
 * FIX #7: checkCbTransID() calls die() on duplicate — Paddle then does not receive
 * 200 and retries the webhook again. Solution: check for duplicate via Capsule/WHMCS DB
 * directly BEFORE calling checkCbTransID, and on duplicate finish with 200 ourselves.
 */
function paddle_handleTransactionCompleted(array $data, array $gatewayParams): void
{
    $gatewayModuleName = 'paddle';

    $transactionId = $data['id'] ?? '';
    $status        = $data['status'] ?? '';

    if (!in_array($status, ['completed', 'paid'], true)) {
        logTransaction($gatewayModuleName, $data, 'Skipped — status: ' . $status);
        return;
    }

    if (empty($transactionId)) {
        logTransaction($gatewayModuleName, $data, 'Missing transaction ID');
        return;
    }

    // Get invoice ID from custom_data
    $customData = $data['custom_data'] ?? [];
    if (!is_array($customData)) {
        // Paddle may return custom_data as a JSON string
        $customData = json_decode((string) $customData, true) ?? [];
    }
    $invoiceId = (int) ($customData['whmcs_invoice_id'] ?? 0);

    if (!$invoiceId) {
        logTransaction($gatewayModuleName, $data, 'No whmcs_invoice_id in custom_data');
        return;
    }

    // FIX #7: check for duplicate via WHMCS DB before calling checkCbTransID
    // so that on duplicate we return 200 (instead of die without a response)
    $existing = \WHMCS\Database\Capsule::table('tblaccounts')
        ->where('gateway', $gatewayModuleName)
        ->where('transid', $transactionId)
        ->count();

    if ($existing > 0) {
        logTransaction($gatewayModuleName, ['transid' => $transactionId], 'Duplicate — already processed');
        // Exit normally — http_response_code(200) + echo 'OK' will execute in the main script
        return;
    }

    // Payment amount
    $totals    = $data['details']['totals'] ?? [];
    $amountRaw = $totals['total'] ?? $totals['subtotal'] ?? '0';
    $amount    = number_format((float) $amountRaw / 100, 2, '.', '');

    // Standard WHMCS checks
    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);
    checkCbTransID($transactionId);

    logTransaction($gatewayModuleName, $data, 'Payment Successful');

    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $amount,
        0,
        $gatewayModuleName
    );
}

/**
 * Handle refund via webhook (adjustment.created, action=refund).
 */
function paddle_handleRefund(array $data, array $gatewayParams): void
{
    $gatewayModuleName = 'paddle';

    $adjustmentId  = $data['id'] ?? '';
    $transactionId = $data['transaction_id'] ?? '';
    $totals        = $data['totals'] ?? [];
    $amountRaw     = $totals['total'] ?? '0';
    $amount        = number_format((float) $amountRaw / 100, 2, '.', '');

    logTransaction(
        $gatewayModuleName,
        $data,
        "Refund via webhook — adj: {$adjustmentId}, txn: {$transactionId}, amount: {$amount}"
    );

    // Webhook refunds are logged. To automatically credit the client, you can add:
    // localAPI('AddCredit', ['clientid' => $clientId, 'description' => 'Refund', 'amount' => $amount])
}


// ════════════════════════════════════════════════════════════════════════════
// Signature verification
// ════════════════════════════════════════════════════════════════════════════

/**
 * Verify Paddle Billing webhook signature.
 *
 * Paddle algorithm: HMAC-SHA256(webhookSecret, "{timestamp}:{rawBody}")
 * Header: Paddle-Signature: ts=TIMESTAMP;h1=HMAC_HEX
 *
 * @see https://developer.paddle.com/webhooks/signature-verification
 */
function paddle_verifyWebhookSignature(string $rawBody, string $signatureHeader, string $secret): bool
{
    if ($secret === '' || $signatureHeader === '') {
        return false;
    }

    // Parse header ts=...;h1=...
    $parts = [];
    foreach (explode(';', $signatureHeader) as $part) {
        $pair = explode('=', $part, 2);
        if (count($pair) === 2) {
            $parts[trim($pair[0])] = trim($pair[1]);
        }
    }

    $timestamp = $parts['ts'] ?? '';
    $h1        = $parts['h1'] ?? '';

    if ($timestamp === '' || $h1 === '') {
        return false;
    }

    // Replay attack protection: reject events older than 5 minutes
    if (abs(time() - (int) $timestamp) > 300) {
        return false;
    }

    // Compute expected signature
    $expectedHash = hash_hmac('sha256', $timestamp . ':' . $rawBody, $secret);

    // Use timing-safe comparison
    return hash_equals($expectedHash, $h1);
}
