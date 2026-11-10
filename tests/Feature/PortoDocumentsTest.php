<?php

use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\RunInsuranceQuoteJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use App\Models\InsuranceProviderRun;
use App\Models\Lead;
use App\Services\Insurance\AnalysisDocumentService;
use App\Services\Insurance\InsuranceQuoteCoordinator;
use App\Services\Insurance\ProviderRefusalLetterService;
use App\Services\Insurance\Providers\PortoInsuranceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();
    Cache::flush();
    config([
        'features.insurance_analysis.enabled' => true,
        'queue.default' => 'database',
        'services.porto.enabled' => true,
        'services.porto.base_url' => 'https://portoapicloud-hml.portoseguro.com.br/fianca-locaticia/',
        'services.porto.token_url' => 'https://portoapi-hml.portoseguro.com.br/oauth/v2/access-token',
        'services.porto.client_id' => 'test-client',
        'services.porto.client_secret' => 'test-secret',
        'services.porto.susep' => 'ABC12J',
        'services.porto.cpf_cotador' => '09238037698',
    ]);
});

function portoDocsApi(): string
{
    return 'https://portoapicloud-hml.portoseguro.com.br/fianca-locaticia/emissao/aluguel-essencial/v1';
}

function portoDocsPdf(string $label = 'documento'): string
{
    return "%PDF-1.4\n% {$label}\n1 0 obj <<>> endobj\ntrailer <<>>\n%%EOF\n";
}

/** Orçamentos recusados por conjunto de locatários (principal, cônjuge, conjunta). */
function portoDocsBudgets(): array
{
    return [
        '09238037698' => '000000011900001',
        '38329560856' => '000000011900002',
        '09238037698,38329560856' => '000000011900003',
    ];
}

/**
 * Simula a Porto para documentos. $create decide o retorno do POST pelos
 * locatários; $documents responde aos GETs de PDF.
 */
function portoDocsFake(?Closure $create = null, ?Closure $documents = null): void
{
    Http::fake(function (Request $request) use ($create, $documents) {
        $url = $request->url();
        if (str_contains($url, '/oauth/')) {
            return Http::response(['access_token' => 'porto-token', 'token_type' => 'Bearer', 'expires_in' => 3600]);
        }
        if ($request->method() === 'POST') {
            $documents_ = implode(',', array_column($request['locatarios'], 'cpfCnpj'));

            return $create
                ? $create($documents_)
                : Http::response(['errorCode' => 409, 'errorMessage' => 'Recusado pela Porto', 'detailedMessage' => portoDocsBudgets()[$documents_]], 409);
        }
        if ($request->method() === 'PUT') {
            return Http::response(['status' => '1'], 200);
        }
        if (str_contains($url, '/pdf/')) {
            return $documents
                ? $documents($request)
                : Http::response(['status' => 200, 'message' => 'PDF gerado', 'data' => ['file' => base64_encode(portoDocsPdf($url))]], 200);
        }

        return Http::response(['orcamento' => '000000010009512', 'ofertas' => [[
            'seguro' => '0008', 'nomeSeguro' => 'FIANCA',
            'formasPagamento' => [['formaPagamento' => 'CC', 'condicoesPagamento' => ['condicaoPagamento' => '029', 'quantidadeParcela' => 29, 'valorParcela' => 210.5]]],
        ], [
            'seguro' => '0001', 'nomeSeguro' => 'ESSENCIAL 12',
            'formasPagamento' => [['formaPagamento' => 'CC', 'condicoesPagamento' => ['condicaoPagamento' => '129', 'quantidadeParcela' => 29, 'valorParcela' => 90.1]]],
        ]]], 200);
    });
}

/**
 * Executa um pacote Porto completo com o lead indicado.
 *
 * @return array{0: InsuranceAnalysisBatch, 1: InsuranceAnalysis, 2: InsuranceProviderRun}
 */
