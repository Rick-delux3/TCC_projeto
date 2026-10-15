export const initializeActivityPicker = (container) => {
    const picker = container.querySelector('[data-activity-picker]');
    if (!picker || picker.dataset.initialized) return;
    picker.dataset.initialized = 'true';

    const input = picker.querySelector('[data-activity-search]');
    const selectedId = picker.querySelector('[data-activity-id]');
    const list = picker.querySelector('[data-activity-options]');
    const status = picker.querySelector('[data-activity-status]');
    let results = [];
    let active = -1;
    let timer;
    let request;
    let version = 0;
    let composing = false;
    const validate = () => input.setCustomValidity(selectedId.value ? '' : 'Pesquise e selecione uma atividade da lista.');
    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };
    const cancel = () => {
        clearTimeout(timer);
        request?.abort();
        version++;
        if (input.hasAttribute('aria-busy')) status.textContent = '';
        input.removeAttribute('aria-busy');
    };
    const highlight = (index) => {
        active = index;
        Array.from(list.children).forEach((option, position) => {
            option.setAttribute('aria-selected', String(position === active));
            option.classList.toggle('bg-teal-50', position === active);
        });
        if (list.children[active]) {
            input.setAttribute('aria-activedescendant', list.children[active].id);
            list.children[active].scrollIntoView({ block: 'nearest' });
        }
    };
    const select = (index) => {
        const activity = results[index];
        if (!activity) return;
        cancel();
        selectedId.value = String(activity.id);
        input.value = activity.descricao;
        input.removeAttribute('aria-invalid');
        validate();
        close();
        status.textContent = 'Atividade selecionada.';
        input.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const search = async (query, currentVersion) => {
        request = new AbortController();
        status.textContent = 'Buscando atividades…';
        input.setAttribute('aria-busy', 'true');
        try {
            const url = new URL(picker.dataset.searchUrl, window.location.href);
            url.searchParams.set('q', query);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: request.signal });
            if (!response.ok) throw new Error(String(response.status));
            const payload = await response.json();
            if (currentVersion !== version || !picker.isConnected) return;
            results = payload.data;
            list.replaceChildren();
            results.forEach((activity, index) => {
                const option = document.createElement('li');
                option.id = `activity-option-${activity.id}`;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                option.className = 'cursor-pointer px-3 py-3 text-sm text-slate-800 hover:bg-teal-50 break-words';
                option.textContent = activity.descricao;
                option.addEventListener('pointerdown', (event) => event.preventDefault());
                option.addEventListener('click', () => select(index));
                list.append(option);
            });
            list.hidden = results.length === 0;
            input.setAttribute('aria-expanded', String(results.length > 0));
            status.textContent = results.length === 0
                ? 'Nenhuma atividade encontrada. Tente uma palavra mais geral da descrição.'
                : `${results.length} opções. ${payload.has_more ? 'Digite mais detalhes para refinar a busca.' : 'Selecione a atividade correspondente.'}`;
        } catch (error) {
            if (error.name === 'AbortError' || currentVersion !== version || !picker.isConnected) return;
            close();
            status.textContent = error.message === '429'
                ? 'Aguarde um momento e digite novamente para pesquisar.'
                : 'Não foi possível buscar. Digite novamente para tentar outra vez.';
        } finally {
            if (currentVersion === version) input.removeAttribute('aria-busy');
        }
    };
    const schedule = () => {
        cancel();
        selectedId.value = '';
        validate();
        close();
        results = [];
        const query = input.value.trim().replace(/\s+/g, ' ');
        status.textContent = '';
        if (composing) return;
        if (query.length < 2 || query.length > 100) {
            status.textContent = query.length > 100 ? 'Use até 100 caracteres na pesquisa.' : 'Digite pelo menos 2 caracteres.';
            return;
        }
        const currentVersion = version;
        timer = setTimeout(() => search(query, currentVersion), 300);
    };
    input.addEventListener('input', schedule);
    input.addEventListener('compositionstart', () => { composing = true; });
    input.addEventListener('compositionend', () => { composing = false; schedule(); });
    input.addEventListener('focus', () => {
        if (!selectedId.value && input.value.trim().length >= 2) schedule();
    });
    input.addEventListener('blur', () => { cancel(); close(); });
    input.addEventListener('keydown', (event) => {
        if (event.isComposing) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            cancel();
            close();
        } else if (['ArrowDown', 'ArrowUp'].includes(event.key) && results.length) {
            event.preventDefault();
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            highlight(event.key === 'ArrowDown' ? (active + 1) % results.length : (active <= 0 ? results.length - 1 : active - 1));
        } else if (event.key === 'Enter' && !list.hidden) {
            event.preventDefault();
            if (active >= 0) select(active);
        }
    });
    validate();
    status.textContent = selectedId.value ? 'Atividade selecionada.' : '';
};
