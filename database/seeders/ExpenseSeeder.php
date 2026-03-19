<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class ExpenseSeeder extends Seeder
{
    public function run(): void
    {
        $expenses = [
            ['category' => 'rent',      'description' => 'Monthly rent',          'amount' => 5000, 'payment_method' => 'transfer'],
            ['category' => 'salary',    'description' => 'Staff salaries',         'amount' => 8000, 'payment_method' => 'transfer'],
            ['category' => 'supplies',  'description' => 'Hair & beauty supplies', 'amount' => 1200, 'payment_method' => 'cash'],
            ['category' => 'utilities', 'description' => 'Electricity & water',    'amount' => 600,  'payment_method' => 'transfer'],
            ['category' => 'other',     'description' => 'Marketing & ads',        'amount' => 800,  'payment_method' => 'card'],
        ];

        Tenant::all()->each(function (Tenant $tenant) use ($expenses) {
            $tenant->makeCurrent();

            $branch = Branch::where('tenant_id', $tenant->id)->first();

            foreach ($expenses as $expenseData) {
                $expense = Expense::create(array_merge($expenseData, [
                    'tenant_id'    => $tenant->id,
                    'branch_id'    => $branch->id,
                    'expense_date' => now()->subDays(rand(1, 30)),
                ]));

                LedgerEntry::create([
                    'tenant_id'      => $tenant->id,
                    'branch_id'      => $branch->id,
                    'type'           => 'expense',
                    'category'       => $expenseData['category'],
                    'amount'         => $expenseData['amount'],
                    'tax_amount'     => 0,
                    'reference_type' => 'App\Models\Expense',
                    'reference_id'   => $expense->id,
                    'description'    => $expenseData['description'],
                    'entry_date'     => $expense->expense_date,
                    'is_locked'      => false,
                ]);
            }

            Tenant::forgetCurrent();
        });
    }
}
