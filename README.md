# Pixelforge Studio

AI image generation studio built with **Laravel 12**, **Vue 3 (TypeScript)**, **Inertia.js v2**, and **Tailwind CSS**, integrating the asynchronous [Pixelforge API](https://pixelforge.promptedit.dev/docs).

Includes all core requirements plus both stretch goals (**Admin Billing & Usage Reconciliation Report** and **Ledger-Backed Credit Balances**).

## Key Engineering Decisions

### 1. Exact Money Arithmetic (Zero Floats)
- All monetary columns (`balance_nanos`, `actual_provider_cost_nanos`, `actual_user_cost_nanos`, ledger `amount_nanos`) are stored as signed 64-bit integer **nanodollars** (`1 USD = 1,000,000,000` nanos, matching Pixelforge's exact 9-decimal `cost_usd`).
- Backend calculations use `bcmath` (`App\Support\Money`, `App\Services\Pixelforge\PricingCalculator`); frontend upfront estimates mirror this with `BigInt` (`resources/js/lib/pricing.ts`) and Unicode code-point counting (`[...prompt].length` / `mb_strlen(..., 'UTF-8')`).
- Actual provider costs are always computed from the **`rates` snapshot returned on each generation object**, never hardcoded rates.
- User charges apply a 25% markup (`provider_cost × 1.25`). For all current Pixelforge rates this is an exact integer in nanodollars; for any hypothetical future rate producing a sub-nanodollar fraction, markup rounds up to the next nanodollar (`$0.000000001`).
- Failed generations always cost **`$0.000000000`**.

### 2. Balance Holds & Immutable Credit Ledger
- New accounts receive `$2.00` welcome credits (`welcome_bonus`) and can top up via simulated **Buy Credits** purchases.
- Submitting a generation atomically locks the user row (`lockForUpdate()`), verifies `balance >= max_estimated_user_cost`, creates the local `Generation`, and records a `generation_hold` reserve.
- On success, `settleGenerationSuccess()` releases the unused difference (`reserved - actual`); on failure, `refundGenerationFailure()` refunds 100%.
- Unique constraints on `(generation_id, type)` guarantee ledger settlements and refunds can never be applied twice.

### 3. Resilience, Race Conditions & Webhooks
- **Early webhook race**: Local `Generation` rows are created *before* calling `POST /v1/generations` with a deterministic `Idempotency-Key` and `metadata.local_generation_id`, allowing `GenerationStateService` to match webhooks that arrive before the `202 Accepted` response returns.
- **Retryable vs. non-retryable errors**: `SubmitGenerationToPixelforgeJob` retries `503 overloaded`, `429 rate_limit_exceeded`, `429 concurrency_limit_exceeded`, and network timeouts using `max(Retry-After, exponential backoff)` (capped at 6 attempts), while immediately failing and refunding `400 content_policy_violation`, `422 validation_failed`, and `429 daily_limit_exceeded`.
- **Signed, idempotent, monotonic webhooks**: `POST /webhooks/pixelforge` verifies `Pixelforge-Signature` (HMAC-SHA256 on `<timestamp>.<raw_body>`, constant-time comparison, 5-minute replay window), deduplicates `evt_*` IDs via `insertOrIgnore` (safe on both PostgreSQL and SQLite), and enforces monotonic transitions (`submitting -> queued -> processing -> succeeded | failed`).
- **Polling fallback**: `pixelforge:reconcile-pending` runs every minute (plus an on-demand **Poll** button in the UI) respecting `X-RateLimit-Remaining`.

### 4. Operator Admin Report (`/admin/report`)
- Protected by `is_admin` RBAC (`EnsureUserIsAdmin` middleware returning `403` to non-admins).
- Groups daily spend, provider cost, and gross profit by **UTC `completed_at` date** (`completed_utc_date`) to align 1:1 with Pixelforge's `GET /v1/usage`, displaying live per-day and total reconciliation down to `$0.000000001`.

## Local Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
```

Configure Pixelforge in `.env`:
```dotenv
PIXELFORGE_API_KEY=your_local_api_key
PIXELFORGE_WEBHOOK_SECRET=whsec_your_local_secret
```

Run the dev stack (web server, queue worker, logs, Vite) and a public tunnel for webhooks:
```bash
composer run dev
# In another terminal:
cloudflared tunnel --url http://127.0.0.1:8000
```
Set your local Pixelforge key's webhook URL to `https://<tunnel-host>/webhooks/pixelforge`.

### Seeded Accounts
- **Operator Admin**: `admin@pixelforge-studio.test` / `password` *(configurable via `ADMIN_EMAIL` & `ADMIN_PASSWORD`)*
- **Demo User**: `demo@pixelforge-studio.test` / `password`
- Promote any user: `php artisan user:make-admin user@example.com`

## Running Tests

```bash
php artisan test
npm run build
```