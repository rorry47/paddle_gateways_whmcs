<?php
/**
 * Paddle Billing v2 — WHMCS Payment Gateway Module
 *
 * Compatibility: WHMCS 9.x / PHP 8.x
 * API: Paddle Billing (v2) + Paddle.js v2
 *
 * Structure:
 *   modules/gateways/paddle.php                    — main module
 *   modules/gateways/callback/paddle.php           — webhook handler
 *   modules/gateways/paddle/create_transaction.php — API helper (AJAX)
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Guard against redeclaration of functions when required via callback/create_transaction
if (function_exists('paddle_MetaData')) {
    return;
}

// ════════════════════════════════════════════════════════════════════════════
// Metadata and configuration
// ════════════════════════════════════════════════════════════════════════════

function paddle_MetaData(): array
{
    return [
        'DisplayName'                 => 'Paddle Billing',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

/**
 * Module settings — two complete sets of keys (Production + Sandbox).
 * Switch between environments with a single dropdown.
 */
function paddle_config(): array
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Paddle Billing',
        ],

        // ── Environment switcher ──────────────────────────────────────────
        'sandboxMode' => [
            'FriendlyName' => 'Environment Mode',
            'Type'         => 'dropdown',
            'Options'      => 'production,sandbox',
            'Description'  => 'Select environment. In sandbox mode, a test mode warning is shown to clients.',
            'Default'      => 'production',
        ],

        // ── Production ────────────────────────────────────────────────────
        'liveApiKey' => [
            'FriendlyName' => '[Production] API Key (Secret)',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Starts with pdl_live_... · Paddle Dashboard → Developer Tools → Authentication',
        ],
        'liveClientToken' => [
            'FriendlyName' => '[Production] Client-Side Token',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Starts with live_... · Paddle Dashboard → Developer Tools → Authentication',
        ],
        'liveWebhookSecret' => [
            'FriendlyName' => '[Production] Webhook Secret Key',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Paddle Dashboard → Developer Tools → Notifications',
        ],

        // ── Sandbox ───────────────────────────────────────────────────────
        'sandboxApiKey' => [
            'FriendlyName' => '[Sandbox] API Key (Secret)',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Starts with pdl_sbox_... · sandbox.paddle.com → Developer Tools → Authentication',
        ],
        'sandboxClientToken' => [
            'FriendlyName' => '[Sandbox] Client-Side Token',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Starts with test_... · sandbox.paddle.com → Developer Tools → Authentication',
        ],
        'sandboxWebhookSecret' => [
            'FriendlyName' => '[Sandbox] Webhook Secret Key',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'sandbox.paddle.com → Developer Tools → Notifications',
        ],
    ];
}

// ════════════════════════════════════════════════════════════════════════════
// Helper functions
// ════════════════════════════════════════════════════════════════════════════

/**
 * Returns the active set of keys/settings based on the selected environment.
 * Single switching point — used across all three module files.
 */
function paddle_getEnvConfig(array $params): array
{
    $isSandbox = ($params['sandboxMode'] === 'sandbox');

    return [
        'isSandbox'     => $isSandbox,
        'apiKey'        => $isSandbox ? $params['sandboxApiKey']        : $params['liveApiKey'],
        'clientToken'   => $isSandbox ? $params['sandboxClientToken']   : $params['liveClientToken'],
        'webhookSecret' => $isSandbox ? $params['sandboxWebhookSecret'] : $params['liveWebhookSecret'],
        'apiBase'       => $isSandbox
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com',
    ];
}

/**
 * HTTP request to the Paddle Billing API.
 *
 * FIX #9: explicitly set CURLOPT_SSL_VERIFYPEER = true
 * to avoid depending on hosting defaults.
 */
function paddle_apiRequest(string $method, string $endpoint, array $data, string $apiKey, string $apiBase): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiBase . $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,   // FIX #9: explicit TLS certificate verification
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['error' => ['detail' => 'cURL error: ' . $curlError]];
    }

    return json_decode($response, true) ?? ['error' => ['detail' => 'Invalid JSON response']];
}

// ════════════════════════════════════════════════════════════════════════════
// Core module functions
// ════════════════════════════════════════════════════════════════════════════

/**
 * Payment button — Paddle.js v2 Inline Checkout.
 *
 * FIX #4: all PHP variables inserted into JS are escaped via json_encode()
 * to prevent XSS and script breakage when email/URL contain special characters.
 */
