import { createAnalysisRealtime } from './insurance-analysis-realtime';
import { createAnalysisComponents } from './insurance-analysis-components';

document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-analysis-dialog]');
    if (opener) document.getElementById(opener.dataset.analysisDialog)?.showModal();
    if (event.target.closest('[data-close-analysis-dialog]')) event.target.closest('dialog')?.close();
});

function initializeExpenseForms() {
    document.querySelectorAll('[data-expense-reanalysis]').forEach(form => {
        if (form.dataset.initialized === 'true') return;
        form.dataset.initialized = 'true';
        const updateTotal = () => {
            const cents = Array.from(form.querySelectorAll('[data-expense-value]'))
                .reduce((sum, input) => sum + Math.round((Number(input.value) || 0) * 100), 0);
            form.querySelector('[data-expense-total]').textContent = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100);
        };
        form.addEventListener('input', updateTotal);
        form.addEventListener('submit', (event) => {
            if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
            form.dataset.submitting = 'true';
            form.querySelector('[type="submit"]').disabled = true;
            form.querySelector('[type="submit"]').textContent = 'Solicitando cotações…';
        });
        updateTotal();
    });
}
initializeExpenseForms();
document.querySelector('dialog[data-open-on-load]')?.showModal();

async function startInsuranceAnalyses() {
    const initialState = window.insuranceAnalysisInitialState;
    if (!initialState) return;
    if (initialState.realtime.broadcasting.enabled && !window.Echo) {
        try {
            await import('./echo');
        } catch {
            // Periodic reads remain available if the WebSocket client cannot initialize.
        }
    }
    const components = createAnalysisComponents({ onUpdated: initializeExpenseForms });
    document.addEventListener('close', () => components.flush(), true);
    const controller = createAnalysisRealtime(initialState, {
        echo: window.Echo,
        onState: (state) => {
            window.dispatchEvent(new CustomEvent('insurance-analyses:updated', { detail: state }));
            if (!state) { window.location.reload(); return; }
            components.update(state.components);
        },
        onError: (reason) => window.dispatchEvent(new CustomEvent('insurance-analyses:error', { detail: { reason } })),
    });
    window.insuranceAnalysis = controller;
    delete window.insuranceAnalysisInitialState;
    controller.start();
    window.addEventListener('pagehide', (event) => {
        if (!event.persisted) controller.destroy();
    });
}

startInsuranceAnalyses();
