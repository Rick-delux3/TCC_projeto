<?php

use App\Jobs\RunInsuranceQuoteJob;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceProviderRun;
use App\Models\InsuranceQuote;
use App\Models\Lead;
use App\Services\Insurance\InsuranceQuoteCoordinator;
use App\Services\Insurance\PortoService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const PORTO_API = 'https://portoapicloud-hml.portoseguro.com.br/fianca-locaticia/emissao/aluguel-essencial/v1';
const PORTO_BUDGET = '000000010009512';

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Cache::flush();
    config([
        'features.insurance_analysis.enabled' => true,
        'queue.default' => 'database',
        'services.porto.enabled' => true,
        // Somente a base, como no .env: o service completa o caminho da API.
        'services.porto.base_url' => 'https://portoapicloud-hml.portoseguro.com.br/fianca-locaticia/',
        'services.porto.token_url' => 'https://portoapi-hml.portoseguro.com.br/oauth/v2/access-token',
        'services.porto.client_id' => 'test-client',
        'services.porto.client_secret' => 'test-secret',
        'services.porto.susep' => 'ABC12J',
        'services.porto.cpf_cotador' => '09238037698',
        'services.porto.price_source' => 'offers',
    ]);
});

/**
 * Simula a Porto. Cada operação pode ser substituída por um callable que
 * recebe a requisição; o token e os sucessos padrão já vêm configurados.
 */
function portoFake(array $handlers = []): void
{
    $tokens = 0;
    Http::fake(function (Request $request) use ($handlers, &$tokens) {
        $url = $request->url();
        $operation = match (true) {
            str_contains($url, '/oauth/') => 'token',
            $request->method() === 'POST' && $url === PORTO_API.'/orcamentos' => 'create',
            $request->method() === 'PUT' => 'commission',
            $request->method() === 'GET' && str_ends_with($url, '/ofertas') => 'offers',
            $request->method() === 'GET' => 'budget',
            default => 'unknown',
        };
        if (isset($handlers[$operation])) {
            return $handlers[$operation]($request);
        }

        return match ($operation) {
            'token' => Http::response(['access_token' => 'porto-token-'.(++$tokens), 'token_type' => 'Bearer', 'expires_in' => 3600]),
            'create' => Http::response(portoCreated(), 201),
            'commission' => Http::response(['status' => '1', 'mensagem' => 'Orçamento atualizado'], 200),
            'offers' => Http::response(portoOffers(), 200),
            default => Http::response(['errorCode' => 404, 'errorMessage' => 'Operação não simulada'], 404),
        };
    });
}

function portoCreated(array $offers = ['0008', '0001', '0005', '0007']): array
{
    return [
        'orcamento' => PORTO_BUDGET,
        'localRisco' => ['cep' => '01001000', 'logradouro' => 'PRACA DA SE', 'municipio' => 'SAO PAULO', 'estado' => 'SP'],
        'ofertas' => array_map(fn (string $code) => ['seguro' => $code, 'nomeSeguro' => 'OFERTA '.$code, 'formasPagamento' => []], $offers),
        'regraFixa' => false,
        'diaCorte' => 31,
    ];
}

/** GET de ofertas: condicoesPagamento como objeto, uma forma por condição. */
function portoOffers(array $prices = ['0008' => 210.5, '0001' => 90.1, '0005' => 150.25, '0007' => 120.0]): array
{
    $method = fn (string $code, string $condition, int $count, float $amount) => [
        'formaPagamento' => $code, 'nomeFormaPagamento' => 'FORMA '.$code,
        'condicoesPagamento' => [
            'condicaoPagamento' => $condition, 'quantidadeParcela' => $count, 'valorParcela' => $amount,
            'premioTotal' => round($amount * $count, 2), 'iof' => 1.5,
        ],
    ];
    $offers = [];
    foreach ($prices as $code => $price) {
        $offers[] = [
            'seguro' => (string) $code, 'nomeSeguro' => 'OFERTA '.$code,
            'comissao' => ['percentualMinimo' => 0, 'percentualMaximo' => 30],
            'formasPagamento' => [
                $method('CC', '029', 29, $price),
                // Com entrada (30 pagamentos) é mais barata, mas não é o padrão.
                $method('CC', '030', 30, $price - 10),
                $method('FAT', '129', 29, $price + 5),
                $method('ADC', '010', 0, 172.8),
            ],
        ];
    }

    return ['susep' => 'ABC12J', 'orcamento' => PORTO_BUDGET, 'ofertas' => $offers, 'origem' => 22];
}

