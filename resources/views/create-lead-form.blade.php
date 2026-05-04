@extends('layout-inicial.app')




@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4 p-md-5">
                    <div class="mb-4 text-center">
                        <h1 class="h3 fw-bold mb-2">Cadastro de Lead</h1>
                        <p class="text-muted mb-0">
                            Preencha os dados abaixo para iniciar a análise.
                        </p>
                    </div>

                    <div class="alert alert-primary">
                        <strong>Imobiliária:</strong> {{ $company->name }}
                    </div>

                    @if (session('success'))
                        <div class="alert alert-success">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="alert alert-danger">
                            Verifique os campos destacados e tente novamente.
                        </div>
                    @endif

                    <form action="{{ route('public.leads.store', $company->lead_form_token) }}" method="POST">
                        @csrf

                        {{-- Honeypot contra bots --}}
                        <div style="display: none;">
                            <label>Website</label>
                            <input type="text" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="row g-3">

                            <div class="col-md-12">
                                <label for="nome" class="form-label">Nome completo</label>
                                <input 
                                    type="text" 
                                    name="nome" 
                                    id="nome"
                                    class="form-control @error('nome') is-invalid @enderror"
                                    value="{{ old('nome') }}"
                                    placeholder="Ex: João da Silva"
                                    required
                                >
                                @error('nome')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="email" class="form-label">E-mail</label>
                                <input 
                                    type="email" 
                                    name="email" 
                                    id="email"
                                    class="form-control @error('email') is-invalid @enderror"
                                    value="{{ old('email') }}"
                                    placeholder="exemplo@email.com"
                                    required
                                >
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="cpf" class="form-label">CPF</label>
                                <input 
                                    type="text" 
                                    name="cpf" 
                                    id="cpf"
                                    class="form-control @error('cpf') is-invalid @enderror"
                                    value="{{ old('cpf') }}"
                                    placeholder="000.000.000-00"
                                    maxlength="14"
                                    required
                                >
                                @error('cpf')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="telefone" class="form-label">Telefone</label>
                                <input 
                                    type="text" 
                                    name="telefone" 
                                    id="telefone"
                                    class="form-control @error('telefone') is-invalid @enderror"
                                    value="{{ old('telefone') }}"
                                    placeholder="(00) 00000-0000"
                                    maxlength="15"
                                    required
                                >
                                @error('telefone')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="estado_civil" class="form-label">Estado civil</label>
                                <select 
                                    name="estado_civil" 
                                    id="estado_civil"
                                    class="form-select @error('estado_civil') is-invalid @enderror"
                                    required
                                >
                                    <option value="">Selecione</option>
                                    <option value="solteiro" {{ old('estado_civil') === 'solteiro' ? 'selected' : '' }}>Solteiro(a)</option>
                                    <option value="casado" {{ old('estado_civil') === 'casado' ? 'selected' : '' }}>Casado(a)</option>
                                    <option value="uniao_estavel" {{ old('estado_civil') === 'uniao_estavel' ? 'selected' : '' }}>União estável</option>
                                    <option value="divorciado" {{ old('estado_civil') === 'divorciado' ? 'selected' : '' }}>Divorciado(a)</option>
                                    <option value="viuvo" {{ old('estado_civil') === 'viuvo' ? 'selected' : '' }}>Viúvo(a)</option>
                                </select>
                                @error('estado_civil')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div id="dados-conjuge" class="col-12 d-none">
                                <div class="border rounded-4 p-3 bg-light">
                                    <h2 class="h6 fw-bold mb-3">Dados do cônjuge</h2>

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="conjuge_nome" class="form-label">Nome do cônjuge</label>
                                            <input 
                                                type="text" 
                                                name="conjuge_nome" 
                                                id="conjuge_nome"
                                                class="form-control @error('conjuge_nome') is-invalid @enderror"
                                                value="{{ old('conjuge_nome') }}"
                                                placeholder="Nome completo"
                                            >
                                            @error('conjuge_nome')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>

                                        <div class="col-md-6">
                                            <label for="conjuge_cpf" class="form-label">CPF do cônjuge</label>
                                            <input 
                                                type="text" 
                                                name="conjuge_cpf" 
                                                id="conjuge_cpf"
                                                class="form-control @error('conjuge_cpf') is-invalid @enderror"
                                                value="{{ old('conjuge_cpf') }}"
                                                placeholder="000.000.000-00"
                                                maxlength="14"
                                            >
                                            @error('conjuge_cpf')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="valor_aluguel" class="form-label">Valor do aluguel</label>
                                <input 
                                    type="text" 
                                    name="valor_aluguel" 
                                    id="valor_aluguel"
                                    class="form-control @error('valor_aluguel') is-invalid @enderror"
                                    value="{{ old('valor_aluguel') }}"
                                    placeholder="R$ 0,00"
                                    required
                                >
                                @error('valor_aluguel')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="outras_despesas" class="form-label">Outras despesas</label>
                                <input 
                                    type="text" 
                                    name="outras_despesas" 
                                    id="outras_despesas"
                                    class="form-control @error('outras_despesas') is-invalid @enderror"
                                    value="{{ old('outras_despesas') }}"
                                    placeholder="R$ 0,00"
                                >
                                <small class="text-muted">Ex: condomínio, IPTU, água, luz ou outros encargos.</small>
                                @error('outras_despesas')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-8">
                                <label for="cidade_imovel" class="form-label">Cidade do imóvel pretendido</label>
                                <input 
                                    type="text" 
                                    name="cidade_imovel" 
                                    id="cidade_imovel"
                                    class="form-control @error('cidade_imovel') is-invalid @enderror"
                                    value="{{ old('cidade_imovel') }}"
                                    placeholder="Ex: Tatuí"
                                    required
                                >
                                @error('cidade_imovel')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-4">
                                <label for="estado" class="form-label">Estado</label>
                                <input 
                                    type="text" 
                                    name="estado" 
                                    id="estado"
                                    class="form-control @error('estado') is-invalid @enderror"
                                    value="{{ old('estado') }}"
                                    placeholder="SP"
                                    maxlength="2"
                                >
                                @error('estado')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-12">
                                <label for="responsavel_preenchimento" class="form-label">
                                    Nome do responsável pelo preenchimento
                                </label>
                                <input 
                                    type="text" 
                                    name="responsavel_preenchimento" 
                                    id="responsavel_preenchimento"
                                    class="form-control @error('responsavel_preenchimento') is-invalid @enderror"
                                    value="{{ old('responsavel_preenchimento') }}"
                                    placeholder="Ex: Maria Oliveira"
                                    required
                                >
                                @error('responsavel_preenchimento')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                Cadastrar Lead
                            </button>
                        </div>

                        <p class="text-muted small text-center mt-3 mb-0">
                            Seus dados serão utilizados apenas para fins de análise e contato.
                        </p>
                    </form>

                </div>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const estadoCivil = document.getElementById('estado_civil');
    const dadosConjuge = document.getElementById('dados-conjuge');
    const conjugeNome = document.getElementById('conjuge_nome');
    const conjugeCpf = document.getElementById('conjuge_cpf');

    function toggleDadosConjuge() {
        const precisaConjuge = estadoCivil.value === 'casado' || estadoCivil.value === 'uniao_estavel';

        if (precisaConjuge) {
            dadosConjuge.classList.remove('d-none');
            conjugeNome.setAttribute('required', 'required');
            conjugeCpf.setAttribute('required', 'required');
        } else {
            dadosConjuge.classList.add('d-none');
            conjugeNome.removeAttribute('required');
            conjugeCpf.removeAttribute('required');
            conjugeNome.value = '';
            conjugeCpf.value = '';
        }
    }

    estadoCivil.addEventListener('change', toggleDadosConjuge);
    toggleDadosConjuge();

    function maskCpf(input) {
        input.addEventListener('input', function () {
            let value = input.value.replace(/\D/g, '').slice(0, 11);

            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');

            input.value = value;
        });
    }

    function maskTelefone(input) {
        input.addEventListener('input', function () {
            let value = input.value.replace(/\D/g, '').slice(0, 11);

            if (value.length <= 10) {
                value = value.replace(/(\d{2})(\d)/, '($1) $2');
                value = value.replace(/(\d{4})(\d)/, '$1-$2');
            } else {
                value = value.replace(/(\d{2})(\d)/, '($1) $2');
                value = value.replace(/(\d{5})(\d)/, '$1-$2');
            }

            input.value = value;
        });
    }

    function maskMoney(input) {
        input.addEventListener('input', function () {
            let value = input.value.replace(/\D/g, '');

            if (!value) {
                input.value = '';
                return;
            }

            value = (Number(value) / 100).toLocaleString('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            });

            input.value = value;
        });
    }

    maskCpf(document.getElementById('cpf'));
    maskCpf(document.getElementById('conjuge_cpf'));
    maskTelefone(document.getElementById('telefone'));
    maskMoney(document.getElementById('valor_aluguel'));
    maskMoney(document.getElementById('outras_despesas'));
});
</script>


@endsection