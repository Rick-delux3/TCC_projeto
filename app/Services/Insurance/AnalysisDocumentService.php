<?php

namespace App\Services\Insurance;

use App\Exceptions\AnalysisDocumentsNotReady;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AnalysisDocumentService
{
    public function __construct(
        private readonly AnalysisResultPreparationService $preparation,
        private readonly ProviderRefusalLetterService $letters,
        private readonly ProviderDocumentDownload $documents,
    ) {}

    /** @return list<array{path: string, name: string}> */
    public function generate(InsuranceAnalysisBatch $batch, string $attemptId): array
    {
        return Cache::lock('analysis-documents:'.$batch->id.':'.hash('sha256', $attemptId), 210)
            ->block(5, function () use ($batch, $attemptId): array {
                $prepared = $this->preparation->prepare($batch, $attemptId);
                if ($prepared['document_type'] === 'none') {
                    throw new AnalysisDocumentsNotReady('O pacote contém falhas técnicas sem aprovação.');
                }

                if ($prepared['document_type'] === 'own_pdf') {
                    if (! config('analysis_documents.own_pdf_enabled') || ! $prepared['best_quote'] || $prepared['comparison_issue']) {
                        throw new AnalysisDocumentsNotReady('PDF próprio aguardando conteúdo ou dados comparáveis.');
                    }

                    $html = view('emails.analysis-summary-pdf', ['result' => $prepared])->render();
                    $text = preg_replace('/<(head|style|script)\b[^>]*>.*?<\/\1>/is', '', $html);
                    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (preg_match('/[^\s\p{Z}]/u', $text) !== 1) {
                        throw new AnalysisDocumentsNotReady('A view do PDF próprio ainda não possui conteúdo.');
                    }

                    return [$this->document($batch, $attemptId, 'summary', $prepared['analyses'][0]['id'], function () use ($html): string {
                        return Pdf::loadHTML($html)->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false])
                            ->setPaper('a4')->output();
                    })];
                }

                $attachments = [];
                foreach ($prepared['analyses'] as $analysis) {
                    $attachments[] = $this->document($batch, $attemptId, 'refusal-'.$analysis['id'], $analysis['id'],
                        fn (): string => $this->letters->fetch($batch, $attemptId, $analysis['id']));
                }

                return $attachments;
            });
    }

    /** @return array{path: string, name: string} */
    private function document(InsuranceAnalysisBatch $batch, string $attemptId, string $key, int $analysisId, \Closure $create): array
    {
        $disk = Storage::disk('local');
        $relativePath = 'analysis-results/batch-'.$batch->id.'/'.hash('sha256', $attemptId).'/'.$key.'.pdf';
        $name = $key === 'summary' ? 'orcamento-seguro-fianca.pdf' : 'carta-recusa-'.$analysisId.'.pdf';
        $existing = InsuranceAnalysisEvent::query()->where('insurance_analysis_id', $analysisId)
            ->where('event_type', 'document_generated')->where('payload->attempt_id', $attemptId)
            ->where('payload->document_key', $key)->latest('id')->first();

        if ($existing && $disk->exists($relativePath) && $disk->size($relativePath) <= (int) config('analysis_documents.max_bytes')) {
            $contents = $disk->get($relativePath);
            if (hash_equals((string) ($existing->payload['sha256'] ?? ''), hash('sha256', $contents))) {
                $this->documents->validatePdf($contents);
                InsuranceAnalysisAttempt::runBatch($batch, $attemptId, fn (): bool => true);

                return ['path' => $disk->path($relativePath), 'name' => $name];
            }
        }

        $contents = $this->documents->validatePdf($create());
        InsuranceAnalysisAttempt::runBatch($batch, $attemptId, function () use ($batch, $attemptId, $key, $analysisId, $disk, $relativePath, $name, $contents): void {
            if (! $disk->put($relativePath, $contents, ['visibility' => 'private'])) {
                throw new RuntimeException('Não foi possível salvar o documento em armazenamento privado.');
            }
            $batch->analyses()->findOrFail($analysisId)->events()->create([
                'event_type' => 'document_generated',
                'payload' => [
                    'attempt_id' => $attemptId, 'document_key' => $key, 'disk' => 'local',
                    'path' => $relativePath, 'name' => $name, 'sha256' => hash('sha256', $contents), 'bytes' => strlen($contents),
                ],
            ]);
        });

        return ['path' => $disk->path($relativePath), 'name' => $name];
    }
}