/** @return array{0: InsuranceAnalysis, 1: InsuranceProviderRun} */
function portoRun(array $lead = [], array $spouse = [], ?Imobiliaria $company = null, array $expenses = [], array $business = []): array
{
    $lead = Lead::query()->create(array_merge([
        'cpf' => '09238037698', 'nome' => 'Pretendente de Teste', 'data_nascimento' => '1990-05-10',
        'tipo_locacao' => 'residencial', 'tipo_solicitante' => $company ? 'imobiliaria_cadastrada' : 'locador',
        'company_id' => $company?->id, 'estado_civil' => $spouse ? 'casado' : 'solteiro',
        'email' => 'principal@example.test', 'tel' => '11987654321',
    ], $lead));
    $lead->despesas()->create(array_merge(['valor_aluguel' => 1500, 'valor_condominio' => 300], $expenses));
    $lead->endereco()->create(['cep' => '01001000', 'logradouro' => 'Praça da Sé', 'numero' => '100', 'bairro' => 'Sé', 'cidade_imovel' => 'São Paulo', 'estado' => 'SP']);
    if ($spouse) {
        $lead->conjuge()->create($spouse);
    }
    if ($business) {
        $lead->lead_empresa()->create($business);
    }
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'status' => 'processing', 'total_providers' => 1]);
    $analysis = InsuranceAnalysis::query()->create([
        'lead_id' => $lead->id, 'insurance_analysis_batch_id' => $batch->id, 'provider' => 'porto',
        'product' => 'fianca_locaticia_residencial', 'status' => 'pending', 'rent_amount' => 1500,
    ]);
    $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => 'porto-test']]);

    return [$analysis, app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'porto-test', false)];
}

function portoQuote(InsuranceProviderRun $run, string $kind = 'primary'): InsuranceQuote
{
    return $run->scenarios()->where('kind', $kind)->firstOrFail()->quotes()->firstOrFail();
}

function runPortoQuote(InsuranceQuote $quote): void
{
    app()->call([new RunInsuranceQuoteJob($quote->id, 'porto-test', (int) $quote->fresh()->poll_count), 'handle']);
}

/** @return list<Request> */
function portoRequests(string $method): array
{
    return Http::recorded(fn (Request $request) => $request->method() === $method && ! str_contains($request->url(), '/oauth/'))
        ->map(fn (array $pair) => $pair[0])->values()->all();
}

