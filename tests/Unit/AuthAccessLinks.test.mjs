import assert from 'node:assert/strict';
import test from 'node:test';
import { protectAuthAccessLinks } from '../../resources/js/auth-access-links.js';

function setup() {
    const root = new EventTarget();
    let time = 0;
    protectAuthAccessLinks(root, () => time);

    return {
        advance(milliseconds) { time += milliseconds; },
        click({ type = 'click', button = 0, access = true, target = '_blank', prevented = false } = {}) {
            const event = new Event(type, { cancelable: true });
            Object.defineProperties(event, {
                button: { value: button },
                target: { value: { closest: () => access ? { target } : null } },
            });
            if (prevented) event.preventDefault();
            root.dispatchEvent(event);
            return event.defaultPrevented;
        },
    };
}

test('allows the first activation and blocks rapid activation across different access links', () => {
    const page = setup();
    assert.equal(page.click(), false);
    assert.equal(page.click(), true);
    page.advance(999);
    assert.equal(page.click({ type: 'auxclick', button: 1 }), true);
    page.advance(1);
    assert.equal(page.click(), false);
});

test('middle click participates in the same cooldown', () => {
    const page = setup();
    assert.equal(page.click({ type: 'auxclick', button: 1 }), false);
    assert.equal(page.click(), true);
});

test('unrelated links, same-tab navigation and right clicks do not consume the cooldown', () => {
    const page = setup();
    assert.equal(page.click({ access: false }), false);
    assert.equal(page.click({ target: '_self' }), false);
    assert.equal(page.click({ type: 'auxclick', button: 2 }), false);
    assert.equal(page.click({ prevented: true }), true);
    assert.equal(page.click(), false);
});
