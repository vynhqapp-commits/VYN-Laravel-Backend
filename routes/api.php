<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LoginFallbackController;
use App\Http\Controllers\Api\Public\PublicBookingController;
use App\Http\Controllers\Api\SuperAdmin\AuditController as SuperAdminAuditController;
use App\Http\Controllers\Api\SuperAdmin\PlatformReportController;
use App\Http\Controllers\Api\SuperAdmin\RoleController as SuperAdminRoleController;
use App\Http\Controllers\Api\SuperAdmin\SubscriptionController as SuperAdminSubscriptionController;
use App\Http\Controllers\Api\SuperAdmin\TenantController;
use App\Http\Controllers\Api\SuperAdmin\UserController as SuperAdminUserController;
use App\Http\Controllers\Api\Tenant\AppointmentController;
use App\Http\Controllers\Api\Tenant\ApprovalRequestController;
use App\Http\Controllers\Api\Tenant\BranchController;
use App\Http\Controllers\Api\Tenant\CashDrawerController;
use App\Http\Controllers\Api\Tenant\CommissionController;
use App\Http\Controllers\Api\Tenant\CouponController;
use App\Http\Controllers\Api\Tenant\CustomerBookingController;
use App\Http\Controllers\Api\Tenant\CustomerController;
use App\Http\Controllers\Api\Tenant\CustomerCrmController;
use App\Http\Controllers\Api\Tenant\CustomerFavoriteController;
use App\Http\Controllers\Api\Tenant\CustomerReviewController;
use App\Http\Controllers\Api\Tenant\DebtController;
use App\Http\Controllers\Api\Tenant\ExpenseController;
use App\Http\Controllers\Api\Tenant\FranchiseAnalyticsController;
use App\Http\Controllers\Api\Tenant\FranchiseOwnerInvitationController;
use App\Http\Controllers\Api\Tenant\GiftCardController;
use App\Http\Controllers\Api\Tenant\InventoryController;
use App\Http\Controllers\Api\Tenant\InvoiceController;
use App\Http\Controllers\Api\Tenant\LedgerController;
use App\Http\Controllers\Api\Tenant\MonthlyClosingController;
use App\Http\Controllers\Api\Tenant\PackageMembershipCatalogController;
use App\Http\Controllers\Api\Tenant\ProductController;
use App\Http\Controllers\Api\Tenant\ReportController;
use App\Http\Controllers\Api\Tenant\ReviewModerationController;
use App\Http\Controllers\Api\Tenant\SaleController;
use App\Http\Controllers\Api\Tenant\SalonPhotoController;
use App\Http\Controllers\Api\Tenant\ScheduleController;
use App\Http\Controllers\Api\Tenant\ServiceAddOnController;
use App\Http\Controllers\Api\Tenant\ServiceAvailabilityController;
use App\Http\Controllers\Api\Tenant\ServiceAvailabilityOverrideController;
use App\Http\Controllers\Api\Tenant\ServiceCategoryController;
use App\Http\Controllers\Api\Tenant\ServiceController;
use App\Http\Controllers\Api\Tenant\StaffController;
use App\Http\Controllers\Api\Tenant\StaffInvitationController;
use App\Http\Controllers\Api\Tenant\StaffTimeController;
use App\Http\Controllers\Api\Tenant\TenantSettingsController;
use App\Http\Controllers\Api\Tenant\TimeBlockController;
use App\Http\Controllers\Api\Tenant\TimeOffRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::post('login', [AuthController::class, 'login'])->name('login');
Route::post('register', [AuthController::class, 'register']);
Route::post('otp/send', [AuthController::class, 'sendOtp']);
Route::post('otp/verify', [AuthController::class, 'verifyOtp']);

// Sentinel GET /login — POST above is the real login endpoint (named
// 'login'); this GET counterpart catches clients that follow the 302
// redirect Laravel issues for unauthenticated non-JSON requests, so
// they receive a 401 JSON envelope instead of a 500 HTML error page.
Route::get('login', LoginFallbackController::class)->name('login.fallback');

// Frontend-friendly /auth/* aliases
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register/customer', [AuthController::class, 'registerCustomer']);
    Route::post('register/salon-owner', [AuthController::class, 'registerSalonOwner']);
    Route::post('request-otp', [AuthController::class, 'sendOtp'])->middleware('throttle:otp');
    Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('google', [AuthController::class, 'googleAuth']);
    Route::post('staff-invitations/accept', [StaffInvitationController::class, 'accept']);
    Route::post('franchise-owner-invitations/accept', [FranchiseOwnerInvitationController::class, 'accept']);
});

