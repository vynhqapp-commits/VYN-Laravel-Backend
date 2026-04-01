<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SuperAdmin\TenantController;
use App\Http\Controllers\Api\SuperAdmin\PlatformReportController;
use App\Http\Controllers\Api\SuperAdmin\UserController as SuperAdminUserController;
use App\Http\Controllers\Api\SuperAdmin\RoleController as SuperAdminRoleController;
use App\Http\Controllers\Api\SuperAdmin\SubscriptionController as SuperAdminSubscriptionController;
use App\Http\Controllers\Api\SuperAdmin\AuditController as SuperAdminAuditController;
use App\Http\Controllers\Api\Public\PublicBookingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::post('login',       [AuthController::class, 'login']);
Route::post('register',    [AuthController::class, 'register']);
Route::post('otp/send',    [AuthController::class, 'sendOtp']);
Route::post('otp/verify',  [AuthController::class, 'verifyOtp']);

// Frontend-friendly /auth/* aliases
Route::prefix('auth')->group(function () {
    Route::post('login',                    [AuthController::class, 'login']);
    Route::post('register/customer',        [AuthController::class, 'registerCustomer']);
    Route::post('register/salon-owner',     [AuthController::class, 'registerSalonOwner']);
    Route::post('request-otp',              [AuthController::class, 'sendOtp'])->middleware('throttle:otp');
    Route::post('verify-otp',               [AuthController::class, 'verifyOtp']);
});

