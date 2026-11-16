<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'code' => 'P'.fake()->unique()->numerify('#####'),
            'name' => 'Helado '.fake()->unique()->word(),
            'price' => fake()->randomElement([3.5, 5, 6, 8, 12]),
            'unit' => 'NIU',
            'tax_affectation' => '10',
            'track_stock' => false,
            'stock' => 0,
            'stock_min' => 0,
            'active' => true,
        ];
    }

    public function withStock(float $stock, float $min = 0): static
    {
        return $this->state(['track_stock' => true, 'stock' => $stock, 'stock_min' => $min]);
    }
}
