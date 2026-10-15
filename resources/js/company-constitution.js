export const initializeCompanyConstitution = (form, renderFields) => {
    const documentInput = form.querySelector('[name="cpf"]');
    const wizard = form.closest('.simulation-wizard');
    const dialog = wizard.querySelector('[data-company-constitution-modal]');
    const message = dialog.querySelector('[data-company-constitution-message]').textContent.trim();
    const field = () => form.querySelector('[name="empresa_constituida"]');
    const normalizedDocument = () => documentInput.value.replace(/\D/g, '');
    let previousDocument = normalizedDocument();
    let correctingDocument = false;

    const updateValidity = (clearError = false) => {
        const select = field();
        if (!select) {
            return;
        }

        const rejected = select.value === 'N';
        select.setCustomValidity(rejected ? message : '');
        const error = form.querySelector('#empresa_constituida-error');
        if (rejected || clearError) {
            select.setAttribute('aria-invalid', String(rejected));
            error.textContent = rejected ? message : '';
            error.hidden = !rejected;
        }
    };

    const showRejection = () => {
        if (!dialog.open) {
            correctingDocument = false;
            dialog.showModal();
        }
    };

    form.addEventListener('change', (event) => {
        if (event.target !== field()) {
            return;
        }

        updateValidity(true);
        if (field().value === 'N') {
            showRejection();
        }
    });

    form.addEventListener('submit', (event) => {
        if (field()?.value === 'N') {
            event.preventDefault();
            event.stopImmediatePropagation();
            updateValidity();
            showRejection();
        }
    }, true);

    dialog.querySelector('[data-company-constitution-correct]').addEventListener('click', () => {
        correctingDocument = true;
        dialog.close();
    });
    dialog.querySelector('[data-company-constitution-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        if (correctingDocument) {
            wizard.querySelector('[data-step-link="1"]').click();
            documentInput.scrollIntoView({ block: 'center', behavior: 'instant' });
            documentInput.focus({ preventScroll: true });
        } else {
            field()?.focus();
        }
        correctingDocument = false;
    });

    return () => {
        const document = normalizedDocument();
        const visible = document.length === 14
            && form.querySelector('[name="tipo_locacao"]:checked')?.value === 'comercial';

        if (document !== previousDocument) {
            renderFields('company-constitution', false);
        }
        previousDocument = document;
        const newlyRendered = !field();
        renderFields('company-constitution', visible);
        if (!visible && dialog.open) {
            dialog.close();
        }
        updateValidity(newlyRendered);
    };
};