it('creates the budget, adjusts every offer commission and selects the Fiança price', function () {
    portoFake();
    [$analysis, $run] = portoRun();
    $quote = portoQuote($run);
    expect($quote->payment_type)->toBe('CC');

    runPortoQuote($quote);

    // POST na URL completa, com o token nos dois headers e sem código de imobiliária.
    $posts = portoRequests('POST');
    expect($posts)->toHaveCount(1);
    expect($posts[0]->url())->toBe(PORTO_API.'/orcamentos');
    expect($posts[0]->header('Authorization'))->toBe(['Bearer porto-token-1']);
    expect($posts[0]->header('access_token'))->toBe(['porto-token-1']);
    expect($posts[0]->data())->toMatchArray(['susep' => 'ABC12J', 'cpfCotador' => '09238037698', 'tipoLocacao' => '1'])
        ->not->toHaveKey('imobiliaria');
    expect($posts[0]['locatarios'])->toHaveCount(1);

    // Um PUT mínimo por oferta retornada na criação, no mesmo orçamento.
    $puts = portoRequests('PUT');
    expect($puts)->toHaveCount(4);
    $bodies = [];
    foreach ($puts as $put) {
        expect($put->url())->toBe(PORTO_API.'/orcamentos/'.PORTO_BUDGET.'/ABC12J');
        expect($put->header('access_token'))->toBe(['porto-token-1']);
        $bodies[] = $put->data();
    }
    usort($bodies, fn (array $a, array $b) => strcmp($a['ofertas'][0]['seguro'], $b['ofertas'][0]['seguro']));
    expect($bodies)->toBe(array_map(
        fn (string $code) => ['ofertas' => [['seguro' => $code, 'comissao' => ['codComissao' => 15]]]],
        ['0001', '0005', '0007', '0008'],
    ));

    // O GET de ofertas só acontece depois de todos os PUTs.
    $operations = Http::recorded()->map(fn (array $pair) => $pair[0]->method())->all();
    expect($operations)->toBe(['POST', 'POST', 'PUT', 'PUT', 'PUT', 'PUT', 'GET']);
    expect(portoRequests('GET')[0]->url())->toBe(PORTO_API.'/orcamentos/22/'.PORTO_BUDGET.'/ABC12J/ofertas');

    // Fiança (0008), CC, 29 pagamentos Sem entrada — não a mais barata nem a de 30.
    $quote->refresh();
    expect($quote->status)->toBe('approved');
    expect($quote->quote_id)->toBe(PORTO_BUDGET);
    expect($quote->payment_status)->toBe('ready');
    expect($quote->installment_amount)->toBe('210.50');
    expect($quote->installments)->toBe(29);
    $payment = $quote->response_payload['payment'];
    expect($payment['selection'])->toMatchArray([
        'offer_code' => '0008', 'payment_method' => 'CC', 'condition_code' => '029', 'count' => 29, 'with_entry' => false,
    ]);
    expect(array_column($payment['alternatives'], 'amount', 'offer_code'))->toBe(['0001' => '90.10', '0005' => '150.25', '0007' => '120.00']);
    expect($payment['commission'])->each->toMatchArray(['applied' => true, 'http_status' => 200]);

    // Consolidado na análise sem payload de pretendentes.
    $analysis->refresh();
    expect($analysis->status)->toBe('approved');
    expect($analysis->quote_id)->toBe(PORTO_BUDGET);
    expect($analysis->request_payload)->toBeNull();
    expect(json_encode($analysis->response_payload))->not->toContain('09238037698')->not->toContain('porto-token');
    expect($analysis->response_payload['quote_selection'])->toMatchArray(['installment_amount' => '210.50', 'installments' => 29, 'payment_type' => 'CC']);
    expect($run->fresh()->finished_at)->not->toBeNull();

    // Reexecutar o job não recria o orçamento nem repete chamadas.
    runPortoQuote($quote);
    Http::assertSentCount(7);
});

it('quotes invoice for a registered agency with a Porto code and credit card without one', function (?string $code, string $type, string $amount) {
    portoFake();
    $company = Imobiliaria::factory()->create(['porto_partner_code' => $code]);
    [, $run] = portoRun(company: $company);
    $quote = portoQuote($run);
    expect($quote->payment_type)->toBe($type);

    runPortoQuote($quote);

    expect(portoRequests('POST')[0]->data()['imobiliaria'] ?? null)->toBe($code);
    $quote->refresh();
    expect($quote->installment_amount)->toBe($amount);
    expect($quote->response_payload['payment']['selection'])->toMatchArray(['payment_method' => $type, 'count' => 29]);
})->with([
    'com código Porto' => ['000123', 'FAT', '215.50'],
    'sem código Porto' => [null, 'CC', '210.50'],
]);

