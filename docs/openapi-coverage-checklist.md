# OpenAPI Coverage Checklist

Generated from php artisan route:list --path=api --json.

## App\Http\Controllers\Api\AuthController

- POST api/auth/google -> googleAuth
- POST api/auth/login -> login
- POST api/auth/register/customer -> registerCustomer
- POST api/auth/register/salon-owner -> registerSalonOwner
- POST api/auth/request-otp -> sendOtp
- POST api/auth/verify-otp -> verifyOtp
- POST api/login -> login
- POST api/logout -> logout
- GET|HEAD api/me -> me
- POST api/otp/send -> sendOtp
- POST api/otp/verify -> verifyOtp
- PATCH api/profile -> updateProfile
- POST api/profile/change-password -> changePassword
- POST api/register -> register
- GET|HEAD api/salon/profile -> salonProfile
- PATCH api/salon/profile -> updateSalonProfile

## App\Http\Controllers\Api\Public\PublicBookingController

- GET|HEAD api/public/availability -> availability
- POST api/public/book -> book
- GET|HEAD api/public/salons -> salons
- GET|HEAD api/public/salons/nearby -> nearbySalons
- GET|HEAD api/public/salons/{slug} -> salon

## App\Http\Controllers\Api\SuperAdmin\AuditController

- GET|HEAD api/admin/audit -> index

## App\Http\Controllers\Api\SuperAdmin\PlatformReportController

- GET|HEAD api/admin/franchise/kpis -> franchiseKpis
- GET|HEAD api/admin/reports -> index
- GET|HEAD api/admin/reports/financial -> financial

## App\Http\Controllers\Api\SuperAdmin\RoleController

- GET|HEAD api/admin/permissions -> permissions
- GET|HEAD api/admin/roles -> roles

## App\Http\Controllers\Api\SuperAdmin\SubscriptionController

- GET|HEAD api/admin/subscriptions -> index
- PATCH api/admin/tenants/{tenant}/subscription -> upsertForTenant

## App\Http\Controllers\Api\SuperAdmin\TenantController

- GET|HEAD api/admin/tenants -> index
- POST api/admin/tenants -> store
- GET|HEAD api/admin/tenants/{tenant} -> show
- PUT api/admin/tenants/{tenant} -> update
- PATCH api/admin/tenants/{tenant} -> update
- DELETE api/admin/tenants/{tenant} -> destroy
- PATCH api/admin/tenants/{tenant}/activate -> activate
- PATCH api/admin/tenants/{tenant}/suspend -> suspend

## App\Http\Controllers\Api\SuperAdmin\UserController

- GET|HEAD api/admin/users -> index
- POST api/admin/users -> store
- PATCH api/admin/users/{user} -> update
- DELETE api/admin/users/{user} -> destroy

## App\Http\Controllers\Api\Tenant\AppointmentController

- GET|HEAD api/appointments -> index
- POST api/appointments -> store
- GET|HEAD api/appointments/{appointment} -> show
- PUT|PATCH api/appointments/{appointment} -> update
- DELETE api/appointments/{appointment} -> destroy

## App\Http\Controllers\Api\Tenant\BranchController

- GET|HEAD api/branches -> index
- POST api/branches -> store
- GET|HEAD api/branches/{branch} -> show
- PUT|PATCH api/branches/{branch} -> update
- DELETE api/branches/{branch} -> destroy

## App\Http\Controllers\Api\Tenant\CashDrawerController

- GET|HEAD api/cash-drawers -> index
- POST api/cash-drawers/open -> open
- POST api/cash-drawers/{session}/approve -> approve
- POST api/cash-drawers/{session}/close -> close
- POST api/cash-drawers/{session}/transaction -> transaction

## App\Http\Controllers\Api\Tenant\CommissionController

- GET|HEAD api/commissions -> index
- POST api/commissions/rules -> store
- PUT api/commissions/rules/{commission} -> update
- DELETE api/commissions/rules/{commission} -> destroy
- GET|HEAD api/commissions/staff/{staff}/earnings -> staffEarnings
- GET|HEAD api/commissions/{commission} -> show

## App\Http\Controllers\Api\Tenant\CouponController

- GET|HEAD api/coupons -> index
- POST api/coupons -> store
- GET|HEAD api/coupons/{coupon} -> show
- PATCH api/coupons/{coupon} -> update
- DELETE api/coupons/{coupon} -> destroy

## App\Http\Controllers\Api\Tenant\CustomerBookingController

- GET|HEAD api/customer/bookings -> index
- GET|HEAD api/customer/bookings/{appointment} -> show
- PATCH api/customer/bookings/{appointment}/cancel -> cancel
- POST api/customer/bookings/{appointment}/rebook -> rebook
- PATCH api/customer/bookings/{appointment}/reschedule -> reschedule

