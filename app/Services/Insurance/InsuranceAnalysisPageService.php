<?php

namespace App\Services\Insurance;

use App\Models\Corretor;
use App\Models\InsuranceAnalysis;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class InsuranceAnalysisPageService
{
    public function __construct(
        private readonly InsuranceAnalysisReadService $reader,
        private readonly AnalysisQuoteSummary $quotes,
        private readonly AnalysisQuoteComparison $comparison,
    ) {}

    /** @return array<string, mixed> */
    public function read(Lead $lead, string $viewerType, User|Corretor $viewer): array
    {
        $data = $this->reader->read($lead);
        $batch = $data['batch'];
        $analyses = $data['analyses'];
        $admin = $viewerType === 'admin';
        $prefix = $admin ? 'admin.insurance-analyses.' : 'insurance-analyses.';
        $returnUrl = route($admin ? 'Dashboard-Admin' : 'company.dashboard').'#leads-section';
        $refreshUrl = route($prefix.'data', $lead);
        $enabled = (bool) config('features.insurance_analysis.enabled', false);
        $permissions = [
            'view' => true,
            'edit_lead' => ! $admin || Gate::forUser($viewer)->allows('edit-leads'),
            'create_analysis' => $enabled && (! $admin || Gate::forUser($viewer)->allows('create-analysis')),
        ];
        $total = max((int) $batch?->total_providers, $analyses->count());
        $completed = $analyses->filter(fn (InsuranceAnalysis $analysis): bool => ProviderAnalysisStatus::isTerminal($analysis->status))->count();
        $allTerminal = $total > 0 && $completed === $total;
        $final = $allTerminal && $batch->finished_at !== null
            && in_array($batch->status, ['completed', 'completed_with_errors'], true);
        $quotes = $analyses->map(fn (InsuranceAnalysis $analysis): array => $this->quotes->summarize($analysis))->keyBy('analysis_id');
        $comparison = $this->comparison->compare($quotes);
        $reason = match (true) {
            $batch === null => 'awaiting_batch',
            ! $allTerminal => 'awaiting_results',
            ! $final => 'awaiting_consolidation',
            $quotes->where('status', 'approved')->isEmpty() => 'no_approved_quotes',
            default => $comparison['comparison_issue'],
        };
        $progress = [
            'total' => $total,
            'finished' => $completed,
            'pending' => $total - $completed,
            'approved' => $analyses->where('status', 'approved')->count(),
            'rejected' => $analyses->where('status', 'rejected')->count(),
            'failed' => $analyses->where('status', 'failed')->count(),
            'percent' => $total > 0 ? (int) floor($completed * 100 / $total) : 0,
        ];
        $page = [
            'version' => 1,
            'lead' => [
                'id' => $lead->id,
                'nome' => $lead->nome,
                'email' => $lead->email,
                'tel' => $lead->tel,
                'cpf' => $lead->cpf,
                'data_nascimento' => $lead->data_nascimento?->format('Y-m-d'),
                'tipo_locacao' => $lead->tipo_locacao?->value,
                'tipo_solicitante' => $lead->tipo_solicitante,
                'endereco' => $lead->endereco?->only(['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade_imovel', 'estado']),
                'despesas' => $lead->despesas?->only(['valor_aluguel', 'valor_agua', 'valor_luz', 'valor_gas', 'valor_condominio', 'valor_iptu', 'outras_despesas', 'valor_total_encargos']),
                'conjuge' => $lead->conjuge?->only(['nome', 'cpf']),
            ],
            'navigation' => ['viewer_type' => $viewerType, 'return_url' => $returnUrl],
            'awaiting_batch' => $batch === null,
            'batch' => $batch === null ? null : [
                'id' => $batch->id,
                'status' => $batch->status,
                'total_providers' => $total,
                'completed_providers' => $progress['approved'] + $progress['rejected'],
                'failed_providers' => $progress['failed'],
                'started_at' => $batch->started_at?->toIso8601String(),
                'finished_at' => $batch->finished_at?->toIso8601String(),
                'updated_at' => $batch->updated_at?->toIso8601String(),
            ],
            'progress' => $progress,
            'result' => ['is_final' => $final, 'status' => $final ? InsuranceBatchResult::status($batch) : null],
            'analyses' => $analyses->map(function (InsuranceAnalysis $analysis) use ($data, $quotes, $enabled, $permissions, $admin, $viewer, $prefix): array {
                $attempt = $data['analysisAttempts'][$analysis->id];
                $allowed = $enabled && ($admin || (int) $analysis->company_id === (int) $viewer->company_id);

                return [
                    'id' => $analysis->id,
                    'provider' => $analysis->provider,
                    'product' => $analysis->product,
                    'status' => $analysis->status,
                    'provider_status' => $analysis->provider_status,
                    'is_terminal' => ProviderAnalysisStatus::isTerminal($analysis->status),
                    'attempt' => $attempt,
                    'premium_amount' => $analysis->premium_amount,
                    'gross_premium' => $analysis->gross_premium,
                    'total_monthly_amount' => $analysis->total_monthly_amount,
                    'quote' => $quotes[$analysis->id],
                    'requested_at' => $analysis->requested_at?->toIso8601String(),
                    'finished_at' => $analysis->finished_at?->toIso8601String(),
                    'updated_at' => $analysis->updated_at?->toIso8601String(),
                    'actions' => [
                        'retry' => $this->action($allowed && $permissions['create_analysis'] && in_array($analysis->status, ['failed', 'error'], true), $prefix.'retry', $analysis->id),
                        'reanalysis' => $this->action($allowed && $permissions['create_analysis'] && $this->canReanalyze($analysis), $prefix.'provider-reanalysis', $analysis->id) + ['requires_data_changes' => true],
                        'sync' => $this->action($allowed && $attempt !== null && $this->canSync($analysis), $prefix.'sync-status', $analysis->id),
                    ],
                ];
            })->all(),
            'comparison' => [
                'available' => $reason === null,
                'reason' => $reason,
                'best_quote' => $reason === null ? $comparison['best_quote'] : null,
            ],
            'permissions' => $permissions,
            'actions' => [
                'update_lead' => $this->action($permissions['edit_lead'], $admin ? 'admin.leads.update' : 'dashboard.leads.update', $lead->id, $admin ? 'POST' : 'PUT'),
                'reanalysis' => $this->action($enabled && $permissions['create_analysis'] && $final
                    && (int) $lead->last_analysis_batch_id === $batch->id && $lead->canRequestGeneralReanalysis()
                    && $analyses->contains(fn (InsuranceAnalysis $analysis): bool => $analysis->canRequestProviderReanalysis())
                    && $analyses->filter(fn (InsuranceAnalysis $analysis): bool => $analysis->canRequestProviderReanalysis())
                        ->every(fn (InsuranceAnalysis $analysis): bool => $this->canReanalyze($analysis)),
                    $admin ? 'admin.leads.reanalyze' : 'dashboard.leads.reanalyze', $lead->id),
            ],
            'realtime' => [
                'transport' => 'polling',
                'refresh_url' => $refreshUrl,
                'method' => 'GET',
                'interval_ms' => max(1000, (int) config('insurance_analysis_page.poll_interval_ms', 5000)),
                'should_refresh' => ! $final,
                'broadcasting' => ['enabled' => false, 'channel' => null, 'event' => null],
            ],
        ];

        return $data + ['pageData' => $page, 'viewerType' => $viewerType, 'returnUrl' => $returnUrl, 'refreshUrl' => $refreshUrl];
    }

    /** @return array{available: bool, url: string|null, method: string} */
    private function action(bool $available, string $route, int $id, string $method = 'POST'): array
    {
        return ['available' => $available, 'url' => $available ? route($route, $id) : null, 'method' => $method];
    }

    private function canReanalyze(InsuranceAnalysis $analysis): bool
    {
        $payload = $analysis->providerResponsePayload();

        return $analysis->canRequestProviderReanalysis()
            && (! $analysis->isTooProvider() || (
                filled($payload['numeroProposta'] ?? $payload['numero_proposta'] ?? $analysis->proposal_id)
                && filled($payload['numeroFicha'] ?? $payload['numero_ficha'] ?? $payload['numeroProposta'] ?? $analysis->proposal_id)
            ));
    }

    private function canSync(InsuranceAnalysis $analysis): bool
    {
        if (! $analysis->isTooProvider()) {
            return filled($analysis->quote_id);
        }

        $payload = $analysis->providerResponsePayload();

        return filled($analysis->tooNumeroProposta())
            && (bool) ($payload['too_status_check_stopped'] ?? false)
            && (bool) ($payload['too_manual_sync_available'] ?? false)
            && ! in_array(mb_strtolower((string) $analysis->status), ['approved', 'quoted', 'rejected', 'denied', 'refused', 'failed', 'error'], true);
    }
}
