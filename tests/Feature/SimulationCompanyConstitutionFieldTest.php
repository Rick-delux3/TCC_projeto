<?php

use App\Models\Imobiliaria;
use App\Support\CompanyConstitution;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->withoutVite();
});

function constitutionFieldDom(string $html): DOMXPath
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

it('renders the constitution selector only for commercial companies in all shared forms', function (string $route, string $document, string $rentalType, bool $visible): void {
    if ($route === 'simulation.registered-company.form') {
        $company = Imobiliaria::factory()->create(['lead_access_code' => 'FIELD1']);
        $this->post(route('simulation.registered-company.verify'), ['lead_access_code' => $company->lead_access_code])->assertRedirect();
    }

    $this->withSession(['_old_input' => [
        'cpf' => $document, 'tipo_locacao' => $rentalType, 'empresa_constituida' => 'S',
    ]]);
    $dom = constitutionFieldDom($this->get(route($route))->assertOk()->getContent());
    $active = '//*[@data-simulation-fields="company-constitution"]';

    expect($dom->query($active.'//select[@name="empresa_constituida" and @required]')->length)->toBe($visible ? 1 : 0)
        ->and($dom->query('//template[@data-simulation-template="company-constitution"]//select')->length)->toBe(1)
        ->and($dom->query('//dialog[@data-company-constitution-modal and not(ancestor::form)]')->length)->toBe(1)
        ->and($dom->evaluate('string(//dialog//button[@data-company-constitution-correct])'))->toBe('Ir para o campo CPF/CNPJ')
        ->and($dom->evaluate('string(//*[@id="company-constitution-message"])'))->toBe(CompanyConstitution::REJECTION_MESSAGE)
        ->and($dom->query('//input[@name="dataFundacao" or @name="data_fundacao_calculada" or @name="tipo_pessoa"]')->length)->toBe(0);

    if ($visible) {
        expect($dom->evaluate('string('.$active.'//option[@selected]/@value)'))->toBe('S')
            ->and($dom->query($active.'//option')->length)->toBe(3)
            ->and($dom->query('//*[@data-form-step="2"]'.$active.'//select')->length)->toBe(1)
            ->and($dom->evaluate('string('.$active.'/div/@class)'))->toContain('md:col-span-2');
    }
})->with(['simulation.tenant.form', 'simulation.unregistered-company.form', 'simulation.registered-company.form'])
    ->with([
        ['11.222.333/0001-81', 'comercial', true],
        ['11222333000181', 'residencial', false],
        ['52998224725', 'comercial', false],
        ['52998224725', 'residencial', false],
    ]);

it('restores a rejected constitution answer with an accessible error in step two', function (): void {
    $errors = (new ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag([
        'empresa_constituida' => CompanyConstitution::REJECTION_MESSAGE,
    ]));
    $this->withSession(['errors' => $errors, '_old_input' => [
        'cpf' => '11222333000181', 'tipo_locacao' => 'comercial', 'empresa_constituida' => 'N',
    ]]);
    $dom = constitutionFieldDom($this->get(route('simulation.tenant.form'))->assertOk()->getContent());
    $active = '//*[@data-simulation-fields="company-constitution"]';
    expect($dom->evaluate('string('.$active.'//option[@selected]/@value)'))->toBe('N')
        ->and($dom->evaluate('string('.$active.'//select/@aria-invalid)'))->toBe('true')
        ->and($dom->evaluate('string('.$active.'//select/@aria-describedby)'))->toBe('empresa_constituida-error')
        ->and($dom->query($active.'//p[@hidden]')->length)->toBe(0)
        ->and($dom->evaluate('string(//*[@data-simulation-wizard]/@data-initial-step)'))->toBe('2');
});

