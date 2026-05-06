# Paddle Billing v2 — WHMCS Payment Gateway

A payment gateway module for WHMCS 9.x that integrates [Paddle Billing (v2)](https://developer.paddle.com) using Paddle.js v2. Supports full payment lifecycle: checkout, webhooks, and refunds.

<img src="https://github.com/rorry47/paddle_gateways_whmcs/blob/main/screen_paddle.jpg">

---

## Features

- Paddle Billing v2 API + Paddle.js v2 inline checkout overlay
- Separate API keys for **Production** and **Sandbox** — switch environments with a single dropdown
- Dynamic pricing: automatically creates Paddle products and prices on the fly for any invoice amount, caches them by amount+currency to avoid redundant API calls
- Webhook signature verification (HMAC-SHA256) with replay attack protection
- Refunds via WHMCS admin panel
- Sandbox warning banner shown to clients during testing
- PHP 8.x compatible

---

## Requirements

- WHMCS 9.x
- PHP 8.0 or higher
- A [Paddle Billing](https://paddle.com) account (not Paddle Classic)
- cURL extension enabled

---

## File Structure

```
modules/gateways/
├── paddle.php                          # Main gateway module
├── callback/
│   └── paddle.php                      # Webhook handler
└── paddle/
    └── create_transaction.php          # AJAX helper (creates Paddle transactions)
```

---

## Installation

### Step 1 — Upload files

Copy the module files to your WHMCS installation preserving the directory structure:

```
/path/to/whmcs/modules/gateways/paddle.php
/path/to/whmcs/modules/gateways/callback/paddle.php
/path/to/whmcs/modules/gateways/paddle/create_transaction.php
```

### Step 2 — Activate in WHMCS

1. Log in to your WHMCS Admin Panel
2. Go to **Settings → Payment Gateways → All Payment Gateways**
3. Find **Paddle Billing** and click **Activate**
4. You will be redirected to the configuration page

### Step 3 — Configure the module

Fill in the following fields:

| Field | Description |
|---|---|
| **Environment** | Select `production` for live payments or `sandbox` for testing |
| **[Production] API Key (Secret)** | Your live secret API key from Paddle Dashboard |
| **[Production] Client-Side Token** | Your live client-side token from Paddle Dashboard |
| **[Production] Webhook Secret Key** | Webhook secret from your live notification endpoint |
| **[Sandbox] API Key (Secret)** | Your sandbox secret API key |
| **[Sandbox] Client-Side Token** | Your sandbox client-side token |
| **[Sandbox] Webhook Secret Key** | Webhook secret from your sandbox notification endpoint |

---

## Paddle Dashboard Setup

### Step 1 — Get API credentials

1. Log in to [Paddle Dashboard](https://vendors.paddle.com) (or [sandbox.paddle.com](https://sandbox.paddle.com) for testing)
2. Go to **Developer Tools → Authentication**
3. Copy the following:
   - **API Key** (starts with `pdl_live_...` for production or `pdl_sdbx_...` for sandbox)
   - **Client-Side Token** (starts with `live_...` or `test_...`)
4. Paste both into the corresponding WHMCS module settings fields

### Step 2 — Create a Webhook endpoint

1. In Paddle Dashboard go to **Developer Tools → Notifications**
2. Click **New destination**
3. Select type: **Webhook**
4. Set the URL to:
   ```
   https://your-whmcs-domain.com/modules/gateways/callback/paddle.php
   ```
5. Subscribe to the following events:
   - `transaction.completed`
   - `transaction.paid`
   - `adjustment.created`
6. Click **Save** and copy the **Secret key** that appears
7. Paste it into the **Webhook Secret Key** field in WHMCS module settings

> Repeat steps 1-7 for both **Production** and **Sandbox** environments using their respective dashboards.

---

## How It Works

```
Client clicks "Pay Invoice"
        |
WHMCS calls paddle_link() — renders checkout button
        |
AJAX -> create_transaction.php
        |
Check price cache (mod_paddle_prices table):
  |- Cache HIT  -> use existing price_id (1 API call)
  |- Cache MISS -> create Product + Price in Paddle,
                   save price_id to cache (3 API calls, first time only)
        |
Create Paddle Transaction with price_id
        |
Paddle.js opens checkout overlay
        |
Client completes payment
        |
Paddle sends webhook: transaction.completed
        |
callback/paddle.php:
  1. Verifies HMAC-SHA256 signature
  2. Checks for duplicate transaction
  3. Extracts whmcs_invoice_id from custom_data
  4. Calls addInvoicePayment()
        |
Invoice marked as paid in WHMCS
```

### Price Caching

The module automatically manages Paddle products and prices. When a payment is made for a new amount+currency combination (e.g. $22.00 USD), it:

1. Creates a Paddle product: `WHMCS Payment 22.00 USD`
2. Creates a Paddle price: `$22.00 USD`
3. Saves the `price_id` to the `mod_paddle_prices` database table

All subsequent payments for the same amount reuse the cached `price_id` — no extra API calls.

The cache is stored per environment (`sandbox` / `live`) so switching environments does not affect cached data.

---

## Refunds

Refunds can be initiated directly from the WHMCS admin panel:

1. Go to **Billing → Invoices** and open the paid invoice
2. Click the **Refund** button
3. WHMCS will call `paddle_refund()` which creates an adjustment via the Paddle API

Refunds initiated from the Paddle Dashboard will also be received via the `adjustment.created` webhook and logged in the WHMCS Gateway Log.

---

## Sandbox Testing

1. Set **Environment** to `sandbox` in the WHMCS module settings
2. Use [sandbox.paddle.com](https://sandbox.paddle.com) for your Paddle Dashboard
3. Clients will see a yellow warning banner indicating test mode
4. Use the following test card for payments:

| Field | Value |
|---|---|
| Card number | `4242 4242 4242 4242` |
| Expiry | Any future date |
| CVC | Any 3 digits |

---

## Database

The module automatically creates one table on first use:

**`mod_paddle_prices`** — price cache

| Column | Type | Description |
|---|---|---|
| `id` | int | Auto-increment primary key |
| `environment` | varchar(10) | `live` or `sandbox` |
| `cache_key` | varchar(20) | `AMOUNT_CURRENCY` e.g. `2200_USD` |
| `price_id` | varchar(50) | Paddle price ID (`pri_xxx`) |
| `product_id` | varchar(50) | Paddle product ID (`pro_xxx`) |
| `created_at` | timestamp | Record creation time |
| `updated_at` | timestamp | Record update time |

---

## Troubleshooting

**Checkout does not open**
- Check that the Client-Side Token is correct for the selected environment
- Open browser DevTools → Console for JavaScript errors

**Webhook not received**
- Verify the webhook URL is correct in Paddle Dashboard
- Ensure your server is accessible from the internet
- Check WHMCS **Utilities → Logs → Gateway Log** for incoming events

**Payment not reflected in WHMCS after checkout**
- Webhooks are required for invoice payment confirmation — make sure they are configured
- Check that the Webhook Secret Key matches between Paddle Dashboard and WHMCS settings

**502 error on payment**
- Check that the API Key is valid and matches the selected environment
- Verify cURL can reach `api.paddle.com` or `sandbox-api.paddle.com` from your server

---

## Security

- Webhook signatures are verified using HMAC-SHA256
- Replay attack protection: events older than 5 minutes are rejected
- Duplicate transaction detection prevents double-payments
- Invoice ownership is verified before creating a Paddle transaction
- All values inserted into JavaScript are escaped via `json_encode()`
- TLS certificate verification is enforced for all API requests

---

## Compatibility

| | |
|---|---|
| WHMCS | 9.x |
| PHP | 8.0+ |
| Paddle API | Billing v2 |
| Paddle.js | v2 |
| Paddle Classic | Not supported |

---


## Support

- PayPall: `lyjex.lyjex@gmail.com`
- Bitcoin [BTC]: `1JK1og8cLFJ7CvRL6Ff5fEN8gzMDpNJFMm`
- Ethereum [ERC20]: `0x1f332bcca1b6b04824d18d31e52d1a7613113e7c`
- TetherUS [TRC20]: `TMXgowg4cQb1iLUSeADcvGHfb4F8HsSw1m`
