<?php

namespace Database\Factories;

use App\Models\AtividadeEconomica;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AtividadeEconomica>
 */
class AtividadeEconomicaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'descricao' => fake()->unique()->sentence(5),
            'ativo' => true,
        ];
    }
}
