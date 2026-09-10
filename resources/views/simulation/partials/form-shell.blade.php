@php
    $isAdminSimulation = $isAdminSimulation ?? false;
    $lockResponsavelTipo = $lockResponsavelTipo ?? false;
    $isTenant = $profile === 'tenant';
    $isRegistered = $profile === 'registered';
    $profileLabel = $isTenant ? 'Pretendente à locação' : ($isRegistered ? 'Imobiliária cadastrada' : 'Imobiliária não cadastrada ou proprietário');
    $firstStepLabel = $isTenant ? 'Seus dados' : 'Pessoas e responsável';
    $document = \App\Rules\CpfOrCnpj::normalize(old('cpf', ''));
    $isCompanyDocument = is_string($document) && preg_match('/^[0-9]{14}$/D', $document) === 1;
    $firstStepFields = ['nome', 'email', 'cpf', 'tel', 'estado_civil', 'conjuge_nome', 'conjuge_cpf', 'cpf_responsavel', 'nome_responsavel', 'responsavel_tipo', 'responsavel_nome', 'responsavel_email', 'responsavel_telefone', 'responsavel_preenchimento'];
    $initialStep = $errors->any() && ! $errors->hasAny($firstStepFields) ? 2 : 1;
@endphp

@include('simulation.partials.form-style')