function paddle_link(array $params): string
{
    $invoiceId   = (int) $params['invoiceid'];
    $amount      = $params['amount'];
    $currency    = preg_replace('/[^A-Z]/', '', strtoupper($params['currency']));
    $returnUrl   = $params['returnurl'];
    $systemUrl   = $params['systemurl'];
    $clientEmail = $params['clientdetails']['email'];

    $env         = paddle_getEnvConfig($params);
    $isSandbox   = $env['isSandbox'];
    $clientToken = $env['clientToken'];

    $amountInCents = (int) round((float) $amount * 100);

    // FIX #4: escape all values for safe insertion into JS
    $jsToken      = json_encode($clientToken);
    $jsReturnUrl  = json_encode($returnUrl);
    $jsEmail      = json_encode($clientEmail);
    $jsSystemUrl  = json_encode(rtrim($systemUrl, '/') . '/');
    $jsInvoiceId  = $invoiceId;          // already int, safe
    $jsAmount     = json_encode((string) $amountInCents);
    $jsCurrency   = json_encode($currency);
    $jsSandbox    = $isSandbox ? 'true' : 'false';

    $sandboxInit = $isSandbox ? 'Paddle.Environment.set("sandbox");' : '';

    // Test mode banner — visible to the client only in sandbox
    $sandboxBanner = '';
    if ($isSandbox) {
        $sandboxBanner = <<<HTML
<div style="background:#fff3cd;border:1px solid #ffc107;border-left:4px solid #ff9800;border-radius:4px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#856404;display:flex;align-items:center;gap:8px;">
    <span style="font-size:16px;">⚠️</span>
    <span>
        <strong>Test Mode (Sandbox)</strong> — real payments are not accepted.<br>
        Use the test card: <code style="background:#f8e8a0;padding:1px 4px;border-radius:3px;">4242 4242 4242 4242</code>
    </span>
</div>
HTML;
    }

    return <<<HTML
{$sandboxBanner}
<script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>
<script>
    {$sandboxInit}
    Paddle.Initialize({
        token: {$jsToken},
        eventCallback: function(data) {
            if (data.name === 'checkout.completed' ||
                (data.name === 'checkout.closed' && data.data.transaction.status === 'completed') ||
                (data.name === 'checkout.closed' && data.data.transaction.status === 'paid')) {
                sessionStorage.setItem('paddle_paid_{$invoiceId}', '1');
                window.location.href = {$jsReturnUrl};
            }
        }
    });
</script>

<div id="paddle-checkout-container">
    <p style="color:#888;font-size:13px;">⏳ Loading payment form...</p>
</div>

<script>
(function() {
    var systemUrl = {$jsSystemUrl};
    if (sessionStorage.getItem('paddle_paid_{$invoiceId}')) {
        document.getElementById('paddle-checkout-container').innerHTML = 
            '<p style="color:green;">✓ Payment completed. Please refresh the page.</p>';
        return;
    }
    fetch(systemUrl + 'modules/gateways/paddle/create_transaction.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            invoice_id: {$jsInvoiceId},
            amount:     {$jsAmount},
            currency:   {$jsCurrency},
            email:      {$jsEmail},
        })
    })
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function(data) {
        if (data.transaction_id) {
            Paddle.Checkout.open({
                settings: {
                    displayMode: 'overlay',
                    theme: 'light',
                    successUrl: {$jsReturnUrl},
                },
                transactionId: data.transaction_id,
            });
            document.getElementById('paddle-checkout-container').innerHTML = '';
        } else {
            document.getElementById('paddle-checkout-container').innerHTML =
                '<p style="color:red;">⚠️ Error: ' + (data.error || 'unknown') + '</p>';
        }
    })
    .catch(function(err) {
        document.getElementById('paddle-checkout-container').innerHTML =
            '<p style="color:red;">⚠️ Connection error with payment gateway. Please try again later.</p>';
    });
})();
</script>
HTML;
}

/**
 * Refund via Paddle Billing API v2.
 *
 * FIX #6: for adjustment items we use the correct field 'id' of the transaction item
 * (txnitm_xxx) — this is exactly what the Paddle API expects in the item_id field.
 */
function paddle_refund(array $params): array
{
    $transactionId = $params['transid'];
    $amount        = $params['amount'];
    $currency      = $params['currency'];

    $env     = paddle_getEnvConfig($params);
    $apiKey  = $env['apiKey'];
    $apiBase = $env['apiBase'];

    // Fetch transaction details
    $txnDetails = paddle_apiRequest('GET', "/transactions/{$transactionId}", [], $apiKey, $apiBase);

    if (!isset($txnDetails['data']['id'])) {
        return ['status' => 'error', 'rawdata' => $txnDetails, 'transid' => ''];
    }

    $amountInCents = (int) round((float) $amount * 100);

    // FIX #6: the 'id' field in transaction items = txnitm_xxx — this must be passed as item_id
    $items = [];
    foreach ($txnDetails['data']['items'] ?? [] as $item) {
        $itemId = $item['id'] ?? '';      // txnitm_xxx — transaction item ID
        if ($itemId !== '') {
            $items[] = [
                'item_id' => $itemId,
                'type'    => 'full',
            ];
        }
    }

    // If items could not be extracted — perform a partial refund for the full amount
    $postData = empty($items)
        ? [
            'transaction_id' => $transactionId,
            'reason'         => 'Refund via WHMCS',
            'action'         => 'refund',
            'type'           => 'partial',
            'amount'         => (string) $amountInCents,
            'currency_code'  => $currency,
          ]
        : [
            'transaction_id' => $transactionId,
            'reason'         => 'Refund via WHMCS',
            'action'         => 'refund',
            'items'          => $items,
          ];

    $response = paddle_apiRequest('POST', '/adjustments', $postData, $apiKey, $apiBase);

    if (isset($response['data']['id'])) {
        return ['status' => 'success', 'rawdata' => $response, 'transid' => $response['data']['id']];
    }

    return ['status' => 'error', 'rawdata' => $response, 'transid' => ''];
}
