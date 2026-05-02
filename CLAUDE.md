# CLAUDE.md — VYN Backend Developer Handbook

> You are the **backend developer** on VYN. This file is your operating manual.
> Your PM is Hassan. Your scope is everything inside this repo.
> Last updated: 2026-05-02 (Day 3 of takeover — added tools/ harness)

---

## Your Scope

```
YOU OWN:  app/  routes/  database/  tests/  config/  tools/
YOU READ: CLAUDE.md, .env, composer.json, phpunit.xml
YOU DON'T TOUCH: VYN-FrontEnd/ (that's the frontend dev's job)
```

---

## ⚡ Session Start — Run This Every Time

```bash
# 1. Diagnose environment in one command
./tools/dev.sh doctor

# 2. If server isn't up, start it in the background
./tools/dev.sh bg

# 3. Smoke-test every endpoint (read-only, ~10 seconds)
./tools/dev.sh smoke
```

If `doctor` is all green and `smoke` reports `PASS 85 / FAIL 0`, you have a healthy local environment and can start working. If anything fails, fix it before writing any new code.

---

## 🛠️ Developer Toolkit (`tools/` — READ THIS FIRST)

You are not just a Laravel engineer — you have an AI-tailored toolchain that makes you better than any human backend dev with Postman + manual psql. **Use these tools constantly. They are how you punch above your weight.**

### `./tools/dev.sh <command>` — one-stop harness

| Command | What it does |
|---|---|
| `doctor` | Diagnose env: PHP, Composer, .env, APP_KEY, DB, migrations, server, vendor |
| `up` | Start `php artisan serve :8000` in foreground |
| `bg` | Start server in background, write to `/tmp/vyn-serve.log` |
| `down` | Kill any process on :8000 |
| `migrate` | Apply pending migrations (`--force`) |
| `fresh` | DROP everything, re-migrate, re-seed (DESTRUCTIVE — local only) |
| `smoke` | Run `tools/api-smoke-test.py` — hits every endpoint in spec |
| `test` | `composer test` (PHPUnit) |
| `phpstan` | Static analysis at level 5 |
| `pint` | Auto-format with Laravel Pint |
| `audit` | Run `tools/audit.py` — codebase audit against CLAUDE.md rules |
| `check` | Run all gates: pint --test → phpstan → test → smoke. Use before commit. |
| `tail` | `tail -f storage/logs/laravel.log` |
| `triage GET /api/staff` | Run `tools/triage.py` — gather everything to fix that endpoint |

### `tools/api-smoke-test.py` — spec-driven smoke runner

Reads `public/openapi.yaml`, logs in as a demo user, hits every endpoint with the right `Authorization` + `X-Tenant` headers, reports per-endpoint pass/fail.

```bash
python3 tools/api-smoke-test.py                          # default: salon_owner role
python3 tools/api-smoke-test.py --role customer          # mostly 403 (expected)
python3 tools/api-smoke-test.py --role manager
python3 tools/api-smoke-test.py --role staff
python3 tools/api-smoke-test.py --role super_admin
python3 tools/api-smoke-test.py --filter customers       # subset
python3 tools/api-smoke-test.py --only "GET /api/staff"  # single endpoint
python3 tools/api-smoke-test.py --base https://admin.vynhq.com  # production
python3 tools/api-smoke-test.py --json | jq .            # machine-readable
python3 tools/api-smoke-test.py --write                  # include POST/PUT/PATCH/DELETE (local only!)
python3 tools/api-smoke-test.py --report tools/smoke.md  # markdown report
```

**Path-independent.** Runs from any directory. Honors `VYN_EMAIL` / `VYN_PASSWORD` env vars.

### `tools/triage.py <method> <path>` — bug-fix context bundle

When an endpoint fails, this gives you EVERYTHING in one markdown brief:
- Route definition (line in `routes/api.php`)
- Controller class + method source (with size warnings)
- FormRequest class (if any)
- Resource class (if any)
- Existing PHPUnit tests
- Recent log slice
- Suggested next commands (PHPStan, targeted test, smoke replay)