it('synchronizes constitution visibility and blocks negative answers before wizard submission', function (): void {
    $process = new Process(['node', '--input-type=module', '-e', <<<'JS'
        import assert from 'node:assert/strict';
        import { initializeCompanyConstitution } from './resources/js/company-constitution.js';
        function setup(answer = '') {
            const listeners = new Map();
            let current = null;
            let focus = null;
            let scrolledToDocument = false;
            let step = 2;
            const message = 'Empresa com menos de dois anos não tem aceitação. Por favor fazer análise com CPF.';
            const document = {
                value: '11.222.333/0001-81',
                scrollIntoView(options) {
                    assert.equal(step, 1);
                    assert.equal(options.block, 'center');
                    scrolledToDocument = true;
                },
                focus(options) {
                    assert.equal(options.preventScroll, true);
                    focus = 'document';
                },
            };
            const rental = { value: 'comercial' };
            const error = { textContent: 'Old server error', hidden: false };
            const correct = new EventTarget();
            const close = new EventTarget();
            const dialog = Object.assign(new EventTarget(), {
                open: false,
                showModal() { this.open = true; },
                close() { this.open = false; this.dispatchEvent(new Event('close')); },
                querySelector(query) {
                    return query.includes('-message]') ? { textContent: message }
                        : query.includes('-correct]') ? correct : close;
                },
            });
            const wizard = { querySelector: query => query.includes('data-step-link') ? { click() { step = 1; } } : dialog };
            const form = {
                closest: () => wizard,
                querySelector(query) {
                    return ({ '[name="cpf"]': document, '[name="tipo_locacao"]:checked': rental,
                        '[name="empresa_constituida"]': current, '#empresa_constituida-error': error })[query];
                },
                addEventListener(event, handler, capture = false) { listeners.set(event, { handler, capture }); },
            };
            function renderFields(condition, visible) {
                assert.equal(condition, 'company-constitution');
                if (!visible) { current = null; }
                else if (!current) {
                    current = { value: '', validity: '', attributes: {},
                        setCustomValidity(value) { this.validity = value; },
                        setAttribute(name, value) { this.attributes[name] = value; },
                        focus() { focus = 'select'; },
                    };
                }
            }
            renderFields('company-constitution', true);
            current.value = answer;
            const sync = initializeCompanyConstitution(form, renderFields);
            sync();
            return { document, rental, error, dialog, correct, close, sync, message,
                get field() { return current; }, get focus() { return focus; }, get step() { return step; },
                get scrolledToDocument() { return scrolledToDocument; },
                change(value) { current.value = value; listeners.get('change').handler({ target: current }); },
                submit() {
                    assert.equal(listeners.get('submit').capture, true);
                    let prevented = false; let stopped = false;
                    listeners.get('submit').handler({ preventDefault() { prevented = true; }, stopImmediatePropagation() { stopped = true; } });
                    return { prevented, stopped };
                },
            };
        }
        const ui = setup('S');
        assert.equal(ui.field.value, 'S');
        ui.change('N');
        assert.equal(ui.dialog.open, true);
        assert.equal(ui.field.validity, ui.message);
        assert.equal(ui.error.textContent, ui.message);
        assert.equal(ui.error.hidden, false);
        ui.close.dispatchEvent(new Event('click'));
        assert.equal(ui.dialog.open, false);
        assert.equal(ui.focus, 'select');
        assert.deepEqual(ui.submit(), { prevented: true, stopped: true });
        assert.equal(ui.dialog.open, true);
        ui.correct.dispatchEvent(new Event('click'));
        assert.equal(ui.step, 1);
        assert.equal(ui.focus, 'document');
        assert.equal(ui.scrolledToDocument, true);
        assert.equal(ui.field.validity, ui.message);
        ui.document.value = '529.982.247-25'; ui.sync();
        assert.equal(ui.field, null);
        assert.deepEqual(ui.submit(), { prevented: false, stopped: false });
        ui.document.value = '11222333000181'; ui.sync();
        assert.equal(ui.field.value, '');
        assert.equal(ui.error.hidden, true);
        ui.change('S');
        assert.equal(ui.field.validity, '');
        assert.equal(ui.field.attributes['aria-invalid'], 'false');
        assert.deepEqual(ui.submit(), { prevented: false, stopped: false });
        ui.document.value = '04252011000110'; ui.sync();
        assert.equal(ui.field.value, '');
        ui.change('S'); ui.rental.value = 'residencial'; ui.sync();
        assert.equal(ui.field, null);
        ui.rental.value = 'comercial'; ui.sync();
        assert.equal(ui.field.value, '');
        const restored = setup('N');
        assert.equal(restored.field.validity, restored.message);
        assert.equal(restored.dialog.open, false);
        restored.submit();
        assert.equal(restored.dialog.open, true);
        restored.rental.value = 'residencial'; restored.sync();
        assert.equal(restored.dialog.open, false);
        assert.equal(restored.field, null);
        console.log('constitution interactions verified');
        JS], base_path());
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('constitution interactions verified');
});