// Public booking (no auth, no tenant header required)
Route::prefix('public')->middleware('throttle:public')->group(function () {
    Route::get('salons', [PublicBookingController::class, 'salons']);
    Route::get('salons/nearby', [PublicBookingController::class, 'nearbySalons']);
    Route::get('salons/{slug}', [PublicBookingController::class, 'salon']);
    Route::get('availability', [PublicBookingController::class, 'availability']);
    Route::post('book', [PublicBookingController::class, 'book']);
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes (all roles)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->group(function () {

    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    // Profile — all authenticated roles
    Route::patch('profile', [AuthController::class, 'updateProfile']);
    Route::post('profile/change-password', [AuthController::class, 'changePassword']);

    // Salon profile — salon_owner only (legacy aliases)
    Route::middleware('role:salon_owner')->group(function () {
        Route::get('salon/profile', [AuthController::class, 'salonProfile']);
        Route::patch('salon/profile', [AuthController::class, 'updateSalonProfile']);
    });

    /*
    |----------------------------------------------------------------------
    | Customer Booking Routes (auth only — no X-Tenant required)
    | Customers query across all tenants via withoutGlobalScopes()
    |----------------------------------------------------------------------
    */
    Route::middleware('role:customer')->prefix('customer')->group(function () {
        Route::get('bookings', [CustomerBookingController::class, 'index']);
        Route::get('bookings/{appointment}', [CustomerBookingController::class, 'show']);
        Route::patch('bookings/{appointment}/reschedule', [CustomerBookingController::class, 'reschedule']);
        Route::post('bookings/{appointment}/rebook', [CustomerBookingController::class, 'rebook']);
        Route::patch('bookings/{appointment}/cancel', [CustomerBookingController::class, 'cancel']);
        Route::post('bookings/{appointment}/review', [CustomerReviewController::class, 'store']);
        Route::get('reviews', [CustomerReviewController::class, 'index']);
        Route::get('favorites', [CustomerFavoriteController::class, 'index']);
        Route::post('favorites', [CustomerFavoriteController::class, 'store']);
        Route::delete('favorites/{salon}', [CustomerFavoriteController::class, 'destroy']);
    });

    /*
    |----------------------------------------------------------------------
    | Super Admin Routes
    |----------------------------------------------------------------------
    */
    Route::middleware('super_admin')->prefix('admin')->group(function () {

        // Tenant management
        Route::get('tenants', [TenantController::class, 'index']);
        Route::post('tenants', [TenantController::class, 'store']);
        Route::get('tenants/{tenant}', [TenantController::class, 'show']);
        Route::put('tenants/{tenant}', [TenantController::class, 'update']);
        Route::patch('tenants/{tenant}', [TenantController::class, 'update']);
        Route::delete('tenants/{tenant}', [TenantController::class, 'destroy']);
        Route::patch('tenants/{tenant}/suspend', [TenantController::class, 'suspend']);
        Route::patch('tenants/{tenant}/activate', [TenantController::class, 'activate']);

        // Platform users
        Route::get('users', [SuperAdminUserController::class, 'index']);
        Route::post('users', [SuperAdminUserController::class, 'store']);
        Route::patch('users/{user}', [SuperAdminUserController::class, 'update']);
        Route::delete('users/{user}', [SuperAdminUserController::class, 'destroy']);
        Route::get('users/{user}/permissions', [SuperAdminUserController::class, 'permissions']);
        Route::put('users/{user}/permissions', [SuperAdminUserController::class, 'syncPermissions']);

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
    | Accessible by: salon_owner, manager, receptionist, staff, customer
    |----------------------------------------------------------------------
    */
    Route::middleware(['tenant', 'staff_branch', 'role:salon_owner,org_owner,franchise_owner,manager,receptionist,staff,customer'])->group(function () {

        // Tenant (salon) settings — read: salon staff; write: owner, manager
        Route::middleware('role:salon_owner,manager,receptionist,staff')->group(function () {
            Route::get('settings', [TenantSettingsController::class, 'index']);
        });
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::patch('settings', [TenantSettingsController::class, 'update']);
        });

        // Branches — read: salon staff, write: salon_owner, manager
        Route::middleware('role:salon_owner,manager,receptionist,staff')->group(function () {
            Route::get('branches', [BranchController::class, 'index']);
            Route::get('branches/{branch}', [BranchController::class, 'show']);
        });
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::apiResource('branches', BranchController::class)
                ->except(['index', 'show']);
            Route::post('salons/{salon}/photos', [SalonPhotoController::class, 'store']);
            Route::get('reviews', [ReviewModerationController::class, 'index']);
            Route::patch('reviews/{review}/moderate', [ReviewModerationController::class, 'moderate']);
        });

        // Services & Categories — read: all salon roles; write: salon_owner, manager
        Route::middleware('role:salon_owner,manager,receptionist,staff')->group(function () {
            Route::get('service-categories', [ServiceCategoryController::class, 'index']);
            Route::get('services', [ServiceController::class, 'index']);
            Route::get('services/{service}', [ServiceController::class, 'show']);
        });
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::apiResource('service-categories', ServiceCategoryController::class)
                ->except(['show', 'index']);
            Route::apiResource('services', ServiceController::class)
                ->except(['index', 'show', 'destroy']);

            // Per-branch service availability (weekly + overrides)
            Route::get('services/{service}/availabilities', [ServiceAvailabilityController::class, 'index']);
            Route::post('services/{service}/availabilities', [ServiceAvailabilityController::class, 'store']);
            Route::patch('services/{service}/availabilities/{availability}', [ServiceAvailabilityController::class, 'update']);
            Route::delete('services/{service}/availabilities/{availability}', [ServiceAvailabilityController::class, 'destroy']);

            // Service add-ons
            Route::get('services/{service}/add-ons', [ServiceAddOnController::class, 'index']);
            Route::post('services/{service}/add-ons', [ServiceAddOnController::class, 'store']);
            Route::patch('services/{service}/add-ons/{addOn}', [ServiceAddOnController::class, 'update']);
            Route::delete('services/{service}/add-ons/{addOn}', [ServiceAddOnController::class, 'destroy']);

            Route::get('services/{service}/availability-overrides', [ServiceAvailabilityOverrideController::class, 'index']);
            Route::post('services/{service}/availability-overrides', [ServiceAvailabilityOverrideController::class, 'store']);
            Route::patch('services/{service}/availability-overrides/{override}', [ServiceAvailabilityOverrideController::class, 'update']);
            Route::delete('services/{service}/availability-overrides/{override}', [ServiceAvailabilityOverrideController::class, 'destroy']);
        });
        Route::middleware('role:salon_owner,manager,receptionist,staff')->group(function () {
            Route::delete('services/{service}', [ServiceController::class, 'destroy']);
        });

        // Catalog: Package & Membership templates — read: all salon roles; write: salon_owner, manager
        Route::middleware('role:salon_owner,manager,receptionist,staff')->group(function () {
            Route::get('catalog/packages', [PackageMembershipCatalogController::class, 'indexPackages']);
            Route::get('catalog/memberships', [PackageMembershipCatalogController::class, 'indexMemberships']);
        });
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::post('catalog/packages', [PackageMembershipCatalogController::class, 'storePackage']);
            Route::put('catalog/packages/{id}', [PackageMembershipCatalogController::class, 'updatePackage']);
            Route::delete('catalog/packages/{id}', [PackageMembershipCatalogController::class, 'destroyPackage']);
            Route::post('catalog/memberships', [PackageMembershipCatalogController::class, 'storeMembership']);
            Route::put('catalog/memberships/{id}', [PackageMembershipCatalogController::class, 'updateMembership']);
            Route::delete('catalog/memberships/{id}', [PackageMembershipCatalogController::class, 'destroyMembership']);
        });

        // Products — salon_owner, manager (manage) + receptionist, staff (view)
        Route::middleware('role:salon_owner,manager,receptionist,staff')->group(function () {
            Route::get('products', [ProductController::class, 'index']);
            Route::get('products/{product}', [ProductController::class, 'show']);
        });
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::post('products', [ProductController::class, 'store']);
            Route::patch('products/{product}', [ProductController::class, 'update']);
        });
        Route::middleware('role:salon_owner,manager,receptionist,staff')->group(function () {
            Route::delete('products/{product}', [ProductController::class, 'destroy']);
        });

        // Inventory — salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::get('inventory/{branch}/movements', [InventoryController::class, 'movements']);
            Route::get('inventory/{branch}', [InventoryController::class, 'byBranch']);
            Route::post('inventory/stock', [InventoryController::class, 'adjust']);
        });

        // Staff — salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::get('staff/performance', [StaffController::class, 'performance']);
            Route::apiResource('staff', StaffController::class);
            Route::get('staff-invitations', [StaffInvitationController::class, 'index']);
            Route::post('staff-invitations', [StaffInvitationController::class, 'store']);
            Route::post('staff-invitations/{staffInvitation}/resend', [StaffInvitationController::class, 'resend']);
            Route::post('staff-invitations/{staffInvitation}/revoke', [StaffInvitationController::class, 'revoke']);
            Route::get('staff/{staff}/schedules', [ScheduleController::class, 'index']);
            Route::post('staff/{staff}/schedules', [ScheduleController::class, 'store']);
        });

        // Franchise owner invitations — salon_owner, org_owner
        Route::middleware('role:salon_owner,org_owner')->group(function () {
            Route::post('franchise-owner-invitations', [FranchiseOwnerInvitationController::class, 'store']);
        });
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::get('staff-time-entries', [StaffTimeController::class, 'index']);
            Route::post('staff-time-entries/clock-in', [StaffTimeController::class, 'clockIn']);
            Route::post('staff-time-entries/{entry}/clock-out', [StaffTimeController::class, 'clockOut']);
        });

        // Customers — salon_owner, manager, receptionist
        Route::middleware('role:salon_owner,manager,receptionist')->group(function () {
            Route::apiResource('customers', CustomerController::class)
                ->except(['destroy']);
            Route::get('customers/{customer}/notes', [CustomerController::class, 'notes']);
            Route::post('customers/{customer}/notes', [CustomerController::class, 'addNote']);

            // CRM tracking (packages, memberships, per-customer stats)
            Route::get('customers/{customer}/packages', [CustomerCrmController::class, 'packages']);
            Route::get('customers/{customer}/memberships', [CustomerCrmController::class, 'memberships']);
            Route::get('customers/{customer}/stats', [CustomerCrmController::class, 'stats']);

            Route::post('customers/{customer}/packages/{package}/consume', [CustomerCrmController::class, 'consumePackage']);
            Route::post('customers/{customer}/memberships/{membership}/renew', [CustomerCrmController::class, 'renewMembership']);
            Route::patch('customers/{customer}/memberships/{membership}/auto-renew', [CustomerCrmController::class, 'toggleAutoRenew']);
        });

        // Appointments — salon_owner, manager, receptionist, staff (staff: own calendar only)
        Route::middleware('role:salon_owner,manager,receptionist,staff')->group(function () {
            Route::apiResource('appointments', AppointmentController::class)
                ->except(['destroy']);
        });
        Route::middleware('role:salon_owner,manager,receptionist,staff')->group(function () {
            Route::delete('appointments/{appointment}', [AppointmentController::class, 'destroy']);
        });
        // Time blocks — salon_owner, manager, receptionist (block calendar / staff time)
        Route::middleware('role:salon_owner,manager,receptionist')->group(function () {
            Route::get('time-blocks', [TimeBlockController::class, 'index']);
            Route::post('time-blocks', [TimeBlockController::class, 'store']);
            Route::patch('time-blocks/{timeBlock}', [TimeBlockController::class, 'update']);
        });
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::delete('time-blocks/{timeBlock}', [TimeBlockController::class, 'destroy']);
        });
        // POS / Sales — salon_owner, manager, receptionist
        Route::middleware('role:salon_owner,manager,receptionist')->group(function () {
            Route::get('sales', [SaleController::class, 'index']);
            Route::post('sales', [SaleController::class, 'store']);
            Route::get('sales/{sale}', [SaleController::class, 'show']);
            Route::post('sales/{sale}/receipt/notify', [SaleController::class, 'notifyReceipt']);
            Route::post('sales/{sale}/refund', [SaleController::class, 'refund']);
        });

        // Coupons — salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::get('coupons', [CouponController::class, 'index']);
            Route::post('coupons', [CouponController::class, 'store']);
            Route::get('coupons/{coupon}', [CouponController::class, 'show']);
            Route::patch('coupons/{coupon}', [CouponController::class, 'update']);
            Route::delete('coupons/{coupon}', [CouponController::class, 'destroy']);
        });

        // Cash drawer — salon_owner, manager, receptionist (view) but only owner/manager can mutate
        Route::middleware('role:salon_owner,manager,receptionist')->group(function () {
            Route::get('cash-drawers', [CashDrawerController::class, 'index']);
        });
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::post('cash-drawers/open', [CashDrawerController::class, 'open']);
            Route::post('cash-drawers/{session}/transaction', [CashDrawerController::class, 'transaction']);
            Route::post('cash-drawers/{session}/close', [CashDrawerController::class, 'close']);
            Route::post('cash-drawers/{session}/approve', [CashDrawerController::class, 'approve']);
        });

        // Expenses — salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::get('expenses', [ExpenseController::class, 'index']);
            Route::post('expenses', [ExpenseController::class, 'store']);
            Route::patch('expenses/{expense}', [ExpenseController::class, 'update']);
            Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy']);
        });

        // Debts — salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::get('debts', [DebtController::class, 'index']);
            Route::get('debts/aging-report', [DebtController::class, 'agingReport']);
            Route::get('debts/write-off-requests', [DebtController::class, 'writeOffRequests']);
            Route::post('debts/{debt}/payment', [DebtController::class, 'addPayment']);
            Route::post('debts/{debt}/write-off', [DebtController::class, 'writeOff']);
        });

        // Approval requests — salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::get('approval-requests', [ApprovalRequestController::class, 'index']);
            Route::post('approval-requests/{approvalRequest}/approve', [ApprovalRequestController::class, 'approve']);
            Route::post('approval-requests/{approvalRequest}/reject', [ApprovalRequestController::class, 'reject']);
        });
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::post('debts/write-off-requests/{requestItem}/approve', [DebtController::class, 'approveWriteOff']);
            Route::post('debts/write-off-requests/{requestItem}/reject', [DebtController::class, 'rejectWriteOff']);
        });

        // Reports — salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::get('reports/profit-loss', [ReportController::class, 'profitLoss']);
            Route::get('reports/profit-loss/export', [ReportController::class, 'profitLossExport']);
            Route::get('reports/vat', [ReportController::class, 'vat']);
            Route::get('reports/vat/export', [ReportController::class, 'vatExport']);
            Route::get('reports/payment-breakdown', [ReportController::class, 'paymentBreakdown']);
            Route::get('reports/payment-breakdown/export', [ReportController::class, 'paymentBreakdownExport']);
            Route::get('reports/inventory-movement', [ReportController::class, 'inventoryMovement']);
            Route::get('reports/low-stock', [ReportController::class, 'lowStock']);
            Route::get('reports/margins', [ReportController::class, 'margins']);
            // Analytics (FRD072/073/074)
            Route::get('reports/service-popularity', [ReportController::class, 'servicePopularity']);
            Route::get('reports/client-retention', [ReportController::class, 'clientRetention']);
            Route::get('reports/no-show-trends', [ReportController::class, 'noShowTrends']);
        });

        // Monthly Closing — salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::get('monthly-closings', [MonthlyClosingController::class, 'index']);
            Route::post('monthly-closings/close', [MonthlyClosingController::class, 'close']);
        });

        // Franchise / Multi-location analytics — salon_owner, org_owner, franchise_owner
        Route::middleware('role:salon_owner,org_owner,franchise_owner')->group(function () {
            Route::get('analytics/franchise', [FranchiseAnalyticsController::class, 'kpis']);
        });

        // Commissions — rule management: salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::get('commissions', [CommissionController::class, 'index']);
            Route::post('commissions/rules', [CommissionController::class, 'store']);
            Route::put('commissions/rules/{commission}', [CommissionController::class, 'update']);
            Route::delete('commissions/rules/{commission}', [CommissionController::class, 'destroy']);
            Route::get('commissions/{commission}', [CommissionController::class, 'show']);
        });
        // Staff earnings — salon_owner, manager (any staff), staff (own only, enforced in controller)
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::get('commissions/staff/{staff}/earnings', [CommissionController::class, 'staffEarnings']);
        });
        // Time-off requests — staff submit/view own, managers/owners review all
        Route::middleware('role:salon_owner,manager,staff')->group(function () {
            Route::get('time-off-requests', [TimeOffRequestController::class, 'index']);
            Route::post('time-off-requests', [TimeOffRequestController::class, 'store']);
        });
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::patch('time-off-requests/{requestItem}/status', [TimeOffRequestController::class, 'updateStatus']);
        });

        // Gift cards — register POST verify before gift-cards/{card} (literal segment first)
        Route::middleware('role:salon_owner,manager,receptionist')->group(function () {
            Route::post('gift-cards/verify', [GiftCardController::class, 'verify']);
            Route::post('gift-cards/{card}/redeem', [GiftCardController::class, 'redeem']);
        });
        // Gift cards — list/issue/detail/void: salon_owner, manager
        Route::middleware('role:salon_owner,manager')->group(function () {
            Route::get('gift-cards', [GiftCardController::class, 'index']);
            Route::post('gift-cards', [GiftCardController::class, 'store']);
            Route::get('gift-cards/{card}', [GiftCardController::class, 'show']);
            Route::post('gift-cards/{card}/void', [GiftCardController::class, 'void']);
        });

        // Invoices
        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);

        // Ledger
        Route::get('ledger', [LedgerController::class, 'index']);
    });
});
