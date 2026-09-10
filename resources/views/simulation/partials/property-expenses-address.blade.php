<h2 class="simulation-section-title">Valores da locação</h2>
<div class="simulation-fields grid grid-cols-1 gap-x-5 gap-y-4 md:grid-cols-2">
    @include('simulation.partials.rental-type')
    @include('simulation.partials.input', ['field' => ['name' => 'valor_aluguel', 'label' => 'Valor do aluguel', 'type' => 'money', 'placeholder' => '1.500,00', 'required' => true]])
    <div class="simulation-field">
        <label for="expenseSelector">Despesas adicionais</label>
        <div class="flex gap-2">
            <select id="expenseSelector">
                <option value="">Selecione uma despesa</option>
                @foreach (['valor_agua' => 'Água', 'valor_luz' => 'Luz', 'valor_gas' => 'Gás', 'valor_iptu' => 'IPTU', 'valor_condominio' => 'Condomínio'] as $name => $label)
                    <option value="{{ $name }}">{{ $label }}</option>
                @endforeach
            </select>
            <button type="button" class="simulation-button simulation-button-outline simulation-expense-add" id="addExpenseButton"><span aria-hidden="true">+</span> Adicionar</button>
        </div>
    </div>
    @foreach (['valor_agua' => 'Água', 'valor_luz' => 'Luz', 'valor_gas' => 'Gás', 'valor_iptu' => 'IPTU', 'valor_condominio' => 'Condomínio'] as $name => $label)
        @php
            $expenseVisible = old($name) !== null || in_array($name, ['valor_agua', 'valor_luz'], true);
        @endphp
        <div class="expense-field {{ $expenseVisible ? '' : 'd-none' }}" data-expense-field="{{ $name }}">
            <div class="simulation-expense-row">
                @include('simulation.partials.input', ['field' => ['name' => $name, 'label' => $label, 'type' => 'money', 'placeholder' => 'Opcional']])
                <button type="button" class="simulation-remove-expense remove-expense-button" data-remove-expense="{{ $name }}" aria-label="Remover {{ $label }}">@include('simulation.partials.form-icon', ['icon' => 'trash'])</button>
            </div>
        </div>
    @endforeach
    @include('simulation.partials.input', ['field' => ['name' => 'outras_despesas', 'label' => 'Outras despesas', 'type' => 'money', 'placeholder' => 'Opcional']])
    <div class="simulation-expense-info md:col-span-2">
        @include('simulation.partials.form-icon', ['icon' => 'info'])
        <p>Água e luz são opcionais. Se não preencher, será considerado 10% do aluguel para cada uma.</p>
    </div>
