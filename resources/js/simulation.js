const initializeProfileChoice = () => {
    const form = document.querySelector('[data-simulation-start] #profileChoiceForm');

    if (!form) {
        return;
    }

    const status = form.querySelector('#profileChoiceStatus');
    const submit = form.querySelector('#profileChoiceSubmit');
    const submitLabel = submit?.querySelector('span');
    const profileInputs = Array.from(
        form.querySelectorAll('input[name="tipo_solicitante"]'),
    );

    if (!status || !submit || profileInputs.length === 0) {
        return;
    }

    const updateSelectedProfile = (selectedInput) => {
        const optionTitle = selectedInput
            ?.closest('.simulation-option')
            ?.querySelector('.simulation-option__copy strong')
            ?.textContent
            ?.trim();

        submit.classList.toggle('is-ready', Boolean(selectedInput));

        status.textContent = optionTitle
            ? `${optionTitle} selecionado. Você poderá revisar os dados antes de enviar.`
            : 'Você poderá revisar os dados antes de enviar.';
    };

    profileInputs.forEach((input) => {
        input.addEventListener('change', () => updateSelectedProfile(input));
    });

    updateSelectedProfile(
        profileInputs.find((input) => input.checked),
    );

    form.addEventListener('submit', () => {
        if (!form.checkValidity()) {
            return;
        }

        submit.disabled = true;
        submit.setAttribute('aria-busy', 'true');

        if (submitLabel) {
            submitLabel.textContent = 'Continuando…';
        }
    });
};

const initializeConditionalFields = (form) => {
    const documentInput = form.querySelector('[name="cpf"]');
    const maritalStatus = form.querySelector('[name="estado_civil"]');
    const rentalTypes = form.querySelectorAll('[name="tipo_locacao"]');

    if (!documentInput || !maritalStatus) {
        return;
    }

    form.querySelectorAll('[data-simulation-template]').forEach((template) => {
        template.content.querySelectorAll('input').forEach((input) => {
            input.value = '';
        });
    });

    const renderFields = (condition, visible) => {
        const target = form.querySelector(`[data-simulation-fields="${condition}"]`);
        const template = form.querySelector(`[data-simulation-template="${condition}"]`);

        if (!target || !template) {
            return;
        }

        if (!visible) {
            target.replaceChildren();
        } else if (target.childElementCount === 0) {
            target.append(template.content.cloneNode(true));
        }
    };

    const updateDocument = () => {
        const normalized = documentInput.value.replace(/[.\/\s-]+/g, '');
        const isCompany = /^[0-9]{14}$/.test(normalized);

        renderFields('company', isCompany);
        documentInput.setAttribute('aria-expanded', String(isCompany));
    };

    const updateSpouse = () => {
        const status = maritalStatus.value;
        const visible = ['casado', 'uniao_estavel', 'divorciado', 'viuvo'].includes(status);
        const required = ['casado', 'uniao_estavel'].includes(status);

        renderFields('spouse', visible);
        form.querySelectorAll('[name="conjuge_nome"], [name="conjuge_cpf"]').forEach((input) => {
            input.required = required;
        });
    };

    const updateRentalType = () => {
        renderFields('commercial', form.querySelector('[name="tipo_locacao"]:checked')?.value === 'comercial');
    };

    documentInput.addEventListener('input', updateDocument);
    documentInput.addEventListener('change', updateDocument);
    maritalStatus.addEventListener('change', updateSpouse);
    rentalTypes.forEach((input) => input.addEventListener('change', updateRentalType));

    const sync = () => {
        updateDocument();
        updateSpouse();
        updateRentalType();
    };

    window.addEventListener('pageshow', sync);
    sync();
};

const initializeSimulation = () => {
    initializeProfileChoice();
    document.querySelectorAll('form.simulation-form').forEach(initializeConditionalFields);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSimulation, { once: true });
} else {
    initializeSimulation();
}
