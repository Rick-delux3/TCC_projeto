<?php

namespace Database\Factories;

use App\Models\Imobiliaria;
use App\Models\ImobiliariaSetor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImobiliariaSetor>
 */
class ImobiliariaSetorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Imobiliaria::factory(),
            'key' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'email' => fake()->safeEmail(),
        ];
    }
}