it('stores the refused budget number and never adjusts commission or reads offers', function () {
    portoFake(['create' => fn () => Http::response([
        'errorCode' => 409,
        'errorMessage' => 'Devido aos critérios de aceitação da companhia, infelizmente não será possível prosseguir com a contratação para o cadastro informado',
        'detailedMessage' => '000000011912429',
    ], 409)]);
    [$analysis, $run] = portoRun();
    $quote = portoQuote($run);

    runPortoQuote($quote);

    $quote->refresh();
    expect($quote->status)->toBe('rejected');
    expect($quote->quote_id)->toBe('000000011912429');
    expect($quote->rejection_reason)->toContain('critérios de aceitação');
    expect($quote->response_payload['budget_missing'])->toBeFalse();
    expect($analysis->fresh()->status)->toBe('rejected');
    expect(portoRequests('PUT'))->toBeEmpty()->and(portoRequests('GET'))->toBeEmpty();
});

it('keeps a refusal without a valid budget number as a refusal', function (array $body) {
    portoFake(['create' => fn () => Http::response($body, 409)]);
    [, $run] = portoRun();
    $quote = portoQuote($run);

    runPortoQuote($quote);

    $quote->refresh();
    expect($quote->status)->toBe('rejected');
    expect($quote->quote_id)->toBeNull();
    expect($quote->response_payload['budget_missing'])->toBeTrue();
})->with([
    'sem detailedMessage' => [['errorCode' => 409, 'errorMessage' => 'Recusado']],
    'detailedMessage fora do formato' => [['errorCode' => 409, 'errorMessage' => 'Recusado', 'detailedMessage' => '409']],
]);

it('treats technical failures as failures, never as refusals, and does not create another budget', function (callable $create, ?int $status) {
    // Contado no próprio fake: Http::recorded() não registra requisições que
    // terminam em exceção de conexão.
    $creates = 0;
    portoFake(['create' => function (Request $request) use ($create, &$creates) {
        $creates++;

        return $create($request);
    }]);
    [$analysis, $run] = portoRun(spouse: ['nome' => 'Cônjuge de Teste', 'cpf' => '38329560856', 'data_nascimento' => '1992-03-04']);
    $quote = portoQuote($run);

    runPortoQuote($quote);
    runPortoQuote($quote);

    $quote->refresh();
    expect($quote->status)->toBe('failed');
    expect($quote->result)->toBeNull();
    expect($quote->quote_id)->toBeNull();
    expect($quote->response_payload['http_status'])->toBe($status);
    expect(json_encode($quote->response_payload))->not->toContain('porto-token')->not->toContain('09238037698');
    expect($creates)->toBe(1);
    expect(portoRequests('PUT'))->toBeEmpty();
    // Falha técnica não autoriza a análise conjunta.
    expect($run->scenarios()->where('kind', 'joint')->exists())->toBeFalse();
    expect($analysis->fresh()->status)->not->toBe('rejected');
})->with([
    'HTTP 400' => [fn () => fn () => Http::response(['errorCode' => 400, 'errorMessage' => 'O Campo cep não foi preenchido!'], 400), 400],
    'HTTP 500' => [fn () => fn () => Http::response(['errorCode' => 500, 'errorMessage' => 'Internal Server Error'], 500), 500],
    'HTTP 502 em texto' => [fn () => fn () => Http::response('"Error forwarding call to back-end server"', 502), 502],
    'aprovado sem orçamento' => [fn () => fn () => Http::response(['ofertas' => []], 201), 201],
    'falha de conexão' => [fn () => fn () => throw new ConnectionException('cURL error 28: timeout'), null],
]);

