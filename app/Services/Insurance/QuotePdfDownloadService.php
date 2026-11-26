<?php

namespace App\Services\Insurance;

use App\Exceptions\InsuranceProviderDisabled;
use App\Exceptions\QuotePdfUnavailable;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceQuote;
use App\Services\Insurance\Payloads\TooRentalGuaranteePayloadBuilder;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Download do PDF da cotação ("Baixar cotação") pela página de análises.
 *
 * Regras (26/11/2026):
 * - só para companhia aprovada; um PDF por companhia, da cotação exibida no
 *   card (a selecionada na rodada atual; a conjunta quando é ela a aprovada);
 * - reanálise passa a servir o PDF da cotação nova;
 * - validade de 30 meses a partir da rodada; a Tokio usa o dtVldad da cotação;
 * - buscado no clique e guardado em cache privado; falha nunca altera a análise;
 * - Porto: as rotas de PDF exigem proposta em digitação ou biometria aprovada,
 *   então a página informa que o PDF está indisponível.
 */
class QuotePdfDownloadService
{
    /** Companhias com PDF da cotação validado em homologação. */
    public const PROVIDERS = ['too', 'pottencial', 'tokio'];

    public const STATE_AVAILABLE = 'available';

    public const STATE_EXPIRED = 'expired';

    public const STATE_UNSUPPORTED = 'unsupported';

    private const DOCUMENT_KEY_PREFIX = 'page-quote-pdf-';

    public function __construct(private readonly ProviderDocumentDownload $documents) {}

    /** Cotação aprovada e com preço que o card exibe na rodada atual. */
    public function pdfQuote(InsuranceAnalysis $analysis): ?InsuranceQuote
    {
        $run = $analysis->currentProviderRun;
        if ($analysis->status !== 'approved' || ! $run?->selected_quote_id) {
            return null;
        }
        foreach ($run->scenarios as $scenario) {
            $quote = $scenario->quotes->firstWhere('id', $run->selected_quote_id);
            if ($quote) {
                return $quote->status === 'approved' && $quote->payment_status === 'ready' && filled($quote->quote_id)
                    ? $quote->setRelation('scenario', $scenario) : null;
            }
        }

        return null;
    }

