export function initializeCompanyPasswordModal(editModal, editButtons) {
    const modal = document.querySelector('[data-company-password-modal]');
    const form = modal?.querySelector('[data-company-password-form]');
    const openButton = editModal.querySelector('[data-company-password-open]');
    if (!modal || !form || !openButton) return () => {};

    const password = form.elements.namedItem('password');
    const confirmation = form.elements.namedItem('password_confirmation');
    const submit = form.querySelector('[data-company-password-submit]');
    const feedback = form.querySelector('[data-password-feedback]');
    const status = editModal.querySelector('[data-company-password-status]');
    const success = editModal.querySelector('[data-company-password-success]');
    const dismissButtons = [...modal.querySelectorAll('[data-password-dismiss]')];
    const toggles = [...modal.querySelectorAll('[data-company-password-toggle]')];
    let selectedButton = null;
    let busy = false;
    let returnToEdit = false;

    const clearErrors = () => {
        feedback.hidden = true;
        feedback.textContent = '';
        [password, confirmation].forEach((input) => {
            input.classList.remove('is-invalid');
            input.removeAttribute('aria-invalid');
            input.setCustomValidity('');
            const error = form.querySelector(`[data-password-error="${input.name}"]`);
            error.textContent = '';
            error.hidden = true;
        });
    };

    const resetSecrets = () => {
        form.reset();
        [password, confirmation].forEach((input) => { input.value = ''; input.type = 'password'; });
        toggles.forEach((button) => {
            button.setAttribute('aria-pressed', 'false');
            button.setAttribute('aria-label', button.dataset.showLabel);
            button.querySelector('i').className = 'bi bi-eye';
        });
        clearErrors();
    };

    const setBusy = (value) => {
        busy = value;
        form.setAttribute('aria-busy', String(value));
        submit.disabled = value;
        submit.querySelector('[data-submit-spinner]').hidden = !value;
        submit.querySelector('[data-submit-label]').textContent = value ? 'Salvando...' : 'Concluído';
        [password, confirmation].forEach((input) => { input.readOnly = value; });
        [...dismissButtons, ...toggles].forEach((button) => { button.disabled = value; });
    };

    const showError = (message) => {
        feedback.textContent = message;
        feedback.hidden = false;
        feedback.focus();
    };

    toggles.forEach((button) => {
        button.dataset.showLabel = button.getAttribute('aria-label');
        button.addEventListener('click', () => {
            const input = form.querySelector(`#${button.dataset.companyPasswordToggle}`);
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(visible));
            button.setAttribute('aria-label', visible ? button.dataset.showLabel.replace('Mostrar', 'Ocultar') : button.dataset.showLabel);
            button.querySelector('i').className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    });

    [password, confirmation].forEach((input) => input.addEventListener('input', () => {
        input.setCustomValidity('');
        confirmation.setCustomValidity('');
        input.classList.remove('is-invalid');
        input.removeAttribute('aria-invalid');
        form.querySelector(`[data-password-error="${input.name}"]`).hidden = true;
    }));

    openButton.addEventListener('click', () => {
        if (!selectedButton || !window.bootstrap?.Modal || busy) return;
        resetSecrets();
        setBusy(false);
        success.hidden = true;
        form.action = selectedButton.dataset.companyPasswordUrl;
        modal.querySelector('[data-password-company-name]').textContent = selectedButton.dataset.companyName;
        returnToEdit = true;
        editModal.addEventListener('hidden.bs.modal', () => {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }, { once: true });
        window.bootstrap.Modal.getOrCreateInstance(editModal).hide();
    });

    editModal.addEventListener('show.bs.modal', () => { openButton.disabled = true; });
    editModal.addEventListener('shown.bs.modal', () => {
        openButton.disabled = !selectedButton?.dataset.companyPasswordUrl;
    });

    modal.addEventListener('shown.bs.modal', () => password.focus());
    modal.addEventListener('hide.bs.modal', (event) => {
        if (busy) {
            event.preventDefault();
        } else {
            resetSecrets();
        }
    });
    modal.addEventListener('hidden.bs.modal', () => {
        resetSecrets();
        if (returnToEdit) {
            returnToEdit = false;
            editModal.addEventListener('shown.bs.modal', () => openButton.focus(), { once: true });
            window.bootstrap.Modal.getOrCreateInstance(editModal).show();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || !selectedButton) return;
        clearErrors();
        if (confirmation.value !== password.value) {
            confirmation.setCustomValidity('A confirmação da nova senha não confere.');
        }
        if (!form.reportValidity()) return;

        const url = new URL(selectedButton.dataset.companyPasswordUrl, window.location.href);
        const csrf = form.elements.namedItem('_token')?.value;
        if (url.origin !== window.location.origin || !csrf) {
            showError('Não foi possível preparar a alteração. Recarregue a página e tente novamente.');
            return;
        }

        setBusy(true);
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 30000);
        try {
            const response = await fetch(url.href, {
                method: 'PUT',
                credentials: 'same-origin',
                redirect: 'error',
                cache: 'no-store',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ password: password.value, password_confirmation: confirmation.value }),
            });
            const result = await response.json().catch(() => null);

            if (response.status === 422) {
                [password, confirmation].forEach((input) => {
                    const message = result?.errors?.[input.name]?.[0];
                    if (typeof message !== 'string') return;
                    input.classList.add('is-invalid');
                    input.setAttribute('aria-invalid', 'true');
                    const error = form.querySelector(`[data-password-error="${input.name}"]`);
                    error.textContent = message;
                    error.hidden = false;
                });
                showError('Revise os campos destacados e tente novamente.');
                return;
            }

            if (!response.ok || result?.has_password !== true || String(result?.company_id) !== selectedButton.dataset.companyId) {
                const messages = {
                    401: 'Sua sessão expirou. Entre novamente para alterar a senha.',
                    403: 'Você não tem permissão para alterar esta senha.',
                    419: 'Sua sessão expirou. Recarregue a página antes de tentar novamente.',
                    429: 'Muitas tentativas. Aguarde um minuto antes de tentar novamente.',
                };
                showError(messages[response.status] ?? (response.status === 409 && typeof result?.message === 'string'
                    ? result.message : 'Não foi possível confirmar a alteração da senha. Recarregue a página antes de tentar novamente.'));
                return;
            }

            editButtons.filter((button) => button.dataset.companyId === selectedButton.dataset.companyId)
                .forEach((button) => { button.dataset.companyHasPassword = 'true'; });
            status.value = '••••••••';
            success.hidden = false;
            setBusy(false);
            resetSecrets();
            window.bootstrap.Modal.getOrCreateInstance(modal).hide();
        } catch {
            showError('Não foi possível confirmar a alteração. Verifique a conexão e recarregue a página antes de tentar novamente.');
        } finally {
            window.clearTimeout(timeout);
            setBusy(false);
        }
    });

    window.addEventListener('pagehide', resetSecrets);
    window.addEventListener('pageshow', () => { resetSecrets(); setBusy(false); });

    return (button) => {
        selectedButton = button;
        status.value = button.dataset.companyHasPassword === 'true' ? '••••••••' : 'Senha indisponível ou inexistente';
        openButton.disabled = !button.dataset.companyPasswordUrl;
        success.hidden = true;
    };
}
