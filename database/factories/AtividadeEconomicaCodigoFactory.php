<?php

namespace Database\Factories;

use App\Models\AtividadeEconomica;
use App\Models\AtividadeEconomicaCodigo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AtividadeEconomicaCodigo>
 */
class AtividadeEconomicaCodigoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'atividade_economica_id' => AtividadeEconomica::factory(),
            'seguradora' => 'tokio',
            'codigo' => (string) fake()->unique()->numberBetween(10000, 99999),
            'ativo' => true,
        ];
    }
}
