<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().' '.fake()->unique()->numberBetween(1, 9999),
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
