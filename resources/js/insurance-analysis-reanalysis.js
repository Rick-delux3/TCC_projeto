/**
 * Reanálise por companhia: o diálogo salva as despesas (sem iniciar cotações) e cada
 * card reanalisa somente a sua companhia. Não existe reanálise geral do lead.
 * Toda a interação usa atributos data-*, permitindo que um frontend futuro reaproveite
 * o mesmo contrato (endpoint JSON + eventos) sem depender da marcação Bootstrap.
 */
export function createReanalysisModes({
    root = document,
    fetchJson = window.fetch.bind(window),
    onRequested = () => {},
} = {}) {
    const mode = 'separate';
    let expenses = null;
    let saving = false;
    const pending = new Set();

    const container = () => root.querySelector('[data-reanalysis-root]');
    const dialog = () => root.getElementById('expense-reanalysis');
    const form = () => root.querySelector('[data-expense-reanalysis]');
    const csrfToken = () => root.querySelector('meta[name="csrf-token"]')?.content ?? '';

    /** Mantido por compatibilidade: só existe a reanálise por companhia. */
    function setMode() {
        apply();
    }

    function apply() {
        container()?.setAttribute('data-reanalysis-mode', mode);
        const expenseForm = form();
        if (expenseForm) {
            const submit = expenseForm.querySelector('[type="submit"]');
            submit.textContent = saving ? 'Salvando despesas…' : 'Salvar despesas';
            submit.disabled = saving;
            submit.setAttribute('aria-busy', saving ? 'true' : 'false');
            if (expenses && !dialog()?.open) restoreExpenses();
        }
        root.querySelectorAll('[data-provider-reanalysis]').forEach(button => {
            setLoading(button, pending.has(button.dataset.providerReanalysis));
        });
    }

    function setLoading(button, loading) {
        const unavailable = !button.dataset.url;
        button.disabled = loading || unavailable;
        button.setAttribute('aria-busy', loading ? 'true' : 'false');
        button.querySelector('[data-loading-indicator]')?.classList.toggle('d-none', !loading);
        const label = button.querySelector('[data-button-label]');
        if (label) label.textContent = loading ? 'Reanalisando…' : 'Reanalisar';
    }

    function readExpenses(expenseForm) {
        return Object.fromEntries(Array.from(expenseForm.querySelectorAll('[data-expense-value]'))
            .map(input => [input.name, input.value]));
    }

    function restoreExpenses() {
        if (!expenses) return;
        form()?.querySelectorAll('[data-expense-value]').forEach(input => {
            input.value = expenses[input.name];
        });
        form()?.dispatchEvent(new Event('input', { bubbles: true }));
    }

    async function saveExpenses(expenseForm) {
        if (saving || !expenseForm.reportValidity()) return;
        const url = expenseForm.dataset.saveUrl;
        if (!url) {
            showModeError('Não foi possível salvar as despesas. Atualize a página.');
            return;
        }
        saving = true;
        showModeError('');
        const values = readExpenses(expenseForm);
        const revision = expenseForm.querySelector('[name="revision"]');
        apply();
        try {
            const response = await fetchJson(url, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ ...values, revision: revision.value }),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok || !body.expenses || !body.revision) {
                showModeError(firstError(body) ?? 'Não foi possível salvar as despesas. Tente novamente.');
                return;
            }
            expenses = body.expenses;
            revision.value = body.revision;
            dialog()?.close();
            notify(body.message, 'success');
            await onRequested();
        } catch {
            showModeError('Falha de conexão ao salvar as despesas. Tente novamente.');
        } finally {
            saving = false;
            apply();
        }
    }

    function showModeError(message) {
        const alert = root.querySelector('[data-reanalysis-mode-error]');
        if (!alert) return;
        alert.textContent = message;
        alert.classList.toggle('d-none', !message);
    }

    function notify(message, variant) {
        const region = root.querySelector('[data-reanalysis-feedback]');
        if (!region) return;
        const alert = root.createElement('div');
        alert.className = `alert alert-${variant} alert-dismissible shadow-sm mb-0`;
        alert.setAttribute('role', variant === 'danger' ? 'alert' : 'status');
        alert.textContent = message;
        const close = root.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('aria-label', 'Fechar');
        close.addEventListener('click', () => alert.remove());
        alert.append(close);
        region.append(alert);
        setTimeout(() => alert.remove(), 8000);
    }

    function firstError(body) {
        if (body?.errors) return Object.values(body.errors).flat()[0];
        return body?.message;
    }

    async function reanalyze(button) {
        const provider = button.dataset.providerReanalysis;
        if (!button.dataset.url || pending.has(provider)) return;
        if (!expenses) {
            dialog()?.showModal();
            return;
        }
        pending.add(provider);
        setLoading(button, true);
        try {
            const response = await fetchJson(button.dataset.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(expenses),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                notify(`${button.dataset.providerName}: ${firstError(body) ?? 'Não foi possível solicitar a reanálise.'}`, 'danger');
                return;
            }
            notify(`${button.dataset.providerName}: ${body.message ?? 'Nova cotação solicitada.'}`, 'success');
            root.dispatchEvent(new CustomEvent('insurance-analyses:reanalysis-requested', { detail: { provider, ...body } }));
            await onRequested(provider);
        } catch {
            notify(`${button.dataset.providerName}: falha de conexão. Tente novamente.`, 'danger');
        } finally {
            pending.delete(provider);
            apply();
        }
    }

    root.addEventListener('click', event => {
        if (saving && event.target.closest('[data-close-analysis-dialog]')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        const providerButton = event.target.closest('[data-provider-reanalysis]');
        if (providerButton) reanalyze(providerButton);
    });

    root.addEventListener('close', event => {
        if (event.target !== dialog()) return;
        restoreExpenses();
    }, true);

    root.addEventListener('cancel', event => {
        if (saving && event.target === dialog()) event.preventDefault();
    }, true);

    // O diálogo só salva as despesas; a cotação é pedida no card de cada companhia.
    root.addEventListener('submit', event => {
        if (event.target !== form()) return;
        event.preventDefault();
        saveExpenses(event.target);
    }, true);

    apply();

    return { apply, setMode, get mode() { return mode; } };
}
