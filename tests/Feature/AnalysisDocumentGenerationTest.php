<?php

use App\Exceptions\AnalysisDocumentsNotReady;
use App\Exceptions\ObsoleteInsuranceAnalysisAttempt;
use App\Jobs\SendAnalysisResultsEmailJob;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use App\Models\Lead;
use App\Services\Insurance\AnalysisDocumentService;
use App\Services\Insurance\ProviderDocumentDownload;
use App\Services\Insurance\ProviderRefusalLetterService;
use App\Services\TooService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    config([
        'features.insurance_analysis.enabled' => true,
        'services.pottencial.enabled' => true, 'services.pottencial.base_url' => 'https://pottencial.example.test',
        'services.too.enabled' => true, 'services.too.base_url' => 'https://too.example.test',
    ]);
    Cache::put('pottencial_access_token', 'test-token', 60);
    Cache::put('too_access_token', 'test-token', 60);
    Storage::fake('local');
    Mail::fake();
    Http::preventStrayRequests();
});

function documentBatch(array $statuses = ['rejected', 'rejected']): InsuranceAnalysisBatch
{
    $lead = Lead::query()->create(['nome' => 'Documents', 'email' => 'documents@example.test', 'tipo_solicitante' => 'locatario']);
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'total_providers' => count($statuses), 'status' => 'completed', 'finished_at' => now()]);
    foreach ($statuses as $index => $status) {
        $analysis = $batch->analyses()->create([
            'lead_id' => $lead->id, 'provider' => $index === 0 ? 'pottencial' : 'too', 'product' => 'fianca_locaticia_residencial',
            'status' => $status, 'quote_id' => 'quote-123', 'proposal_id' => '123', 'gross_premium' => '1000.00',
            'request_payload' => ['ficha_payload' => ['pretendentes' => [['cpf' => '52998224725']]]],
        ]);
        $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'documents']]);
    }

    return $batch;
}

function testLetterPdf(): string
{
    return "%PDF-1.4\n1 0 obj <<>> endobj\ntrailer <<>>\n%%EOF\n";
}

it('keeps approval delivery pending while the view is disabled or empty', function (bool $enabled) {
    config(['analysis_documents.own_pdf_enabled' => $enabled]);
    $batch = documentBatch(['approved']);
    (new SendAnalysisResultsEmailJob($batch->id, 'documents'))->handle();
    expect($batch->fresh()->email_status)->toBe('pending')
        ->and($batch->fresh()->email_sent_at)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Mail::assertNothingSent();
    Http::assertNothingSent();
})->with([false, true]);

