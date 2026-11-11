@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const wizard = document.querySelector('[data-simulation-wizard]');
    if (!wizard) return;
    const form = wizard.querySelector('[data-simulation-form]');
    const panels = [...form.querySelectorAll('[data-form-step]')];
    let currentStep = Number(wizard.dataset.initialStep) || 1;

    const showStep = (step, focus = true) => {
        currentStep = step;
        wizard.dataset.activeStep = String(step);
        wizard.querySelectorAll('[data-step-link]').forEach(button => {
            if (Number(button.dataset.stepLink) === step) button.setAttribute('aria-current', 'step');
            else button.removeAttribute('aria-current');
            button.classList.toggle('is-complete', button.dataset.stepLink === '1' && step === 2);
        });
        wizard.querySelector('[data-step-number]').hidden = step === 2;
        wizard.querySelector('[data-step-check]').hidden = step !== 2;
        wizard.querySelectorAll('[data-step-help]').forEach(help => { help.hidden = Number(help.dataset.stepHelp) !== step; });
        if (focus) {
            const title = panels[step - 1].querySelector('[tabindex="-1"]');
            title.focus({ preventScroll: true });
            wizard.querySelector('.simulation-form-card').scrollIntoView({ block: 'start', behavior: 'instant' });
        }
    };
    const validateStep = (step) => {
        const invalid = [...panels[step - 1].querySelectorAll('input, select, textarea')].find(input => !input.disabled && !input.checkValidity());
        if (!invalid) return true;
        showStep(step, false);
        invalid.reportValidity();
        invalid.focus();
        return false;
    };
    let checking = false;
    const next = async () => {
        if (checking) return;
        checking = true;
        const button = wizard.querySelector('[data-step-next]');
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        try {
            if (form.confirmCpfData && !await form.confirmCpfData()) return;
            if (validateStep(1)) showStep(2);
        } finally {
            checking = false;
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    };
    form.addEventListener('simulation-focus-field', event => {
        const input = form.elements.namedItem(event.detail);
        const panel = input?.closest('[data-form-step]');
        if (panel) { showStep(Number(panel.dataset.formStep), false); input.focus(); }
    });
    wizard.querySelector('[data-step-next]').addEventListener('click', next);
    wizard.querySelector('[data-step-back]').addEventListener('click', () => showStep(1));
    wizard.querySelectorAll('[data-step-link]').forEach(button => {
        button.addEventListener('click', () => Number(button.dataset.stepLink) === 1 ? showStep(1) : next());
    });
    wizard.querySelectorAll('[data-error-field]').forEach(button => {
        button.addEventListener('click', () => {
            const field = form.elements.namedItem(button.dataset.errorField);
            const input = button.dataset.errorField === 'atividade_economica_id'
                ? form.querySelector('[data-activity-search]')
                : (field instanceof RadioNodeList ? field[0] : field);
            const panel = input?.closest('[data-form-step]');
            if (panel) {
                showStep(Number(panel.dataset.formStep), false);
                input.focus();
            }
        });
    });
    form.noValidate = true;
    form.addEventListener('submit', event => {
        if (currentStep === 1) {
            event.preventDefault();
            next();
            return;
        }
        if (!validateStep(1) || !validateStep(2)) {
            event.preventDefault();
            return;
        }
        const submit = form.querySelector('[data-simulation-submit]');
        submit.disabled = true;
        submit.textContent = 'Enviando solicitação…';
        submit.setAttribute('aria-busy', 'true');
    });
    window.addEventListener('pageshow', () => {
        const submit = form.querySelector('[data-simulation-submit]');
        submit.disabled = false;
        submit.textContent = 'Enviar solicitação';
        submit.removeAttribute('aria-busy');
    });
    wizard.classList.add('is-enhanced');
    showStep(currentStep, false);
});
</script>
@endpush
