const onlyNumbers = (value, limit) => String(value ?? '')
    .replace(/\D/g, '')
    .slice(0, limit);

const formatCnpj = (value) => {
    const numbers = onlyNumbers(value, 14);

    return numbers
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\d{4})(\d)/, '$1-$2');
};

const formatCep = (value) => {
    const numbers = onlyNumbers(value, 8);

    return numbers.length > 5
        ? `${numbers.slice(0, 5)}-${numbers.slice(5)}`
        : numbers;
};

async function copyToClipboard(value) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();

    const copied = document.execCommand('copy');
    textarea.remove();

    if (!copied) {
        throw new Error('Copy command failed');
    }
}

document.querySelectorAll('[data-copy-code]').forEach((button) => {
    button.addEventListener('click', async () => {
        const code = button.dataset.copyCode;
        const feedback = document.getElementById(button.dataset.copyFeedback);
        const label = button.querySelector('[data-copy-label]');
        const icon = button.querySelector('i');

        if (!code) {
            return;
        }

        try {
            await copyToClipboard(code);
            button.classList.add('is-copied');
            button.setAttribute('title', 'Código copiado');

            if (icon) {
                icon.className = 'bi bi-check-lg';
            }

            if (label) {
                label.textContent = 'Copiado';
            }

            if (feedback) {
                feedback.textContent = `Código ${code} copiado.`;
            }
        } catch (_error) {
            if (feedback) {
                feedback.textContent = 'Não foi possível copiar o código. Selecione-o manualmente.';
            }
        }

        window.setTimeout(() => {
            button.classList.remove('is-copied');
            button.setAttribute('title', 'Copiar código');

            if (icon) {
                icon.className = 'bi bi-copy';
            }

            if (label) {
                label.textContent = 'Copiar';
            }
        }, 2200);
    });
});

const registrationForm = document.querySelector('[data-company-registration-form]');

if (registrationForm) {
    const cnpjInput = registrationForm.querySelector('#cnpj');
    const cepInput = registrationForm.querySelector('#cep');
    const cityInput = registrationForm.querySelector('#city');
    const stateInput = registrationForm.querySelector('#state');
    const cepFeedback = registrationForm.querySelector('#cep-feedback');
    const endpointTemplate = registrationForm.dataset.cepEndpoint;
    const submitButton = registrationForm.querySelector('[data-company-submit]');
    const submitLabel = submitButton?.querySelector('[data-submit-label]');
    const submitSpinner = submitButton?.querySelector('[data-submit-spinner]');
    let lastResolvedCep = null;
    let lookupSequence = 0;

    if (cnpjInput) {
        cnpjInput.value = formatCnpj(cnpjInput.value);
        cnpjInput.addEventListener('input', () => {
            cnpjInput.value = formatCnpj(cnpjInput.value);
        });
    }

    const setCepFeedback = (message, state = 'neutral') => {
        if (!cepFeedback) {
            return;
        }

        cepFeedback.textContent = message;
        cepFeedback.classList.toggle('text-danger', state === 'error');
        cepFeedback.classList.toggle('text-success', state === 'success');
    };

    const clearAddress = () => {
        if (cityInput) {
            cityInput.value = '';
        }

        if (stateInput) {
            stateInput.value = '';
        }
    };

    const resolveCep = async () => {
        if (!cepInput || !cityInput || !stateInput || !endpointTemplate) {
            return;
        }

        const cep = onlyNumbers(cepInput.value, 8);

        if (cep.length !== 8) {
            lastResolvedCep = null;
            clearAddress();

            if (cep.length > 0) {
                cepInput.classList.add('is-invalid');
                setCepFeedback('Informe um CEP com 8 números.', 'error');
            } else {
                setCepFeedback('Digite os 8 números do CEP.');
            }

            return;
        }

        if (cep === lastResolvedCep && cityInput.value && stateInput.value) {
            return;
        }

        const currentSequence = ++lookupSequence;
        cepInput.classList.remove('is-invalid');
        cepInput.setAttribute('aria-busy', 'true');
        cityInput.value = 'Buscando...';
        stateInput.value = '...';
        setCepFeedback('Consultando CEP...');

        try {
            const endpoint = endpointTemplate.replace('00000000', cep);
            const response = await fetch(endpoint, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const result = await response.json();

            if (currentSequence !== lookupSequence) {
                return;
            }

            if (!response.ok || !result.success) {
                throw new Error(result.message ?? 'CEP não encontrado.');
            }

            const city = String(result.data?.cidade ?? '').trim();
            const state = String(result.data?.estado ?? '').trim().toUpperCase();

            if (!city || !state) {
                throw new Error('A consulta não retornou cidade e UF.');
            }

            cityInput.value = city;
            stateInput.value = state;
            lastResolvedCep = cep;
            setCepFeedback('Cidade e UF preenchidas automaticamente.', 'success');
        } catch (error) {
            if (currentSequence !== lookupSequence) {
                return;
            }

            clearAddress();
            lastResolvedCep = null;
            cepInput.classList.add('is-invalid');
            setCepFeedback(error.message ?? 'Não foi possível consultar o CEP.', 'error');
        } finally {
            if (currentSequence === lookupSequence) {
                cepInput.removeAttribute('aria-busy');
            }
        }
    };

    if (cepInput) {
        cepInput.value = formatCep(cepInput.value);

        if (onlyNumbers(cepInput.value, 8).length === 8 && cityInput?.value && stateInput?.value) {
            lastResolvedCep = onlyNumbers(cepInput.value, 8);
        }

        cepInput.addEventListener('input', () => {
            cepInput.value = formatCep(cepInput.value);
            const currentCep = onlyNumbers(cepInput.value, 8);

            if (currentCep !== lastResolvedCep) {
                lookupSequence += 1;
                lastResolvedCep = null;
                clearAddress();
                cepInput.classList.remove('is-invalid');
                setCepFeedback('Digite os 8 números do CEP.');
            }

            if (currentCep.length === 8) {
                resolveCep();
            }
        });

        cepInput.addEventListener('blur', resolveCep);

        if (onlyNumbers(cepInput.value, 8).length === 8 && (!cityInput?.value || !stateInput?.value)) {
            resolveCep();
        }
    }

    const resetSubmitState = () => {
        registrationForm.removeAttribute('aria-busy');

        if (submitButton) {
            submitButton.disabled = false;
        }

        if (submitSpinner) {
            submitSpinner.hidden = true;
        }

        if (submitLabel) {
            submitLabel.textContent = 'Cadastrar imobiliária';
        }
    };

    registrationForm.addEventListener('submit', () => {
        registrationForm.setAttribute('aria-busy', 'true');

        if (submitButton) {
            submitButton.disabled = true;
        }

        if (submitSpinner) {
            submitSpinner.hidden = false;
        }

        if (submitLabel) {
            submitLabel.textContent = 'Cadastrando...';
        }
    });

    window.addEventListener('pageshow', resetSubmitState);
}
