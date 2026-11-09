import { createAnalysisRealtime } from './insurance-analysis-realtime';
import { createAnalysisComponents } from './insurance-analysis-components';
import { createReanalysisModes } from './insurance-analysis-reanalysis';

const reanalysisModes = createReanalysisModes({
    onRequested: () => window.insuranceAnalysis?.refresh(),
});

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
            if (event.defaultPrevented) return;
            if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
            form.dataset.submitting = 'true';
            const submit = form.querySelector('[type="submit"]');
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            const spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm me-2';
            spinner.setAttribute('aria-hidden', 'true');
            submit.replaceChildren(spinner, submit.dataset.loadingLabel ?? 'Solicitando cotações…');
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
    const components = createAnalysisComponents({
        onUpdated: () => {
            initializeExpenseForms();
            reanalysisModes.apply();
        },
    });
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