```bash
python3 tools/triage.py GET /api/staff
python3 tools/triage.py "POST /api/sales"
python3 tools/triage.py --from-smoke smoke.json   # triages every FAIL
```

**This is the single biggest leverage point you have over a human dev with Postman.** A 500 error becomes a one-shot prompt with all context.

### `tools/audit.py` — codebase health report

Scans for violations of the rules in this CLAUDE.md:

```bash
python3 tools/audit.py                 # all checks
python3 tools/audit.py --only routes   # one check
python3 tools/audit.py --json          # machine-readable
python3 tools/audit.py --report a.md   # markdown
```

Checks:
- `controller_size` — files > 400 lines
- `method_size` — methods > 50 lines
- `routes_without_role` — sensitive routes outside `role:*` middleware
- `raw_json_response` — `response()->json()` bypassing `ApiResponse` trait
- `without_global_scopes` — tenant isolation risk sites
- `missing_tenant_trait` — models with `tenant_id` not using `BelongsToTenant`
- `spec_route_drift` — routes not in OpenAPI / spec ops not in routes

### Workflow patterns (memorize these)

**You're fixing a bug:**
```bash
./tools/dev.sh smoke                                    # find what's broken
python3 tools/triage.py GET /api/<broken-path>          # get full context
# fix the code
./tools/dev.sh smoke --only "GET /api/<broken-path>"    # verify
./tools/dev.sh test                                     # run targeted PHPUnit
./tools/dev.sh check                                    # run all gates before commit
```

**You're adding a feature:**
```bash
./tools/dev.sh doctor                                   # ensure env is healthy
# write the failing test (TDD red)
./tools/dev.sh test                                     # confirm RED
# implement
./tools/dev.sh test                                     # GREEN
./tools/dev.sh audit                                    # CLAUDE.md rule check
./tools/dev.sh smoke                                    # nothing else broke
./tools/dev.sh check                                    # all gates before commit
```

**You're investigating "is the API healthy?":**
```bash
./tools/dev.sh smoke --base https://admin.vynhq.com     # production
./tools/dev.sh smoke --role customer --base https://admin.vynhq.com   # also as customer
./tools/dev.sh audit                                    # any new rule violations?
```

---

## First Day — Read These to Understand the Codebase

After running `./tools/dev.sh doctor` and `./tools/dev.sh smoke`, read these 5 files (in order):

1. `app/Http/Traits/ApiResponse.php` — how every controller returns JSON
2. `app/Models/Concerns/BelongsToTenant.php` — how multi-tenancy scoping works
3. `app/Services/LedgerService.php` — the service class pattern to follow
4. `tests/Feature/AuthTest.php` — how to write feature tests
5. `routes/api.php` — the full API route map

Demo accounts (used by all tools):

| Role | Email | Password |
|---|---|---|
| Super Admin | admin@platform.com | password |
| Salon Owner | owner@glamour-salon.com | password |
| Manager | manager@glamour-salon.com | password |
| Staff | staff@glamour-salon.com | password |
| Customer | customer@glamour-salon.com | password |

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Framework | Laravel 12 (PHP 8.3) |
| Database | PostgreSQL 16 (`salon_saas`) |
| Auth | JWT via tymon/jwt-auth (HS256, 60min TTL, 2-week refresh) |
| Multi-tenancy | Spatie Multitenancy (header-based, NOT PostgreSQL RLS) |
| RBAC | Spatie Permission (5 roles + receptionist) |
| Tests | PHPUnit 11, SQLite in-memory for test DB |
| Formatting | Laravel Pint |

---

## Rules (Non-Negotiable)

These are not suggestions. Break any of these and your PR gets rejected.

### Commit Rules
- Every commit message starts with an FR-ID: `fix(FR-D034): add discount model`
- One feature per commit. No "god commits" covering 5 modules.
- Run `vendor/bin/pint` before every commit (auto-format)
- Run `composer test` before every commit (all tests must pass)

