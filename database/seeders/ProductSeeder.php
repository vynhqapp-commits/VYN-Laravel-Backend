<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['name' => 'Shampoo 500ml',       'sku' => 'SHP-001', 'price' => 45,  'cost' => 20,  'stock_quantity' => 50, 'low_stock_threshold' => 10],
            ['name' => 'Conditioner 500ml',   'sku' => 'CND-001', 'price' => 45,  'cost' => 20,  'stock_quantity' => 40, 'low_stock_threshold' => 10],
            ['name' => 'Hair Color - Black',  'sku' => 'HCL-001', 'price' => 80,  'cost' => 35,  'stock_quantity' => 30, 'low_stock_threshold' => 5],
            ['name' => 'Hair Color - Brown',  'sku' => 'HCL-002', 'price' => 80,  'cost' => 35,  'stock_quantity' => 25, 'low_stock_threshold' => 5],
            ['name' => 'Nail Polish - Red',   'sku' => 'NPL-001', 'price' => 30,  'cost' => 10,  'stock_quantity' => 60, 'low_stock_threshold' => 15],
            ['name' => 'Nail Polish - Pink',  'sku' => 'NPL-002', 'price' => 30,  'cost' => 10,  'stock_quantity' => 55, 'low_stock_threshold' => 15],
            ['name' => 'Face Mask',           'sku' => 'FCM-001', 'price' => 60,  'cost' => 25,  'stock_quantity' => 20, 'low_stock_threshold' => 5],
            ['name' => 'Moisturizer 100ml',   'sku' => 'MST-001', 'price' => 120, 'cost' => 50,  'stock_quantity' => 3,  'low_stock_threshold' => 5], // low stock
            ['name' => 'Gel Base Coat',       'sku' => 'GBC-001', 'price' => 55,  'cost' => 22,  'stock_quantity' => 35, 'low_stock_threshold' => 8],
            ['name' => 'Makeup Remover',      'sku' => 'MKR-001', 'price' => 40,  'cost' => 15,  'stock_quantity' => 45, 'low_stock_threshold' => 10],
        ];

        Tenant::all()->each(function (Tenant $tenant) use ($products) {
            $tenant->makeCurrent();

            foreach ($products as $productData) {
                $product = Product::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'sku' => $productData['sku']],
                    array_merge($productData, ['tenant_id' => $tenant->id, 'is_active' => true])
                );

                // Initial stock movement
                if ($product->wasRecentlyCreated) {
                    StockMovement::create([
                        'tenant_id'  => $tenant->id,
                        'product_id' => $product->id,
                        'type'       => 'in',
                        'quantity'   => $productData['stock_quantity'],
                        'reason'     => 'purchase',
                    ]);
                }
            }

            Tenant::forgetCurrent();
        });
    }
}
