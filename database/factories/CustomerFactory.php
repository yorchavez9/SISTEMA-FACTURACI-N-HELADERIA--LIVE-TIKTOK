<?php

namespace Database\Factories;

use App\Enums\IdentityDocType;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'doc_type' => IdentityDocType::Dni,
            'doc_number' => fake()->unique()->numerify('########'),
            'name' => mb_strtoupper(fake()->name()),
            'address' => null,
        ];
    }

    public function company(): static
    {
        return $this->state(fn () => [
            'doc_type' => IdentityDocType::Ruc,
            'doc_number' => '20'.fake()->unique()->numerify('#########'),
            'name' => mb_strtoupper(fake()->company()).' S.A.C.',
            'address' => 'AV. LARCO 123, MIRAFLORES',
        ]);
    }
}
