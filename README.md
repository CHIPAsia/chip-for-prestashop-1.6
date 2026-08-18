# CHIP for PrestaShop 1.6

CHIP payment gateway module for **PrestaShop 1.6.x** (compatible with PHP 5.4+).

> **Menggunakan PrestaShop 1.7 atau lebih baru (8.x, 9.x)?** Modul ini untuk PrestaShop 1.6 sahaja. Guna **[chip-for-prestashop](https://github.com/CHIPAsia/chip-for-prestashop)** untuk PrestaShop 1.7 – 9.x.

Accept payments via CHIP: FPX, FPX B2B1, DuitNow QR, Card (Visa, Mastercard, Maestro), Atome, GrabPay, Maybank QRPay, ShopeePay, Touch 'n Go eWallet, Crypto Coin.

## Requirements

- PrestaShop 1.6.x (tested against 1.6.1.24)
- PHP 5.4 – 5.6 (or any newer PHP that still runs PrestaShop 1.6)
- `allow_url_fopen` or PHP `curl` extension (used by the CHIP API client)
- `openssl` extension (required for webhook signature verification)
- A CHIP merchant account with an active **brand** (secret key + brand ID)

---

## Installation

1. Copy the `chip/` folder into the PrestaShop `modules/` directory:
   ```
   modules/chip/
   ├── chip.php
   ├── config.xml
   ├── logo.png
   ├── controllers/front/{payment,callback}.php
   ├── classes/ChipApi.php
   ├── views/templates/front/{payment,payment_return}.tpl
   └── index.php (per folder)
   ```
2. Back office → **Modules → Modules** → find **CHIP** → **Install**.
3. Open the module **Configure** page.

---

## Configuration (Back Office)

| Setting | Description |
|---|---|
| **Secret Key** | Your CHIP brand secret key (`CHIP_SECRET_KEY`). Never share it. |
| **Brand ID** | Your CHIP brand ID (`CHIP_BRAND_ID`). |
| **Payment Methods** | Multi-select whitelist of payment method codes (`fpx`, `fpx_b2b1`, `card`, `duitnow_qr`, `razer_atome`, `razer_grabpay`, `razer_maybankqr`, `razer_shopeepay`, `razer_tng`, `crypto_coin`). Empty = all methods allowed. |
| **Due Strict** | When enabled, payment must complete before the due time (`CHIP_DUE_STRICT`). |
| **Purchase Timezone** | Timezone for the purchase (default `Asia/Kuala_Lumpur`, `CHIP_PURCHASE_TIME_ZONE`). |

Configuration values are stored with the `CHIP_` prefix in `ps_configuration`.

> **Payment method display names** (user-facing, exact spelling): `FPX`, `FPX B2B1`, `Card (Visa, Mastercard, Maestro)`, `DuitNow QR` (not "Duitnow QR"), `Atome`, `GrabPay`, `Maybank QRPay`, `ShopeePay`, `Touch 'n Go eWallet`, `Crypto Coin`.

---

## Payment Flow

1. Customer proceeds to checkout → **Pay with CHIP** button appears (hook `displayPayment`).
2. Form POST to `index.php?fc=module&module=chip&controller=payment` (module front controller `payment`).
3. The controller builds the purchase and calls `POST /purchases/` (CHIP Collect API):

   ```json
   {
     "success_callback": "https://shop/module/chip/callback?id_cart=12",
     "success_redirect": "https://shop/module/chip/callback?id_cart=12",
     "failure_redirect": "https://shop/module/chip/callback?id_cart=12",
     "cancel_redirect": "https://shop/module/chip/callback?id_cart=12",
     "creator_agent": "PrestaShop 1.6: 1.0.0",
     "reference": "12",
     "platform": "prestashop",
     "purchase": {
       "total_override": 12500,
       "due_strict": false,
       "timezone": "Asia/Kuala_Lumpur",
       "currency": "myr",
       "language": "en",
       "products": [{"name": "...", "price": 12500, "quantity": 1}]
     },
     "brand_id": "<brand>",
     "client": {"email": "...", "full_name": "...", "country": "MY", "...": "..."},
     "payment_method_whitelist": ["fpx", "card"]
   }
   ```

4. The customer is redirected to the CHIP payment page (`checkout_url`).
5. CHIP sends a webhook (`success_callback`) **and** the customer is redirected back (`success_redirect` / `failure_redirect` / `cancel_redirect`).

---

## Callback Handling (`controllers/front/callback.php`)

- Verifies the `X-Signature` header using the CHIP public key (`GET /public_key/`, cached in `CHIP_PUBLIC_KEY`), signature is RSA PKCS#1 v1.5 / SHA-256, base64 decoded:
  `openssl_verify($content, base64_decode($signature), $key, 'sha256WithRSAEncryption')`
- If the signature is missing or verification fails → **fallback**: `GET /purchases/{id}/` to check the real status (purchase id read from the customer's session cookie).
- **`status === 'paid'`** → `validateOrder()` with `PS_OS_PAYMENT`, guarded so it never double-validates (`Order::getOrderByCartId()` is checked first).
- On success the customer is redirected to `order-confirmation` (`id_cart` + `id_module` + `key`).
- Non-paid statuses are logged and the customer is redirected back to the order page.

Webhook idempotency: `validateOrder()` is only called once per cart; a second callback for the same cart is ignored.

---

## Refunds

**Not supported from the PrestaShop back office.** PrestaShop 1.6 has no `displayAdminOrderContentOrder`-style payment-module hook and there is no tokenization, so:

- Refunds must be issued from the **CHIP dashboard** (or the CHIP API `POST /purchases/{id}/refund/`).
- The CHIP purchase ID is saved as the `transaction_id` of the order payment so it can be matched against the CHIP dashboard.

---

## Limitations

- No refund UI in the back office (see above).
- No tokenization / saved-card / recurring payments.
- No admin `mark as paid` / capture actions.
- Payment method whitelist is configured per shop (Configuration values are shop-scoped where the module runs).
- PHP 5.6 is the minimum; the module does **not** use PHP 7+ syntax.

---

## Testing

1. Set `PS_DEBUG_ERRORS` / disable friendly URLs if needed.
2. Check logs: **Back office → Advanced Parameters → Logs** (module logs are prefixed `CHIP:`).
3. Test with a small amount first. If the CHIP test mode is enabled, use the test keys in the **Modules → CHIP → Configure** page.
4. To verify the callback, check that:
   - a purchase is created (CHIP dashboard),
   - the order appears in the PrestaShop back office once paid,
   - the customer lands on `order-confirmation`.

---

## Support

- CHIP docs: https://docs.chip-in.asia
- API base: `https://gate.chip-in.asia/api/v1`

---

## License

Open Software License (OSL 3.0) — see http://opensource.org/licenses/osl-3.0.php
