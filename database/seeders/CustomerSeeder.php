<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            ['name' => 'Aisha Mohammed',  'phone' => '+966500000001', 'email' => 'aisha@example.com',  'gender' => 'female', 'birthday' => '1990-05-15'],
            ['name' => 'Fatima Ali',      'phone' => '+966500000002', 'email' => 'fatima@example.com', 'gender' => 'female', 'birthday' => '1988-03-22'],
            ['name' => 'Mona Hassan',     'phone' => '+966500000003', 'email' => 'mona@example.com',   'gender' => 'female', 'birthday' => '1995-07-10'],
            ['name' => 'Sara Khalid',     'phone' => '+966500000004', 'email' => 'sara@example.com',   'gender' => 'female', 'birthday' => '1992-11-30'],
            ['name' => 'Hana Nasser',     'phone' => '+966500000005', 'email' => 'hana@example.com',   'gender' => 'female', 'birthday' => '1997-01-18'],
            ['name' => 'Rania Omar',      'phone' => '+966500000006', 'email' => 'rania@example.com',  'gender' => 'female', 'birthday' => '1985-09-05'],
            ['name' => 'Dina Youssef',    'phone' => '+966500000007', 'email' => 'dina@example.com',   'gender' => 'female', 'birthday' => '1993-04-25'],
            ['name' => 'Layla Saad',      'phone' => '+966500000008', 'email' => 'layla@example.com',  'gender' => 'female', 'birthday' => '1991-08-14'],
            ['name' => 'Nadia Faris',     'phone' => '+966500000009', 'email' => 'nadia@example.com',  'gender' => 'female', 'birthday' => '1996-12-03'],
            ['name' => 'Yasmin Tarek',    'phone' => '+966500000010', 'email' => 'yasmin@example.com', 'gender' => 'female', 'birthday' => '1989-06-20'],
        ];

        Tenant::all()->each(function (Tenant $tenant) use ($customers) {
            $tenant->makeCurrent();

            foreach ($customers as $customer) {
                Customer::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'phone' => $customer['phone']],
                    array_merge($customer, ['tenant_id' => $tenant->id])
                );
            }

            Tenant::forgetCurrent();
        });
    }
}
