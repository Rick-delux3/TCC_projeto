import { createAnalysisRealtime } from './insurance-analysis-realtime';

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
    const controller = createAnalysisRealtime(initialState, {
        echo: window.Echo,
        onState: (state) => window.dispatchEvent(new CustomEvent('insurance-analyses:updated', { detail: state })),
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