## App\Http\Controllers\Api\Tenant\CustomerController

- GET|HEAD api/customers -> index
- POST api/customers -> store
- GET|HEAD api/customers/{customer} -> show
- PUT|PATCH api/customers/{customer} -> update
- GET|HEAD api/customers/{customer}/notes -> notes
- POST api/customers/{customer}/notes -> addNote

## App\Http\Controllers\Api\Tenant\CustomerCrmController

- GET|HEAD api/customers/{customer}/memberships -> memberships
- PATCH api/customers/{customer}/memberships/{membership}/auto-renew -> toggleAutoRenew
- POST api/customers/{customer}/memberships/{membership}/renew -> renewMembership
- GET|HEAD api/customers/{customer}/packages -> packages
- POST api/customers/{customer}/packages/{package}/consume -> consumePackage
- GET|HEAD api/customers/{customer}/stats -> stats

## App\Http\Controllers\Api\Tenant\CustomerFavoriteController

- GET|HEAD api/customer/favorites -> index
- POST api/customer/favorites -> store
- DELETE api/customer/favorites/{salon} -> destroy

## App\Http\Controllers\Api\Tenant\CustomerReviewController

- POST api/customer/bookings/{appointment}/review -> store
- GET|HEAD api/customer/reviews -> index

## App\Http\Controllers\Api\Tenant\DebtController

- GET|HEAD api/debts -> index
- GET|HEAD api/debts/aging-report -> agingReport
- GET|HEAD api/debts/write-off-requests -> writeOffRequests
- POST api/debts/write-off-requests/{requestItem}/approve -> approveWriteOff
- POST api/debts/write-off-requests/{requestItem}/reject -> rejectWriteOff
- POST api/debts/{debt}/payment -> addPayment
- POST api/debts/{debt}/write-off -> writeOff

## App\Http\Controllers\Api\Tenant\ExpenseController

- GET|HEAD api/expenses -> index
- POST api/expenses -> store
- PATCH api/expenses/{expense} -> update
- DELETE api/expenses/{expense} -> destroy

## App\Http\Controllers\Api\Tenant\FranchiseAnalyticsController

- GET|HEAD api/analytics/franchise -> kpis

## App\Http\Controllers\Api\Tenant\ApprovalRequestController

- GET|HEAD api/approval-requests -> index
- POST api/approval-requests/{approvalRequest}/approve -> approve
- POST api/approval-requests/{approvalRequest}/reject -> reject

## App\Http\Controllers\Api\Tenant\FranchiseOwnerInvitationController

- POST api/franchise-owner-invitations -> store
- POST api/auth/franchise-owner-invitations/accept -> accept

## App\Http\Controllers\Api\Tenant\GiftCardController

- GET|HEAD api/gift-cards -> index
- POST api/gift-cards -> store
- POST api/gift-cards/verify -> verify
- GET|HEAD api/gift-cards/{card} -> show
- POST api/gift-cards/{card}/redeem -> redeem
- POST api/gift-cards/{card}/void -> void

## App\Http\Controllers\Api\Tenant\InventoryController

- POST api/inventory/stock -> adjust
- GET|HEAD api/inventory/{branch} -> byBranch
- GET|HEAD api/inventory/{branch}/movements -> movements

## App\Http\Controllers\Api\Tenant\InvoiceController

- GET|HEAD api/invoices -> index
- GET|HEAD api/invoices/{invoice} -> show
- POST api/invoices/{invoice}/void -> void

## App\Http\Controllers\Api\Tenant\LedgerController

- GET|HEAD api/ledger -> index

## App\Http\Controllers\Api\Tenant\MonthlyClosingController

- GET|HEAD api/monthly-closings -> index
- POST api/monthly-closings/close -> close

## App\Http\Controllers\Api\Tenant\PackageMembershipCatalogController

- GET|HEAD api/catalog/memberships -> indexMemberships
- POST api/catalog/memberships -> storeMembership
- PUT api/catalog/memberships/{id} -> updateMembership
- DELETE api/catalog/memberships/{id} -> destroyMembership
- GET|HEAD api/catalog/packages -> indexPackages
- POST api/catalog/packages -> storePackage
- PUT api/catalog/packages/{id} -> updatePackage
- DELETE api/catalog/packages/{id} -> destroyPackage

## App\Http\Controllers\Api\Tenant\ProductController

- GET|HEAD api/products -> index
- POST api/products -> store
- GET|HEAD api/products/{product} -> show
- PATCH api/products/{product} -> update
- DELETE api/products/{product} -> destroy

## App\Http\Controllers\Api\Tenant\ReportController

