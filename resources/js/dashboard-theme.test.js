import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initializeDashboardTheme } from './dashboard-theme.js';

function element(classes = []) {
    const tokens = new Set(classes);
    const attributes = new Map();
    const events = new Map();
    return {
        textContent: '',
        classList: {
            contains: name => tokens.has(name),
            toggle: (name, enabled) => enabled ? tokens.add(name) : tokens.delete(name),
        },
        setAttribute: (name, value) => attributes.set(name, value),
        getAttribute: name => attributes.get(name),
        querySelector: () => null,
        addEventListener: (name, handler) => events.set(name, handler),
        click: () => events.get('click')(),
    };
}

function page({ brand = 'tcc', admin = true, controls = 1, storage = new Map() } = {}) {
    const body = element(admin ? ['dashboard-admin-body'] : []);
    body.setAttribute('data-brand', brand);
    const root = element(['dashboard-shell']);
    const toggles = Array.from({ length: controls }, () => element());
    const document = {
        body,
        querySelectorAll: selector => selector.includes('toggle') || selector.includes('Toggle') ? toggles : [root],
    };
    const browser = { localStorage: {
        getItem: key => storage.get(key),
        setItem: (key, value) => storage.set(key, value),
    } };
    return { document, browser, body, root, toggles, storage };
}

for (const brand of ['tcc', 'client']) {
    test(`${brand}: restores dark mode on subpages even without the leads toggle`, () => {
        const fixture = page({ brand, controls: 0, storage: new Map([['dashboard-theme', 'dark']]) });
        initializeDashboardTheme(fixture.document, fixture.browser);
        assert.equal(fixture.root.getAttribute('data-dashboard-theme'), 'dark');
        assert.equal(fixture.body.getAttribute('data-dashboard-theme'), 'dark');
        assert.equal(fixture.body.getAttribute('data-bs-theme'), 'dark');
        assert.equal(fixture.body.getAttribute('data-brand'), brand);
    });

    test(`${brand}: synchronizes both controls, persists navigation, and returns to light`, () => {
        const first = page({ brand, controls: 2 });
        const icon = element(['bi-moon']);
        first.toggles[0].querySelector = selector => selector === '[data-dashboard-theme-icon]' ? icon : null;
        initializeDashboardTheme(first.document, first.browser);
        first.toggles[1].click();
        assert.equal(first.root.getAttribute('data-dashboard-theme'), 'dark');
        assert.equal(icon.classList.contains('bi-sun'), true);
        first.toggles.forEach(toggle => {
            assert.equal(toggle.getAttribute('aria-pressed'), 'true');
            assert.equal(toggle.getAttribute('aria-label'), 'Modo claro');
        });
        assert.equal(first.toggles[1].textContent, 'Modo claro');

        const next = page({ brand, storage: first.storage });
        initializeDashboardTheme(next.document, next.browser);
        assert.equal(next.root.getAttribute('data-dashboard-theme'), 'dark');
        next.toggles[0].click();
        assert.equal(next.root.getAttribute('data-dashboard-theme'), 'light');
        assert.equal(next.body.getAttribute('data-bs-theme'), 'light');
        assert.equal(next.toggles[0].getAttribute('aria-pressed'), 'false');
        assert.equal(first.storage.get('dashboard-theme'), 'light');
    });
}

test('invalid saved preferences default to light', () => {
    const fixture = page({ storage: new Map([['dashboard-theme', 'invalid']]) });
    initializeDashboardTheme(fixture.document, fixture.browser);
    assert.equal(fixture.root.getAttribute('data-dashboard-theme'), 'light');
});

test('theme controls still work when browser storage is blocked', () => {
    const fixture = page();
    Object.defineProperty(fixture.browser, 'localStorage', { get() { throw new Error('Blocked storage'); } });
    initializeDashboardTheme(fixture.document, fixture.browser);
    fixture.toggles[0].click();
    assert.equal(fixture.root.getAttribute('data-dashboard-theme'), 'dark');
});

test('the existing user dashboard toggle continues to work without changing its body theme', () => {
    const fixture = page({ admin: false });
    initializeDashboardTheme(fixture.document, fixture.browser);
    fixture.toggles[0].click();
    assert.equal(fixture.root.getAttribute('data-dashboard-theme'), 'dark');
    assert.equal(fixture.body.getAttribute('data-bs-theme'), undefined);
});