it('renders one private consolidated PDF and reuses it on retries', function () {
    config(['analysis_documents.own_pdf_enabled' => true]);
    $batch = documentBatch(['approved', 'rejected']);
    $view = Mockery::mock(\Illuminate\Contracts\View\View::class);
    $view->shouldReceive('render')->twice()->andReturn('<html><body><p>Test document</p></body></html>');
    View::partialMock()->shouldReceive('make')->twice()->withArgs(function ($name, $data): bool {
        expect($name)->toBe('emails.analysis-summary-pdf')
            ->and($data['result']['best_quote']['provider'])->toBe('pottencial')
            ->and($data['result'])->toHaveKeys(['lead', 'best_quote', 'other_quotes', 'analyses', 'is_reanalysis']);

        return true;
    })->andReturn($view);
    $service = app(AnalysisDocumentService::class);
    $first = $service->generate($batch, 'documents');
    expect($first)->toHaveCount(1)->and($service->generate($batch, 'documents'))->toBe($first)
        ->and(file_get_contents($first[0]['path']))->toStartWith('%PDF-')
        ->and(InsuranceAnalysisEvent::query()->where('event_type', 'document_generated')->count())->toBe(1);
    $event = InsuranceAnalysisEvent::query()->where('event_type', 'document_generated')->first();
    expect($event->payload['disk'])->toBe('local')
        ->and(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'));
    Http::assertNothingSent();
});

it('blocks cached own PDFs when delivery is disabled or the view has no visible text', function (bool $enabled, string $html) {
    config(['analysis_documents.own_pdf_enabled' => true]);
    $batch = documentBatch(['approved']);
    $view = Mockery::mock(\Illuminate\Contracts\View\View::class);
    $view->shouldReceive('render')->times($enabled ? 2 : 1)->andReturn('<p>Test document</p>', $html);
    View::partialMock()->shouldReceive('make')->times($enabled ? 2 : 1)
        ->withArgs(fn (string $name, array $data): bool => $name === 'emails.analysis-summary-pdf' && isset($data['result']))->andReturn($view);
    app(AnalysisDocumentService::class)->generate($batch, 'documents');
    config(['analysis_documents.own_pdf_enabled' => $enabled]);

    (new SendAnalysisResultsEmailJob($batch->id, 'documents'))->handle();

    expect($batch->fresh()->email_status)->toBe('pending')
        ->and($batch->fresh()->email_sent_at)->toBeNull()
        ->and(InsuranceAnalysisEvent::query()->where('event_type', 'document_generated')->count())->toBe(1);
    Mail::assertNothingSent();
    Http::assertNothingSent();
})->with([
    'disabled' => [false, '<p>Test document</p>'],
    'empty' => [true, ''],
    'structure only' => [true, '<html><head><title>PDF</title><style>body { color: black; }</style></head><body>&nbsp; &#160;</body></html>'],
]);

it('reuses a successful refusal letter when the next company fails and retries only the missing document', function () {
    Http::fake([
        'https://pottencial.example.test/insurance/v1/fianca-locaticia/quotes/quote-123/letters' => Http::response(testLetterPdf(), 200),
        'https://too.example.test/fianca/credito/52998224725/123/parecer' => Http::sequence()->push('', 503)->push(testLetterPdf(), 200),
    ]);
    $batch = documentBatch();
    $service = app(AnalysisDocumentService::class);
    expect(fn () => $service->generate($batch, 'documents'))->toThrow(RuntimeException::class, 'HTTP 503');
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    $files = $service->generate($batch, 'documents');
    expect($files)->toHaveCount(2)->and($service->generate($batch, 'documents'))->toBe($files)
        ->and(InsuranceAnalysisEvent::query()->where('event_type', 'document_generated')->count())->toBe(2);
    Http::assertSentCount(3);
});

it('sends all refusal letters through the job only after every document is available', function () {
    Http::fake([
        'pottencial.example.test/*' => Http::response(testLetterPdf()),
        'too.example.test/*' => Http::sequence()->push('', 503)->push(testLetterPdf()),
    ]);
    $batch = documentBatch();
    $confirmation = Mockery::mock(\Illuminate\Mail\SentMessage::class);
    $confirmation->shouldReceive('getMessageId')->andReturn('refusal-message');
    Mail::shouldReceive('raw')->once()->withArgs(function (string $body, Closure $callback): bool {
        $email = new \Symfony\Component\Mime\Email;
        $callback(new \Illuminate\Mail\Message($email));
        expect($email->getAttachments())->toHaveCount(2)
            ->and($email->getTo()[0]->getAddress())->toBe('documents@example.test');

        return true;
    })->andReturn($confirmation);
    $job = new SendAnalysisResultsEmailJob($batch->id, 'documents');
    expect(fn () => $job->handle())->toThrow(RuntimeException::class)
        ->and($batch->fresh()->email_sent_at)->toBeNull();
    $job->handle();
    $job->handle();
    expect($batch->fresh()->email_status)->toBe('sent');
    Http::assertSentCount(3);
});

it('never fetches refusal letters with an approval or a technical failure', function (array $statuses) {
    $batch = documentBatch($statuses);
    expect(fn () => app(ProviderRefusalLetterService::class)->fetch($batch, 'documents', $batch->analyses()->first()->id))
        ->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([[['approved', 'rejected']], [['failed', 'rejected']]]);

it('keeps a technical failure without approval out of document delivery', function () {
    $batch = documentBatch(['failed', 'rejected']);
    expect(fn () => app(AnalysisDocumentService::class)->generate($batch, 'documents'))->toThrow(AnalysisDocumentsNotReady::class);
    Http::assertNothingSent();
});

it('rejects an obsolete response before persisting the document', function () {
    $batch = documentBatch(['rejected']);
    $analysis = $batch->analyses()->first();
    $analysis->update(['provider' => 'too']);
    $this->mock(TooService::class)->shouldReceive('getCreditOpinionPdf')->once()->andReturnUsing(function () use ($analysis): string {
        $analysis->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'new-documents']]);

        return testLetterPdf();
    });
    expect(fn () => app(AnalysisDocumentService::class)->generate($batch, 'documents'))->toThrow(ObsoleteInsuranceAnalysisAttempt::class)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('accepts PDF bytes and base64 documents', function (bool $base64) {
    Http::fake(['https://too.example.test/document' => Http::response($base64 ? ['base64' => base64_encode(testLetterPdf())] : testLetterPdf())]);
    expect(app(ProviderDocumentDownload::class)->fetch('https://too.example.test/document', []))->toBe(testLetterPdf());
})->with([false, true]);

it('downloads an allowed document URL without forwarding company credentials', function () {
    Http::fake([
        'https://too.example.test/document' => Http::response(['url' => 'https://files.example.test/document.pdf']),
        'https://files.example.test/document.pdf' => Http::response(testLetterPdf()),
    ]);
    expect(app(ProviderDocumentDownload::class)->fetch('https://too.example.test/document', ['Authorization' => 'Bearer secret'], ['files.example.test']))->toBe(testLetterPdf());
    Http::assertSent(fn ($request) => $request->url() === 'https://files.example.test/document.pdf' && ! $request->hasHeader('Authorization'));
});

it('rejects untrusted document URLs and malformed PDF responses', function (mixed $response) {
    Http::fake(['https://too.example.test/document' => Http::response($response)]);
    expect(fn () => app(ProviderDocumentDownload::class)->fetch('https://too.example.test/document', []))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
})->with([
    [['url' => 'https://untrusted.example.test/document.pdf']],
    [['url' => 'http://127.0.0.1/document.pdf']],
    ['<html>error</html>'],
    ["%PDF-1.4\ntruncated"],
]);

it('regenerates corrupted cached documents', function () {
    Http::fake(['pottencial.example.test/*' => fn () => Http::response(testLetterPdf())]);
    $batch = documentBatch(['rejected']);
    $service = app(AnalysisDocumentService::class);
    $service->generate($batch, 'documents');
    $event = InsuranceAnalysisEvent::query()->where('event_type', 'document_generated')->first();
    Storage::disk('local')->put($event->payload['path'], 'broken');
    $service->generate($batch, 'documents');
    expect(Storage::disk('local')->get($event->payload['path']))->toBe(testLetterPdf());
    Http::assertSentCount(2);
});

it('does not record a generated document when private storage fails', function () {
    Http::fake(['pottencial.example.test/*' => Http::response(testLetterPdf())]);
    $disk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->withArgs(fn ($path, $bytes, $options) => $options['visibility'] === 'private')->andReturnFalse();
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    expect(fn () => app(AnalysisDocumentService::class)->generate(documentBatch(['rejected']), 'documents'))->toThrow(RuntimeException::class)
        ->and(InsuranceAnalysisEvent::query()->where('event_type', 'document_generated')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('rejects oversized downloads and HTTP redirects', function (bool $oversized) {
    config(['analysis_documents.max_bytes' => 20]);
    Http::fake(['https://too.example.test/document' => $oversized
        ? Http::response(str_repeat('x', 100))
        : Http::response('', 302, ['Location' => 'https://untrusted.example.test/document'])]);
    expect(fn () => app(ProviderDocumentDownload::class)->fetch('https://too.example.test/document', []))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
})->with([false, true]);