function portoDocsBatch(bool $spouse = true): array
{
    $lead = Lead::query()->create([
        'cpf' => '09238037698', 'nome' => 'Pretendente de Teste', 'data_nascimento' => '1990-05-10',
        'tipo_locacao' => 'residencial', 'tipo_solicitante' => 'locatario', 'estado_civil' => $spouse ? 'casado' : 'solteiro',
        'email' => 'principal@example.test', 'tel' => '11987654321',
    ]);
    $lead->despesas()->create(['valor_aluguel' => 1500]);
    $lead->endereco()->create(['cep' => '01001000', 'logradouro' => 'Praça da Sé', 'numero' => '100', 'bairro' => 'Sé', 'cidade_imovel' => 'São Paulo', 'estado' => 'SP']);
    if ($spouse) {
        $lead->conjuge()->create(['nome' => 'Cônjuge de Teste', 'cpf' => '38329560856', 'data_nascimento' => '1992-03-04']);
    }
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'status' => 'processing', 'total_providers' => 1]);
    $analysis = InsuranceAnalysis::query()->create([
        'lead_id' => $lead->id, 'insurance_analysis_batch_id' => $batch->id, 'provider' => 'porto',
        'product' => 'fianca_locaticia_residencial', 'status' => 'pending', 'rent_amount' => 1500,
    ]);
    $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => 'porto-docs']]);
    $run = app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'porto-docs', false);

    foreach (['primary', 'spouse', 'joint'] as $kind) {
        $quote = $run->scenarios()->where('kind', $kind)->first()?->quotes()->first();
        if ($quote) {
            app()->call([new RunInsuranceQuoteJob($quote->id, 'porto-docs'), 'handle']);
        }
    }
    (new CompleteInsuranceAnalysesBatchJob($batch->id, 'porto-docs'))->handle();

    return [$batch->fresh(), $analysis->fresh(), $run];
}

/** @return list<string> */
function portoDocsPdfUrls(): array
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), '/pdf/'))
        ->map(fn (array $pair) => $pair[0]->url())->values()->all();
}

it('attaches one Porto refusal letter per refused scenario, using the 409 budget', function () {
    portoDocsFake();
    [$batch, $analysis, $run] = portoDocsBatch();
    expect($run->scenarios()->orderBy('id')->pluck('kind')->all())->toBe(['primary', 'spouse', 'joint']);
    expect($analysis->status)->toBe('rejected');

    $attachments = app(AnalysisDocumentService::class)->generate($batch, 'porto-docs');

    $budgets = array_values(portoDocsBudgets());
    expect(portoDocsPdfUrls())->toBe(array_map(fn (string $budget) => portoDocsApi()."/orcamentos/{$budget}/ABC12J/pdf/carta-recusa", $budgets));
    foreach (Http::recorded(fn (Request $request) => str_contains($request->url(), '/pdf/')) as [$request]) {
        expect($request->method())->toBe('GET');
        expect($request->header('Authorization'))->toBe(['Bearer porto-token']);
        expect($request->header('access_token'))->toBe(['porto-token']);
    }
    expect(array_column($attachments, 'name'))->toBe(array_map(fn (string $budget) => "carta-recusa-{$analysis->id}-{$budget}.pdf", $budgets));
    foreach ($attachments as $index => $attachment) {
        expect(file_get_contents($attachment['path']))->toBe(portoDocsPdf(portoDocsApi()."/orcamentos/{$budgets[$index]}/ABC12J/pdf/carta-recusa"));
    }
    // Armazenamento privado, com hash registrado; reexecutar reaproveita os arquivos.
    expect(Storage::disk('local')->allFiles())->toHaveCount(3);
    expect(InsuranceAnalysisEvent::query()->where('event_type', 'document_generated')->count())->toBe(3);
    expect(app(AnalysisDocumentService::class)->generate($batch, 'porto-docs'))->toBe($attachments);
    expect(portoDocsPdfUrls())->toHaveCount(3);
});

it('requests no letter for a refusal that did not return a valid budget', function () {
    portoDocsFake(create: fn () => Http::response(['errorCode' => 409, 'errorMessage' => 'Recusado pela Porto'], 409));
    [$batch, $analysis] = portoDocsBatch(spouse: false);
    expect($analysis->status)->toBe('rejected');

    expect(app(ProviderRefusalLetterService::class)->portoRefusedBudgets($batch, 'porto-docs', $analysis->id))->toBe([]);
    expect(app(AnalysisDocumentService::class)->generate($batch, 'porto-docs'))->toBe([]);
    expect(portoDocsPdfUrls())->toBe([]);
});

