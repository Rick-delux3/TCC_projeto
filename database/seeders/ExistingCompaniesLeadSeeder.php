<?php

namespace Database\Seeders;

use App\Models\Imobiliaria;
use App\Models\Lead;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ExistingCompaniesLeadSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Este seeder de demonstracao exige ambiente local ou testing.');
        }

        $created = 0;
        $companies = 0;

        (new Lead)->getConnection()->transaction(function () use (&$created, &$companies): void {
            foreach (Imobiliaria::query()->lazyById(100) as $company) {
                $companies++;

                for ($position = 1; $position <= 15; $position++) {
                    $lead = $company->leads()->firstOrCreate([
                        'email' => "demo.empresa-{$company->id}.lead-{$position}@example.test",
                    ], [
                        'nome' => fake('pt_BR')->name(),
                        'tel' => $position % 4 === 0 ? null : '1190000'.str_pad((string) $position, 4, '0', STR_PAD_LEFT),
                        'tipo_solicitante' => 'imobiliaria_cadastrada',
                        'tipo_locacao' => 'residencial',
                        'estado_civil' => 'solteiro',
                        'imobiliaria' => $company->name,
                        'tags_originais' => $company->name,
                        'status' => ['novo', 'em-andamento', 'qualificado'][$position % 3],
                        'origem' => 'imobiliaria_cadastrada',
                        'leadlovers_status' => 'pending',
                        'aceite_termos' => false,
                        'observacoes' => 'Lead ficticio de demonstracao criado por ExistingCompaniesLeadSeeder. Nao enviar a integracoes externas.',
                        'created_at' => now()->subDays($position - 1)->subMinutes($position * 17),
                    ]);

                    if (! $lead->wasRecentlyCreated) {
                        continue;
                    }

                    $lead->endereco()->create([
                        'cidade_imovel' => $company->city,
                        'estado' => $company->state,
                    ]);
                    $lead->despesas()->create([
                        'valor_aluguel' => 1200 + ($position * 125),
                        'valor_agua' => 0,
                        'valor_luz' => 0,
                        'valor_gas' => 0,
                        'valor_condominio' => 0,
                        'valor_iptu' => 0,
                        'outras_despesas' => 0,
                        'valor_total_encargos' => 1200 + ($position * 125),
                    ]);
                    $created++;
                }
            }
        });

        $this->command?->info("{$created} leads criados para {$companies} imobiliarias; lote de 15 por imobiliaria.");
    }
}
