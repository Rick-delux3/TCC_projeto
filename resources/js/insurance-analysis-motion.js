/**
 * Movimento da página de análises (perfil NVS / data-brand="tcc").
 *
 * A entrada inicial é CSS (sem piscar antes de o módulo carregar). Aqui ficam só
 * as animações que respondem a uma atualização em tempo real:
 *   - barra do pacote avança a partir do percentual anterior;
 *   - companhia que mudou de estado pulsa o contorno na cor do novo estado;
 *   - comparação nova reaparece em sequência e redesenha o filete do melhor preço.
 *
 * Regras (ui-ux-pro-max): só transform/opacity (o pulso é um contorno curto);
 * cada nova atualização cancela a anterior do mesmo componente; o estado final
 * nunca depende do fim da animação — cancelar apenas mostra o DOM como está.
 */
const EASE_OUT = 'cubic-bezier(0.25, 0.46, 0.45, 0.94)';
const EASE_DRAW = 'cubic-bezier(0.16, 1, 0.3, 1)';
const INTRO_MS = 1100;

export function createAnalysisMotion({ root = document, view = globalThis.window } = {}) {
    const running = new Map();
    const canAnimatePseudo = typeof KeyframeEffect !== 'undefined' && 'pseudoElement' in KeyframeEffect.prototype;

    function enabled() {
        return root.body?.getAttribute('data-brand') === 'tcc'
            && !view?.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    }

    function track(key, animations) {
        running.get(key)?.forEach(animation => animation.cancel());
        running.set(key, animations.filter(Boolean));
    }

    function rise(element, { distance = 6, duration = 240, delay = 0 } = {}) {
        return element?.animate?.(
            [{ opacity: 0, transform: `translateY(${distance}px)` }, { opacity: 1, transform: 'none' }],
            { duration, delay, easing: EASE_OUT, fill: 'backwards' },
        );
    }

    function percent(component) {
        const value = parseFloat(component?.querySelector?.('.progress-bar')?.style?.width);
        return Number.isFinite(value) ? value : null;
    }

    function batch(previous, next) {
        const before = percent(previous);
        const after = percent(next);
        const animations = [];
        const bar = next.querySelector('.progress-bar');
        if (bar && before !== null && after > before) {
            animations.push(bar.animate(
                [{ transform: `scaleX(${before / after})` }, { transform: 'scaleX(1)' }],
                { duration: 560, easing: EASE_DRAW },
            ));
        }
        // O pacote acabou de fechar: o resultado consolidado aparece depois da barra.
        const finished = next.getAttribute('data-batch-final') === 'true'
            && previous.getAttribute?.('data-batch-final') !== 'true';
        if (finished) animations.push(rise(next.querySelector('.card-body > p:not(.small)'), { delay: 200 }));
        return animations;
    }

    function provider(previous, next) {
        const card = next.querySelector('.ia-company');
        if (!card) return [];
        const animations = [rise(card.querySelector('.card-body'), { distance: 4, duration: 220 })];
        const before = previous.querySelector?.('.ia-company')?.getAttribute('data-analysis-state');
        if (before !== card.getAttribute('data-analysis-state')) {
            const color = view.getComputedStyle(card).getPropertyValue('--ia-state').trim() || 'currentColor';
            animations.push(card.animate(
                [{ boxShadow: `0 0 0 1px ${color}` }, { boxShadow: '0 0 0 1px transparent' }],
                { duration: 1200, easing: 'ease-out' },
            ));
        }
        return animations;
    }

    function comparison(next) {
        const columns = Array.from(next.querySelectorAll('.row > [style*="--ia-i"]'));
        const animations = columns.map((column, index) => rise(column, { distance: 8, duration: 280, delay: index * 50 }));
        const best = next.querySelector('.ia-offer--best');
        if (best && canAnimatePseudo) {
            animations.push(best.animate(
                [{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }],
                { pseudoElement: '::before', duration: 560, delay: 200, easing: EASE_DRAW, fill: 'backwards' },
            ));
        }
        return animations;
    }

    return {
        /** Encerra a sequência de entrada para que componentes trocados não a repitam. */
        intro() {
            if (!root.body?.hasAttribute('data-ia-intro')) return;
            view.setTimeout(() => root.body.removeAttribute('data-ia-intro'), INTRO_MS);
        },

        replaced(key, previous, next) {
            if (!enabled() || !next?.querySelector) return;
            if (key === 'batch') track(key, batch(previous, next));
            else if (key === 'comparison') track(key, comparison(next));
            else if (key.startsWith('provider-')) track(key, provider(previous, next));
        },
    };
}
