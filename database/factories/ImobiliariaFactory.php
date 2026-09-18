<?php

namespace Database\Factories;

use App\Models\Imobiliaria;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Imobiliaria>
 */
class ImobiliariaFactory extends Factory
{
    protected static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('119########'),
            'cnpj' => fake('pt_BR')->unique()->cnpj(false),
            'cep' => '01001000',
            'city' => 'São Paulo',
            'state' => 'SP',
            'password' => static::$password ??= Hash::make('senha1234'),
            'lead_form_active' => true,
        ];
    }
}
