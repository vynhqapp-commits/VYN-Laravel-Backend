# Backend acceptance claim map (evidence-based)

Audited against backend implementation in `salon-saas-backend/`.

Legend:
- **Confirmed**: implemented + enforced at runtime (and ideally tested)
- **Partial**: exists in parts, but gaps remain (missing role(s), missing enforcement, or not generalized)
- **Missing**: no implementation present

## AC-1 Staff invitation works end-to-end
- **Status**: **Confirmed**
- **Evidence**
  - Controller: `app/Http/Controllers/Api/Tenant/StaffInvitationController.php`
  - Model/migration: `app/Models/StaffInvitation.php`, `database/migrations/2026_04_16_120000_create_staff_invitations_table.php`
  - Feature test: `tests/Feature/StaffInvitationWorkflowTest.php`

## AC-2 Multi-tenant SaaS isolation enforced
- **Status**: **Confirmed**
- **Evidence**
  - Tenant middleware: `app/Http/Middleware/EnsureTenant.php`
  - Tenant global scope: `app/Models/Concerns/BelongsToTenant.php`
  - Feature test: `tests/Feature/TenantIsolationTest.php`

## AC-3 RBAC complete (all SRS roles + granular permission enforcement)
- **Status**: **Partial**
- **Evidence**
  - Roles seeded (6 roles only): `database/seeders/RolesAndPermissionsSeeder.php`
  - Missing roles in seeder: **no `franchise_owner`, no `org_owner`**
  - Route-level role middleware is present (broad), but controller-level granular permission checks are not consistently used (many endpoints rely on role middleware alone).

## AC-4 ABAC complete (branch-level scoping enforced middleware-wide)
- **Status**: **Missing (enforcement gap)**
- **Evidence**
  - Example leak pattern: `app/Http/Controllers/Api/Tenant/AppointmentController.php`
    - `index()` accepts `branch_id` filter and applies it directly, without constraining it to the authenticated staff’s branch.
  - Centralized branch-scoping middleware: **not present** (no enforcement layer that injects/locks branch_id for staff-role users).

## AC-5 Multi-location per organization
- **Status**: **Confirmed**
- **Evidence**
  - Branch CRUD: `app/Http/Controllers/Api/Tenant/BranchController.php`
  - Branch endpoints in `docs/openapi-coverage-checklist.md` (`/api/branches`)

## AC-6 Franchise owner role + branch-scoped invitation
- **Status**: **Missing**
- **Evidence**
  - Role missing from seeding: `database/seeders/RolesAndPermissionsSeeder.php`
  - No ownership pivot table/model for franchise owners: **not found**
  - No franchise owner invitation controller/flow: **not found**
  - Franchise analytics endpoint exists but is not scoped to “owned branches” (role is not implemented): `app/Http/Controllers/Api/Tenant/FranchiseAnalyticsController.php`

## AC-7 Cross-role workflow (receptionist books appointment, staff sees it)
- **Status**: **Confirmed**
- **Evidence**
  - Appointments are shared tenant-wide; staff can read appointments via `AppointmentController@index` and filters.
  - Permissions seeded include `booking.view` for `staff` and receptionist has create/update permissions: `database/seeders/RolesAndPermissionsSeeder.php`

## AC-8 Generic approval workflow (receptionist deletions/refunds/overrides queued + expire)
- **Status**: **Partial**
- **Evidence**
  - A specific approval workflow exists for debt write-offs:
    - Controller: `app/Http/Controllers/Api/Tenant/DebtController.php` (`approveWriteOff`, `rejectWriteOff`)
    - Model/migration: `app/Models/DebtWriteOffRequest.php`, `database/migrations/2026_03_18_000008_create_debt_write_off_requests_table.php`
  - Appointment delete currently executes directly (no queue): `app/Http/Controllers/Api/Tenant/AppointmentController.php` (`destroy()`)
  - Sale refund currently executes directly (no queue): `app/Http/Controllers/Api/Tenant/SaleController.php` (`refund()`)
  - Generic `approval_requests` entity + expiry job: **not present**

## Architecture risk note (not an acceptance criterion, but delivery risk)
- `SaleController::store()` is a large “god method” and should be split into services for testability and safe iteration:
  - `app/Http/Controllers/Api/Tenant/SaleController.php`

