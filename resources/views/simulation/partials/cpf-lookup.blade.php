@php
    $cpfErrors = collect(['cpf', 'cpf_responsavel', 'conjuge_cpf'])->filter(fn ($field) => $errors->has($field))
        ->map(fn ($field) => ['field' => $field, 'message' => $errors->first($field)])->values();
@endphp
<dialog class="simulation-constitution-modal w-full max-w-md rounded-xl border p-6" data-cpf-modal aria-labelledby="cpf-modal-title" aria-describedby="cpf-modal-message">
    <div class="flex flex-col gap-5">
        <h2 id="cpf-modal-title" class="text-xl font-semibold">Confira os dados do CPF</h2>
        <p id="cpf-modal-message" class="text-sm leading-relaxed" data-cpf-message></p>
        <p class="text-sm leading-relaxed">Confira o documento da pessoa indicada. Se a consulta estiver indisponível, aguarde e tente novamente. Seus dados permanecem no formulário.</p>
        <div class="flex flex-col gap-3">
            <button type="button" class="simulation-button simulation-button-primary" data-cpf-correct autofocus>Ir para o campo CPF</button>
            <button type="button" class="simulation-button simulation-button-outline" data-cpf-close>Voltar ao formulário</button>
        </div>
    </div>
</dialog>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-simulation-form]');
    const dialog = document.querySelector('[data-cpf-modal]');
    if (!form || !dialog) return;
    const errors = {{ Illuminate\Support\Js::from($cpfErrors) }};
    const names = {cpf: 'nome', cpf_responsavel: 'nome_responsavel', conjuge_cpf: 'conjuge_nome'};
    const labels = {cpf: 'Pretendente', cpf_responsavel: 'Responsável pela empresa', conjuge_cpf: 'Cônjuge'};
    let target;
    const showError = (field, message) => {
        target = field;
        dialog.querySelector('[data-cpf-message]').textContent = `${labels[field]}: ${message}`;
        if (!dialog.open) dialog.showModal();
    };
    dialog.querySelector('[data-cpf-close]').addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-cpf-correct]').addEventListener('click', () => {
        dialog.close();
        form.dispatchEvent(new CustomEvent('simulation-focus-field', {detail: target}));
    });
    if (errors.length) showError(errors[0].field, errors[0].message);
    if (!@json((bool) config('features.cpf_lookup.enabled'))) return;
    const states = new WeakMap();
    const digits = input => input.value.replace(/\D/g, '');
    const lookup = input => {
        const cpf = digits(input);
        if (input.name === 'cpf' && cpf.length === 14) return Promise.resolve(true);
        if (cpf.length !== 11) { showError(input.name, 'Informe os 11 dígitos do CPF.'); return Promise.resolve(false); }
        const previous = states.get(input);
        if (previous?.cpf === cpf) return previous.promise;
        let status = input.parentElement.querySelector('[data-cpf-status]');
        if (!status) {
            status = document.createElement('p');
            status.className = 'simulation-section-description';
            status.dataset.cpfStatus = '';
            status.setAttribute('role', 'status');
            input.parentElement.append(status);
        }
        status.textContent = 'Consultando nome e nascimento…';
        const state = {cpf};
        states.set(input, state);
        state.promise = (async () => {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 18000);
            try {
                const response = await fetch(@json(route('simulation.cpf.lookup')), {
                    method: 'POST', credentials: 'same-origin', signal: controller.signal,
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.elements.namedItem('_token').value},
                    body: JSON.stringify({cpf})
                });
                const data = await response.json().catch(() => ({}));
                if (!input.isConnected || digits(input) !== cpf || states.get(input) !== state) return false;
                if (!response.ok || typeof data.name !== 'string') {
                    throw new Error(response.status === 429 ? 'Aguarde um minuto antes de consultar novamente.'
                        : response.status === 419 ? 'Sua sessão expirou. Atualize a página para continuar.'
                        : data.errors?.cpf?.[0] || 'Consulta indisponível. Aguarde e tente novamente.');
                }
                const name = form.elements.namedItem(names[input.name]);
                if (name) { name.value = data.name; name.dispatchEvent(new Event('input', {bubbles: true})); }
                status.textContent = 'Nome preenchido e nascimento confirmado.';
                return true;
            } catch (error) {
                if (input.isConnected && digits(input) === cpf && states.get(input) === state) {
                    states.delete(input);
                    status.textContent = 'Não foi possível confirmar os dados.';
                    showError(input.name, error.name === 'AbortError' || error instanceof TypeError
                        ? 'A consulta não foi concluída. Verifique sua conexão e tente novamente.' : error.message);
                }
                return false;
            } finally { clearTimeout(timeout); }
        })();
        return state.promise;
    };
    form.addEventListener('focusout', event => {
        if (names[event.target.name] && event.target.value) lookup(event.target);
    });
    form.addEventListener('input', event => {
        const input = event.target;
        if (!names[input.name]) return;
        states.delete(input);
        const name = form.elements.namedItem(names[input.name]);
        if (name && !(input.name === 'cpf' && digits(input).length === 14)) name.value = '';
        const status = input.parentElement.querySelector('[data-cpf-status]');
        if (status) status.textContent = '';
    });
    form.confirmCpfData = async () => {
        for (const field of Object.keys(names)) {
            const input = form.elements.namedItem(field);
            if (input && !input.disabled && !await lookup(input)) return false;
        }
        return true;
    };
});
</script>
@endpush
