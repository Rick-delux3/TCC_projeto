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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeProfileChoice, { once: true });
} else {
    initializeProfileChoice();
}