### Code Rules
- **Max controller method: 50 lines.** If it's longer, extract a Service class.
- **Max file: 400 lines.** If it's longer, split it.
- **No business logic in controllers.** Controllers handle: request validation, calling a service, returning a response. That's it.
- **Use the ApiResponse trait** on every controller. Never return raw `response()->json()`.
- **Use BelongsToTenant** on every new tenant-scoped model.
- **Every new endpoint gets a feature test.** No exceptions.
- **No `any` type equivalents** — type-hint all method parameters and returns.

### Security Rules
- **Every route group needs role middleware.** Check `routes/api.php:281-287` for the gift card routes — they have NO role restriction. This is a known security bug. Fix it.
- **Never use `withoutGlobalScopes()` unless absolutely necessary.** It bypasses tenant isolation. If you must use it, add a comment explaining why.
- **No hardcoded secrets.** Use `.env` for all config values.
- **Validate all input.** Use FormRequest classes or inline validation.

---

## Patterns to Follow

### Response Pattern (REQUIRED)

```php
// In your controller — use the ApiResponse trait
use App\Http\Traits\ApiResponse;

class MyController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $items = MyModel::paginate(15);
        return $this->paginated($items);         // paginated list
    }

    public function store(Request $request)
    {
        $item = MyModel::create($validated);
        return $this->created($item);             // 201
    }

    public function show(MyModel $item)
    {
        return $this->success($item);             // 200
    }

    public function destroy(MyModel $item)
    {
        $item->delete();
        return $this->success(null, 'Deleted');   // 200
    }
}
```

Response envelope is always:
```json
{"success": true, "message": "...", "data": {...}}
{"success": false, "message": "...", "errors": {...}}
```

### Multi-Tenancy Pattern (REQUIRED for tenant-scoped models)

```php
// In your model
use App\Models\Concerns\BelongsToTenant;

class MyModel extends Model
{
    use BelongsToTenant;  // adds WHERE tenant_id = X to all queries
}
```

Tenant is resolved from the `X-Tenant` header via `EnsureTenant` middleware.

### Service Class Pattern (FOLLOW THIS)

```php
// Good example: app/Services/LedgerService.php
// Anti-pattern: app/Http/Controllers/Api/Tenant/SaleController.php (782 lines!)

// Create services in app/Services/
// Services contain business logic
// Controllers call services
// Services are testable without HTTP
```

### Test Pattern (FOLLOW THIS)

```php
// See: tests/Feature/AuthTest.php, tests/Feature/TenantIsolationTest.php

// 1. Set up tenant + user with role
// 2. Act as that user with JWT
// 3. Hit the API endpoint
// 4. Assert response structure + status code
// 5. Assert database state changed correctly
```

---

## Database: 51 Tables

### Core Business (13)
- `tenants` — root table (name, slug, plan, currency, vat_rate)
- `branches` — salon locations (tenant-scoped)
- `services` — catalog (duration, price, cost, deposit_amount)
- `service_categories` — grouping
- `staff` — employees linked to branch + optional user
- `staff_schedules` — weekly shifts (day_of_week, start/end)
- `customers` — clients (tenant-scoped, optional user_id)
- `customer_notes` — private notes
- `appointments` — bookings with status machine
- `appointment_services` — line items (service + price snapshot)
- `service_branch_availabilities` — weekly windows
- `service_branch_availability_overrides` — per-date exceptions

### Financial (10)
- `invoices` — POS transactions (subtotal, discount, tax, total, paid_amount, status)
- `invoice_items` — polymorphic (Service or Product)
- `payments` — per invoice (cash/card/transfer/gift_card)
- `expenses` — operating costs (soft-deletable)
- `ledger_entries` — append-only log (lockable by monthly close)
- `monthly_closings` — period lock (year+month, open/closed)
- `debts` — unpaid balances per customer
- `debt_payments` — payments against debts
- `debt_ledger_entries` — append-only debt log
- `debt_write_off_requests` — approval workflow

### Cash Management (3)
- `cash_drawers` — per-branch register
- `cash_drawer_sessions` — daily open/close with reconciliation
- `cash_movements` — cash in/out

