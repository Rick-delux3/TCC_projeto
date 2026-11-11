<section aria-labelledby="{{ $codePrefix }}-partner-codes-title">
    <h3 id="{{ $codePrefix }}-partner-codes-title" class="h6 fw-semibold mb-2">Códigos das seguradoras</h3>
    <p class="form-text mt-0 mb-3">Campos opcionais. Preencha os códigos fornecidos por cada seguradora ou deixe em branco quando não houver vínculo.</p>
    <div class="row g-3">
        @foreach (['tokio_partner_code' => ['Tokio Marine', 50], 'porto_partner_code' => ['Porto Seguro', 6]] as $field => [$label, $length])
            @php
                $inputId = $codePrefix.'-'.$field;
                $submittedCode = $restoreCodes ? old($field) : '';
                $codeValue = is_scalar($submittedCode) ? (string) $submittedCode : '';
            @endphp
            <div class="col-12 col-md-6">
                <label for="{{ $inputId }}" class="form-label fw-semibold">Código {{ $label }}</label>
                <input
                    type="text"
                    id="{{ $inputId }}"
                    name="{{ $field }}"
                    value="{{ $codeValue }}"
                    class="form-control @error($field) is-invalid @enderror"
                    inputmode="numeric"
                    pattern="[0-9]{1,{{ $length }}}"
                    maxlength="{{ $length }}"
                    autocomplete="off"
                    placeholder="Não informado"
                    aria-describedby="{{ $inputId }}-help @error($field) {{ $inputId }}-error @enderror"
                    @error($field) aria-invalid="true" @enderror
                >
                <div id="{{ $inputId }}-help" class="form-text">
                    @if ($field === 'porto_partner_code')
                        Até 6 dígitos. Os zeros à esquerda são completados ao salvar.
                    @else
                        Informe somente os números do código de parceiro.
                    @endif
                </div>
                @error($field)
                    <div id="{{ $inputId }}-error" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        @endforeach
    </div>
</section>
