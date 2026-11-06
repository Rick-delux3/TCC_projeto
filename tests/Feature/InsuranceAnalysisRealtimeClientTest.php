<?php

it('coalesces invalidations recovers after reconnect and refuses stale or unauthorized state', function () {
    $process = new \Symfony\Component\Process\Process(['node', '--input-type=module', '-e', <<<'JS'
        import assert from 'node:assert/strict';
        import { createAnalysisRealtime } from './resources/js/insurance-analysis-realtime.js';
        globalThis.window = new EventTarget();
        globalThis.document = Object.assign(new EventTarget(), { hidden: false });
        const timers = new Map();
        let sequence = 0;
        globalThis.setTimeout = (callback, delay) => { const id = ++sequence; timers.set(id, { callback, delay }); return id; };
        globalThis.clearTimeout = id => timers.delete(id);
        const fire = () => {
            const [id, task] = [...timers].sort((a, b) => a[1].delay - b[1].delay)[0];
            timers.delete(id);
            return task.callback();
        };
        const handlers = {};
        const left = [];
        const channel = {
            listen(event, callback) { assert.equal(event, '.insurance.analyses.changed'); handlers.event = callback; return this; },
            subscribed(callback) { handlers.subscribed = callback; return this; },
            error(callback) { handlers.error = callback; return this; },
        };
        const echo = {
            private(name) { assert.equal(name, 'admins.leads.1.analyses'); return channel; },
            leave(name) { left.push(name); },
            connector: { pusher: { connection: {
                bind(name, callback) { handlers[name] = callback; },
                unbind(name) { delete handlers[name]; },
            } } },
        };
        const initial = {
            lead: { id: 1 }, revision: 0,
            realtime: { refresh_url: '/state', interval_ms: 5000, reconcile_interval_ms: 30000, should_refresh: true,
                broadcasting: { enabled: true, channel: 'admins.leads.1.analyses', event: '.insurance.analyses.changed' } },
        };
        const pending = [];
        let calls = 0;
        const fetchState = (url, options) => {
            assert.equal(url, '/state'); assert.equal(options.cache, 'no-store'); assert.equal(options.credentials, 'same-origin');
            calls++;
            return new Promise(resolve => pending.push(resolve));
        };
        const response = (revision, finished = false) => ({ ok: true, status: 200, json: async () => ({
            data: { ...initial, revision, realtime: { ...initial.realtime, should_refresh: !finished } },
        }) });
        const published = [];
        const errors = [];
        const client = createAnalysisRealtime(initial, { echo, fetchState, onState: state => published.push(state?.revision ?? null), onError: error => errors.push(error) });
        client.start();
        const first = fire();
        for (let i = 0; i < 20; i++) handlers.event({ lead_id: 1 });
        assert.equal(calls, 1);
        pending.shift()(response(1)); await first;
        assert.deepEqual(published, [0]);
        const latest = fire(); pending.shift()(response(2)); await latest;
        assert.equal(client.state.revision, 2); assert.equal(calls, 2);
        const scheduled = [...timers.keys()];
        handlers.event({ lead_id: 999 }); assert.deepEqual([...timers.keys()], scheduled);

        handlers.subscribed();
        const complete = fire(); pending.shift()(response(3, true)); await complete;
        assert.equal(client.state.realtime.should_refresh, false);
        assert.equal([...timers.values()][0].delay, 30000);
        handlers['state_change']({ current: 'disconnected' });
        handlers['state_change']({ current: 'connected' });
        handlers.subscribed();
        const reconnect = fire(); pending.shift()(response(4, true)); await reconnect;
        assert.equal(client.state.revision, 4);

        handlers.event({ lead_id: 1 });
        const reanalysis = fire(); pending.shift()(response(5)); await reanalysis;
        assert.equal(client.state.realtime.should_refresh, true);
        const failure = client.refresh(); pending.shift()({ status: 500, ok: false }); await failure;
        assert.equal(errors.at(-1), 'temporarily_unavailable'); assert.equal(client.state.revision, 5);
        assert.equal([...timers.values()][0].delay, 60000);
        const recovered = fire(); pending.shift()(response(6)); await recovered;
        const newerRound = client.refresh();
        pending.shift()({ ok: true, status: 200, json: async () => ({ data: { ...initial, revision: 7, batch: { id: 2 }, realtime: { ...initial.realtime, round_id: 20 } } }) });
        await newerRound;
        const obsoleteRound = client.refresh();
        pending.shift()({ ok: true, status: 200, json: async () => ({ data: { ...initial, revision: 8, batch: { id: 2 }, realtime: { ...initial.realtime, round_id: 19 } } }) });
        await obsoleteRound;
        assert.equal(client.state.revision, 7);
        document.hidden = true; document.dispatchEvent(new Event('visibilitychange'));
        assert.equal(timers.size, 0);
        document.hidden = false; document.dispatchEvent(new Event('visibilitychange'));
        assert.equal(timers.size, 1);
        const denied = fire(); pending.shift()({ status: 403, ok: false }); await denied;
        assert.equal(client.state, null); assert.equal(errors.at(-1), 'access_denied');
        assert.deepEqual(left, ['admins.leads.1.analyses']); assert.equal(timers.size, 0);
        handlers.event({ lead_id: 1 }); window.dispatchEvent(new Event('online'));
        assert.equal(timers.size, 0);

        const fallback = createAnalysisRealtime(initial, { fetchState });
        fallback.start();
        const fallbackRead = fire(); pending.shift()(response(7)); await fallbackRead;
        assert.equal(fallback.state.revision, 7); assert.equal([...timers.values()][0].delay, 5000);
        const expiredSession = fallback.refresh(); pending.shift()({ status: 0, type: 'opaqueredirect', ok: false }); await expiredSession;
        assert.equal(fallback.state, null); assert.equal(timers.size, 0);
        console.log('Realtime client checks passed');
        JS,
    ], base_path());
    $process->setTimeout(20)->mustRun();
    expect($process->getOutput())->toContain('Realtime client checks passed');
});

