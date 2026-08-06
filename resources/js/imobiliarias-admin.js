const onlyNumbers = (value, limit) => String(value ?? '')
    .replace(/\D/g, '')
    .slice(0, limit);

const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const animateCounter = (counter) => {
    if (counter.dataset.counted === 'true') {
        return;
    }

    counter.dataset.counted = 'true';
    const target = Number.parseInt(counter.dataset.countUp ?? '0', 10);

    if (prefersReducedMotion || !Number.isFinite(target) || target <= 0) {
        counter.textContent = new Intl.NumberFormat('pt-BR').format(Math.max(target, 0));
        return;
    }

    const duration = Math.min(1100, 620 + (target * 12));
    const startedAt = performance.now();
    counter.textContent = '0';

    const update = (now) => {
        const progress = Math.min((now - startedAt) / duration, 1);
        const easedProgress = 1 - ((1 - progress) ** 3);
        const currentValue = Math.round(target * easedProgress);
        counter.textContent = new Intl.NumberFormat('pt-BR').format(currentValue);

        if (progress < 1) {
            window.requestAnimationFrame(update);
        }
    };

    window.requestAnimationFrame(update);
};

const revealElement = (element) => {
    element.classList.add('is-revealed');
    element.querySelectorAll('[data-count-up]').forEach(animateCounter);
};

const revealElements = document.querySelectorAll('.real-estate-admin [data-reveal]');

if (revealElements.length > 0 && !prefersReducedMotion && 'IntersectionObserver' in window) {
    document.documentElement.classList.add('company-motion-enabled');

    const revealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            revealElement(entry.target);
            observer.unobserve(entry.target);
        });
    }, {
        threshold: 0.12,
        rootMargin: '0px 0px -6% 0px',
    });

    revealElements.forEach((element) => revealObserver.observe(element));
} else {
    revealElements.forEach(revealElement);
}

if (!prefersReducedMotion) {
    document.querySelectorAll('.company-page-hero, .company-form-header').forEach((hero) => {
        hero.addEventListener('pointermove', (event) => {
            const bounds = hero.getBoundingClientRect();
            const relativeX = ((event.clientX - bounds.left) / bounds.width) - 0.5;
            const relativeY = ((event.clientY - bounds.top) / bounds.height) - 0.5;

            hero.style.setProperty('--hero-shift-x', `${relativeX * 14}px`);
            hero.style.setProperty('--hero-shift-y', `${relativeY * 10}px`);
            hero.style.setProperty('--hero-shift-x-reverse', `${relativeX * -9}px`);
            hero.style.setProperty('--hero-shift-y-reverse', `${relativeY * -6}px`);
        });

        hero.addEventListener('pointerleave', () => {
            hero.style.setProperty('--hero-shift-x', '0px');
            hero.style.setProperty('--hero-shift-y', '0px');
            hero.style.setProperty('--hero-shift-x-reverse', '0px');
            hero.style.setProperty('--hero-shift-y-reverse', '0px');
        });
    });
}

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