it('keeps the refusal when the letter is unavailable or invalid, storing nothing', function (Closure $documents) {
    portoDocsFake(documents: $documents);
    [$batch, $analysis] = portoDocsBatch(spouse: false);

    expect(fn () => app(AnalysisDocumentService::class)->generate($batch, 'porto-docs'))->toThrow(RuntimeException::class);

    expect($analysis->fresh()->status)->toBe('rejected');
    expect($analysis->fresh()->quote_id)->toBe('000000011900001');
    expect(Storage::disk('local')->allFiles())->toBe([]);
    expect(InsuranceAnalysisEvent::query()->where('event_type', 'document_generated')->exists())->toBeFalse();
    // A falha documental não repete o POST do orçamento.
    expect(Http::recorded(fn (Request $request) => $request->method() === 'POST' && ! str_contains($request->url(), '/oauth/')))->toHaveCount(1);
})->with([
    '200 sem arquivo' => [fn () => Http::response(['status' => 200, 'message' => 'OK', 'data' => null], 200)],
    'Base64 inválido' => [fn () => Http::response(['data' => ['file' => '***não é base64***']], 200)],
    'arquivo que não é PDF' => [fn () => Http::response(['data' => ['file' => base64_encode('<html>erro</html>')]], 200)],
    'proposta de outro corretor' => [fn () => Http::response(['status' => 400, 'message' => 'A proposta não pertence a esse corretor'], 400)],
    'indisponibilidade em texto' => [fn () => Http::response('"Error forwarding call to back-end server"', 502)],
]);

it('refuses to fetch a Porto letter for a budget outside the refused scenarios of the round', function (?string $budget) {
    portoDocsFake();
    [$batch, $analysis] = portoDocsBatch(spouse: false);

    expect(fn () => app(ProviderRefusalLetterService::class)->fetch($batch, 'porto-docs', $analysis->id, $budget))
        ->toThrow(RuntimeException::class, 'Orçamento recusado da Porto');
    expect(portoDocsPdfUrls())->toBe([]);
})->with(['sem orçamento' => [null], 'orçamento de outro cliente' => ['000000099999999']]);

it('downloads the quote PDF for exactly the selected offer, payment method and condition', function () {
    portoDocsFake(create: fn () => Http::response([
        'orcamento' => '000000010009512',
        'ofertas' => [['seguro' => '0008', 'nomeSeguro' => 'FIANCA'], ['seguro' => '0001', 'nomeSeguro' => 'ESSENCIAL 12']],
    ], 201));
    [, $analysis, $run] = portoDocsBatch(spouse: false);
    expect($analysis->status)->toBe('approved');
    $quote = $run->scenarios()->first()->quotes()->first();
    $provider = app(PortoInsuranceProvider::class);

    expect($provider->quotePdf($quote))->toStartWith('%PDF-');
    expect($provider->quotePdf($quote, '0001'))->toStartWith('%PDF-');

    expect(portoDocsPdfUrls())->toBe([
        portoDocsApi().'/orcamentos/000000010009512/ABC12J/pdf/cotacao/0008/CC/029',
        portoDocsApi().'/orcamentos/000000010009512/ABC12J/pdf/cotacao/0001/CC/129',
    ]);
    expect(fn () => $provider->quotePdf($quote, '0005'))->toThrow(RuntimeException::class, 'não encontrada');
});

it('offers no quote PDF for a budget without an approved selected price', function () {
    portoDocsFake();
    [, , $run] = portoDocsBatch(spouse: false);
    $quote = $run->scenarios()->first()->quotes()->first();

    expect(fn () => app(PortoInsuranceProvider::class)->quotePdf($quote))->toThrow(RuntimeException::class, 'somente para orçamento aprovado');
    expect(portoDocsPdfUrls())->toBe([]);
});

it('never builds a document URL from malformed identifiers', function (string $budget, string $offer, string $method, string $condition) {
    portoDocsFake();
    $service = app(\App\Services\Insurance\PortoService::class);

    expect(fn () => $service->quotePdf($budget, $offer, $method, $condition))->toThrow(RuntimeException::class);
    expect(fn () => $service->refusalLetter($budget))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([
    'orçamento com caminho' => ['../../../oauth', '0008', 'CC', '029'],
    'oferta com caminho' => ['000000010009512x', '../x', 'CC', '029'],
    'forma com caminho' => ['00000001000951', '0008', 'C/C', '029'],
    'condição curta' => ['0000000100095', '0008', 'CC', '29'],
]);
