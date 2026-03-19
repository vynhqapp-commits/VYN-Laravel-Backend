<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,    // 1.  roles & permissions + super admin
            TenantSeeder::class,                 // 2.  tenants
            UserSeeder::class,                   // 3.  users per tenant (needs tenants + roles)
            BranchSeeder::class,                 // 4.  branches (needs tenants)
            ServiceSeeder::class,                // 5.  categories + services (needs tenants)
            ServiceAvailabilitySeeder::class,    // 6.  weekly windows + date overrides (needs services + branches)
            StaffSeeder::class,                  // 7.  staff + schedules (needs tenants + branches)
            CustomerSeeder::class,               // 8.  customers (needs tenants)
            ProductSeeder::class,                // 9.  products + stock movements (needs tenants)
            AppointmentSeeder::class,            // 10. appointments with source (needs branches, customers, staff, services)
            InvoiceSeeder::class,                // 11. invoices, payments, debts, commissions, ledger (needs appointments)
            ExpenseSeeder::class,                // 12. expenses + ledger entries (needs branches)
            GiftCardSeeder::class,               // 13. gift cards (needs customers)
        ]);
    }
}