it('updates independent components and preserves edited open dialogs until closing', function () {
    $process = new \Symfony\Component\Process\Process(['node', '--input-type=module', '-e', <<<'JS'
        import assert from 'node:assert/strict';
        import { createAnalysisComponents } from './resources/js/insurance-analysis-components.js';
        const nodes = new Map();
        const replacements = [];
        let components = [];
        const node = (key, html, open = false) => ({
            dataset: { analysisComponent: key }, outerHTML: html, open, value: 'edited expense',
            querySelector() { return this.open ? {} : null; },
            replaceWith(next) { replacements.push(key); nodes.set(key, next); }
        });
        const card = node('provider-too', 'processing');
        const form = node('expense-form', 'old form', true);
        const details = node('details-too', 'old details', true);
        for (const item of [card, form, details]) nodes.set(item.dataset.analysisComponent, item);
        const root = {
            querySelector(selector) { return nodes.get(selector.match(/="([^"]+)"/)[1]); },
            createElement() { return { content: { querySelectorAll() { return components; } } }; }
        };
        const renderer = createAnalysisComponents({ root });
        components = [node('provider-too', 'processing')];
        renderer.update('unchanged initial HTML');
        assert.equal(replacements.length, 0);
        components = [node('provider-too', 'approved'), node('expense-form', 'new form'), node('details-too', 'new details')];
        renderer.update('server-rendered HTML');
        assert.equal(nodes.get('provider-too').outerHTML, 'approved');
        assert.equal(nodes.get('expense-form'), form);
        assert.equal(form.value, 'edited expense');
        assert.equal(nodes.get('details-too'), details);
        components = [node('expense-form', 'latest form')];
        renderer.update('new round');
        form.open = false;
        renderer.flush();
        assert.equal(nodes.get('expense-form').outerHTML, 'latest form');
        assert.equal(nodes.get('details-too'), details);
        details.open = false;
        renderer.flush();
        assert.equal(nodes.get('details-too').outerHTML, 'new details');
        const previousCount = replacements.length;
        components = [node('provider-too', 'approved')];
        renderer.update('unchanged state');
        assert.equal(replacements.length, previousCount);
        console.log('Component updates passed');
        JS,
    ], base_path());
    $process->setTimeout(20)->mustRun();
    expect($process->getOutput())->toContain('Component updates passed');
});
