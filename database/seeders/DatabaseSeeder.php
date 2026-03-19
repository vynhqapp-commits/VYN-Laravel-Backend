<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class, // 1. roles & permissions + super admin
            TenantSeeder::class,              // 2. tenants
            UserSeeder::class,                // 3. users per tenant (needs tenants + roles)
            BranchSeeder::class,              // 4. branches (needs tenants)
            ServiceSeeder::class,             // 5. categories + services (needs tenants)
            StaffSeeder::class,               // 6. staff + schedules (needs tenants + branches)
            CustomerSeeder::class,            // 7. customers (needs tenants)
            ProductSeeder::class,             // 8. products + stock movements (needs tenants)
            AppointmentSeeder::class,         // 9. appointments (needs branches, customers, staff, services)
            InvoiceSeeder::class,             // 10. invoices, payments, debts, commissions, ledger (needs appointments)
            ExpenseSeeder::class,             // 11. expenses + ledger entries (needs branches)
            GiftCardSeeder::class,            // 12. gift cards (needs customers)
        ]);
    }
}