it('runs the joint budget only after both individual budgets are refused', function (bool $spouseRefused) {
    $refusal = fn () => Http::response(['errorCode' => 409, 'errorMessage' => 'Recusado', 'detailedMessage' => '000000011912429'], 409);
    portoFake(['create' => function (Request $request) use ($refusal, $spouseRefused) {
        $documents = array_column($request['locatarios'], 'cpfCnpj');
        if (count($documents) === 2 || ($documents === ['38329560856'] && ! $spouseRefused)) {
            return Http::response(portoCreated(['0008']), 201);
        }

        return $refusal();
    }]);
    [$analysis, $run] = portoRun(spouse: ['nome' => 'Cônjuge de Teste', 'cpf' => '38329560856', 'data_nascimento' => '1992-03-04']);
    expect($run->scenarios()->pluck('kind')->all())->toBe(['primary', 'spouse']);

    runPortoQuote(portoQuote($run, 'primary'));
    runPortoQuote(portoQuote($run, 'spouse'));

    if (! $spouseRefused) {
        expect($run->scenarios()->where('kind', 'joint')->exists())->toBeFalse();
        expect($analysis->fresh()->status)->toBe('approved');
        expect(portoRequests('POST'))->toHaveCount(2);

        return;
    }

    runPortoQuote(portoQuote($run, 'joint'));
    $joint = portoRequests('POST')[2]->data()['locatarios'];
    expect(array_column($joint, 'titular', 'cpfCnpj'))->toBe(['09238037698' => 'S', '38329560856' => 'N']);
    expect(array_unique(array_column($joint, 'email')))->toBe(['principal@example.test']);
    expect($analysis->fresh()->status)->toBe('approved');
    expect(portoQuote($run, 'joint')->installment_amount)->toBe('210.50');
})->with(['ambos recusados' => [true], 'cônjuge aprovado' => [false]]);

it('analyses a company alone under the commercial frame', function () {
    portoFake();
    [, $run] = portoRun(
        ['cpf' => null, 'nome' => 'Empresa de Teste LTDA', 'tipo_pessoa' => 'J', 'estado_civil' => null],
        business: ['cnpj' => '11222333000181', 'cpf_responsavel' => '09238037698', 'nome_responsavel' => 'Responsável de Teste'],
    );
    expect($run->scenarios()->pluck('kind')->all())->toBe(['primary']);

    runPortoQuote(portoQuote($run));

    $payload = portoRequests('POST')[0]->data();
    expect($payload['tipoLocacao'])->toBe('2');
    expect($payload['locatarios'])->toHaveCount(1);
    expect($payload['locatarios'][0])->toMatchArray(['cpfCnpj' => '11222333000181', 'nome' => 'Empresa de Teste LTDA', 'titular' => 'S']);
    expect($payload['locacaoComercial'])->toBe(['capitalSocial' => 450000, 'capitalAberturaNegocio' => 150000, 'motivoLocacao' => 'A']);
    expect(portoQuote($run)->fresh()->status)->toBe('approved');
});

it('keeps the approval without a comparable price when Fiança cannot be published', function (array $handlers, array $created, array $alternatives) {
    portoFake($handlers + ['create' => fn () => Http::response(portoCreated($created), 201)]);
    [$analysis, $run] = portoRun();
    $quote = portoQuote($run);

    runPortoQuote($quote);

    $quote->refresh();
    expect($quote->status)->toBe('approved');
    expect($quote->payment_status)->toBe('unavailable');
    expect($quote->installment_amount)->toBeNull();
    $payment = $quote->response_payload['payment'];
    expect($payment['selection'])->toBeNull();
    expect($payment['unavailable_reason'])->not->toBeEmpty();
    expect(array_column($payment['alternatives'], 'offer_code'))->toBe($alternatives);
    expect($analysis->fresh()->status)->toBe('approved');
})->with([
    'Fiança não retornada' => [
        ['offers' => fn () => Http::response(portoOffers(['0001' => 90.1, '0005' => 150.25]), 200)],
        ['0001', '0005'], ['0001', '0005'],
    ],
    'comissão da Fiança não confirmada' => [
        ['commission' => fn (Request $request) => $request['ofertas'][0]['seguro'] === '0008'
            ? Http::response(['errorCode' => 400, 'errorMessage' => 'Comissão fora do limite'], 400)
            : Http::response(['status' => '1'], 200)],
        ['0008', '0001'], ['0001'],
    ],
    'Fiança sem condição de 29 parcelas' => [
        ['offers' => function () {
            $offers = portoOffers(['0008' => 210.5, '0001' => 90.1]);
            $offers['ofertas'][0]['formasPagamento'] = [$offers['ofertas'][0]['formasPagamento'][1]];

            return Http::response($offers, 200);
        }],
        ['0008', '0001'], ['0001'],
    ],
    'criação sem ofertas' => [[], [], []],
]);

