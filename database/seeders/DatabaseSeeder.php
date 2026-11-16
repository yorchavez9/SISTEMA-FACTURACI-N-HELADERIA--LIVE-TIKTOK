<?php

namespace Database\Seeders;

use App\Enums\IdentityDocType;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(['email' => 'admin@heladeria.test'], [
            'name' => 'Administrador',
            'password' => 'password',
            'role' => UserRole::Admin,
            'active' => true,
        ]);

        User::query()->updateOrCreate(['email' => 'cajero@heladeria.test'], [
            'name' => 'Cajero',
            'password' => 'password',
            'role' => UserRole::Cajero,
            'active' => true,
        ]);

        $catalog = [
            'Helados' => [
                ['Helado 1 bola', 4.00], ['Helado 2 bolas', 7.00], ['Helado 3 bolas', 9.50],
                ['Cono simple', 5.00], ['Cono doble', 8.00], ['Copa especial', 14.00], ['Banana split', 16.00],
            ],
            'Para llevar' => [
                ['Pote 1/2 litro', 15.00, 20], ['Pote 1 litro', 28.00, 15], ['Torta helada', 65.00, 4],
            ],
            'Paletas' => [
                ['Paleta de fruta', 3.50, 40], ['Paleta de crema', 4.50, 40], ['Paleta bañada', 6.00, 30],
            ],
            'Bebidas' => [
                ['Agua 625 ml', 2.50, 24], ['Gaseosa 500 ml', 3.50, 24], ['Milkshake', 12.00], ['Frappé', 13.00],
            ],
            'Toppings' => [
                ['Topping extra', 1.50], ['Barquillo', 1.00], ['Salsa de chocolate', 1.50],
            ],
        ];

        $sort = 0;
        $n = 1;
        foreach ($catalog as $categoryName => $products) {
            $category = Category::query()->firstOrCreate(['name' => $categoryName], ['sort_order' => $sort++]);

            foreach ($products as $p) {
                Product::query()->firstOrCreate(['name' => $p[0]], [
                    'category_id' => $category->id,
                    'code' => 'P'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                    'price' => $p[1],
                    'track_stock' => isset($p[2]),
                    'stock' => $p[2] ?? 0,
                    'stock_min' => isset($p[2]) ? 5 : 0,
                ]);
                $n++;
            }
        }

        Customer::query()->firstOrCreate(['doc_type' => IdentityDocType::Dni, 'doc_number' => '72243561'], ['name' => 'CHAVEZ HUINCHO JORGE']);
        Customer::query()->firstOrCreate(['doc_type' => IdentityDocType::Ruc, 'doc_number' => '20601234567'], [
            'name' => 'DISTRIBUIDORA COMERCIAL DEL PACIFICO S.A.C.',
            'address' => 'AV. REPUBLICA DE PANAMA 3565, SURQUILLO - LIMA',
        ]);
    }
}
