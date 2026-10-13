<?php

use App\Models\AtividadeEconomica;
use App\Models\AtividadeEconomicaCodigo;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->withoutVite();
    config(['features.insurance_analysis.enabled' => false]);
    Queue::fake();
    Http::preventStrayRequests();
});

it('searches accents punctuation word order and codes without duplicating descriptions', function (string $search): void {
    $activity = AtividadeEconomica::factory()->create(['descricao' => 'Comércio de peças para veículos']);
    AtividadeEconomicaCodigo::factory()->for($activity, 'atividadeEconomica')->create(['codigo' => '23872']);
    AtividadeEconomicaCodigo::factory()->for($activity, 'atividadeEconomica')->create(['codigo' => '23873']);
    $this->getJson(route('simulation.activities', ['q' => $search]))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $activity->id)
        ->assertJsonPath('data.0.descricao', $activity->descricao)
        ->assertJsonPath('data.0.codigos', ['23872', '23873'])->assertJsonPath('has_more', false);
})->with(['COMERCIO  pecas', 'veículos, comércio', '23873', '2387']);

it('limits suggestions and excludes unavailable activities and codes', function (): void {
    for ($index = 0; $index < 12; $index++) {
        AtividadeEconomicaCodigo::factory()->for(
            AtividadeEconomica::factory()->state(['descricao' => 'Comércio '.$index]), 'atividadeEconomica'
        )->create();
    }
    $inactive = AtividadeEconomica::factory()->create(['descricao' => 'Comércio inativo', 'ativo' => false]);
    AtividadeEconomicaCodigo::factory()->for($inactive, 'atividadeEconomica')->create(['codigo' => '99999']);
    AtividadeEconomicaCodigo::factory()->create(['codigo' => '88888', 'ativo' => false]);
    AtividadeEconomicaCodigo::factory()->create(['codigo' => '77777', 'seguradora' => 'outra']);
    $this->getJson(route('simulation.activities', ['q' => 'comercio']))->assertOk()
        ->assertJsonCount(10, 'data')->assertJsonPath('has_more', true);
    foreach (['99999', '88888', '77777', '%_'] as $term) {
        $this->getJson(route('simulation.activities', ['q' => $term]))->assertOk()->assertJsonCount(0, 'data');
    }
});

it('validates the search input', function (mixed $search): void {
    $this->getJson(route('simulation.activities', ['q' => $search]))->assertUnprocessable()->assertJsonValidationErrors('q');
})->with([null, 'a', str_repeat('a', 101), [['text']]]);

function catalogSimulationPayload(array $overrides = []): array
{
    return array_replace([
        'aceite_termos' => '1', 'nome' => 'Pretendente comercial', 'email' => 'catalog@example.test',
        'tel' => '11988887777', 'cpf' => '52998224725', 'data_nascimento' => '1992-02-29',
        'tipo_locacao' => 'comercial', 'ramo_atividade' => 21205, 'estado_civil' => 'solteiro',
        'valor_aluguel' => '1500', 'cep' => '01001000', 'logradouro' => 'Praça da Sé',
        'numero' => '100', 'bairro' => 'Sé', 'cidade_imovel' => 'São Paulo', 'estado' => 'SP',
    ], $overrides);
}

it('stores the catalog relation and full canonical description instead of submitted text', function (): void {
    $description = 'Comércio varejista de equipamentos e suprimentos de informática e comunicação';
    $activity = AtividadeEconomica::factory()->create(['descricao' => $description]);
    AtividadeEconomicaCodigo::factory()->for($activity, 'atividadeEconomica')->create();
    $this->post(route('simulation.tenant.store'), catalogSimulationPayload([
        'atividade_economica_id' => $activity->id, 'descrever_atividade' => ['forged'],
    ]))->assertSessionHasNoErrors()->assertRedirect(route('simulation.success'));
    $lead = Lead::query()->sole();
    expect($lead->atividadeEconomica->is($activity))->toBeTrue()
        ->and($lead->descrever_atividade)->toBe($description);
    $lead->update(['descrever_atividade' => 'Descrição alterada por outro fluxo']);
    expect($lead->fresh()->atividade_economica_id)->toBeNull();
});

it('rejects free text unknown or inactive selections', function (string $case): void {
    $code = AtividadeEconomicaCodigo::factory()->create();
    $id = $code->atividade_economica_id;
    if ($case === 'inactive activity') {
        $code->atividadeEconomica->update(['ativo' => false]);
    }
    if ($case === 'inactive code') {
        $code->update(['ativo' => false]);
    }
    if ($case === 'unknown') {
        $id = 999999;
    }
    if ($case === 'free text') {
        $id = null;
    }
    $this->postJson(route('simulation.tenant.store'), catalogSimulationPayload([
        'atividade_economica_id' => $id, 'descrever_atividade' => 'Uma atividade inventada',
    ]))->assertUnprocessable()->assertJsonValidationErrors('atividade_economica_id');
    expect(Lead::query()->count())->toBe(0);
})->with(['inactive activity', 'inactive code', 'unknown', 'free text']);

it('discards the activity selection for residential submissions', function (): void {
    $this->post(route('simulation.tenant.store'), catalogSimulationPayload([
        'tipo_locacao' => 'residencial', 'atividade_economica_id' => ['invalid'], 'descrever_atividade' => 'Texto',
    ]))->assertSessionHasNoErrors();
    expect(Lead::query()->sole()->atividade_economica_id)->toBeNull()
        ->and(Lead::query()->sole()->descrever_atividade)->toBeNull();
});

