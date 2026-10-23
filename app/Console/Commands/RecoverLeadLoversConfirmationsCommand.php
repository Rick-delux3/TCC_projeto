<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadLoversConfirmationRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecoverLeadLoversConfirmationsCommand extends Command
{
    protected $signature = 'leadlovers:recover-confirmations
        {--id=* : IDs locais a verificar}
        {--limit=100 : Maximo de registros examinados}
        {--apply : Agenda a confirmacao dos IDs explicitamente selecionados}';

    protected $description = 'Exibe ou agenda a recuperacao seletiva de confirmacoes antigas no TCC';

    public function handle(LeadLoversConfirmationRecoveryService $recovery): int
    {
        if (config('branding.active') !== 'tcc') {
            $this->error('Comando disponivel somente no perfil TCC.');

            return self::FAILURE;
        }

        $ids = [];
        foreach ($this->option('id') as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                $this->error('Informe somente IDs locais inteiros positivos.');

                return self::FAILURE;
            }
            $ids[] = $id;
        }

        $ids = array_unique($ids);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $apply = (bool) $this->option('apply');

        if ($limit === false || ($apply && $ids === [])) {
            $this->error('Informe --limit entre 1 e 1000; --apply exige pelo menos um --id.');

            return self::FAILURE;
        }

        if ($apply && ! $recovery->canSchedule()) {
            $this->error('A recuperacao exige integracao habilitada e uma fila assincrona.');

            return self::FAILURE;
        }

        $leads = Lead::query()->where('leadlovers_status', 'failed')
            ->where('leadlovers_lead_id', '>', 0)->whereNull('sent_to_leadlovers_at')
            ->when($ids !== [], fn ($query) => $query->whereKey($ids))
            ->orderBy('id')->limit($limit)->get();
        $eligible = 0;
        $scheduled = 0;

        foreach ($leads as $lead) {
            if (! $recovery->eligible($lead)) {
                continue;
            }

            $eligible++;
            $this->line("Lead {$lead->id}: elegivel para confirmar vinculo.");
            if (! $apply) {
                continue;
            }

            try {
                $scheduled += (int) $recovery->schedule((int) $lead->id);
            } catch (Throwable $exception) {
                Log::warning('Falha ao agendar recuperacao seletiva LeadLovers.', [
                    'lead_id' => $lead->id,
                    'exception' => $exception::class,
                ]);
                $this->error('Falha ao agendar. O estado anterior foi preservado quando ainda nao havia processamento.');

                return self::FAILURE;
            }
        }

        $this->info("Elegiveis: {$eligible}; agendados: {$scheduled}.");
        if (! $apply) {
            $this->comment('Previa: nenhuma alteracao ou chamada a API foi realizada.');
        }

        return self::SUCCESS;
    }
}
