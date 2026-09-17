export function normalizeCompanySearch(value) {
    return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR').trim();
}

export async function sendLeadCompanyLink(url, companyId, csrfToken, signal) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        redirect: 'error',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ company_id: companyId }),
        signal,
    });
    const payload = await response.json().catch(() => null);

    if (response.status === 202 && Number.isInteger(payload?.request_id)) {
        return { accepted: true, requestId: payload.request_id };
    }

    const messages = {
        401: 'Sua sessão expirou. Atualize a página e entre novamente para continuar.',
        403: 'Você não tem permissão para vincular este lead. Atualize a página para conferir seu acesso.',
        419: 'Sua sessão expirou. Atualize a página antes de solicitar o vínculo.',
        404: 'Este lead não está mais disponível. Atualize a página para conferir os dados.',
        429: 'Muitas solicitações em sequência. Aguarde um momento antes de tentar novamente.',
    };
    const validationMessage = payload?.errors?.company_id?.[0];

    return {
        accepted: false,
        message: response.status === 422 && typeof validationMessage === 'string'
            ? validationMessage
            : messages[response.status] ?? 'Não foi possível confirmar a solicitação. Atualize o dashboard para verificar o vínculo antes de tentar novamente.',
    };
}

export function initializeLeadCompanyLink() {
    const modal = document.getElementById('adminLeadCompanyModal');
    if (!modal) return;

    const form = modal.querySelector('[data-lead-company-form]');
    const search = modal.querySelector('[data-link-search]');
    const fields = modal.querySelector('[data-link-fields]');
    const options = Array.from(modal.querySelectorAll('[data-link-option]')).map((element) => ({
        element,
        input: element.querySelector('input'),
        searchText: normalizeCompanySearch(element.textContent),
    }));
    const count = modal.querySelector('[data-link-count]');
    const empty = modal.querySelector('[data-link-no-results]');
    const selection = modal.querySelector('[data-link-selection]');
    const error = modal.querySelector('[data-link-error]');
    const confirmation = modal.querySelector('[data-link-confirmation]');
    const submit = modal.querySelector('[data-link-submit]');
    const submitLabel = modal.querySelector('[data-link-submit-label]');
    const cancel = modal.querySelector('[data-link-cancel]');
    const done = modal.querySelector('[data-link-done]');
    const dismissButtons = modal.querySelectorAll('[data-bs-dismiss="modal"]');
    const announcement = modal.querySelector('[data-link-announcement]');
    const title = modal.querySelector('#adminLeadCompanyModalTitle');
    const description = modal.querySelector('#adminLeadCompanyModalDescription');
    const linkDescription = description.textContent.trim();
    let trigger = null;
    let submitting = false;
    let currentCompanyId = '';

    function filterCompanies() {
        const terms = normalizeCompanySearch(search.value).split(/\s+/).filter(Boolean);
        let matches = 0;

        options.forEach((option) => {
            const visible = option.input.value !== currentCompanyId
                && terms.every((term) => option.searchText.includes(term));
            option.element.hidden = !visible;
            if (visible) matches++;
        });

        count.textContent = `${matches} ${matches === 1 ? 'imobiliária disponível' : 'imobiliárias disponíveis'}`;
        empty.hidden = matches > 0 || options.length === 0;
    }

    function showError(message) {
        error.textContent = message;
        error.hidden = false;
        error.focus();
    }

    function setSubmitting(value) {
        submitting = value;
        form.setAttribute('aria-busy', String(value));
        form.dataset.submitting = String(value);
        options.forEach(({ input }) => { input.disabled = value || input.value === currentCompanyId; });
        search.disabled = value;
        dismissButtons.forEach((button) => { button.disabled = value; });
        submit.disabled = value || !form.querySelector('input[name="company_id"]:checked');
        submitLabel.textContent = value ? 'Enviando solicitação…' : (currentCompanyId ? 'Solicitar substituição' : 'Solicitar vínculo');
        announcement.textContent = value ? 'Enviando solicitação de vínculo. Aguarde.' : '';
    }

    modal.addEventListener('show.bs.modal', (event) => {
        const source = event.relatedTarget;
        if (!source?.matches('[data-lead-company-trigger]') || source.disabled) {
            event.preventDefault();
            return;
        }

        trigger = source;
        currentCompanyId = source.dataset.currentCompanyId || '';
        title.textContent = currentCompanyId ? 'Substituir imobiliária' : 'Vincular imobiliária';
        description.textContent = currentCompanyId
            ? 'A imobiliária atual perderá o acesso a este lead e às suas análises, que passarão para a selecionada. A tag da imobiliária anterior será substituída; a origem, as demais tags e os resultados serão mantidos.'
            : linkDescription;
        form.reset();
        form.action = source.dataset.linkUrl;
        delete form.dataset.realtimeChanged;
        delete form.dataset.realtimeSubmitting;
        modal.querySelector('[data-link-lead-name]').textContent = source.dataset.leadName;
        modal.querySelector('[data-link-lead-profile]').textContent = source.dataset.leadProfile;
        confirmation.hidden = true;
        error.hidden = true;
        error.textContent = '';
        selection.hidden = true;
        fields.hidden = false;
        submit.hidden = false;
        cancel.hidden = false;
        done.hidden = true;
        setSubmitting(false);
        filterCompanies();
    });

    modal.addEventListener('shown.bs.modal', () => {
        (options.length > 0 ? search : cancel).focus();
    });

    modal.addEventListener('hide.bs.modal', (event) => {
        if (submitting) event.preventDefault();
    });

    modal.addEventListener('hidden.bs.modal', () => {
        form.reset();
        delete form.dataset.realtimeChanged;
        delete form.dataset.realtimeSubmitting;
        delete form.dataset.submitting;
        if (trigger?.disabled) {
            const card = trigger.closest('article');
            const focusTarget = card.querySelector('.admin-lead-action');
            focusTarget?.focus();
        }
    });

    search.addEventListener('input', filterCompanies);
    form.addEventListener('change', (event) => {
        if (!event.target.matches('input[name="company_id"]')) return;
        const companyName = event.target.dataset.companyName;
        modal.querySelector('[data-link-selected-name]').textContent = companyName;
        selection.hidden = false;
        submit.disabled = false;
        error.hidden = true;
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submitting || !confirmation.hidden || !trigger) return;
        const selected = form.querySelector('input[name="company_id"]:checked');
        if (!selected || selected.disabled || selected.value === currentCompanyId) {
            showError('Selecione a imobiliária que receberá o vínculo.');
            return;
        }

        const csrfToken = form.querySelector('input[name="_token"]')?.value;
        if (!csrfToken) {
            showError('Atualize a página para renovar sua sessão e tentar novamente.');
            return;
        }

        const companyName = selected.dataset.companyName;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 30000);
        error.hidden = true;
        setSubmitting(true);

        try {
            const result = await sendLeadCompanyLink(form.action, selected.value, csrfToken, controller.signal);
            if (!result.accepted) {
                showError(result.message);
                return;
            }

            trigger.disabled = true;
            trigger.querySelector('[data-lead-company-trigger-label]').textContent = currentCompanyId ? 'Substituição solicitada' : 'Vínculo solicitado';
            const status = trigger.parentElement.querySelector('[data-lead-company-status]');
            status.textContent = 'Aguardando processamento.';
            status.hidden = false;
            modal.querySelector('[data-link-confirmed-name]').textContent = companyName;
            fields.hidden = true;
            confirmation.hidden = false;
            submit.hidden = true;
            cancel.hidden = true;
            done.hidden = false;
            delete form.dataset.realtimeChanged;
            confirmation.focus();
        } catch {
            showError('A conexão foi interrompida e não foi possível confirmar o envio. Atualize o dashboard para verificar se o vínculo foi solicitado antes de tentar novamente.');
        } finally {
            window.clearTimeout(timeout);
            setSubmitting(false);
            delete form.dataset.realtimeSubmitting;
        }
    });
}