it('retries only the price stage when the offers query fails', function () {
    $fail = true;
    portoFake(['offers' => function () use (&$fail) {
        return $fail ? Http::response('"Error forwarding call to back-end server"', 502) : Http::response(portoOffers(), 200);
    }]);
    [, $run] = portoRun();
    $quote = portoQuote($run);

    expect(fn () => runPortoQuote($quote))->toThrow(RuntimeException::class);
    $quote->refresh();
    expect($quote->status)->toBe('approved');
    expect($quote->payment_status)->toBe('pending');
    expect($quote->quote_id)->toBe(PORTO_BUDGET);

    $fail = false;
    runPortoQuote($quote);

    expect($quote->fresh()->payment_status)->toBe('ready');
    expect($quote->fresh()->installment_amount)->toBe('210.50');
    // O orçamento foi criado uma única vez; só PUTs e GET foram repetidos.
    expect(portoRequests('POST'))->toHaveCount(1);
    expect(portoRequests('PUT'))->toHaveCount(8);
    expect(portoRequests('GET'))->toHaveCount(2);
});

it('renews a refused token once and resends the budget creation', function () {
    $calls = 0;
    portoFake(['create' => function (Request $request) use (&$calls) {
        return ++$calls === 1
            ? Http::response('"Could not find a required Access Token in the request, identified by HEADER access_token"', 401)
            : Http::response(portoCreated(), 201);
    }]);

    $result = app(PortoService::class)->createBudget(['susep' => 'ABC12J']);

    expect($result)->toMatchArray(['success' => true, 'http_status' => 201]);
    $posts = portoRequests('POST');
    expect($posts)->toHaveCount(2);
    expect($posts[0]->header('access_token'))->toBe(['porto-token-1']);
    expect($posts[1]->header('access_token'))->toBe(['porto-token-2']);
    expect($posts[1]->header('Authorization'))->toBe(['Bearer porto-token-2']);
});

it('retries a transient commission failure once and only for the failed offer', function (callable $failure) {
    $attempts = [];
    portoFake(['commission' => function (Request $request) use (&$attempts, $failure) {
        $offer = $request['ofertas'][0]['seguro'];
        $attempts[$offer] = ($attempts[$offer] ?? 0) + 1;

        return $offer === '0005' && $attempts[$offer] === 1 ? $failure() : Http::response(['status' => '1'], 200);
    }]);
    $payloads = [];
    foreach (['0008', '0001', '0005'] as $offer) {
        $payloads[$offer] = ['ofertas' => [['seguro' => $offer, 'comissao' => ['codComissao' => 15]]]];
    }

    $results = app(PortoService::class)->updateCommissions(PORTO_BUDGET, $payloads);

    expect(array_map(fn (array $result) => $result['success'], $results))->toBe(['0008' => true, '0001' => true, '0005' => true]);
    expect($attempts)->toBe(['0008' => 1, '0001' => 1, '0005' => 2]);
})->with([
    'HTTP 500' => [fn () => fn () => Http::response(['errorCode' => 500, 'errorMessage' => 'Internal Server Error'], 500)],
    'falha de conexão' => [fn () => fn () => throw new ConnectionException('cURL error 28: timeout')],
]);

it('does not retry a definitive commission refusal', function () {
    $attempts = 0;
    portoFake(['commission' => function () use (&$attempts) {
        $attempts++;

        return Http::response(['errorCode' => 400, 'errorMessage' => 'Comissão fora do limite'], 400);
    }]);

    $results = app(PortoService::class)->updateCommissions(PORTO_BUDGET, ['0008' => ['ofertas' => []]]);

    expect($results['0008'])->toMatchArray(['success' => false, 'http_status' => 400]);
    expect($attempts)->toBe(1);
});