const formatPhone = (value) => {
    const numbers = onlyNumbers(value, 11);

    if (numbers.length <= 2) {
        return numbers.length > 0 ? `(${numbers}` : '';
    }

    const localNumber = numbers.slice(2);
    const prefixLength = numbers.length === 11 ? 5 : 4;
    const prefix = localNumber.slice(0, prefixLength);
    const suffix = localNumber.slice(prefixLength);

    return `(${numbers.slice(0, 2)}) ${prefix}${suffix ? `-${suffix}` : ''}`;
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

const setFormSubmitting = (form, button, submitting, submittingLabel, idleLabel) => {
    if (submitting) {
        form.setAttribute('aria-busy', 'true');
    } else {
        form.removeAttribute('aria-busy');
    }

    if (!button) {
        return;
    }

    button.disabled = submitting;

    const spinner = button.querySelector('[data-submit-spinner]');
    const label = button.querySelector('[data-submit-label]');

    if (spinner) {
        spinner.hidden = !submitting;
    }

    if (label) {
        label.textContent = submitting ? submittingLabel : idleLabel;
    }
};

const editModalElement = document.querySelector('[data-company-edit-modal]');

if (editModalElement) {
    const editForm = editModalElement.querySelector('[data-company-edit-form]');
    const editSubmitButton = editModalElement.querySelector('[data-company-edit-submit]');
    const editCompanyId = editModalElement.querySelector('[data-edit-company-id]');
    const editTitle = editModalElement.querySelector('[data-edit-company-title]');
    const editName = editModalElement.querySelector('#edit-company-name');
    const editEmail = editModalElement.querySelector('#edit-company-email');
    const editPhone = editModalElement.querySelector('[data-company-phone-input]');
    const editCnpj = editModalElement.querySelector('[data-company-cnpj-input]');
    const editCep = editModalElement.querySelector('[data-company-cep-input]');
    const editCity = editModalElement.querySelector('#edit-company-city');
    const editState = editModalElement.querySelector('#edit-company-state');
    const editStatus = editModalElement.querySelector('#edit-company-status');
    const validationSummary = editModalElement.querySelector('.company-modal-validation');

    const clearServerValidation = () => {
        validationSummary?.setAttribute('hidden', '');
        editForm?.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
    };

    const populateEditForm = (button, preserveSubmittedValues = false) => {
        if (!editForm || !button.dataset.companyId || !button.dataset.companyUpdateUrl) {
            return;
        }

        editForm.action = button.dataset.companyUpdateUrl;

        if (editCompanyId) {
            editCompanyId.value = button.dataset.companyId;
        }

        if (editTitle) {
            editTitle.textContent = button.dataset.companyName ?? '';
        }

        if (preserveSubmittedValues) {
            return;
        }

        clearServerValidation();

        if (editName) {
            editName.value = button.dataset.companyName ?? '';
        }

        if (editEmail) {
            editEmail.value = button.dataset.companyEmail ?? '';
        }

        if (editPhone) {
            editPhone.value = formatPhone(button.dataset.companyPhone);
        }

        if (editCnpj) {
            editCnpj.value = formatCnpj(button.dataset.companyCnpj);
        }

        if (editCep) {
            editCep.value = formatCep(button.dataset.companyCep);
        }

        if (editCity) {
            editCity.value = button.dataset.companyCity ?? '';
        }

        if (editState) {
            editState.value = button.dataset.companyState ?? '';
        }

        if (editStatus) {
            editStatus.value = button.dataset.companyStatus === '0' ? '0' : '1';
        }
    };

    const editButtons = [...document.querySelectorAll('[data-company-edit]')];

    editButtons.forEach((button) => {
        button.addEventListener('click', () => populateEditForm(button));
    });

    if (editPhone) {
        editPhone.value = formatPhone(editPhone.value);
        editPhone.addEventListener('input', () => {
            editPhone.value = formatPhone(editPhone.value);
        });
    }

    if (editCnpj) {
        editCnpj.value = formatCnpj(editCnpj.value);
        editCnpj.addEventListener('input', () => {
            editCnpj.value = formatCnpj(editCnpj.value);
        });
    }

    if (editCep) {
        editCep.value = formatCep(editCep.value);
        editCep.addEventListener('input', () => {
            editCep.value = formatCep(editCep.value);
        });
    }

    editState?.addEventListener('input', () => {
        editState.value = editState.value.replace(/[^a-z]/gi, '').slice(0, 2).toUpperCase();
    });

    editForm?.addEventListener('submit', (event) => {
        if (editForm.getAttribute('aria-busy') === 'true') {
            event.preventDefault();
            return;
        }

        setFormSubmitting(editForm, editSubmitButton, true, 'Salvando...', 'Salvar alterações');
    });

    editModalElement.addEventListener('shown.bs.modal', () => {
        const firstInvalidField = editModalElement.querySelector('.is-invalid');
        (firstInvalidField ?? editName)?.focus();
    });

    const reopenCompanyId = editModalElement.dataset.reopenCompanyId;
    const shouldPreserveInput = editModalElement.dataset.preserveInput === 'true';
    const reopenButton = editButtons.find((button) => button.dataset.companyId === reopenCompanyId);

    if (reopenButton && shouldPreserveInput && window.bootstrap?.Modal) {
        populateEditForm(reopenButton, true);
        window.bootstrap.Modal.getOrCreateInstance(editModalElement).show();
    }

    window.addEventListener('pageshow', () => {
        if (editForm) {
            setFormSubmitting(editForm, editSubmitButton, false, 'Salvando...', 'Salvar alterações');
        }
    });
}

const deleteModalElement = document.querySelector('[data-company-delete-modal]');

if (deleteModalElement) {
    const deleteForm = deleteModalElement.querySelector('[data-company-delete-form]');
    const deleteSubmitButton = deleteModalElement.querySelector('[data-company-delete-submit]');
    const deleteCompanyName = deleteModalElement.querySelector('[data-delete-company-name]');

    document.querySelectorAll('[data-company-delete]').forEach((button) => {
        button.addEventListener('click', () => {
            if (deleteForm && button.dataset.companyDeleteUrl) {
                deleteForm.action = button.dataset.companyDeleteUrl;
            }

            if (deleteCompanyName) {
                deleteCompanyName.textContent = button.dataset.companyName ?? '';
            }
        });
    });

    deleteForm?.addEventListener('submit', (event) => {
        if (deleteForm.getAttribute('aria-busy') === 'true') {
            event.preventDefault();
            return;
        }

        setFormSubmitting(deleteForm, deleteSubmitButton, true, 'Removendo...', 'Sim, remover');
    });

    deleteModalElement.addEventListener('shown.bs.modal', () => deleteSubmitButton?.focus());

    window.addEventListener('pageshow', () => {
        if (deleteForm) {
            setFormSubmitting(deleteForm, deleteSubmitButton, false, 'Removendo...', 'Sim, remover');
        }
    });
}

const registrationForm = document.querySelector('[data-company-registration-form]');

if (registrationForm) {
    const cnpjInput = registrationForm.querySelector('#cnpj');
    const phoneInput = registrationForm.querySelector('#phone');
    const cepInput = registrationForm.querySelector('#cep');
    const cityInput = registrationForm.querySelector('#city');
    const stateInput = registrationForm.querySelector('#state');
    const cepFeedback = registrationForm.querySelector('#cep-feedback');
    const endpointTemplate = registrationForm.dataset.cepEndpoint;
    const submitButton = registrationForm.querySelector('[data-company-submit]');
    const submitLabel = submitButton?.querySelector('[data-submit-label]');
    const submitSpinner = submitButton?.querySelector('[data-submit-spinner]');
    const statusInput = registrationForm.querySelector('#lead_form_active');
    const statusState = registrationForm.querySelector('[data-status-state]');
    let lastResolvedCep = null;
    let lookupSequence = 0;

    const updateStatusState = () => {
        if (!statusInput || !statusState) {
            return;
        }

        const isActive = statusInput.checked;
        statusState.textContent = isActive ? 'Ativo' : 'Inativo';
        statusState.classList.toggle('is-active', isActive);
        statusState.classList.toggle('is-inactive', !isActive);
    };

    statusInput?.addEventListener('change', updateStatusState);
    updateStatusState();

    registrationForm.querySelectorAll('.company-form-section').forEach((section) => {
        section.addEventListener('focusin', () => section.classList.add('is-active'));
        section.addEventListener('focusout', () => {
            window.setTimeout(() => {
                if (!section.contains(document.activeElement)) {
                    section.classList.remove('is-active');
                }
            }, 0);
        });
    });

    if (cnpjInput) {
        cnpjInput.value = formatCnpj(cnpjInput.value);
        cnpjInput.addEventListener('input', () => {
            cnpjInput.value = formatCnpj(cnpjInput.value);
        });
    }

    if (phoneInput) {
        phoneInput.value = formatPhone(phoneInput.value);
        phoneInput.addEventListener('input', () => {
            phoneInput.value = formatPhone(phoneInput.value);
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
