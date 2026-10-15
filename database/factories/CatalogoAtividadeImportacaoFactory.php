<?php

namespace Database\Factories;

use App\Models\CatalogoAtividadeImportacao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogoAtividadeImportacao>
 */
class CatalogoAtividadeImportacaoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seguradora' => 'tokio',
            'arquivo_origem' => 'catalogo-teste.pdf',
            'sha256_origem' => hash('sha256', 'pdf-teste'),
            'arquivo_catalogo' => 'catalogo-teste.csv',
            'sha256_catalogo' => hash('sha256', 'csv-teste'),
            'quantidade_descricoes' => 1,
            'quantidade_codigos' => 1,
            'codigos_ausentes' => [],
            'revisao_necessaria' => false,
        ];
    }
}
