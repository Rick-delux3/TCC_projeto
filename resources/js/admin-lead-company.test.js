import { afterEach, mock, test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeCompanySearch, sendLeadCompanyLink, initializeLeadCompanyLink } from './admin-lead-company.js';

afterEach(() => mock.restoreAll());

test('company search ignores accents, case and surrounding whitespace', () => {
    assert.equal(normalizeCompanySearch('  Imobiliária São João · Tatuí  '), 'imobiliaria sao joao · tatui');
    assert.equal(normalizeCompanySearch(''), '');
});

test('submits only the company ID with session and CSRF protection', async () => {
    const signal = new AbortController().signal;
    const fetch = mock.method(globalThis, 'fetch', async () => new Response(JSON.stringify({ request_id: 72 }), { status: 202 }));
    const result = await sendLeadCompanyLink('/Dashboard/Admin/leads/14/imobiliaria', '8', 'csrf-test', signal);
    const [url, options] = fetch.mock.calls[0].arguments;

    assert.deepEqual(result, { accepted: true, requestId: 72 });
    assert.equal(url, '/Dashboard/Admin/leads/14/imobiliaria');
    assert.equal(options.method, 'POST');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.redirect, 'error');
    assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf-test');
    assert.equal(options.headers.Accept, 'application/json');
    assert.equal(options.signal, signal);
    assert.deepEqual(JSON.parse(options.body), { company_id: '8' });
});

test('shows the backend validation message for an unavailable or conflicting company', async () => {
    mock.method(globalThis, 'fetch', async () => new Response(JSON.stringify({
        errors: { company_id: ['A imobiliária selecionada já possui outro lead com este e-mail.'] },
    }), { status: 422 }));

    const result = await sendLeadCompanyLink('/link', '8', 'csrf-test');
    assert.equal(result.accepted, false);
    assert.equal(result.message, 'A imobiliária selecionada já possui outro lead com este e-mail.');
});

for (const [status, expectedText] of [
    [401, 'sessão expirou'],
    [403, 'não tem permissão'],
    [404, 'não está mais disponível'],
    [419, 'sessão expirou'],
    [429, 'Muitas solicitações'],
    [500, 'Não foi possível confirmar'],
]) {
    test(`handles HTTP ${status} without displaying an internal error page`, async () => {
        mock.method(globalThis, 'fetch', async () => new Response('<html>Internal error details</html>', { status }));
        const result = await sendLeadCompanyLink('/link', '8', 'csrf-test');
        assert.equal(result.accepted, false);
        assert.ok(result.message.includes(expectedText));
        assert.ok(!result.message.includes('Internal error'));
    });
}

test('does not report an accepted request when the response is incomplete', async () => {
    mock.method(globalThis, 'fetch', async () => new Response('{}', { status: 202 }));
    assert.equal((await sendLeadCompanyLink('/link', '8', 'csrf-test')).accepted, false);
});

test('does not automatically resend on a network interruption', async () => {
    const fetch = mock.method(globalThis, 'fetch', async () => { throw new TypeError('Network error'); });
    await assert.rejects(() => sendLeadCompanyLink('/link', '8', 'csrf-test'), TypeError);
    assert.equal(fetch.mock.calls.length, 1);
});

test('does not initialize company linking on dashboards without the authorized modal', () => {
    const previousDocument = globalThis.document;
    globalThis.document = { getElementById: () => null };
    try {
        assert.doesNotThrow(initializeLeadCompanyLink);
    } finally {
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
    }
});
