import test from 'node:test';
import assert from 'node:assert/strict';
import { buildDepartmentPayload } from './company-departments.js';

test('disabled department emails send no departments even when fields retain their values', () => {
    assert.deepEqual(buildDepartmentPayload([
        { key: 'comercial', name: 'Comercial / Vendas', email: 'vendas@example.test', custom: false },
    ], false), []);
});

test('empty suggested departments are omitted and remaining rows form a contiguous list', () => {
    const result = buildDepartmentPayload([
        { key: 'comercial', name: 'Comercial / Vendas', email: '', custom: false },
        { key: 'financeiro', name: 'Financeiro / Pagamentos', email: ' FINANCEIRO@EXAMPLE.TEST ', custom: false },
        { key: 'socio', name: 'Sócio / Proprietário', email: ' ', custom: false },
    ], true);
    assert.deepEqual(result, [
        { key: 'financeiro', name: 'Financeiro / Pagamentos', email: 'financeiro@example.test' },
    ]);
});

test('custom departments accept an optional email and receive unique valid keys', () => {
    const result = buildDepartmentPayload([
        { key: '', name: ' Vistórias / Locação ', email: '', custom: true },
        { key: '', name: 'Vistórias / Locação', email: 'locacao@example.test', custom: true },
        { key: 'personalizado_vistorias_locacao_1', name: 'Setor existente', email: '', custom: true },
    ], true);
    assert.equal(new Set(result.map(({ key }) => key)).size, 3);
    result.forEach(({ key }) => {
        assert.match(key, /^[a-z][a-z0-9_-]*$/);
        assert.ok(key.length <= 100);
    });
    assert.equal(result[0].name, 'Vistórias / Locação');
    assert.equal(result[0].email, '');
    assert.equal(result[2].key, 'personalizado_vistorias_locacao_1');
});

test('restored department keys and shared emails are preserved without mutating the form data', () => {
    const input = [
        { key: 'comercial', name: 'Comercial / Vendas', email: 'contato@example.test', custom: false },
        { key: 'personalizado_atendimento_1', name: 'Novo nome', email: 'contato@example.test', custom: true },
    ];
    const before = structuredClone(input);
    const result = buildDepartmentPayload(input, true);
    assert.equal(result[1].key, 'personalizado_atendimento_1');
    assert.equal(result[0].email, result[1].email);
    assert.deepEqual(input, before);
});
