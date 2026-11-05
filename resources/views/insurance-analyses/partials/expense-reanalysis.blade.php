<dialog id="expense-reanalysis" class="border-0 rounded p-0 w-100" style="max-width: 640px; max-height: 85vh" aria-labelledby="expense-reanalysis-title" aria-describedby="expense-reanalysis-description" @if ($errors->expenseReanalysis->any()) data-open-on-load @endif>
    <form action="{{ $expenseAction['url'] }}" method="POST" class="card" data-expense-reanalysis>
        @csrf
        @method('PUT')
        <input type="hidden" name="revision" value="{{ old('revision', $expenseAction['revision']) }}">
        <div class="card-header d-flex justify-content-between align-items-center gap-3">
            <h2 id="expense-reanalysis-title" class="h5 mb-0">Reanalisar despesas</h2>
            <button type="button" class="btn-close" data-close-analysis-dialog aria-label="Fechar"></button>
        </div>
        <div class="card-body">
            <p id="expense-reanalysis-description" class="text-body-secondary">Atualize os valores mensais. As novas cotações usarão estas despesas, mantendo os dados do solicitante.</p>
            @if ($errors->expenseReanalysis->any())
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        @foreach ($errors->expenseReanalysis->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                    <a href="{{ url()->current() }}" class="alert-link">Recarregar dados atuais</a>
                </div>
            @endif
            <div class="row g-3">
                @foreach (['valor_aluguel' => 'Aluguel', 'valor_condominio' => 'Condomínio', 'valor_iptu' => 'IPTU', 'valor_agua' => 'Água', 'valor_luz' => 'Luz', 'valor_gas' => 'Gás', 'outras_despesas' => 'Outras despesas'] as $field => $label)
                    <div class="col-12 col-sm-6">
                        <label for="expense-{{ $field }}" class="form-label">{{ $label }} (R$)</label>
                        <input type="number" class="form-control {{ $errors->expenseReanalysis->has($field) ? 'is-invalid' : '' }}" id="expense-{{ $field }}" name="{{ $field }}" value="{{ old($field, number_format((float) ($expenses[$field] ?? 0), 2, '.', '')) }}" min="{{ $field === 'valor_aluguel' ? '0.01' : '0' }}" max="9999999.99" step="0.01" inputmode="decimal" required data-expense-value @if ($errors->expenseReanalysis->has($field)) aria-invalid="true" aria-describedby="error-{{ $field }}" @endif>
                        @error($field, 'expenseReanalysis')
                            <div class="invalid-feedback" id="error-{{ $field }}">{{ $message }}</div>
                        @enderror
                    </div>
                @endforeach
            </div>
            <p class="small text-body-secondary mt-3">Para remover uma despesa, informe zero. O aluguel deve ser maior que zero.</p>
            <div class="border-top pt-3 d-flex justify-content-between gap-3">
                <strong>Total dos encargos</strong>
                <output data-expense-total aria-live="polite">{{ $formatMoney($expenses['valor_total_encargos'] ?? 0) }}</output>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary" data-close-analysis-dialog>Cancelar</button>
            <button type="submit" class="btn btn-primary">Salvar e reanalisar</button>
        </div>
    </form>
</dialog>
