# Backend metrics reconciliation (canonical sources)

This note reconciles **backend reality** against repo docs that track delivery/coverage.

## Canonical sources (backend truth)

1. **Routes actually exposed**
   - `docs/openapi-coverage-checklist.md` (generated from `php artisan route:list --path=api --json`)
2. **Behavior that is verified**
   - `tests/Feature/*` (feature tests)
3. **CI truth**
   - `.github/workflows/backend-ci.yml` (what is enforced on PR/push)

## Secondary sources (useful, but can be stale)

- `docs/11-api-contracts.md` (contracts + “planned” list)
- `docs/00-repo-inventory.md` (snapshot of present/missing)
- Root `# VYN Feature Matrix — SRS V2 vs Deliver.md` (FR-by-FR tracking; **last updated 2026-04-04**)

## Conflicts found (doc vs backend reality)

### 1) “Planned/missing routes” that are **already implemented**

`docs/11-api-contracts.md` and `docs/00-repo-inventory.md` list the following as missing/planned, but they are present in `docs/openapi-coverage-checklist.md`:

- **Products**
  - `GET/POST/PATCH/DELETE /api/products`
- **Inventory**
  - `GET /api/inventory/{branch}`
  - `POST /api/inventory/stock`
  - `GET /api/reports/low-stock`
- **Cash drawer**
  - `GET /api/cash-drawers`
  - `POST /api/cash-drawers/open`
  - `POST /api/cash-drawers/{session}/transaction`
  - `POST /api/cash-drawers/{session}/close`
  - `POST /api/cash-drawers/{session}/approve`
- **Debt**
  - `GET /api/debts`
  - `GET /api/debts/aging-report`
  - `POST /api/debts/{debt}/payment`
  - `POST /api/debts/{debt}/write-off`
  - `GET /api/debts/write-off-requests`
  - `POST /api/debts/write-off-requests/{requestItem}/approve`
  - `POST /api/debts/write-off-requests/{requestItem}/reject`
- **Commission**
  - `GET /api/commissions`
  - `POST /api/commissions/rules`
  - `PUT /api/commissions/rules/{commission}`
  - `DELETE /api/commissions/rules/{commission}`
  - `GET /api/commissions/staff/{staff}/earnings`
- **Gift cards**
  - `GET/POST /api/gift-cards`
  - `POST /api/gift-cards/verify`
  - `POST /api/gift-cards/{card}/redeem`
  - `POST /api/gift-cards/{card}/void`
- **Franchise analytics**
  - `GET /api/analytics/franchise`
- **Monthly closing**
  - `GET /api/monthly-closings`
  - `POST /api/monthly-closings/close`
- **Customer self-service**
  - `GET /api/customer/bookings`
  - `GET /api/customer/bookings/{appointment}`
  - `PATCH /api/customer/bookings/{appointment}/cancel`
  - `POST /api/customer/bookings/{appointment}/rebook`
  - `PATCH /api/customer/bookings/{appointment}/reschedule`

Conclusion: those two docs should be treated as **historical context** unless refreshed from the route checklist.

### 2) FR delivery matrix internal inconsistencies (backend relevance)

The root matrix (`# VYN Feature Matrix — SRS V2 vs Deliver.md`) is valuable, but has at least one clear internal contradiction:

- **Analytics**
  - Section score shows **86%** (“Analytics Score: 6/7 (86%)”)
  - Overall summary row shows **43%** (“Analytics 43%”)

Until the matrix is refreshed, backend “module percent” claims should be grounded in:
- route coverage checklist (surface area), and
- feature tests (verified behavior), and
- code inspection for acceptance criteria (see `docs/backend-acceptance-claim-map.md`).

### 3) CI enforcement reality

`.github/workflows/backend-ci.yml` runs `php artisan test` but:
- does **not** enable coverage collection (`coverage: none`)
- does **not** run PHPStan
- does **not** run Pint

So any “quality gates” or “static analysis enforced” metric is **not currently backed by CI**.

