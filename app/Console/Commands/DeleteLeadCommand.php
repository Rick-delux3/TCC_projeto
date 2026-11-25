<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

class DeleteLeadCommand extends Command
{
    protected $signature = 'leads:delete {id : ID do lead} {--force : Confirmar a exclusão sem interação}';

    protected $description = 'Exclui do banco um lead e seus pacotes de análise pelo ID.';

    public function handle(): int
    {
        $id = filter_var($this->argument('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->error('Informe um ID inteiro positivo.');

            return self::INVALID;
        }

        $lead = Lead::query()->find($id, ['id']);
        if (! $lead) {
            $this->error("Lead #{$id} não encontrado.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Excluir definitivamente o lead #{$id} e todos os seus pacotes de análise?")) {
            $this->info('Exclusão cancelada.');

            return self::FAILURE;
        }

        if (! Lead::query()->whereKey($id)->delete()) {
            $this->error("Lead #{$id} já removido.");

            return self::FAILURE;
        }

        $this->info("Lead #{$id} e seus pacotes de análise excluídos do banco.");

        return self::SUCCESS;
    }
}