    /**
     * Estado do botão no card; null quando a companhia não tem cotação aprovada.
     *
     * @return array{state: string, quote: ?InsuranceQuote, expires_at: ?CarbonImmutable}|null
     */
    public function state(InsuranceAnalysis $analysis): ?array
    {
        $quote = $this->pdfQuote($analysis);
        if (! $quote) {
            return null;
        }
        if (! in_array($analysis->provider, self::PROVIDERS, true)) {
            return ['state' => self::STATE_UNSUPPORTED, 'quote' => null, 'expires_at' => null];
        }
        $expiresAt = $this->expiresAt($analysis, $quote);

        return [
            'state' => $expiresAt !== null && CarbonImmutable::now()->greaterThan($expiresAt) ? self::STATE_EXPIRED : self::STATE_AVAILABLE,
            'quote' => $quote,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{contents: string, name: string}
     *
     * @throws QuotePdfUnavailable quando a regra não permite o download
     * @throws RuntimeException quando a companhia não entrega um PDF válido
     */
    public function download(InsuranceAnalysis $analysis, InsuranceQuote $quote): array
    {
        $analysis->loadMissing(['currentProviderRun.round', 'currentProviderRun.scenarios.quotes', 'lead.lead_empresa']);
        $state = $this->state($analysis);
        if (($state['state'] ?? null) !== self::STATE_AVAILABLE || ! $state['quote']->is($quote)) {
            throw new QuotePdfUnavailable('PDF da cotação indisponível para download nesta análise.');
        }
        $quote = $state['quote'];
        $name = $this->fileName($analysis);

        // A Pottencial pode levar perto de 1 minuto: o lock dura mais que o download.
        return Cache::lock('quote-pdf-download:'.$quote->id, 120)->block(15, function () use ($analysis, $quote, $name): array {
            return ['contents' => $this->cachedOrFetch($analysis, $quote, $name), 'name' => $name];
        });
    }

    /** cotacao-{companhia}-cliente-{nome}.pdf; PJ: cotacao-{companhia}-cliente-pj-{razão social}.pdf */
    public function fileName(InsuranceAnalysis $analysis): string
    {
        $lead = $analysis->lead;
        $company = $lead && ($lead->tipo_pessoa === 'J' || $lead->lead_empresa !== null);
        $slug = Str::slug((string) $lead?->nome) ?: 'lead-'.$analysis->lead_id;

        return sprintf('cotacao-%s-cliente-%s%s.pdf', $analysis->provider, $company ? 'pj-' : '', Str::limit($slug, 80, ''));
    }

    private function expiresAt(InsuranceAnalysis $analysis, InsuranceQuote $quote): ?CarbonImmutable
    {
        if ($analysis->provider === 'tokio') {
            $validity = data_get($quote->providerResponsePayload(), 'response.cotacaoInfo.dtVldad');
            if (is_string($validity) && preg_match('#^\d{2}/\d{2}/\d{4}$#D', $validity) === 1) {
                $date = CarbonImmutable::createFromFormat('!d/m/Y', $validity, 'America/Sao_Paulo');
                if ($date !== null && $date->format('d/m/Y') === $validity) {
                    return $date->endOfDay();
                }
            }
        }
        $start = $analysis->currentProviderRun?->round?->created_at ?? $quote->created_at;

        return $start ? CarbonImmutable::parse($start)->addMonthsNoOverflow((int) config('analysis_documents.quote_validity_months', 30)) : null;
    }

    private function cachedOrFetch(InsuranceAnalysis $analysis, InsuranceQuote $quote, string $name): string
    {
        $disk = Storage::disk('local');
        $key = self::DOCUMENT_KEY_PREFIX.$quote->id;
        $path = sprintf('analysis-results/quote-pdfs/analysis-%d/%s.pdf', $analysis->id, hash('sha256', $analysis->provider.'|'.$quote->id.'|'.$quote->quote_id));

        $existing = $analysis->events()->where('event_type', 'document_generated')
            ->where('payload->document_key', $key)->latest('id')->first();
        if ($existing && ($existing->payload['path'] ?? null) === $path && $disk->exists($path)
            && $disk->size($path) <= (int) config('analysis_documents.max_bytes')) {
            $contents = $disk->get($path);
            if (hash_equals((string) ($existing->payload['sha256'] ?? ''), hash('sha256', $contents))) {
                return $this->documents->validatePdf($contents);
            }
        }

        // Companhia desligada no .env: só serve o que já está guardado.
        if (! config('features.insurance_analysis.enabled', false) || ! InsuranceProviderResolver::providerEnabled($analysis->provider)) {
            throw new InsuranceProviderDisabled($analysis->provider);
        }

        $contents = $this->documents->validatePdf($this->fetch($analysis, $quote));
        if (! $disk->put($path, $contents, ['visibility' => 'private'])) {
            throw new RuntimeException('Não foi possível salvar o PDF da cotação em armazenamento privado.');
        }
        $analysis->events()->create([
            'event_type' => 'document_generated',
            'payload' => [
                'attempt_id' => $analysis->currentAttemptContext()['attempt_id'] ?? null,
                'document_key' => $key, 'document_type' => 'quote_pdf', 'source' => 'page', 'quote_id' => $quote->id,
                'disk' => 'local', 'path' => $path, 'name' => $name,
                'sha256' => hash('sha256', $contents), 'bytes' => strlen($contents),
            ],
        ]);

        return $contents;
    }

    private function fetch(InsuranceAnalysis $analysis, InsuranceQuote $quote): string
    {
        return match ($analysis->provider) {
            'too' => $this->tooPdf($quote),
            'pottencial' => app(PottencialService::class)->getRentalGuaranteeQuotePdf((string) $quote->quote_id),
            'tokio' => app(TokioService::class)->quotePdf(
                (string) $quote->quote_id,
                (string) data_get($quote->providerResponsePayload(), 'response.cotacaoInfo.token', ''),
            ),
            default => throw new QuotePdfUnavailable('Companhia sem PDF da cotação disponível na página.'),
        };
    }

    /** Mesma ficha, cotação e condição de pagamento (29x) exibidas no card. */
    private function tooPdf(InsuranceQuote $quote): string
    {
        $payload = app(TooRentalGuaranteePayloadBuilder::class)->buildQuotePdfPayload($quote, (string) $quote->tooNumeroProposta());
        $result = app(TooService::class)->getQuotePdf((string) $quote->quote_id, $payload);
        if (! ($result['success'] ?? false) || ! is_string($result['pdf_content'] ?? null)) {
            throw new RuntimeException(sprintf('A Too não disponibilizou o PDF da cotação (HTTP %s).', $result['http_status'] ?? 'sem resposta'));
        }

        return $result['pdf_content'];
    }
}