### Compensation (3)
- `commission_rules` — staff earning rules (percentage/fixed/tiered)
- `commission_entries` — calculated per invoice
- `tip_allocations` — per staff per invoice

### Inventory (4)
- `products` — catalog (name, sku, price, cost, stock)
- `inventories` — stock per branch per product
- `stock_movements` — append-only audit trail
- `service_product_usages` — BOM: which products a service consumes

### Gift Cards (2)
- `gift_cards` — prepaid (code, balance, currency, status, expiry)
- `gift_card_transactions` — append-only (issue/redeem/void)

### Other (3)
- `subscriptions` — tenant subscription plan/status
- `audit_logs` — platform-wide action audit
- `otp_codes` — time-limited (6-digit, 10min TTL)

### System/Framework (13)
- `users`, `sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`
- Spatie: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`

---

## Appointment Status Machine

```
pending → scheduled → confirmed → checked_in → in_progress → completed
    |          |           |
    +-----+----+-----+-----+
          |          |
       cancelled   no_show
```

On completion: auto-deducts inventory via `service_product_usages` recipe.

---

## POS Sale Flow

```
1. Create Invoice + InvoiceItems (polymorphic: Service or Product)
2. Create Payment(s) — supports split payment
3. If partial → create Debt
4. Create LedgerEntry (revenue)
5. Calculate CommissionEntry from CommissionRules
6. Create TipAllocation if tips
7. Link to CashDrawerSession if cash
```

This entire flow is currently crammed into `SaleController::store()` (473 lines).
**Sprint 03 task: extract into PosCheckoutService.**

---

## Auth Flow

```
Login:    POST /api/login → email+password → JWT token
OTP:      POST /api/auth/request-otp → 6-digit → email (SMS stubbed)
Register: customer or salon-owner (salon-owner creates new Tenant)
Profile:  PATCH /api/profile, POST /api/profile/change-password
JWT:      HS256, 60min TTL, blacklist enabled
```

---

## Role Permissions

| Role | Access |
|------|--------|
| super_admin | All platform + all tenants |
| salon_owner | Own tenant: full control |
| manager | Own tenant: operations (no staff.manage, no settings) |
| receptionist | Own tenant: customers, appointments, POS, cash drawer view |
| staff | Own tenant: calendar (view/create/update), own earnings |
| customer | Own bookings across tenants, no tenant header needed |

---

## Route Structure (routes/api.php)

```
Public (no auth):         /api/login, /api/register, /api/otp/*, /api/public/*
Auth only (no tenant):    /api/me, /api/logout, /api/profile, /api/customer/*
Super Admin:              /api/admin/* (tenant CRUD, users, audit, reports)
Tenant (X-Tenant header): /api/branches, /api/services, /api/appointments, /api/sales, etc.
```

Rate limiting: `public` = 60/min, `otp` = 6/min.

---

## Known Tech Debt

| # | Issue | Priority | Fix | Status |
|---|-------|----------|-----|--------|
| 1 | ~~Gift card routes: no role middleware~~ | ~~P0~~ | ~~Add `role:salon_owner,manager`~~ | ✅ DONE — routes/api.php:429-439 wrap verify/redeem in `role:salon_owner,manager,receptionist` and CRUD/void in `role:salon_owner,manager` |
| 2 | SaleController: 782 lines | P1 | Extract PosCheckoutService, CommissionService, TipService | OPEN |
| 3 | PublicBookingController: 545 lines | P2 | Extract BookingService | OPEN |
| 4 | ~~No PHPStan/Psalm~~ | ~~P2~~ | ~~Add static analysis~~ | ✅ DONE Day 2 — `composer phpstan` runs at level 5; 413 errors grandfathered into baseline |
| 5 | ~~No pre-commit hooks~~ | ~~P2~~ | ~~Add Pint + PHPUnit hooks~~ | ✅ DONE Day 2 — `.githooks/pre-commit` |
| 6 | SQLite as default DB in config | P3 | Change config/database.php default to pgsql | OPEN |
| 7 | 2 models missing `BelongsToTenant` trait — `Subscription`, `AuditLog` | P1 | Add trait, migrate, regression-test | OPEN (audit finding Day 3) |
| 8 | 26 raw `response()->json()` calls bypass ApiResponse trait | P2 | Replace with `$this->success/error/etc.` | OPEN |
| 9 | 22 controller methods >50 lines | P2 | Extract services per CLAUDE.md rule | OPEN |
| 10 | ~~9/23 Resources cast IDs to string + UserResource uses camelCase tenantId~~ | ~~P0~~ | ~~Standardize on integer IDs + snake_case tenant_id~~ | ✅ DONE Day 3 — `tests/Feature/IdTypeConsistencyTest.php` locks it in. See `08-DOCS/problems-identified.md` Problem #1+#2 |

---

## Sprint 03 Tasks (April 7-13, 2026)

**Assigned by PM. Do in this order.**

| # | Task | Size | FR-ID | Acceptance Criteria |
|---|------|------|-------|---------------------|
| 1 | Fix gift card route security | S (15min) | N/A | Gift card routes wrapped in `role:salon_owner,manager` middleware. Test: customer role gets 403 on gift card endpoints |
| 2 | Extract PosCheckoutService | M (1-2 days) | N/A | SaleController::store() calls PosCheckoutService. Controller under 50 lines. All existing POS tests still pass |
| 3 | Add PHPStan level 5 | S (2 hours) | N/A | `composer phpstan` runs clean. Add to composer.json scripts |
| 4 | Discount/promo model + endpoints | L (3-5 days) | FR-D034 | Coupon model, CRUD endpoints, apply-to-invoice logic, feature tests |

---

## File Structure

```
app/
  Http/
    Controllers/Api/
      AuthController.php             — login, register, OTP, profile
      Public/PublicBookingController  — public salon search + booking
      SuperAdmin/                    — 8 admin controllers
      Tenant/                        — 28 business controllers
    Middleware/
      EnsureTenant.php               — X-Tenant header resolution
      CheckRole.php                  — role enforcement
      SuperAdminMiddleware.php       — super admin check
    Resources/                       — 21 API resource classes
    Traits/ApiResponse.php           — standardized JSON responses
  Models/
    Concerns/BelongsToTenant.php     — global scope + auto tenant_id
    (38 domain models)
  Services/
    AuditLogger.php                  — audit logging
    LedgerService.php                — period lock enforcement
    Notifications/                   — booking + SMS services
  Mail/
    OtpMail.php, BookingConfirmationMail.php
  TenantFinder/HeaderTenantFinder.php
routes/api.php                       — all API routes (298 lines)
database/
  migrations/                        — 60 migration files
  seeders/                           — 16 seeders for demo data
tests/
  Feature/                           — 20 feature test files
  Unit/                              — 1 example test
```

---

## How to Ask for Help

- Tag the PM in your commit message or PR description
- If blocked: say what you tried, what failed, what you need
- Before asking: check existing code for patterns — 90% of answers are in the codebase
- Run `./tools/dev.sh audit` before asking "is this code OK?" — it tells you objectively
- Run `python3 tools/triage.py <method> <path>` before asking "why is this endpoint failing?" — it gathers all the context

## Reference docs

- [`08-DOCS/problems-identified.md`](../08-DOCS/problems-identified.md) — **canonical tracker for known backend problems** (severity, status, resolution evidence)
- [`08-DOCS/swagger-api.md`](../08-DOCS/swagger-api.md) — Swagger/OpenAPI status, what changed since Captain, what's still needed
- [`08-DOCS/dev-toolkit-mechanics.md`](../08-DOCS/dev-toolkit-mechanics.md) — how the smoke runner / triage / audit / diff tools work internally
- [`13-TAKEOVER/swagger-fix-tracker.md`](../13-TAKEOVER/swagger-fix-tracker.md) — running work log, day-by-day verification proofs
- [`13-TAKEOVER/4-takeover-status.md`](../13-TAKEOVER/4-takeover-status.md) — risk register, ownership matrix, sequencing
- [`tools/README.md`](tools/README.md) — quick reference for the dev toolkit (this file is the canonical version)