it('reads prices from the updated budget when configured, without storing tenant data', function () {
    config(['services.porto.price_source' => 'budget']);
    // GET do orçamento: condicoesPagamento como lista dentro de cada forma.
    $budget = [
        'orcamento' => PORTO_BUDGET, 'susep' => 'ABC12J', 'status' => '1',
        'locatarios' => [['cpfCnpj' => '09238037698', 'nome' => 'Pretendente de Teste']],
        'ofertas' => [[
            'seguro' => '0008', 'nomeSeguro' => 'FIANCA',
            'formasPagamento' => [['formaPagamento' => 'CC', 'nomeFormaPagamento' => 'DEMAIS CARTOES', 'condicoesPagamento' => [
                ['condicaoPagamento' => '030', 'quantidadeParcela' => 30, 'valorParcela' => 180.0],
                ['condicaoPagamento' => '029', 'quantidadeParcela' => 29, 'valorParcela' => 199.9],
            ]]],
        ]],
    ];
    portoFake(['budget' => fn () => Http::response($budget, 200)]);
    [, $run] = portoRun();
    $quote = portoQuote($run);

    runPortoQuote($quote);

    expect(portoRequests('GET')[0]->url())->toBe(PORTO_API.'/orcamentos/'.PORTO_BUDGET.'/ABC12J');
    $quote->refresh();
    expect($quote->installment_amount)->toBe('199.90');
    expect($quote->response_payload['payment']['source'])->toBe('budget');
    expect($quote->response_payload['payment']['response'])->not->toHaveKey('locatarios');
});

it('sends nothing when the integration is disabled or misconfigured', function (array $config, string $message) {
    portoFake();
    config($config);
    $service = app(PortoService::class);

    expect($service->createBudget(['susep' => 'ABC12J']))->toMatchArray(['success' => false, 'http_status' => null]);
    expect($service->createBudget([])['error'])->toContain($message);
    expect($service->offers(PORTO_BUDGET)['success'])->toBeFalse();
    expect($service->updateCommissions(PORTO_BUDGET, ['0008' => []])['0008']['success'])->toBeFalse();
    Http::assertNothingSent();
})->with([
    'desativada' => [['services.porto.enabled' => false], 'desativada'],
    'host fora da Porto' => [['services.porto.base_url' => 'https://portoseguro.com.br.evil.test/fianca-locaticia/'], 'endpoint'],
    'sem HTTPS' => [['services.porto.base_url' => 'http://portoapicloud-hml.portoseguro.com.br/fianca-locaticia/'], 'endpoint'],
    'credenciais na URL' => [['services.porto.base_url' => 'https://user:pass@portoapicloud-hml.portoseguro.com.br/fianca-locaticia/'], 'endpoint'],
    'SUSEP ausente' => [['services.porto.susep' => null], 'PORTO_SUSEP'],
]);

it('rejects a malformed budget number before building any URL', function (string $budget) {
    portoFake();
    $service = app(PortoService::class);

    expect($service->offers($budget)['success'])->toBeFalse();
    expect($service->budget($budget)['success'])->toBeFalse();
    expect($service->updateCommissions($budget, ['0008' => []])['0008']['success'])->toBeFalse();
    Http::assertNothingSent();
})->with(['curto' => ['10009512'], 'com caminho' => ['../../oauth/v2/xx'], 'com letras' => ['00000001000951A']]);

it('does not duplicate the API path when the base URL already contains it', function () {
    config(['services.porto.base_url' => PORTO_API.'/']);
    portoFake();

    app(PortoService::class)->createBudget(['susep' => 'ABC12J']);

    expect(portoRequests('POST')[0]->url())->toBe(PORTO_API.'/orcamentos');
});