// Public booking (no auth, no tenant header required)
Route::prefix('public')->middleware('throttle:public')->group(function () {
    Route::get('salons',              [PublicBookingController::class, 'salons']);
    Route::get('salons/nearby',       [PublicBookingController::class, 'nearbySalons']);
    Route::get('salons/{slug}',       [PublicBookingController::class, 'salon']);
    Route::get('availability',        [PublicBookingController::class, 'availability']);
    Route::post('book',               [PublicBookingController::class, 'book']);
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes (all roles)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->group(function () {

    Route::get('me',       [AuthController::class, 'me']);
    Route::post('logout',  [AuthController::class, 'logout']);

    // Profile — all authenticated roles
    Route::patch('profile',                [AuthController::class, 'updateProfile']);
    Route::post('profile/change-password', [AuthController::class, 'changePassword']);

    /*
    |----------------------------------------------------------------------
    | Customer Booking Routes (auth only — no X-Tenant required)
    | Customers query across all tenants via withoutGlobalScopes()
    |----------------------------------------------------------------------
    */
    Route::middleware('role:customer')->prefix('customer')->group(function () {
        Route::get('bookings',                        [\App\Http\Controllers\Api\Tenant\CustomerBookingController::class, 'index']);
        Route::get('bookings/{appointment}',          [\App\Http\Controllers\Api\Tenant\CustomerBookingController::class, 'show']);
        Route::patch('bookings/{appointment}/reschedule', [\App\Http\Controllers\Api\Tenant\CustomerBookingController::class, 'reschedule']);
        Route::patch('bookings/{appointment}/cancel', [\App\Http\Controllers\Api\Tenant\CustomerBookingController::class, 'cancel']);
    });

    /*
    |----------------------------------------------------------------------
    | Super Admin Routes
    |----------------------------------------------------------------------
    */
    Route::middleware('super_admin')->prefix('admin')->group(function () {

        // Tenant management
        Route::get('tenants',                    [TenantController::class, 'index']);
        Route::post('tenants',                   [TenantController::class, 'store']);
        Route::get('tenants/{tenant}',           [TenantController::class, 'show']);
        Route::put('tenants/{tenant}',           [TenantController::class, 'update']);
        Route::patch('tenants/{tenant}',         [TenantController::class, 'update']);
        Route::delete('tenants/{tenant}',        [TenantController::class, 'destroy']);
        Route::patch('tenants/{tenant}/suspend', [TenantController::class, 'suspend']);
        Route::patch('tenants/{tenant}/activate',[TenantController::class, 'activate']);

        // Platform users
        Route::get('users', [SuperAdminUserController::class, 'index']);
        Route::post('users', [SuperAdminUserController::class, 'store']);
        Route::patch('users/{user}', [SuperAdminUserController::class, 'update']);
        Route::delete('users/{user}', [SuperAdminUserController::class, 'destroy']);

        // Roles & permissions (read-only)
        Route::get('roles', [SuperAdminRoleController::class, 'roles']);
        Route::get('permissions', [SuperAdminRoleController::class, 'permissions']);

        // Subscriptions (internal-only)
        Route::get('subscriptions', [SuperAdminSubscriptionController::class, 'index']);
        Route::patch('tenants/{tenant}/subscription', [SuperAdminSubscriptionController::class, 'upsertForTenant']);

        // Audit logs
        Route::get('audit', [SuperAdminAuditController::class, 'index']);

        // Platform reports
        Route::get('reports', [PlatformReportController::class, 'index']);
        Route::get('reports/financial', [PlatformReportController::class, 'financial']);
        Route::get('franchise/kpis', [PlatformReportController::class, 'franchiseKpis']);
    });

    /*
    |----------------------------------------------------------------------
    | Tenant Routes (requires X-Tenant header)
    | Accessible by: salon_owner, manager, staff, customer
    |----------------------------------------------------------------------
    */
    Route::middleware(['tenant', 'role:salon_owner,manager,staff,customer'])->group(function () {

        // Branches — salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::apiResource('branches', \App\Http\Controllers\Api\Tenant\BranchController::class);
            Route::post('salons/{salon}/photos', [\App\Http\Controllers\Api\Tenant\SalonPhotoController::class, 'store']);
        });

        // Services & Categories — salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::apiResource('service-categories', \App\Http\Controllers\Api\Tenant\ServiceCategoryController::class)
                ->except(['show']);
            Route::apiResource('services', \App\Http\Controllers\Api\Tenant\ServiceController::class);

            // Per-branch service availability (weekly + overrides)
            Route::get('services/{service}/availabilities', [\App\Http\Controllers\Api\Tenant\ServiceAvailabilityController::class, 'index']);
            Route::post('services/{service}/availabilities', [\App\Http\Controllers\Api\Tenant\ServiceAvailabilityController::class, 'store']);
            Route::patch('services/{service}/availabilities/{availability}', [\App\Http\Controllers\Api\Tenant\ServiceAvailabilityController::class, 'update']);
            Route::delete('services/{service}/availabilities/{availability}', [\App\Http\Controllers\Api\Tenant\ServiceAvailabilityController::class, 'destroy']);

            Route::get('services/{service}/availability-overrides', [\App\Http\Controllers\Api\Tenant\ServiceAvailabilityOverrideController::class, 'index']);
            Route::post('services/{service}/availability-overrides', [\App\Http\Controllers\Api\Tenant\ServiceAvailabilityOverrideController::class, 'store']);
            Route::patch('services/{service}/availability-overrides/{override}', [\App\Http\Controllers\Api\Tenant\ServiceAvailabilityOverrideController::class, 'update']);
            Route::delete('services/{service}/availability-overrides/{override}', [\App\Http\Controllers\Api\Tenant\ServiceAvailabilityOverrideController::class, 'destroy']);
        });

        // Products — salon_owner, manager (manage) + staff (view)
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::get('products', [\App\Http\Controllers\Api\Tenant\ProductController::class, 'index']);
            Route::get('products/{product}', [\App\Http\Controllers\Api\Tenant\ProductController::class, 'show']);
        });
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::post('products', [\App\Http\Controllers\Api\Tenant\ProductController::class, 'store']);
            Route::patch('products/{product}', [\App\Http\Controllers\Api\Tenant\ProductController::class, 'update']);
            Route::delete('products/{product}', [\App\Http\Controllers\Api\Tenant\ProductController::class, 'destroy']);
        });

        // Inventory — salon_owner, manager, staff
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::get('inventory/{branch}', [\App\Http\Controllers\Api\Tenant\InventoryController::class, 'byBranch']);
            Route::post('inventory/stock', [\App\Http\Controllers\Api\Tenant\InventoryController::class, 'adjust']);
        });

        // Staff — salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::apiResource('staff', \App\Http\Controllers\Api\Tenant\StaffController::class);
            Route::get('staff/{staff}/schedules',  [\App\Http\Controllers\Api\Tenant\ScheduleController::class, 'index']);
            Route::post('staff/{staff}/schedules', [\App\Http\Controllers\Api\Tenant\ScheduleController::class, 'store']);
        });

        // Customers — salon_owner, manager, staff
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::apiResource('customers', \App\Http\Controllers\Api\Tenant\CustomerController::class)
                ->except(['destroy']);
            Route::get('customers/{customer}/notes',  [\App\Http\Controllers\Api\Tenant\CustomerController::class, 'notes']);
            Route::post('customers/{customer}/notes', [\App\Http\Controllers\Api\Tenant\CustomerController::class, 'addNote']);

            // CRM tracking (packages, memberships, per-customer stats)
            Route::get('customers/{customer}/packages', [\App\Http\Controllers\Api\Tenant\CustomerCrmController::class, 'packages']);
            Route::get('customers/{customer}/memberships', [\App\Http\Controllers\Api\Tenant\CustomerCrmController::class, 'memberships']);
            Route::get('customers/{customer}/stats', [\App\Http\Controllers\Api\Tenant\CustomerCrmController::class, 'stats']);

            Route::post('customers/{customer}/packages/{package}/consume', [\App\Http\Controllers\Api\Tenant\CustomerCrmController::class, 'consumePackage']);
            Route::post('customers/{customer}/memberships/{membership}/renew', [\App\Http\Controllers\Api\Tenant\CustomerCrmController::class, 'renewMembership']);
        });

        // Appointments — salon_owner, manager, staff
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::apiResource('appointments', \App\Http\Controllers\Api\Tenant\AppointmentController::class);
        });
        // POS / Sales — salon_owner, manager, staff
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::get('sales', [\App\Http\Controllers\Api\Tenant\SaleController::class, 'index']);
            Route::post('sales', [\App\Http\Controllers\Api\Tenant\SaleController::class, 'store']);
            Route::get('sales/{sale}', [\App\Http\Controllers\Api\Tenant\SaleController::class, 'show']);
            Route::post('sales/{sale}/receipt/notify', [\App\Http\Controllers\Api\Tenant\SaleController::class, 'notifyReceipt']);
            Route::post('sales/{sale}/refund', [\App\Http\Controllers\Api\Tenant\SaleController::class, 'refund']);
        });

        // Cash drawer — salon_owner, manager, staff(view) but only owner/manager can mutate
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::get('cash-drawers', [\App\Http\Controllers\Api\Tenant\CashDrawerController::class, 'index']);
        });
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::post('cash-drawers/open', [\App\Http\Controllers\Api\Tenant\CashDrawerController::class, 'open']);
            Route::post('cash-drawers/{session}/transaction', [\App\Http\Controllers\Api\Tenant\CashDrawerController::class, 'transaction']);
            Route::post('cash-drawers/{session}/close', [\App\Http\Controllers\Api\Tenant\CashDrawerController::class, 'close']);
            Route::post('cash-drawers/{session}/approve', [\App\Http\Controllers\Api\Tenant\CashDrawerController::class, 'approve']);
        });

        // Expenses — salon_owner, manager, staff
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::get('expenses', [\App\Http\Controllers\Api\Tenant\ExpenseController::class, 'index']);
            Route::post('expenses', [\App\Http\Controllers\Api\Tenant\ExpenseController::class, 'store']);
            Route::patch('expenses/{expense}', [\App\Http\Controllers\Api\Tenant\ExpenseController::class, 'update']);
            Route::delete('expenses/{expense}', [\App\Http\Controllers\Api\Tenant\ExpenseController::class, 'destroy']);
        });

        // Debts — salon_owner, manager, staff
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::get('debts', [\App\Http\Controllers\Api\Tenant\DebtController::class, 'index']);
            Route::get('debts/aging-report', [\App\Http\Controllers\Api\Tenant\DebtController::class, 'agingReport']);
            Route::get('debts/write-off-requests', [\App\Http\Controllers\Api\Tenant\DebtController::class, 'writeOffRequests']);
            Route::post('debts/{debt}/payment', [\App\Http\Controllers\Api\Tenant\DebtController::class, 'addPayment']);
            Route::post('debts/{debt}/write-off', [\App\Http\Controllers\Api\Tenant\DebtController::class, 'writeOff']);
        });
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::post('debts/write-off-requests/{requestItem}/approve', [\App\Http\Controllers\Api\Tenant\DebtController::class, 'approveWriteOff']);
            Route::post('debts/write-off-requests/{requestItem}/reject', [\App\Http\Controllers\Api\Tenant\DebtController::class, 'rejectWriteOff']);
        });

        // Reports — salon_owner, manager, staff
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::get('reports/profit-loss', [\App\Http\Controllers\Api\Tenant\ReportController::class, 'profitLoss']);
            Route::get('reports/profit-loss/export', [\App\Http\Controllers\Api\Tenant\ReportController::class, 'profitLossExport']);
            Route::get('reports/vat', [\App\Http\Controllers\Api\Tenant\ReportController::class, 'vat']);
            Route::get('reports/vat/export', [\App\Http\Controllers\Api\Tenant\ReportController::class, 'vatExport']);
            Route::get('reports/payment-breakdown', [\App\Http\Controllers\Api\Tenant\ReportController::class, 'paymentBreakdown']);
            Route::get('reports/payment-breakdown/export', [\App\Http\Controllers\Api\Tenant\ReportController::class, 'paymentBreakdownExport']);
            Route::get('reports/inventory-movement', [\App\Http\Controllers\Api\Tenant\ReportController::class, 'inventoryMovement']);
            Route::get('reports/low-stock', [\App\Http\Controllers\Api\Tenant\ReportController::class, 'lowStock']);
            Route::get('reports/margins', [\App\Http\Controllers\Api\Tenant\ReportController::class, 'margins']);
        });

        // Monthly Closing — salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::get('monthly-closings', [\App\Http\Controllers\Api\Tenant\MonthlyClosingController::class, 'index']);
            Route::post('monthly-closings/close', [\App\Http\Controllers\Api\Tenant\MonthlyClosingController::class, 'close']);
        });

        // Franchise / Multi-location analytics — salon_owner only
        Route::middleware('role:salon_owner')->group(function () {
            Route::get('analytics/franchise', [\App\Http\Controllers\Api\Tenant\FranchiseAnalyticsController::class, 'kpis']);
        });

        // Commissions — salon_owner, manager, staff
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::get('commissions', [\App\Http\Controllers\Api\Tenant\CommissionController::class, 'index']);
            Route::post('commissions/rules', [\App\Http\Controllers\Api\Tenant\CommissionController::class, 'store']);
            Route::put('commissions/rules/{commission}', [\App\Http\Controllers\Api\Tenant\CommissionController::class, 'update']);
            Route::delete('commissions/rules/{commission}', [\App\Http\Controllers\Api\Tenant\CommissionController::class, 'destroy']);
            Route::get('commissions/staff/{staff}/earnings', [\App\Http\Controllers\Api\Tenant\CommissionController::class, 'staffEarnings']);
            Route::get('commissions/{commission}', [\App\Http\Controllers\Api\Tenant\CommissionController::class, 'show']);
        });

        // Gift Cards
        Route::post('gift-cards/verify', [\App\Http\Controllers\Api\Tenant\GiftCardController::class, 'verify']);
        Route::get('gift-cards', [\App\Http\Controllers\Api\Tenant\GiftCardController::class, 'index']);
        Route::post('gift-cards', [\App\Http\Controllers\Api\Tenant\GiftCardController::class, 'store']);
        Route::get('gift-cards/{card}', [\App\Http\Controllers\Api\Tenant\GiftCardController::class, 'show']);
        Route::post('gift-cards/{card}/redeem', [\App\Http\Controllers\Api\Tenant\GiftCardController::class, 'redeem']);
        Route::post('gift-cards/{card}/void', [\App\Http\Controllers\Api\Tenant\GiftCardController::class, 'void']);

        // Invoices
        Route::get('invoices', [\App\Http\Controllers\Api\Tenant\InvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [\App\Http\Controllers\Api\Tenant\InvoiceController::class, 'show']);
        Route::post('invoices/{invoice}/void', [\App\Http\Controllers\Api\Tenant\InvoiceController::class, 'void']);

        // Ledger
        Route::get('ledger', [\App\Http\Controllers\Api\Tenant\LedgerController::class, 'index']);
    });
});