</div>
<h2 class="simulation-section-title simulation-address-title">Endereço do imóvel</h2>
<div class="simulation-fields grid grid-cols-1 gap-x-5 gap-y-4 md:grid-cols-3">
    <div>
        @include('simulation.partials.input', ['field' => ['name' => 'cep', 'label' => 'CEP', 'placeholder' => '00000-000', 'inputmode' => 'numeric', 'maxlength' => 9, 'required' => true, 'autocomplete' => 'postal-code']])
        <p class="simulation-field-hint mt-1" id="cep-status" role="status">Preenchimento automático pelo CEP.</p>
    </div>
    @include('simulation.partials.input', ['field' => ['name' => 'logradouro', 'label' => 'Logradouro', 'placeholder' => 'Rua, avenida, travessa...', 'required' => true, 'maxlength' => 255, 'class' => 'md:col-span-2']])
    @include('simulation.partials.input', ['field' => ['name' => 'numero', 'label' => 'Número', 'placeholder' => 'Opcional', 'required' => false, 'maxlength' => 20]])
    @include('simulation.partials.input', ['field' => ['name' => 'complemento', 'label' => 'Complemento', 'placeholder' => 'Apto, bloco, casa...', 'maxlength' => 100]])
    @include('simulation.partials.input', ['field' => ['name' => 'bairro', 'label' => 'Bairro', 'placeholder' => 'Bairro', 'required' => true, 'maxlength' => 100]])
    @include('simulation.partials.input', ['field' => ['name' => 'cidade_imovel', 'label' => 'Cidade do imóvel', 'placeholder' => 'Cidade', 'required' => true, 'maxlength' => 100, 'class' => 'md:col-span-2']])
    <div class="simulation-field">
        <label for="estado">Estado <span class="simulation-required">*</span></label>
        <select id="estado" name="estado" required @error('estado') aria-invalid="true" aria-describedby="estado-error" @enderror>
            <option value="">UF</option>
            @foreach (['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'] as $uf)
                <option value="{{ $uf }}" @selected(old('estado') === $uf)>{{ $uf }}</option>
            @endforeach
        </select>
        @error('estado')<p class="simulation-field-error" id="estado-error">{{ $message }}</p>@enderror
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const selector = document.getElementById('expenseSelector');
    const addButton = document.getElementById('addExpenseButton');

    function formatarCep(valor) {
        const numeros = valor.replace(/\D/g, '').slice(0, 8);

        if (numeros.length > 5) {
            return `${numeros.slice(0, 5)}-${numeros.slice(5)}`;
        }

        return numeros;
    }

    function showExpenseField(fieldName) {
        if (!fieldName) {
            return;
        }

        const fieldWrapper = document.querySelector(`[data-expense-field="${fieldName}"]`);

        if (fieldWrapper) {
            fieldWrapper.classList.remove('d-none');

            const input = fieldWrapper.querySelector('input');

            if (input) {
                input.disabled = false;
                input.focus();
            }
        }

        syncExpenseSelector();
    }

    function hideExpenseField(fieldName) {
        const fieldWrapper = document.querySelector(`[data-expense-field="${fieldName}"]`);

        if (!fieldWrapper) {
            return;
        }

        const input = fieldWrapper.querySelector('input');

        if (input) {
            input.value = '';
            input.disabled = true;
        }

        fieldWrapper.classList.add('d-none');
        syncExpenseSelector();
    }

    function syncExpenseSelector() {
        if (!selector) {
            return;
        }

        selector.querySelectorAll('option[value]').forEach(function (option) {
            if (!option.value) {
                return;
            }

            const fieldWrapper = document.querySelector(`[data-expense-field="${option.value}"]`);
            const isVisible = fieldWrapper && !fieldWrapper.classList.contains('d-none');

            option.disabled = isVisible;
            option.hidden = isVisible;
        });

        if (selector.selectedOptions[0]?.disabled) {
            selector.value = '';
        }
    }

    if (addButton && selector) {
        addButton.addEventListener('click', function () {
            showExpenseField(selector.value);
            selector.value = '';
        });
    }

    document.querySelectorAll('.remove-expense-button').forEach(function (button) {
        button.addEventListener('click', function () {
            hideExpenseField(button.dataset.removeExpense);
        });
    });

    document.querySelectorAll('.expense-field').forEach(function (fieldWrapper) {
        const input = fieldWrapper.querySelector('input');

        if (input) {
            input.disabled = fieldWrapper.classList.contains('d-none');
        }
    });

    syncExpenseSelector();

    const cepInput = document.getElementById('cep');

    

    if (cepInput) {

        cepInput.value = formatarCep(cepInput.value);

        cepInput.addEventListener('input', function () {
            cepInput.value = formatarCep(cepInput.value);
        });
        
        cepInput.addEventListener('blur', async function () {
            const cep = cepInput.value.replace(/\D/g, '');

            if (cep.length !== 8) {
                return;
            }

            const logradouroInput = document.getElementById('logradouro');
            const bairroInput = document.getElementById('bairro');
            const cidadeInput = document.getElementById('cidade_imovel');
            const estadoInput = document.getElementById('estado');

            try {
                
                cepInput.classList.remove('is-invalid');

                if(logradouroInput) logradouroInput.value = 'Buscando...';
                if(bairroInput) bairroInput.value = 'Buscando...';
                if(cidadeInput) cidadeInput.value = 'Buscando...';
                if(estadoInput) estadoInput.value = '...';

                const response = await fetch(`/cep/${cep}`, {
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    cepInput.classList.add('is-invalid');

                    if (logradouroInput) logradouroInput.value = '';
                    if (bairroInput) bairroInput.value = '';
                    if (cidadeInput) cidadeInput.value = '';
                    if (estadoInput) estadoInput.value = '';

                    alert(result.message ?? 'CEP não encontrado.');
                    return;
                }

                const data = result.data;

                if (logradouroInput) logradouroInput.value = data.logradouro ?? '';
                if (bairroInput) bairroInput.value = data.bairro ?? '';
                if (cidadeInput) cidadeInput.value = data.cidade ?? '';
                if (estadoInput) estadoInput.value = data.estado ?? '';

            } catch (error) {
                cepInput.classList.add('is-invalid');

                if (logradouroInput) logradouroInput.value = '';
                if (bairroInput) bairroInput.value = '';
                if (cidadeInput) cidadeInput.value = '';
                if (estadoInput) estadoInput.value = '';

                alert('Não foi possível consultar o CEP agora. Preencha o endereço manualmente.');
            }
        });
    }
});
</script>
@endpush