<div class="simulation-wizard grid gap-4 lg:grid-cols-[330px_minmax(0,1fr)]" data-simulation-wizard data-initial-step="{{ $initialStep }}">
    <aside class="simulation-sidebar flex flex-col">
        <div class="simulation-sidebar-heading">
            <p class="simulation-eyebrow">{{ $isAdminSimulation ? 'Preenchimento interno' : 'Nova solicitação' }}</p>
            <h2>Seguro fiança</h2>
            <p>{{ $profileLabel }}</p>
            @if ($isRegistered)<p class="simulation-company-name">{{ $company->name }}</p>@endif
        </div>
        <nav aria-label="Etapas da solicitação" class="simulation-step-nav">
            <ol class="flex flex-col gap-3">
                <li>
                    <button type="button" class="simulation-step" data-step-link="1" aria-current="step">
                        <span class="simulation-step-number"><span data-step-number>1</span><span data-step-check hidden>@include('simulation.partials.form-icon', ['icon' => 'check'])</span></span>
                        <span>{{ $firstStepLabel }}</span>
                    </button>
                </li>
                <li>
                    <button type="button" class="simulation-step" data-step-link="2">
                        <span class="simulation-step-number">2</span><span>Imóvel e envio</span>
                    </button>
                </li>
            </ol>
        </nav>
        <div class="simulation-sidebar-help" data-step-help="1">
            @include('simulation.partials.form-icon', ['icon' => 'help'])
            <div><h3>{{ $isTenant ? 'Tudo começa por você' : 'Quem é o pretendente?' }}</h3><p>{{ $isTenant ? 'Informe seus dados para solicitar a análise do seguro fiança.' : 'É a pessoa ou a empresa que deseja alugar o imóvel.' }}</p></div>
        </div>
        <div class="simulation-sidebar-help simulation-sidebar-help-bottom" data-step-help="2" hidden>
            @include('simulation.partials.form-icon', ['icon' => 'help'])
            <div><h3>Antes de enviar</h3><p>Confira os valores, o endereço e os dados {{ $isTenant ? 'informados' : 'do responsável' }}.</p><p class="simulation-help-note">Dados usados para análise de seguro aluguel.</p></div>
        </div>
    </aside>

    <section class="simulation-form-card" aria-label="Solicitação de seguro fiança">
        @if ($errors->any())
            <div class="simulation-form-errors" role="alert">
                <strong>Revise os campos para continuar.</strong>
                <ul>
                    @foreach ($errors->messages() as $fieldName => $messages)
                        <li><button type="button" data-error-field="{{ $fieldName }}">{{ $messages[0] }}</button></li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if (session('error'))<div class="simulation-form-errors" role="alert">{{ session('error') }}</div>@endif
        @if ($isAdminSimulation)
            <p class="simulation-context">{{ $isRegistered ? 'Solicitação vinculada à imobiliária '.$company->name.'.' : 'Solicitação registrada pelo corretor logado, sem vínculo com imobiliária cadastrada.' }}</p>
        @endif
        <form action="{{ $formAction }}" method="POST" class="simulation-form" data-simulation-form>
            @csrf
            @if ($isRegistered && ! $isAdminSimulation)
                <input type="hidden" name="registered_company_context" value="{{ $company->getKey() }}">
            @endif
            @if ($isAdminSimulation && filled($adminSimulationChannel ?? null))
                <input type="hidden" name="admin_simulation_channel" value="{{ $adminSimulationChannel }}">
            @endif
            @include('simulation.partials.honeypot')

            <section data-form-step="1" aria-labelledby="simulation-people-title">
                <header class="simulation-form-heading flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 id="simulation-people-title" tabindex="-1">{{ $isTenant ? 'Seu novo lar começa aqui' : 'Aki sempre o menor valor!' }}</h1>
                        <p>{{ $isTenant ? 'Cadastro exclusivo para pretendente à locação.' : 'é online, é simples, é seguro' }}</p>
                    </div>
                    <span class="simulation-required-note"><span class="simulation-required">*</span> Campos obrigatórios</span>
                </header>
                <h2 class="simulation-section-title">{{ $isTenant ? 'Seus dados' : 'Pretendente à locação' }}</h2>
                @if ($isRegistered)<p class="simulation-section-description">Preencha abaixo os dados do pretendente à locação. O e-mail e telefone do locatário fazem parte da análise do risco.</p>@endif
                <div class="simulation-fields grid grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-2">
                    @include('simulation.partials.input', ['field' => ['name' => 'nome', 'label' => 'Nome completo', 'placeholder' => $isTenant ? 'Seu nome completo' : 'Nome do pretendente', 'required' => true, 'minlength' => 3, 'maxlength' => 255, 'autocomplete' => 'name']])
                    @include('simulation.partials.input', ['field' => ['name' => 'email', 'label' => 'E-mail', 'placeholder' => 'email@exemplo.com', 'type' => 'email', 'required' => true, 'maxlength' => 255, 'autocomplete' => 'email']])
                    @include('simulation.partials.document')
                    @include('simulation.partials.input', ['field' => ['name' => 'tel', 'label' => 'Telefone', 'placeholder' => '(00) 00000-0000', 'type' => 'tel', 'required' => true, 'autocomplete' => 'tel']])
                    @include('simulation.partials.conditional-fields', [
                        'condition' => 'company',
                        'visible' => $isCompanyDocument,
                        'fields' => 'simulation.partials.company-representative',
                    ])
                    @include('simulation.partials.marital-status')
                </div>

                @if ($isRegistered)
                    <h2 class="simulation-section-title simulation-section-divider">Responsável pelo preenchimento</h2>
                    <div class="simulation-fields grid grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-2">
                        @include('simulation.partials.input', ['field' => ['name' => 'responsavel_preenchimento', 'label' => 'Nome do responsável pelo preenchimento', 'placeholder' => 'Nome do responsável', 'maxlength' => 255]])
                        <div class="simulation-field"><label for="company_name">Imobiliária</label><input id="company_name" type="text" value="{{ $company->name }}" readonly></div>
                    </div>
                @elseif (! $isTenant)
                    @include('simulation.partials.requester-fields')
                @endif
                <footer class="simulation-step-footer flex flex-wrap items-start justify-between gap-4">
                    <a class="simulation-button simulation-button-back" href="{{ $isAdminSimulation ? route('Dashboard-Admin') : route('simulation.start') }}">Voltar</a>
                    <div class="flex flex-col items-end gap-2">
                        <button type="button" class="simulation-button simulation-button-primary" data-step-next>Continuar @include('simulation.partials.form-icon', ['icon' => 'arrow'])</button>
                        <span class="simulation-next-hint">Próximo: imóvel e valores da locação</span>
                    </div>
                </footer>
            </section>

            <section data-form-step="2" aria-labelledby="simulation-property-title">
                <header class="simulation-form-heading flex flex-wrap items-start justify-between gap-4">
                    <div><h2 id="simulation-property-title" tabindex="-1">Sobre o imóvel e a locação</h2><p>Informe os valores e o endereço do imóvel pretendido.</p></div>
                    <span class="simulation-required-note"><span class="simulation-required">*</span> Campos obrigatórios</span>
                </header>
                @include('simulation.partials.property-expenses-address')
                @include('simulation.partials.consent-checkbox')
                <footer class="simulation-submit-footer flex flex-wrap justify-end gap-4">
                    <button type="button" class="simulation-button simulation-button-outline" data-step-back>Voltar</button>
                    <button type="submit" class="simulation-button simulation-button-primary" data-simulation-submit>Enviar solicitação</button>
                </footer>
            </section>
        </form>
    </section>
</div>
@include('simulation.partials.form-script')
