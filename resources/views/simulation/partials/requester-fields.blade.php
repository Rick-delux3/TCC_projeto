<h2 class="simulation-section-title simulation-section-divider">Responsável pela solicitação</h2>
<div class="simulation-fields grid grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-2">
    <fieldset class="md:col-span-2">
        <legend class="simulation-field-label">Quem está solicitando? <span class="simulation-required">*</span></legend>
        @if ($lockResponsavelTipo)
            <input type="hidden" name="responsavel_tipo" value="{{ $responsavelTipo }}">
            <p class="simulation-context">{{ $responsavelTipo === 'locador' ? 'Proprietário / locador' : 'Imobiliária não cadastrada' }}</p>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach (['imobiliaria_nao_cadastrada' => 'Imobiliária não cadastrada', 'locador' => 'Proprietário / locador'] as $value => $label)
                    <label class="simulation-requester-choice flex items-center gap-4" for="responsavel_{{ $value }}">
                        <span class="simulation-choice-icon">@include('simulation.partials.form-icon', ['icon' => $value === 'locador' ? 'house' : 'building'])</span>
                        <input type="radio" name="responsavel_tipo" id="responsavel_{{ $value }}" value="{{ $value }}" @checked(old('responsavel_tipo', $responsavelTipo ?? 'imobiliaria_nao_cadastrada') === $value) required>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        @endif
    </fieldset>
    @include('simulation.partials.input', ['field' => ['name' => 'responsavel_nome', 'label' => 'Nome do responsável', 'placeholder' => 'Nome do responsável', 'maxlength' => 255, 'required' => ! $isAdminSimulation, 'class' => 'md:col-span-2']])
    @include('simulation.partials.input', ['field' => ['name' => 'responsavel_email', 'label' => 'E-mail do responsável', 'placeholder' => 'E-mail do responsável', 'type' => 'email', 'maxlength' => 255, 'required' => ! $isAdminSimulation]])
    @include('simulation.partials.input', ['field' => ['name' => 'responsavel_telefone', 'label' => 'Telefone do responsável', 'placeholder' => 'Telefone do responsável', 'type' => 'tel', 'required' => ! $isAdminSimulation]])
</div>
