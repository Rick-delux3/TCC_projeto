<?php

namespace App\Console\Commands;

use App\Jobs\DeleteExpiredRejectedLeadJob;
use App\Models\Lead;
use Illuminate\Console\Command;

class DispatchExpiredRejectedLeadsCommand extends Command
{
    protected $signature = 'leads:dispatch-expired-rejected
        {--limit=100 : Quantidade maxima de candidatos por execucao}
        {--pretend : Apenas informa os candidatos, sem despachar jobs}';

    protected $description = 'Despacha a verificacao de leads recusados cujo prazo expirou';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 1000,
            ],
        ]);

        if (! is_int($limit)) {
            $this->error('Informe --limit entre 1 e 1000.');

            return self::FAILURE;
        }

        $pretend = (bool) $this->option('pretend');
        $candidates = Lead::query()
            ->expiredRejectedRetention()
            ->orderBy('id')
            ->limit($limit)
            ->get([
                'id',
                'rejected_deletion_due_at',
                'leadlovers_confirmed_tag_version',
            ]);

        $this->info(sprintf(
            '%d lead(s) recusado(s) vencido(s) encontrado(s).',
            $candidates->count()
        ));

        if ($pretend) {
            $this->comment('Modo pretend: nenhum job foi despachado e nenhuma escrita foi realizada.');

            return self::SUCCESS;
        }

        foreach ($candidates as $lead) {
            DeleteExpiredRejectedLeadJob::dispatch(
                leadId: (int) $lead->id,
                expectedDeletionDueAt: $lead
                    ->rejected_deletion_due_at
                    ->toImmutable()
                    ->utc()
                    ->toIso8601String(),
                expectedConfirmedTagVersion: $lead->leadlovers_confirmed_tag_version,
            )->onQueue('leadlovers');
        }

        $this->info(sprintf(
            '%d job(s) despachado(s) para a fila leadlovers.',
            $candidates->count()
        ));

        return self::SUCCESS;
    }
}
