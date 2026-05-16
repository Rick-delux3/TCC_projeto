{{-- 
    Partial reutilizável para dados do imóvel e valores da locação.

    Nova lógica:
    - valor_aluguel é obrigatório;
    - água e luz são opcionais;
    - se água/luz não forem preenchidas, o backend calcula 10% do aluguel;
    - condomínio, IPTU e gás são opcionais;
    - o usuário escolhe no select qual despesa deseja adicionar;
    - endereço é preenchido pelo CEP, mas continua editável.
--}}

<div class="col-12 mt-3">
    <h5 class="fw-bold border-bottom pb-2">
        Dados do imóvel e valores da locação
    </h5>
</div>

<div class="col-md-6">
    <label class="form-label">Valor do aluguel <span class="text-danger">*</span></label>
    <div class="input-group">
        <span class="input-group-text">R$</span>
        <input 
            type="text"
            name="valor_aluguel"
            class="form-control @error('valor_aluguel') is-invalid @enderror"
            value="{{ old('valor_aluguel') }}"
            placeholder="Ex: 1500,00"
            inputmode="decimal"
        >
        @error('valor_aluguel')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="col-md-6">
    <label class="form-label">Adicionar despesa, se houver</label>
    <div class="input-group">
        <select id="expenseSelector" class="form-select">
            <option value="">Selecione uma despesa</option>
            <option value="valor_agua">Água</option>
            <option value="valor_luz">Luz</option>
            <option value="valor_gas">Gás</option>
            <option value="valor_iptu">IPTU</option>
            <option value="valor_condominio">Condomínio</option>
        </select>

        <button type="button" class="btn btn-outline-danger" id="addExpenseButton">
            Adicionar
        </button>
    </div>

    <div class="form-text">
        Água e luz são opcionais. Se não preencher, o sistema considera 10% do aluguel para cada uma.
    </div>
</div>

@php
    $expenseFields = [
        'valor_agua' => 'Água',
        'valor_luz' => 'Luz',
        'valor_gas' => 'Gás',
        'valor_iptu' => 'IPTU',
        'valor_condominio' => 'Condomínio',
    ];
@endphp

@foreach ($expenseFields as $fieldName => $fieldLabel)
    <div 
        class="col-md-6 expense-field {{ old($fieldName) ? '' : 'd-none' }}"
        data-expense-field="{{ $fieldName }}"
    >
        <label class="form-label">{{ $fieldLabel }}</label>

        <div class="input-group">
            <span class="input-group-text">R$</span>

            <input 
                type="text"
                name="{{ $fieldName }}"
                class="form-control @error($fieldName) is-invalid @enderror"
                value="{{ old($fieldName) }}"
                placeholder="Ex: 150,00"
                inputmode="decimal"
            >

            <button 
                type="button"
                class="btn btn-outline-secondary remove-expense-button"
                data-remove-expense="{{ $fieldName }}"
            >
                Remover
            </button>

            @error($fieldName)
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
@endforeach

<div class="col-md-6">
    <label class="form-label">Outras despesas</label>
    <div class="input-group">
        <span class="input-group-text">R$</span>
        <input 
            type="text"
            name="outras_despesas"
            class="form-control @error('outras_despesas') is-invalid @enderror"
            value="{{ old('outras_despesas') }}"
            placeholder="Outras despesas não listadas"
            inputmode="decimal"
        >
        @error('outras_despesas')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="col-12 mt-3">
    <h5 class="fw-bold border-bottom pb-2">
        Endereço do imóvel pretendido
    </h5>
</div>

<div class="col-md-4">
    <label class="form-label">CEP <span class="text-danger">*</span></label>
    <input 
        type="text"
        name="cep"
        id="cep"
        class="form-control @error('cep') is-invalid @enderror"
        value="{{ old('cep') }}"
        placeholder="Ex: 18270000"
        maxlength="9"
        inputmode="numeric"
    >
    @error('cep')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror

    <div class="form-text">
        Digite o CEP para preencher o endereço automaticamente.
    </div>
</div>

<div class="col-md-8">
    <label class="form-label">Logradouro <span class="text-danger">*</span></label>
    <input 
        type="text"
        name="logradouro"
        id="logradouro"
        class="form-control @error('logradouro') is-invalid @enderror"
        value="{{ old('logradouro') }}"
        placeholder="Rua, avenida, travessa..."
    >
    @error('logradouro')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="col-md-4">
    <label class="form-label">Número <span class="text-danger">*</span></label>
    <input 
        type="text"
        name="numero"
        class="form-control @error('numero') is-invalid @enderror"
        value="{{ old('numero') }}"
        placeholder="Ex: 123 ou S/N"
    >
    @error('numero')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="col-md-4">
    <label class="form-label">Complemento</label>
    <input 
        type="text"
        name="complemento"
        class="form-control @error('complemento') is-invalid @enderror"
        value="{{ old('complemento') }}"
        placeholder="Apto, bloco, casa..."
    >
    @error('complemento')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="col-md-4">
    <label class="form-label">Bairro <span class="text-danger">*</span></label>
    <input 
        type="text"
        name="bairro"
        id="bairro"
        class="form-control @error('bairro') is-invalid @enderror"
        value="{{ old('bairro') }}"
        placeholder="Bairro"
    >
    @error('bairro')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="col-md-8">
    <label class="form-label">Cidade do imóvel <span class="text-danger">*</span></label>
    <input 
        type="text"
        name="cidade_imovel"
        id="cidade_imovel"
        class="form-control @error('cidade_imovel') is-invalid @enderror"
        value="{{ old('cidade_imovel') }}"
        placeholder="Cidade"
    >
    @error('cidade_imovel')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="col-md-4">
    <label class="form-label">Estado <span class="text-danger">*</span></label>
    <input 
        type="text"
        name="estado"
        id="estado"
        class="form-control text-uppercase @error('estado') is-invalid @enderror"
        value="{{ old('estado') }}"
        placeholder="UF"
        maxlength="2"
    >
    @error('estado')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
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
        }
    }

    function hideExpenseField(fieldName) {
        const fieldWrapper = document.querySelector(`[data-expense-field="${fieldName}"]`);

        if (!fieldWrapper) {
            return;
        }

        const input = fieldWrapper.querySelector('input');

        if (input) {
            input.value = '';
        }

        fieldWrapper.classList.add('d-none');
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

