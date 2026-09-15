<?php

namespace App\Console\Commands;

use App\Exceptions\LeadLoversApiException;
use App\Models\Lead;
use App\Services\LeadLoversApiClient;
use App\Services\LeadLoversLeadResolver;
use Illuminate\Console\Command;

class BackfillLeadLoversLeadIdsCommand extends Command
{
    protected $signature = 'leadlovers:backfill-lead-ids
        {--dry-run : Consulta e relata sem persistir IDs}
        {--id=* : Limita o processamento aos IDs locais informados}
        {--chunk=100 : Quantidade de leads por lote}';

    protected $description = 'Concilia com seguranca IDs remotos de leads enviados antes da nova API';

    public function handle(
        LeadLoversApiClient $leadLovers,
        LeadLoversLeadResolver $resolver,
    ): int {
        if (! config('services.leadlovers.enabled', false)) {
            $this->error('Integracao com a LeadLovers desativada. Nenhuma chamada foi realizada.');

            return self::FAILURE;
        }

        $localIds = $this->localIds();

        if ($localIds === false) {
            return self::FAILURE;
        }

        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 1000,
            ],
        ]);

        if (! is_int($chunk)) {
            $this->error('Informe --chunk entre 1 e 1000.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $stats = [
            'scanned' => 0,
            'already_filled' => 0,
            'reconciled' => 0,
            'would_reconcile' => 0,
            'missing' => 0,
            'ambiguous' => 0,
            'invalid_contract' => 0,
            'invalid_email' => 0,
            'errors' => 0,
        ];
        $remoteFailure = false;
        $query = Lead::query()
            ->whereNotNull('sent_to_leadlovers_at')
            ->whereIn('leadlovers_status', ['sent', 'send'])
            ->orderBy('id');

        if ($localIds !== []) {
            $query->whereKey($localIds);
        }

        $this->info($dryRun
            ? 'Backfill em modo dry-run; nenhum ID sera persistido.'
            : 'Iniciando backfill dos IDs LeadLovers.');

        $query->chunkById($chunk, function ($leads) use (
            $leadLovers,
            $resolver,
            $dryRun,
            &$stats,
            &$remoteFailure,
        ): bool {
            foreach ($leads as $lead) {
                $stats['scanned']++;

                if ($resolver->positiveInteger($lead->leadlovers_lead_id) !== null) {
                    $stats['already_filled']++;

                    continue;
                }

                $email = $resolver->normalizedEmail($lead->email);

                if ($email === null) {
                    $stats['invalid_email']++;
                    $this->warn("Lead local {$lead->id}: e-mail invalido; ignorado.");

                    continue;
                }

                try {
                    $result = $leadLovers->searchLeads(
                        $resolver->searchPayload($email)
                    );
                } catch (LeadLoversApiException $exception) {
                    $stats['errors']++;
                    $remoteFailure = true;
                    $this->error(
                        "Lead local {$lead->id}: consulta remota falhou"
                        .($exception->statusCode !== null
                            ? " (HTTP {$exception->statusCode})"
                            : '')
                        .'. O comando pode ser retomado com seguranca.'
                    );

                    return false;
                }

                $match = $resolver->uniqueExactMatch($result, $email);

                if ($match['outcome'] === 'missing') {
                    $stats['missing']++;
                    $this->warn("Lead local {$lead->id}: ausente na busca remota.");

                    continue;
                }

                if ($match['outcome'] !== 'matched' || ! is_int($match['lead_id'])) {
                    $bucket = in_array($match['outcome'], [
                        'missing_lead_id',
                        'invalid_contract',
                    ], true)
                        ? 'invalid_contract'
                        : 'ambiguous';
                    $stats[$bucket]++;
                    $this->warn(
                        "Lead local {$lead->id}: conciliacao recusada ({$match['outcome']})."
                    );

                    continue;
                }

                if ($dryRun) {
                    $stats['would_reconcile']++;
                    $this->line("Lead local {$lead->id}: conciliacao disponivel.");

                    continue;
                }

                $updated = Lead::query()
                    ->whereKey($lead->id)
                    ->whereNull('leadlovers_lead_id')
                    ->update([
                        'leadlovers_lead_id' => $match['lead_id'],
                    ]);

                if ($updated === 1) {
                    $stats['reconciled']++;
                    $this->line("Lead local {$lead->id}: ID remoto conciliado.");
                } else {
                    $stats['already_filled']++;
                    $this->warn(
                        "Lead local {$lead->id}: alterado concorrentemente; ID nao sobrescrito."
                    );
                }
            }

            return true;
        });

        $this->table(['Resultado', 'Quantidade'], collect($stats)
            ->map(fn (int $value, string $key): array => [$key, $value])
            ->values()
            ->all());

        return $remoteFailure
            || $stats['missing'] > 0
            || $stats['ambiguous'] > 0
            || $stats['invalid_contract'] > 0
            || $stats['invalid_email'] > 0
                ? self::FAILURE
                : self::SUCCESS;
    }

    /**
     * @return array<int, int>|false
     */
    private function localIds(): array|false
    {
        $values = $this->option('id');
        $values = is_array($values) ? $values : [];
        $ids = [];

        foreach ($values as $value) {
            if (! is_string($value) || preg_match('/\A[1-9]\d*\z/', $value) !== 1) {
                $this->error('Cada --id deve ser um inteiro local positivo.');

                return false;
            }

            $id = filter_var($value, FILTER_VALIDATE_INT);

            if (! is_int($id) || $id <= 0) {
                $this->error('Cada --id deve ser um inteiro local positivo.');

                return false;
            }

            $ids[$id] = $id;
        }

        return array_values($ids);
    }
}
