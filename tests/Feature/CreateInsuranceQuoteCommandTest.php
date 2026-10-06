<?php

use App\Models\InsuranceAnalysis;
use App\Models\Lead;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    config([
        'features.insurance_analysis.enabled' => true,
        'services.too.enabled' => true,
        'services.pottencial.enabled' => true,
        'services.too.base_url' => 'https://too.example.test',
        'services.pottencial.base_url' => 'https://pottencial.example.test',
        'services.too.client_id' => 'test-client',
        'services.too.client_secret' => 'test-secret',
        'services.pottencial.client_id' => 'test-client',
        'services.pottencial.client_secret' => 'test-secret',
        'services.too.broker_cnpj' => '11222333000181',
        'services.too.broker_name' => 'Corretora de teste',
        'services.pottencial.broker_document' => '11222333000181',
        'services.pottencial.rental_endpoint' => '/insurance/v1/fianca-locaticia-mensalizado-pf/quotes',
    ]);
    foreach (['too', 'pottencial'] as $provider) {
        Cache::put("{$provider}_access_token", 'private-test-token', 3600);
    }
    $lead = Lead::query()->create([
        'nome' => 'Pretendente teste', 'email' => 'quote@example.test', 'cpf' => '07234828702',
        'tel' => '11999998888', 'data_nascimento' => '1992-02-29',
        'tipo_locacao' => 'residencial', 'tipo_solicitante' => 'locador',
    ]);
    $lead->endereco()->create([
        'cep' => '01001000', 'logradouro' => 'Praça da Sé', 'numero' => '100',
        'bairro' => 'Sé', 'cidade_imovel' => 'São Paulo', 'estado' => 'SP',
    ]);
    $lead->despesas()->create(['valor_aluguel' => 1500, 'valor_agua' => 0, 'valor_luz' => 0]);
    $batch = $lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 2, 'finished_at' => now()]);
    $this->analyses = collect(['too', 'pottencial'])->mapWithKeys(fn (string $provider): array => [$provider => $batch->analyses()->create([
        'lead_id' => $lead->id, 'provider' => $provider, 'product' => $lead->rentalGuaranteeProduct(),
        'status' => 'approved', 'proposal_id' => $provider === 'too' ? '12345' : null,
        'response_payload' => $provider === 'too' ? ['numeroFicha' => '54321'] : [],
    ])]);
    $this->savedAnalyses = $this->analyses->map(fn (InsuranceAnalysis $analysis): array => $analysis->fresh()->getAttributes());
    $this->batch = $batch;
    $this->savedBatch = $batch->fresh()->getAttributes();
});

afterEach(function () {
    Bus::assertNothingDispatched();
    expect(Artisan::output())->not->toContain('test-secret', 'private-test-token');
});

it('creates only a remote quote using the real builder and service of the selected insurer', function (string $provider) {
    $raw = $provider === 'too' ? '{"numeroCotacao":987,"premio":1200}' : '{"quoteId":"quote-987","status":"Pending"}';
    Http::fake(function (Request $request, array $options) use ($raw) {
        expect($options['allow_redirects'])->toBeFalse();

        return Http::response($raw, 201, ['Content-Type' => 'application/json']);
    });
    $analysis = $this->analyses[$provider];
    expect(Artisan::call('apis:criar-orcamento', ['--'.$provider => true, '--analysis' => $analysis->id]))->toBe(0);
    expect(Artisan::output())->toContain('HTTP: 201 Created', $raw);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($provider): bool {
        if ($request->method() !== 'POST') {
            return false;
        }
        if ($provider === 'too') {
            return $request->url() === 'https://too.example.test/fianca/proposta/cotacao'
                && $request['numeroFicha'] === '54321'
                && (float) $request['coberturas']['valorAluguel'] === 1500.0
                && $request->hasHeader('Authorization', 'Bearer private-test-token');
        }

        return $request->url() === 'https://pottencial.example.test/insurance/v1/fianca-locaticia-mensalizado-pf/quotes'
            && $request['riskObjects'][0]['occupation'] === 'Residencial'
            && $request['riskObjects'][0]['tenantDocumentNumber'] === '07234828702'
            && $request->hasHeader('access_token', 'private-test-token');
    });
    expect($analysis->fresh()->getAttributes())->toBe($this->savedAnalyses[$provider])
        ->and($analysis->events()->count())->toBe(0)
        ->and($this->batch->fresh()->getAttributes())->toBe($this->savedBatch);
})->with(['too', 'pottencial']);

it('prints HTTP status descriptions and preserves JSON or non JSON response bodies', function (string $provider, int $status) {
    $raw = $status === 500 ? '<error>falha na companhia</error>' : '{"codigo":"codigo_da_companhia","mensagem":"detalhe original"}';
    Http::fake(['*' => Http::response($raw, $status, ['Retry-After' => '60'])]);
    $exit = Artisan::call('apis:criar-orcamento', ['--'.$provider => true, '--analysis' => $this->analyses[$provider]->id]);
    expect($exit)->toBe($status >= 200 && $status < 300 ? 0 : 1);
    $output = Artisan::output();
    expect($output)->toContain('HTTP: '.$status, $raw, 'Retry-After: 60');
    if (isset(Response::$statusTexts[$status])) {
        expect($output)->toContain(Response::$statusTexts[$status]);
    } else {
        expect($output)->toContain('Código não padronizado');
    }
    Http::assertSentCount(1);
})->with(['too', 'pottencial'])->with([202, 302, 400, 401, 403, 404, 405, 408, 409, 422, 429, 500, 503, 599]);

