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
