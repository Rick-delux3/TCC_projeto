import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createAnalysisMotion } from './insurance-analysis-motion.js';

// Elemento falso: registra animações e responde aos seletores usados pelo módulo.
function element({ attributes = {}, children = {}, style = {} } = {}) {
    const attrs = new Map(Object.entries(attributes));
    const node = {
        style,
        animations: [],
        getAttribute: name => attrs.get(name) ?? null,
        hasAttribute: name => attrs.has(name),
        removeAttribute: name => attrs.delete(name),
        querySelector: selector => children[selector] ?? null,
        querySelectorAll: selector => children[selector] ?? [],
        animate(keyframes, options) {
            const animation = { keyframes, options, cancelled: false, cancel() { this.cancelled = true; } };
            node.animations.push(animation);
            return animation;
        },
    };
    return node;
}

function page({ brand = 'tcc', reduced = false, intro = true } = {}) {
    const timers = [];
    const body = element({ attributes: { 'data-brand': brand, ...(intro ? { 'data-ia-intro': '' } : {}) } });
    const view = {
        matchMedia: () => ({ matches: reduced }),
        getComputedStyle: () => ({ getPropertyValue: () => '#8bbaa1' }),
        setTimeout: callback => timers.push(callback),
    };
    return { motion: createAnalysisMotion({ root: { body }, view }), body, timers };
}

function batch(width, final = 'false') {
    const bar = element({ style: { width } });
    return { bar, node: element({ attributes: { 'data-batch-final': final }, children: { '.progress-bar': bar } }) };
}

function provider(state) {
    const body = element();
    const card = element({ attributes: { 'data-analysis-state': state }, children: { '.card-body': body } });
    return { card, body, node: element({ children: { '.ia-company': card } }) };
}

test('a barra do pacote avança a partir do percentual anterior', () => {
    const { motion } = page();
    const next = batch('100%', 'true');
    motion.replaced('batch', batch('50%').node, next.node);

    assert.equal(next.bar.animations.length, 1);
    assert.deepEqual(next.bar.animations[0].keyframes[0], { transform: 'scaleX(0.5)' });
});

test('a companhia que muda de estado pulsa o contorno; sem mudança, só o conteúdo reaparece', () => {
    const { motion } = page();
    const changed = provider('approved');
    motion.replaced('provider-too', provider('processing').node, changed.node);
    assert.equal(changed.card.animations.length, 1);
    assert.match(changed.card.animations[0].keyframes[0].boxShadow, /#8bbaa1/);

    const same = provider('approved');
    motion.replaced('provider-too', provider('approved').node, same.node);
    assert.equal(same.card.animations.length, 0);
    assert.equal(same.body.animations.length, 1);
});

test('uma nova atualização cancela a animação anterior do mesmo componente', () => {
    const { motion } = page();
    const first = provider('approved');
    motion.replaced('provider-porto', provider('processing').node, first.node);
    motion.replaced('provider-porto', provider('approved').node, provider('approved').node);

    assert.ok(first.card.animations.every(animation => animation.cancelled));
    assert.ok(first.body.animations.every(animation => animation.cancelled));
});

test('movimento reduzido e perfil client não animam nada', () => {
    for (const options of [{ reduced: true }, { brand: 'client' }]) {
        const { motion } = page(options);
        const next = batch('100%');
        motion.replaced('batch', batch('20%').node, next.node);
        assert.equal(next.bar.animations.length, 0);
    }
});

test('a sequência de entrada é encerrada uma única vez', () => {
    const { motion, body, timers } = page();
    motion.intro();
    assert.equal(timers.length, 1);
    timers[0]();
    assert.equal(body.hasAttribute('data-ia-intro'), false);

    motion.intro();
    assert.equal(timers.length, 1);
});
