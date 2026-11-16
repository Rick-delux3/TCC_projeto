<?php

namespace App\Services\Insurance;

use App\Exceptions\RefusalLetterUnavailable;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceQuote;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Download da carta de recusa pela página de análises.
 *
 * Regras (13/11/2026):
 * - só existe link quando o pacote tem ao menos uma aprovação; recusa unânime
 *   segue por e-mail com todas as cartas;
 * - uma carta por companhia recusada, buscada no clique e guardada em cache privado;
 * - com cônjuge, vale somente a carta da análise conjunta;
 * - uma carta por proposta/orçamento, nunca por plano.
 */
class RefusalLetterDownloadService
{
    /** Companhias com carta liberada na página. As demais entram aqui conforme forem validadas. */
    public const PROVIDERS = ['too', 'pottencial', 'porto'];

    private const DOCUMENT_KEY_PREFIX = 'page-refusal-';

    public function __construct(private readonly ProviderDocumentDownload $documents) {}

    /**
     * Cotação cuja carta representa a recusa da companhia na rodada atual.
     * A conjunta, quando existe, substitui as individuais.
     */
    public function letterQuote(InsuranceAnalysis $analysis): ?InsuranceQuote
    {
        if (! in_array($analysis->provider, self::PROVIDERS, true) || $analysis->status !== 'rejected') {
            return null;
        }

        $scenarios = ($analysis->currentProviderRun?->scenarios ?? collect())->keyBy('kind');
        $scenario = $scenarios->get('joint') ?? $scenarios->get('primary');
        if (in_array($analysis->provider, ['pottencial', 'porto'], true) && $scenarios->has('spouse') && ! $scenarios->has('joint')) {
            return null;
        }
        if ($analysis->provider === 'pottencial') {
            $candidates = $scenario?->quotes->filter(fn (InsuranceQuote $quote): bool => $quote->status === 'rejected'
                && filled($this->proposal($quote)));
            $quote = $candidates?->firstWhere('quote_id', $analysis->quote_id) ?? $candidates?->first();

            return $quote?->setRelation('scenario', $scenario);
        }
        $quote = $scenario?->quotes->firstWhere('status', 'rejected');

        return $quote && filled($this->proposal($quote)) ? $quote->setRelation('scenario', $scenario) : null;
    }

    /** O link aparece só com outra companhia aprovada no mesmo pacote. */
    public function batchHasApproval(InsuranceAnalysis $analysis): bool
    {
        return filled($analysis->insurance_analysis_batch_id)
            && InsuranceAnalysis::query()
                ->where('insurance_analysis_batch_id', $analysis->insurance_analysis_batch_id)
                ->where('status', 'approved')
                ->exists();
    }

    /**
     * @return array{contents: string, name: string}
     *
     * @throws RefusalLetterUnavailable quando a regra não permite o download
     * @throws RuntimeException quando a companhia não entrega um PDF válido
     */
    public function download(InsuranceAnalysis $analysis, InsuranceQuote $quote): array
    {
        $analysis->loadMissing('currentProviderRun.scenarios.quotes');
        $letterQuote = $this->letterQuote($analysis);
        if (! $letterQuote || ! $letterQuote->is($quote) || ! $this->batchHasApproval($analysis)) {
            throw new RefusalLetterUnavailable('Carta de recusa indisponível para download nesta análise.');
        }

        $name = sprintf('carta-recusa-%s-lead-%d.pdf', $analysis->provider, $analysis->lead_id);

        return Cache::lock('refusal-letter-download:'.$letterQuote->id, 60)->block(15, function () use ($analysis, $letterQuote, $name): array {
            return ['contents' => $this->cachedOrFetch($analysis, $letterQuote, $name), 'name' => $name];
        });
    }

    private function cachedOrFetch(InsuranceAnalysis $analysis, InsuranceQuote $quote, string $name): string
    {
        $disk = Storage::disk('local');
        $proposal = $this->proposal($quote);
        $key = self::DOCUMENT_KEY_PREFIX.$quote->id;
        $path = sprintf('analysis-results/refusal-letters/analysis-%d/%s.pdf', $analysis->id, hash('sha256', $analysis->provider.'|'.$proposal));

        $existing = $analysis->events()->where('event_type', 'document_generated')
            ->where('payload->document_key', $key)->latest('id')->first();
        if ($existing && ($existing->payload['path'] ?? null) === $path && $disk->exists($path)
            && $disk->size($path) <= (int) config('analysis_documents.max_bytes')) {
            $contents = $disk->get($path);
            if (hash_equals((string) ($existing->payload['sha256'] ?? ''), hash('sha256', $contents))) {
                return $this->documents->validatePdf($contents);
            }
        }

        $contents = $this->documents->validatePdf($this->fetch($analysis, $quote, $proposal));
        if (! $disk->put($path, $contents, ['visibility' => 'private'])) {
            throw new RuntimeException('Não foi possível salvar a carta de recusa em armazenamento privado.');
        }
        $analysis->events()->create([
            'event_type' => 'document_generated',
            'payload' => [
                'attempt_id' => $analysis->currentAttemptContext()['attempt_id'] ?? null,
                'document_key' => $key, 'source' => 'page', 'quote_id' => $quote->id,
                'disk' => 'local', 'path' => $path, 'name' => $name,
                'sha256' => hash('sha256', $contents), 'bytes' => strlen($contents),
            ],
        ]);

        return $contents;
    }

    private function fetch(InsuranceAnalysis $analysis, InsuranceQuote $quote, string $proposal): string
    {
        return match ($analysis->provider) {
            'too' => app(TooService::class)->getCreditOpinionPdf($this->tooCpf($analysis, $quote), $proposal),
            'pottencial' => app(PottencialService::class)->getRentalGuaranteeRefusalLetter($proposal),
            'porto' => app(PortoService::class)->refusalLetter($proposal),
            default => throw new RefusalLetterUnavailable('Companhia sem carta de recusa disponível na página.'),
        };
    }

    private function proposal(InsuranceQuote $quote): ?string
    {
        return match ($quote->provider) {
            'too' => $quote->tooNumeroProposta(),
            'pottencial' => $quote->quote_id,
            'porto' => PortoQuoteResponse::budgetNumber($quote->quote_id),
            default => null,
        };
    }

    /** A Too identifica a carta pelo CPF do pretendente principal enviado na ficha. */
    private function tooCpf(InsuranceAnalysis $analysis, InsuranceQuote $quote): string
    {
        $cpf = data_get($quote->request_payload, 'ficha_payload.pretendentes.0.cpf')
            ?? data_get($analysis->request_payload, 'ficha_payload.pretendentes.0.cpf')
            ?? data_get($analysis->request_payload, 'pretendentes.0.cpf');

        if (! filled($cpf)) {
            throw new RuntimeException('CPF da proposta da Too não identificado.');
        }

        return (string) $cpf;
    }
}