it('authenticates through the selected existing service when its cache is empty', function (string $provider) {
    Cache::forget("{$provider}_access_token");
    $tokenEndpoint = $provider === 'too' ? '/authentication' : '/oauth/v3/access-token';
    Http::fake([
        "https://{$provider}.example.test{$tokenEndpoint}" => Http::response(['access_token' => 'private-test-token']),
        '*' => Http::response(['quoteId' => 'remote'], 201),
    ]);
    expect(Artisan::call('apis:criar-orcamento', ['--'.$provider => true, '--analysis' => $this->analyses[$provider]->id]))->toBe(0);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === "https://{$provider}.example.test{$tokenEndpoint}");
})->with(['too', 'pottencial']);

it('reports missing HTTP responses without automatically repeating a creation request', function (string $provider) {
    Http::fake(['*' => Http::failedConnection('Timeout')]);
    expect(Artisan::call('apis:criar-orcamento', ['--'.$provider => true, '--analysis' => $this->analyses[$provider]->id]))->toBe(1);
    expect(Artisan::output())->toContain('HTTP: sem resposta', 'resultado remoto não está confirmado', '(nenhum corpo HTTP recebido)');
})->with(['too', 'pottencial']);

it('preserves authentication HTTP errors without attributing them to the quote endpoint', function (string $provider) {
    Cache::forget("{$provider}_access_token");
    $raw = '{"error":"invalid_client","description":"Credenciais rejeitadas"}';
    Http::fake(['*' => Http::response($raw, 401)]);
    expect(Artisan::call('apis:criar-orcamento', ['--'.$provider => true, '--analysis' => $this->analyses[$provider]->id]))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('HTTP: 401 Unauthorized', $raw, 'endpoint de criação de orçamento não foi chamado')
        ->not->toContain('test-secret', 'private-test-token');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === ($provider === 'too'
        ? 'https://too.example.test/authentication' : 'https://pottencial.example.test/oauth/v3/access-token'));
})->with(['too', 'pottencial']);

it('rejects invalid command selections before any API request', function (array $options) {
    expect(Artisan::call('apis:criar-orcamento', $options))->toBe(2);
    Http::assertNothingSent();
})->with([
    'no provider' => [[]],
    'both providers' => [['--too' => true, '--pottencial' => true]],
    'no analysis' => [['--too' => true]],
    'invalid ID' => [['--pottencial' => true, '--analysis' => 'abc']],
]);

it('rejects a different provider and a missing analysis', function () {
    expect(Artisan::call('apis:criar-orcamento', ['--too' => true, '--analysis' => $this->analyses['pottencial']->id]))->toBe(2);
    expect(Artisan::call('apis:criar-orcamento', ['--pottencial' => true, '--analysis' => 999999]))->toBe(1);
    Http::assertNothingSent();
});

it('respects the existing provider and analysis feature flags', function (string $flag) {
    config([$flag => false]);
    expect(Artisan::call('apis:criar-orcamento', ['--too' => true, '--analysis' => $this->analyses['too']->id]))->toBe(1);
    Http::assertNothingSent();
})->with(['services.too.enabled', 'features.insurance_analysis.enabled']);

it('requires an approved Too ficha without starting the credit analysis workflow', function (string $status, ?string $ficha) {
    $analysis = $this->analyses['too'];
    $analysis->update(['status' => $status, 'proposal_id' => $ficha, 'response_payload' => ['numeroFicha' => $ficha]]);
    expect(Artisan::call('apis:criar-orcamento', ['--too' => true, '--analysis' => $analysis->id]))->toBe(1);
    expect(Artisan::output())->toContain('exige uma análise aprovada com numeroFicha');
    Http::assertNothingSent();
})->with([['processing', '123'], ['rejected', '123'], ['approved', null]]);

it('uses builder validation before sending an invalid lead payload', function (string $provider) {
    $this->analyses[$provider]->lead->update(['cpf' => '123']);
    expect(Artisan::call('apis:criar-orcamento', ['--'.$provider => true, '--analysis' => $this->analyses[$provider]->id]))->toBe(1);
    Http::assertNothingSent();
})->with(['too', 'pottencial']);

it('honors the Pottencial quote endpoint configured in the application', function () {
    config(['services.pottencial.rental_endpoint' => '/insurance/v1/fianca-locaticia/quotes']);
    Http::fake(['*' => Http::response(['quoteId' => 'custom-endpoint'], 201)]);
    expect(Artisan::call('apis:criar-orcamento', ['--pottencial' => true, '--analysis' => $this->analyses['pottencial']->id]))->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pottencial.example.test/insurance/v1/fianca-locaticia/quotes');
});
