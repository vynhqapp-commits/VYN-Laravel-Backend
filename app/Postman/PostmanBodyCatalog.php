<?php

namespace App\Postman;

/**
 * Request examples keyed by Laravel route action "Fqcn@method".
 * Used by {@see PostmanCollectionGenerator}.
 */
final class PostmanBodyCatalog
{
    /**
     * @return array{raw?: string, mode?: 'json'|'formdata'|'none', formdata?: list<array<string, mixed>>, query?: list<array{key: string, value: string, disabled?: bool}>, note?: string}
     */
    public static function forAction(string $action, string $method): array
    {
        $method = strtoupper($method);
        $q = self::queryByAction();
        if ($method === 'GET' && isset($q[$action])) {
            return ['query' => $q[$action], 'mode' => 'none'];
        }

        $b = self::writeBodies();
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && isset($b[$action])) {
            return $b[$action];
        }

        if ($method === 'DELETE') {
            return ['mode' => 'none', 'note' => 'No request body.'];
        }

        return [];
    }

    /**
     * @return array<string, list<array{key: string, value: string, disabled?: bool}>>
     */
    private static function queryByAction(): array
    {
        $p = fn (string $k, string $v = '', bool $d = false) => ['key' => $k, 'value' => $v, 'disabled' => $d];

        return [
            'App\Http\Controllers\Api\Public\PublicBookingController@salons' => [
                $p('search', ''),
                $p('page', '1'),
                $p('per_page', '12'),
                $p('price_min', ''),
                $p('price_max', ''),
                $p('rating_min', ''),
                $p('availability', '2026-04-20'),
                $p('gender_preference', 'unisex'),
            ],
            'App\Http\Controllers\Api\Public\PublicBookingController@nearbySalons' => [
                $p('lat', '33.8938'),
                $p('lng', '35.5018'),
                $p('radius_km', '10'),
                $p('page', '1'),
                $p('per_page', '24'),
                $p('search', ''),
            ],
            'App\Http\Controllers\Api\Public\PublicBookingController@availability' => [
                $p('branch_id', '1'),
                $p('service_id', '1'),
                $p('date', '2026-04-20'),
            ],
            'App\Http\Controllers\Api\Tenant\BranchController@index' => [
                $p('include_inactive', 'false'),
                $p('q', ''),
            ],
            'App\Http\Controllers\Api\Tenant\AppointmentController@index' => [
                $p('branch_id', ''),
                $p('staff_id', ''),
                $p('date', ''),
                $p('from', ''),
                $p('to', ''),
                $p('status', 'scheduled'),
            ],
            'App\Http\Controllers\Api\Tenant\SaleController@index' => [
                $p('branch_id', ''),
                $p('from', '2026-04-01'),
                $p('to', '2026-04-30'),
            ],
            'App\Http\Controllers\Api\Tenant\StaffInvitationController@index' => [
                $p('status', 'pending'),
            ],
            'App\Http\Controllers\Api\Tenant\DebtController@index' => [
                $p('customer_id', '1'),
            ],
            'App\Http\Controllers\Api\Tenant\DebtController@writeOffRequests' => [
                $p('status', 'pending'),
            ],
            'App\Http\Controllers\Api\Tenant\ReviewModerationController@index' => [
                $p('status', 'pending'),
                $p('per_page', '20'),
            ],
            'App\Http\Controllers\Api\Tenant\CashDrawerController@index' => [
                $p('branch_id', '1'),
                $p('status', 'open'),
            ],
            'App\Http\Controllers\Api\Tenant\InvoiceController@index' => [
                $p('status', ''),
                $p('search', ''),
                $p('from', ''),
                $p('to', ''),
            ],
            'App\Http\Controllers\Api\SuperAdmin\AuditController@index' => [
                $p('tenant_id', ''),
                $p('user_id', ''),
                $p('action', ''),
                $p('from', ''),
                $p('to', ''),
            ],
            'App\Http\Controllers\Api\SuperAdmin\PlatformReportController@index' => [
                $p('from', '2026-01-01'),
                $p('to', '2026-04-30'),
            ],
            'App\Http\Controllers\Api\SuperAdmin\PlatformReportController@financial' => [
                $p('from', '2026-01-01'),
                $p('to', '2026-04-30'),
            ],
            'App\Http\Controllers\Api\SuperAdmin\PlatformReportController@franchiseKpis' => [
                $p('from', '2026-01-01'),
                $p('to', '2026-04-30'),
            ],
            'App\Http\Controllers\Api\Tenant\FranchiseAnalyticsController@kpis' => [
                $p('from', '2026-01-01'),
                $p('to', '2026-04-30'),
            ],
            'App\Http\Controllers\Api\Tenant\ServiceAvailabilityController@index' => [
                $p('branch_id', '1'),
            ],
            'App\Http\Controllers\Api\Tenant\ServiceAvailabilityOverrideController@index' => [
                $p('branch_id', '1'),
                $p('from', '2026-04-01'),
                $p('to', '2026-04-30'),
            ],
            'App\Http\Controllers\Api\Tenant\InventoryController@movements' => [
                $p('from', '2026-04-01'),
                $p('to', '2026-04-30'),
                $p('product_id', ''),
                $p('type', ''),
            ],
            'App\Http\Controllers\Api\Tenant\ProductController@index' => [
                $p('is_active', 'true'),
                $p('search', ''),
                $p('category', ''),
                $p('classification', 'retail'),
                $p('page', '1'),
                $p('per_page', '20'),
            ],
            'App\Http\Controllers\Api\Tenant\CouponController@index' => [
                $p('q', ''),
                $p('is_active', 'true'),
                $p('page', '1'),
                $p('per_page', '50'),
            ],
            'App\Http\Controllers\Api\Tenant\ExpenseController@index' => [
                $p('branch_id', ''),
                $p('from', '2026-04-01'),
                $p('to', '2026-04-30'),
            ],
            'App\Http\Controllers\Api\Tenant\TimeBlockController@index' => [
                $p('branch_id', '1'),
                $p('staff_id', ''),
                $p('from', ''),
                $p('to', ''),
            ],
            'App\Http\Controllers\Api\Tenant\TimeOffRequestController@index' => [
                $p('staff_id', ''),
                $p('status', 'pending'),
            ],
            'App\Http\Controllers\Api\Tenant\StaffTimeController@index' => [
                $p('staff_id', ''),
            ],
            'App\Http\Controllers\Api\Tenant\StaffController@performance' => [
                $p('from', '2026-04-01'),
                $p('to', '2026-04-30'),
            ],
            'App\Http\Controllers\Api\Tenant\PackageMembershipCatalogController@indexPackages' => [
                $p('active_only', 'true'),
            ],
            'App\Http\Controllers\Api\Tenant\PackageMembershipCatalogController@indexMemberships' => [
                $p('active_only', 'true'),
            ],
            'App\Http\Controllers\Api\Tenant\GiftCardController@index' => [
                $p('status', 'active'),
                $p('search', ''),
            ],
            'App\Http\Controllers\Api\Tenant\CommissionController@staffEarnings' => [
                $p('from', '2026-04-01'),
                $p('to', '2026-04-30'),
            ],
            'App\Http\Controllers\Api\Tenant\ReportController@profitLoss' => [
                $p('period', '2026-04'),
                $p('branch_id', ''),
            ],
            'App\Http\Controllers\Api\Tenant\ReportController@vat' => [
                $p('period', '2026-04'),
                $p('branch_id', ''),
            ],
            'App\Http\Controllers\Api\Tenant\ReportController@profitLossExport' => [
                $p('period', '2026-04'),
                $p('branch_id', ''),
            ],
            'App\Http\Controllers\Api\Tenant\ReportController@vatExport' => [
                $p('period', '2026-04'),
                $p('branch_id', ''),
            ],
            'App\Http\Controllers\Api\Tenant\ReportController@paymentBreakdown' => [
                $p('from', '2026-04-01'),
                $p('to', '2026-04-30'),
                $p('branch_id', ''),
            ],
            'App\Http\Controllers\Api\Tenant\ReportController@paymentBreakdownExport' => [
                $p('from', '2026-04-01'),
                $p('to', '2026-04-30'),
                $p('branch_id', ''),
            ],
            'App\Http\Controllers\Api\Tenant\ReportController@inventoryMovement' => [
                $p('from', '2026-04-01'),
                $p('to', '2026-04-30'),
                $p('branch_id', ''),
                $p('product_id', ''),
            ],
            'App\Http\Controllers\Api\Tenant\ReportController@lowStock' => [
                $p('branch_id', ''),
            ],
            'App\Http\Controllers\Api\Tenant\ReportController@margins' => [
                $p('from', '2026-04-01'),
                $p('to', '2026-04-30'),
                $p('branch_id', ''),
            ],
            'App\Http\Controllers\Api\Tenant\ReportController@servicePopularity' => [
                $p('period', '2026-04'),
                $p('branch_id', ''),
                $p('limit', '10'),
            ],
            'App\Http\Controllers\Api\Tenant\ReportController@clientRetention' => [
                $p('period', '2026-04'),
                $p('branch_id', ''),
            ],
            'App\Http\Controllers\Api\Tenant\ReportController@noShowTrends' => [
                $p('period', '2026-04'),
                $p('branch_id', ''),
                $p('bucket', 'week'),
            ],
        ];
    }

    /**
     * @return array<string, array{raw?: string, mode?: 'json'|'formdata'|'none', formdata?: list<array<string, mixed>>, note?: string}>
     */
    private static function writeBodies(): array
    {
        $j = fn (string $json) => ['mode' => 'json', 'raw' => $json];
        $n = ['mode' => 'none', 'note' => 'No JSON body required.'];

        return [
            'App\Http\Controllers\Api\AuthController@login' => $j("{\n  \"email\": \"owner@example.com\",\n  \"password\": \"password\"\n}"),
            'App\Http\Controllers\Api\AuthController@register' => $j("{\n  \"name\": \"Test User\",\n  \"email\": \"user@example.com\",\n  \"password\": \"secret12\",\n  \"password_confirmation\": \"secret12\"\n}"),
            'App\Http\Controllers\Api\AuthController@registerCustomer' => $j("{\n  \"email\": \"customer@example.com\",\n  \"password\": \"secret12\",\n  \"full_name\": \"Jane\",\n  \"phone\": \"+96170000000\"\n}"),
            'App\Http\Controllers\Api\AuthController@registerSalonOwner' => $j("{\n  \"salon_name\": \"My Salon\",\n  \"salon_address\": \"Beirut\",\n  \"email\": \"owner@example.com\",\n  \"password\": \"secret12\",\n  \"full_name\": \"Owner\",\n  \"phone\": \"+96170000001\"\n}"),
            'App\Http\Controllers\Api\AuthController@sendOtp' => $j("{\n  \"email\": \"owner@example.com\"\n}"),
            'App\Http\Controllers\Api\AuthController@verifyOtp' => $j("{\n  \"email\": \"owner@example.com\",\n  \"code\": \"123456\"\n}"),
            'App\Http\Controllers\Api\AuthController@updateProfile' => $j("{\n  \"name\": \"Updated Name\",\n  \"email\": \"new@example.com\",\n  \"phone\": \"+96170000002\"\n}"),
            'App\Http\Controllers\Api\AuthController@changePassword' => $j("{\n  \"current_password\": \"password\",\n  \"new_password\": \"newsecret12\",\n  \"new_password_confirmation\": \"newsecret12\"\n}"),
            'App\Http\Controllers\Api\AuthController@updateSalonProfile' => $j("{\n  \"name\": \"Salon Name\",\n  \"phone\": \"+96170000000\",\n  \"address\": \"Street 1\",\n  \"timezone\": \"Asia/Beirut\",\n  \"currency\": \"USD\",\n  \"gender_preference\": \"unisex\",\n  \"cancellation_window_hours\": 24,\n  \"cancellation_policy_mode\": \"soft\"\n}"),
            'App\Http\Controllers\Api\AuthController@googleAuth' => $j("{\n  \"credential\": \"GOOGLE_ID_TOKEN_OR_ACCESS_TOKEN\"\n}"),

            'App\Http\Controllers\Api\Public\PublicBookingController@book' => $j("{\n  \"tenant_id\": 1,\n  \"branch_id\": 1,\n  \"service_id\": 1,\n  \"staff_id\": null,\n  \"start_at\": \"2026-12-01T10:00:00\",\n  \"client_name\": \"Guest\",\n  \"client_phone\": \"+96170000000\",\n  \"client_email\": \"guest@example.com\"\n}"),

            'App\Http\Controllers\Api\Tenant\StaffInvitationController@accept' => $j("{\n  \"token\": \"INVITE_TOKEN_FROM_EMAIL\",\n  \"name\": \"Staff Member\",\n  \"password\": \"secret12\",\n  \"password_confirmation\": \"secret12\"\n}"),
            'App\Http\Controllers\Api\Tenant\StaffInvitationController@store' => $j("{\n  \"email\": \"newstaff@example.com\",\n  \"name\": \"New Staff\",\n  \"role\": \"staff\",\n  \"branch_id\": 1\n}"),
            'App\Http\Controllers\Api\Tenant\StaffInvitationController@resend' => $n,
            'App\Http\Controllers\Api\Tenant\StaffInvitationController@revoke' => $n,

            'App\Http\Controllers\Api\SuperAdmin\TenantController@store' => $j("{\n  \"name\": \"New Tenant\",\n  \"domain\": null,\n  \"plan\": \"basic\",\n  \"timezone\": \"Asia/Beirut\",\n  \"currency\": \"USD\",\n  \"phone\": \"+961\",\n  \"address\": \"Address\"\n}"),
            'App\Http\Controllers\Api\SuperAdmin\TenantController@update' => $j("{\n  \"name\": \"Updated Tenant\",\n  \"plan\": \"pro\",\n  \"currency\": \"USD\"\n}"),

            'App\Http\Controllers\Api\SuperAdmin\UserController@store' => $j("{\n  \"email\": \"platform@example.com\",\n  \"name\": \"Admin User\",\n  \"password\": \"secret12\",\n  \"tenant_id\": null,\n  \"role\": \"super_admin\"\n}"),
            'App\Http\Controllers\Api\SuperAdmin\UserController@update' => $j("{\n  \"name\": \"Updated\",\n  \"tenant_id\": null,\n  \"role\": \"manager\",\n  \"password\": null\n}"),

            'App\Http\Controllers\Api\SuperAdmin\SubscriptionController@upsertForTenant' => $j("{\n  \"plan\": \"pro\",\n  \"status\": \"active\",\n  \"starts_at\": \"2026-01-01\",\n  \"ends_at\": null,\n  \"notes\": null\n}"),

            'App\Http\Controllers\Api\Tenant\TenantSettingsController@update' => $j("{\n  \"name\": \"Salon\",\n  \"phone\": \"+961\",\n  \"address\": \"Addr\",\n  \"timezone\": \"Asia/Beirut\",\n  \"currency\": \"USD\",\n  \"vat_rate\": 11,\n  \"gender_preference\": \"unisex\",\n  \"preferred_locale\": \"en\",\n  \"cancellation_window_hours\": 24,\n  \"cancellation_policy_mode\": \"soft\"\n}"),

            'App\Http\Controllers\Api\Tenant\BranchController@store' => $j("{\n  \"name\": \"Branch 2\",\n  \"phone\": \"+961\",\n  \"contact_email\": \"branch@example.com\",\n  \"address\": \"Street\",\n  \"timezone\": \"Asia/Beirut\",\n  \"working_hours\": null,\n  \"gender_preference\": \"unisex\",\n  \"lat\": 33.89,\n  \"lng\": 35.50,\n  \"is_active\": true\n}"),
            'App\Http\Controllers\Api\Tenant\BranchController@update' => $j("{\n  \"name\": \"Renamed Branch\",\n  \"is_active\": true\n}"),

            'App\Http\Controllers\Api\Tenant\ServiceCategoryController@store' => $j("{\n  \"name\": \"Hair\"\n}"),
            'App\Http\Controllers\Api\Tenant\ServiceCategoryController@update' => $j("{\n  \"name\": \"Hair care\"\n}"),

            'App\Http\Controllers\Api\Tenant\ServiceController@store' => $j("{\n  \"name\": \"Cut\",\n  \"description\": \"Haircut\",\n  \"price\": 25,\n  \"duration_minutes\": 45,\n  \"service_category_id\": 1,\n  \"is_active\": true\n}"),
            'App\Http\Controllers\Api\Tenant\ServiceController@update' => $j("{\n  \"name\": \"Cut + style\",\n  \"price\": 30\n}"),

            'App\Http\Controllers\Api\Tenant\AppointmentController@store' => $j("{\n  \"branch_id\": 1,\n  \"customer_id\": 1,\n  \"staff_id\": 1,\n  \"service_id\": 1,\n  \"start_time\": \"2026-12-01T10:00:00\",\n  \"end_time\": \"2026-12-01T10:45:00\",\n  \"notes\": null,\n  \"status\": \"scheduled\",\n  \"source\": \"dashboard\"\n}"),
            'App\Http\Controllers\Api\Tenant\AppointmentController@update' => $j("{\n  \"status\": \"confirmed\",\n  \"notes\": \"VIP\",\n  \"start_time\": \"2026-12-01T11:00:00\",\n  \"end_time\": \"2026-12-01T11:45:00\"\n}"),

            'App\Http\Controllers\Api\Tenant\CustomerController@store' => $j("{\n  \"name\": \"Walk-in\",\n  \"phone\": \"+96170000000\",\n  \"email\": \"client@example.com\",\n  \"birthday\": null,\n  \"gender\": \"female\",\n  \"tags\": null\n}"),
            'App\Http\Controllers\Api\Tenant\CustomerController@update' => $j("{\n  \"name\": \"Client updated\",\n  \"phone\": \"+96170000001\"\n}"),
            'App\Http\Controllers\Api\Tenant\CustomerController@addNote' => $j("{\n  \"note\": \"Prefers morning slots\"\n}"),

            'App\Http\Controllers\Api\Tenant\CustomerBookingController@reschedule' => $j("{\n  \"start_at\": \"2026-12-15T14:00:00\",\n  \"staff_id\": null\n}"),
            'App\Http\Controllers\Api\Tenant\CustomerBookingController@rebook' => $j("{\n  \"start_at\": \"2026-12-20T10:00:00\",\n  \"staff_id\": null\n}"),
            'App\Http\Controllers\Api\Tenant\CustomerBookingController@cancel' => $n,

            'App\Http\Controllers\Api\Tenant\CustomerReviewController@store' => $j("{\n  \"rating\": 5,\n  \"comment\": \"Great service\"\n}"),

            'App\Http\Controllers\Api\Tenant\CustomerFavoriteController@store' => $j("{\n  \"salon_id\": 1\n}"),

            'App\Http\Controllers\Api\Tenant\ReviewModerationController@moderate' => $j("{\n  \"action\": \"approve\"\n}"),

            'App\Http\Controllers\Api\Tenant\SaleController@store' => $j("{\n  \"branch_id\": 1,\n  \"customer_id\": 1,\n  \"appointment_id\": null,\n  \"notes\": null,\n  \"items\": [\n    {\n      \"service_id\": 1,\n      \"product_id\": null,\n      \"quantity\": 1,\n      \"unit_price\": 25\n    }\n  ],\n  \"payments\": [\n    {\n      \"method\": \"cash\",\n      \"amount\": 25,\n      \"reference\": null\n    }\n  ]\n}"),
            'App\Http\Controllers\Api\Tenant\SaleController@refund' => $j("{\n  \"refund_reason\": \"Customer request\"\n}"),
            'App\Http\Controllers\Api\Tenant\SaleController@notifyReceipt' => $j("{\n  \"channel\": \"email\"\n}"),

            'App\Http\Controllers\Api\Tenant\TimeBlockController@store' => $j("{\n  \"branch_id\": 1,\n  \"staff_id\": null,\n  \"starts_at\": \"2026-12-01T12:00:00\",\n  \"ends_at\": \"2026-12-01T13:00:00\",\n  \"reason\": \"Lunch\"\n}"),
            'App\Http\Controllers\Api\Tenant\TimeBlockController@update' => $j("{\n  \"starts_at\": \"2026-12-01T12:30:00\",\n  \"ends_at\": \"2026-12-01T13:30:00\",\n  \"reason\": \"Updated\"\n}"),

            'App\Http\Controllers\Api\Tenant\CouponController@store' => $j("{\n  \"code\": \"SAVE10\",\n  \"type\": \"percent\",\n  \"value\": 10,\n  \"is_active\": true,\n  \"starts_at\": null,\n  \"ends_at\": null,\n  \"usage_limit\": 100,\n  \"min_subtotal\": null,\n  \"name\": \"Spring promo\",\n  \"description\": null\n}"),
            'App\Http\Controllers\Api\Tenant\CouponController@update' => $j("{\n  \"is_active\": false\n}"),

            'App\Http\Controllers\Api\Tenant\ExpenseController@store' => $j("{\n  \"branch_id\": 1,\n  \"category\": \"rent\",\n  \"amount\": 500,\n  \"expense_date\": \"2026-04-01\",\n  \"description\": \"Monthly rent\",\n  \"payment_method\": \"bank_transfer\"\n}"),
            'App\Http\Controllers\Api\Tenant\ExpenseController@update' => $j("{\n  \"amount\": 520,\n  \"description\": \"Updated\"\n}"),

            'App\Http\Controllers\Api\Tenant\DebtController@addPayment' => $j("{\n  \"amount\": 50.5\n}"),
            'App\Http\Controllers\Api\Tenant\DebtController@writeOff' => $j("{\n  \"reason\": \"Uncollectible\",\n  \"submit_for_approval\": true\n}"),
            'App\Http\Controllers\Api\Tenant\DebtController@approveWriteOff' => $n,
            'App\Http\Controllers\Api\Tenant\DebtController@rejectWriteOff' => $n,

            'App\Http\Controllers\Api\Tenant\CashDrawerController@open' => $j("{\n  \"branch_id\": 1,\n  \"opening_balance\": 100\n}"),
            'App\Http\Controllers\Api\Tenant\CashDrawerController@transaction' => $j("{\n  \"type\": \"cash_in\",\n  \"amount\": 20,\n  \"reason\": \"Float\"\n}"),
            'App\Http\Controllers\Api\Tenant\CashDrawerController@close' => $j("{\n  \"actual_cash\": 450.5,\n  \"notes\": \"Counted\"\n}"),
            'App\Http\Controllers\Api\Tenant\CashDrawerController@approve' => $j("{\n  \"notes\": \"Approved variance\"\n}"),

            'App\Http\Controllers\Api\Tenant\MonthlyClosingController@close' => $j("{\n  \"year\": 2026,\n  \"month\": 3,\n  \"notes\": \"Month end\"\n}"),

            'App\Http\Controllers\Api\Tenant\GiftCardController@store' => $j("{\n  \"initial_balance\": 100,\n  \"currency\": \"USD\",\n  \"expires_at\": null,\n  \"code\": null,\n  \"customer_id\": null\n}"),
            'App\Http\Controllers\Api\Tenant\GiftCardController@verify' => $j("{\n  \"code\": \"ABCD-EFGH\"\n}"),
            'App\Http\Controllers\Api\Tenant\GiftCardController@redeem' => $j("{\n  \"amount\": 25\n}"),
            'App\Http\Controllers\Api\Tenant\GiftCardController@void' => $n,

            'App\Http\Controllers\Api\Tenant\InventoryController@adjust' => $j("{\n  \"branch_id\": 1,\n  \"product_id\": 1,\n  \"quantity\": -2,\n  \"reason\": \"Damaged stock\",\n  \"type\": \"damage\"\n}"),

            'App\Http\Controllers\Api\Tenant\ProductController@store' => $j("{\n  \"name\": \"Shampoo\",\n  \"description\": null,\n  \"category\": \"retail\",\n  \"classification\": \"retail\",\n  \"sku\": \"SH-001\",\n  \"cost\": 8,\n  \"price\": 15,\n  \"stock_quantity\": 20,\n  \"low_stock_threshold\": 5,\n  \"is_active\": true\n}"),
            'App\Http\Controllers\Api\Tenant\ProductController@update' => $j("{\n  \"price\": 16.5,\n  \"stock_quantity\": 18,\n  \"is_active\": true\n}"),

            'App\Http\Controllers\Api\Tenant\StaffController@store' => $j("{\n  \"branch_id\": 1,\n  \"user_id\": null,\n  \"name\": \"Stylist\",\n  \"phone\": \"+961\",\n  \"specialization\": null,\n  \"photo_url\": null,\n  \"service_ids\": [1],\n  \"color\": \"#FF00AA\"\n}"),
            'App\Http\Controllers\Api\Tenant\StaffController@update' => $j("{\n  \"name\": \"Senior Stylist\",\n  \"is_active\": true,\n  \"service_ids\": [1, 2]\n}"),

            'App\Http\Controllers\Api\Tenant\ScheduleController@store' => $j("{\n  \"schedules\": [\n    {\n      \"day_of_week\": 1,\n      \"start_time\": \"09:00\",\n      \"end_time\": \"17:00\",\n      \"is_day_off\": false\n    }\n  ]\n}"),

            'App\Http\Controllers\Api\Tenant\StaffTimeController@clockIn' => $j("{\n  \"staff_id\": 1,\n  \"branch_id\": 1\n}"),
            'App\Http\Controllers\Api\Tenant\StaffTimeController@clockOut' => $n,

            'App\Http\Controllers\Api\Tenant\CommissionController@store' => $j("{\n  \"type\": \"percent_service\",\n  \"value\": 15,\n  \"tier_threshold\": null,\n  \"staff_id\": null,\n  \"service_id\": null,\n  \"is_active\": true\n}"),
            'App\Http\Controllers\Api\Tenant\CommissionController@update' => $j("{\n  \"value\": 18,\n  \"is_active\": true\n}"),

            'App\Http\Controllers\Api\Tenant\TimeOffRequestController@store' => $j("{\n  \"staff_id\": 1,\n  \"branch_id\": 1,\n  \"start_date\": \"2026-05-01\",\n  \"end_date\": \"2026-05-03\",\n  \"reason\": \"Travel\"\n}"),
            'App\Http\Controllers\Api\Tenant\TimeOffRequestController@updateStatus' => $j("{\n  \"status\": \"approved\",\n  \"decision_note\": \"OK\"\n}"),

            'App\Http\Controllers\Api\Tenant\ServiceAvailabilityController@store' => $j("{\n  \"branch_id\": 1,\n  \"day_of_week\": 1,\n  \"start_time\": \"09:00\",\n  \"end_time\": \"18:00\",\n  \"slot_minutes\": 30\n}"),
            'App\Http\Controllers\Api\Tenant\ServiceAvailabilityController@update' => $j("{\n  \"start_time\": \"10:00\",\n  \"end_time\": \"19:00\"\n}"),

            'App\Http\Controllers\Api\Tenant\ServiceAvailabilityOverrideController@store' => $j("{\n  \"branch_id\": 1,\n  \"date\": \"2026-12-24\",\n  \"start_time\": \"10:00\",\n  \"end_time\": \"14:00\",\n  \"is_closed\": false\n}"),
            'App\Http\Controllers\Api\Tenant\ServiceAvailabilityOverrideController@update' => $j("{\n  \"is_closed\": true\n}"),

            'App\Http\Controllers\Api\Tenant\ServiceAddOnController@store' => $j("{\n  \"name\": \"Deep conditioning\",\n  \"description\": null,\n  \"price\": 10,\n  \"duration_minutes\": 15,\n  \"is_active\": true\n}"),
            'App\Http\Controllers\Api\Tenant\ServiceAddOnController@update' => $j("{\n  \"price\": 12\n}"),

            'App\Http\Controllers\Api\Tenant\PackageMembershipCatalogController@storePackage' => $j("{\n  \"name\": \"5 visits\",\n  \"description\": null,\n  \"price\": 200,\n  \"total_sessions\": 5,\n  \"validity_days\": 180,\n  \"is_active\": true\n}"),
            'App\Http\Controllers\Api\Tenant\PackageMembershipCatalogController@updatePackage' => $j("{\n  \"name\": \"5 visits (updated)\",\n  \"price\": 220,\n  \"total_sessions\": 5\n}"),
            'App\Http\Controllers\Api\Tenant\PackageMembershipCatalogController@storeMembership' => $j("{\n  \"name\": \"Gold\",\n  \"description\": null,\n  \"price\": 99,\n  \"interval_months\": 1,\n  \"credits_per_renewal\": 4,\n  \"is_active\": true\n}"),
            'App\Http\Controllers\Api\Tenant\PackageMembershipCatalogController@updateMembership' => $j("{\n  \"price\": 109,\n  \"interval_months\": 1\n}"),

            'App\Http\Controllers\Api\Tenant\CustomerCrmController@consumePackage' => $j("{\n  \"quantity\": 1\n}"),
            'App\Http\Controllers\Api\Tenant\CustomerCrmController@renewMembership' => $n,
            'App\Http\Controllers\Api\Tenant\CustomerCrmController@toggleAutoRenew' => $j("{\n  \"auto_renew\": true\n}"),

            'App\Http\Controllers\Api\Tenant\SalonPhotoController@store' => [
                'mode' => 'formdata',
                'formdata' => [
                    ['key' => 'photo', 'type' => 'file', 'src' => []],
                    ['key' => 'sort_order', 'type' => 'text', 'value' => '0'],
                ],
                'note' => 'Attach file field `photo` (image). Optional sort_order.',
            ],

            'App\Http\Controllers\Api\AuthController@logout' => $n,
            'App\Http\Controllers\Api\SuperAdmin\TenantController@suspend' => $n,
            'App\Http\Controllers\Api\SuperAdmin\TenantController@activate' => $n,
            'App\Http\Controllers\Api\SuperAdmin\TenantController@destroy' => $n,
            'App\Http\Controllers\Api\SuperAdmin\UserController@destroy' => $n,
            'App\Http\Controllers\Api\Tenant\InvoiceController@void' => $n,
        ];
    }
}