- GET|HEAD api/reports/client-retention -> clientRetention
- GET|HEAD api/reports/inventory-movement -> inventoryMovement
- GET|HEAD api/reports/low-stock -> lowStock
- GET|HEAD api/reports/margins -> margins
- GET|HEAD api/reports/no-show-trends -> noShowTrends
- GET|HEAD api/reports/payment-breakdown -> paymentBreakdown
- GET|HEAD api/reports/payment-breakdown/export -> paymentBreakdownExport
- GET|HEAD api/reports/profit-loss -> profitLoss
- GET|HEAD api/reports/profit-loss/export -> profitLossExport
- GET|HEAD api/reports/service-popularity -> servicePopularity
- GET|HEAD api/reports/vat -> vat
- GET|HEAD api/reports/vat/export -> vatExport

## App\Http\Controllers\Api\Tenant\ReviewModerationController

- GET|HEAD api/reviews -> index
- PATCH api/reviews/{review}/moderate -> moderate

## App\Http\Controllers\Api\Tenant\SaleController

- GET|HEAD api/sales -> index
- POST api/sales -> store
- GET|HEAD api/sales/{sale} -> show
- POST api/sales/{sale}/receipt/notify -> notifyReceipt
- POST api/sales/{sale}/refund -> refund

## App\Http\Controllers\Api\Tenant\SalonPhotoController

- POST api/salons/{salon}/photos -> store

## App\Http\Controllers\Api\Tenant\ScheduleController

- GET|HEAD api/staff/{staff}/schedules -> index
- POST api/staff/{staff}/schedules -> store

## App\Http\Controllers\Api\Tenant\ServiceAddOnController

- GET|HEAD api/services/{service}/add-ons -> index
- POST api/services/{service}/add-ons -> store
- PATCH api/services/{service}/add-ons/{addOn} -> update
- DELETE api/services/{service}/add-ons/{addOn} -> destroy

## App\Http\Controllers\Api\Tenant\ServiceAvailabilityController

- GET|HEAD api/services/{service}/availabilities -> index
- POST api/services/{service}/availabilities -> store
- PATCH api/services/{service}/availabilities/{availability} -> update
- DELETE api/services/{service}/availabilities/{availability} -> destroy

## App\Http\Controllers\Api\Tenant\ServiceAvailabilityOverrideController

- GET|HEAD api/services/{service}/availability-overrides -> index
- POST api/services/{service}/availability-overrides -> store
- PATCH api/services/{service}/availability-overrides/{override} -> update
- DELETE api/services/{service}/availability-overrides/{override} -> destroy

## App\Http\Controllers\Api\Tenant\ServiceCategoryController

- GET|HEAD api/service-categories -> index
- POST api/service-categories -> store
- PUT|PATCH api/service-categories/{service_category} -> update
- DELETE api/service-categories/{service_category} -> destroy

## App\Http\Controllers\Api\Tenant\ServiceController

- GET|HEAD api/services -> index
- POST api/services -> store
- GET|HEAD api/services/{service} -> show
- PUT|PATCH api/services/{service} -> update
- DELETE api/services/{service} -> destroy

## App\Http\Controllers\Api\Tenant\StaffController

- GET|HEAD api/staff -> index
- POST api/staff -> store
- GET|HEAD api/staff/performance -> performance
- GET|HEAD api/staff/{staff} -> show
- PUT|PATCH api/staff/{staff} -> update
- DELETE api/staff/{staff} -> destroy

## App\Http\Controllers\Api\Tenant\StaffInvitationController

- POST api/auth/staff-invitations/accept -> accept
- GET|HEAD api/staff-invitations -> index
- POST api/staff-invitations -> store
- POST api/staff-invitations/{staffInvitation}/resend -> resend
- POST api/staff-invitations/{staffInvitation}/revoke -> revoke

## App\Http\Controllers\Api\Tenant\StaffTimeController

- GET|HEAD api/staff-time-entries -> index
- POST api/staff-time-entries/clock-in -> clockIn
- POST api/staff-time-entries/{entry}/clock-out -> clockOut

## App\Http\Controllers\Api\Tenant\TenantSettingsController

- GET|HEAD api/settings -> index
- PATCH api/settings -> update

## App\Http\Controllers\Api\Tenant\TimeBlockController

- GET|HEAD api/time-blocks -> index
- POST api/time-blocks -> store
- PATCH api/time-blocks/{timeBlock} -> update
- DELETE api/time-blocks/{timeBlock} -> destroy

## App\Http\Controllers\Api\Tenant\TimeOffRequestController

- GET|HEAD api/time-off-requests -> index
- POST api/time-off-requests -> store
- PATCH api/time-off-requests/{requestItem}/status -> updateStatus

