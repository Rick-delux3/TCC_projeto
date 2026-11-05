<footer class="border-top pt-3">
    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ url()->current() }}" class="btn btn-outline-primary">Atualizar visualização</a>
        @if ($permissions['edit_lead'] && $permissions['create_analysis'])
            <button type="button" class="btn btn-primary" data-analysis-dialog="expense-reanalysis" aria-haspopup="dialog" aria-controls="expense-reanalysis" @disabled(! $actions['expense_reanalysis']['available'])>Reanalisar</button>
        @endif
    </div>
    <p class="small text-body-secondary mt-2 mb-0">Altere as despesas para solicitar novas cotações. Companhias com falha não serão reenviadas.</p>
    @if ($actions['expense_reanalysis']['available'])
        @include('insurance-analyses.partials.expense-reanalysis', ['expenseAction' => $actions['expense_reanalysis'], 'expenses' => $pageData['lead']['despesas'] ?? []])
    @elseif ($errors->expenseReanalysis->any())
        <div class="alert alert-warning" role="alert">{{ $errors->expenseReanalysis->first() }}</div>
    @endif
</footer>