it('restores only a valid catalog selection after validation errors', function (): void {
    $code = AtividadeEconomicaCodigo::factory()->create();
    $this->withSession(['_old_input' => [
        'tipo_locacao' => 'comercial', 'atividade_economica_id' => $code->atividade_economica_id,
        'descrever_atividade' => 'forged',
    ]])->get(route('simulation.tenant.form'))->assertOk()
        ->assertSee('role="combobox"', false)->assertSee(e($code->atividadeEconomica->descricao), false)
        ->assertDontSee('value="forged"', false);
});

it('backfills searchable descriptions without rewriting existing lead descriptions', function (): void {
    $migration = require database_path('migrations/2026_10_13_184902_add_activity_catalog_selection_to_leads.php');
    $migration->down();
    AtividadeEconomica::query()->toBase()->insert([
        'descricao' => 'Serviços de alimentação', 'descricao_normalizada' => 'serviços de alimentação',
        'ativo' => true, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $migration->up();
    $activity = AtividadeEconomica::query()->sole();
    expect($activity->descricao_busca)->toBe('servicos de alimentacao')
        ->and($activity->descricao)->toBe('Serviços de alimentação')
        ->and($activity->updated_at->toDateString())->toBe('2026-01-01');
});

it('supports keyboard selection and ignores stale or failed activity searches', function (): void {
    $process = new \Symfony\Component\Process\Process(['node', '--input-type=module', '-e', <<<'JS'
        import assert from 'node:assert/strict';
        import { pathToFileURL } from 'node:url';
        const { initializeActivityPicker } = await import(pathToFileURL(process.argv[1]));
        class Element extends EventTarget {
            constructor() {
                super(); this.value = ''; this.dataset = {}; this.attributes = {}; this.children = [];
                this.isConnected = true; this.hidden = true; this.classList = { toggle() {} };
            }
            setAttribute(key, value) { this.attributes[key] = value; }
            removeAttribute(key) { delete this.attributes[key]; }
            hasAttribute(key) { return key in this.attributes; }
            setCustomValidity(value) { this.validationMessage = value; }
            replaceChildren() { this.children = []; }
            append(child) { this.children.push(child); }
            scrollIntoView() {}
        }
        globalThis.document = { createElement: () => new Element() };
        globalThis.window = { location: { href: 'https://example.test/form' } };
        const timers = new Map(); let nextTimer = 0;
        globalThis.setTimeout = (callback) => { timers.set(++nextTimer, callback); return nextTimer; };
        globalThis.clearTimeout = (id) => timers.delete(id);
        const requests = [];
        globalThis.fetch = (url, options) => new Promise(resolve => requests.push({ url, options, resolve }));
        const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
        const tick = () => { const callbacks = [...timers.values()]; timers.clear(); callbacks.forEach(fn => fn()); };
        const emit = (element, type, values = {}) => {
            const event = new Event(type, { cancelable: true }); Object.assign(event, values);
            element.dispatchEvent(event); return event;
        };
        const input = new Element(), id = new Element(), list = new Element(), status = new Element(), picker = new Element();
        picker.dataset.searchUrl = '/simulacao/atividades';
        picker.querySelector = selector => ({ '[data-activity-search]': input, '[data-activity-id]': id,
            '[data-activity-options]': list, '[data-activity-status]': status })[selector];
        const container = { querySelector: () => picker };
        initializeActivityPicker(container);
        initializeActivityPicker(container);
        assert.ok(input.validationMessage);
        input.value = 'comercio'; emit(input, 'input'); tick();
        input.value = 'servicos'; emit(input, 'input'); tick();
        assert.equal(requests.length, 2);
        assert.equal(requests[0].options.signal.aborted, true);
        requests[1].resolve({ ok: true, json: async () => ({ data: [{ id: 2, descricao: 'Serviços' }], has_more: false }) });
        await flush();
        requests[0].resolve({ ok: true, json: async () => ({ data: [{ id: 1, descricao: 'Comércio' }], has_more: false }) });
        await flush();
        assert.equal(list.children[0].textContent, 'Serviços');
        assert.equal(list.hidden, false);
        emit(input, 'keydown', { key: 'ArrowDown' });
        assert.equal(input.attributes['aria-activedescendant'], 'activity-option-2');
        const enter = emit(input, 'keydown', { key: 'Enter' });
        assert.equal(enter.defaultPrevented, true);
        assert.equal(id.value, '2'); assert.equal(input.value, 'Serviços');
        assert.equal(input.validationMessage, ''); assert.equal(list.hidden, true);
        input.value = 'inventado'; emit(input, 'input');
        assert.equal(id.value, ''); assert.ok(input.validationMessage);
        tick(); requests[2].resolve({ ok: false, status: 503 }); await flush();
        assert.match(status.textContent, /Não foi possível/); assert.equal(list.hidden, true);
        emit(input, 'input'); tick();
        requests[3].resolve({ ok: true, json: async () => ({ data: [], has_more: false }) }); await flush();
        assert.match(status.textContent, /Nenhuma atividade/);
        input.value = 'se'; emit(input, 'input'); tick(); emit(input, 'blur');
        requests[4].resolve({ ok: true, json: async () => ({ data: [{ id: 2, descricao: 'Serviços' }], has_more: false }) });
        await flush(); assert.equal(list.hidden, true);
        input.value = 'se'; emit(input, 'input'); tick();
        requests[5].resolve({ ok: true, json: async () => ({ data: [{ id: 2, descricao: 'Serviços' }], has_more: false }) });
        await flush(); emit(list.children[0], 'click');
        assert.equal(id.value, '2'); assert.equal(input.validationMessage, '');
        console.log('activity picker verified');
        JS, resource_path('js/commercial-activity.js')]);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('activity picker verified');
});
