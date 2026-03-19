<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GiftCardSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::all()->each(function (Tenant $tenant) {
            $tenant->makeCurrent();

            $customers = Customer::where('tenant_id', $tenant->id)->take(3)->get();

            foreach ($customers as $customer) {
                $initialBalance = collect([100, 200, 500])->random();

                $giftCard = GiftCard::create([
                    'tenant_id'         => $tenant->id,
                    'code'              => strtoupper(Str::random(10)),
                    'initial_balance'   => $initialBalance,
                    'remaining_balance' => $initialBalance,
                    'customer_id'       => $customer->id,
                    'expires_at'        => now()->addYear(),
                    'status'            => 'active',
                ]);

                // Record initial top-up transaction
                GiftCardTransaction::create([
                    'gift_card_id'  => $giftCard->id,
                    'type'          => 'top_up',
                    'amount'        => $initialBalance,
                    'balance_after' => $initialBalance,
                ]);
            }

            Tenant::forgetCurrent();
        });
    }
}
