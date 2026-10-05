export function createAnalysisRealtime(initialState, { echo, fetchState = window.fetch.bind(window), onState = () => {}, onError = () => {} } = {}) {
    const leadId = initialState.lead.id;
    const config = initialState.realtime;
    let state = initialState;
    let started = false;
    let stopped = false;
    let subscribed = false;
    let timer;
    let request;
    let inFlight = false;
    let dirty = false;
    let failures = 0;
    const connection = echo?.connector?.pusher?.connection;

    function schedule(delay) {
        clearTimeout(timer);
        if (!stopped && !document.hidden) {
            timer = setTimeout(refresh, delay);
        }
    }

    function invalidate() {
        if (stopped) return;
        if (inFlight) {
            dirty = true;
        } else {
            schedule(150);
        }
    }

    async function refresh() {
        if (stopped || document.hidden) return;
        if (inFlight) {
            dirty = true;
            return;
        }
        clearTimeout(timer);
        inFlight = true;
        dirty = false;
        request = new AbortController();
        const timeout = setTimeout(() => request?.abort(), 10000);
        try {
            const response = await fetchState(config.refresh_url, {
                method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'manual',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: request.signal,
            });
            if (response.type === 'opaqueredirect' || [401, 403, 404, 419].includes(response.status)) {
                destroy();
                state = null;
                onState(null);
                onError('access_denied');
                return;
            }
            if (!response.ok) throw new Error('Refresh failed');
            const payload = await response.json();
            if (Number(payload.data?.lead?.id) !== Number(leadId)) throw new Error('Unexpected lead');
            failures = 0;
            if (!stopped && !dirty) {
                state = payload.data;
                onState(state);
            }
        } catch {
            if (!stopped) {
                failures++;
                onError('temporarily_unavailable');
            }
        } finally {
            clearTimeout(timeout);
            request = null;
            inFlight = false;
            const interval = subscribed || !state?.realtime.should_refresh
                ? config.reconcile_interval_ms : config.interval_ms;
            schedule(dirty ? 150 : Math.min(60000, Math.max(1000, interval) * (2 ** Math.min(failures, 4))));
        }
    }

    function connectionChanged({ current }) {
        if (current !== 'connected') subscribed = false;
        invalidate();
    }

    function visibilityChanged() {
        if (document.hidden) {
            clearTimeout(timer);
        } else {
            invalidate();
        }
    }

    function start() {
        if (started || stopped) return;
        started = true;
        onState(state);
        window.addEventListener('online', invalidate);
        window.addEventListener('pageshow', invalidate);
        document.addEventListener('visibilitychange', visibilityChanged);
        if (config.broadcasting.enabled && echo) {
            try {
                echo.private(config.broadcasting.channel)
                    .listen(config.broadcasting.event, (event) => {
                        if (Number(event.lead_id) === Number(leadId)) invalidate();
                    })
                    .subscribed(() => { subscribed = true; invalidate(); })
                    .error(() => { subscribed = false; invalidate(); });
                connection?.bind('state_change', connectionChanged);
            } catch {
                subscribed = false;
            }
        }
        schedule(0);
    }

    function destroy() {
        stopped = true;
        clearTimeout(timer);
        request?.abort();
        window.removeEventListener('online', invalidate);
        window.removeEventListener('pageshow', invalidate);
        document.removeEventListener('visibilitychange', visibilityChanged);
        connection?.unbind('state_change', connectionChanged);
        if (config.broadcasting.enabled && echo) echo.leave(config.broadcasting.channel);
    }

    return { start, refresh, destroy, get state() { return state; } };
}
