<footer data-analysis-component="actions" class="border-top pt-3">
    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if ($permissions['edit_lead'] && $permissions['create_analysis'] && $actions['save_expenses']['available'])
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-primary" data-analysis-dialog="expense-reanalysis" aria-haspopup="dialog" aria-controls="expense-reanalysis">Editar despesas</button>
        </div>
    @endif
    <p class="small text-body-secondary mt-2 mb-0">A reanálise é feita por companhia: salve as despesas e clique em <strong>Reanalisar</strong> na companhia desejada. Companhias ainda em análise aguardam o resultado atual.</p>
</footer>
<div data-analysis-component="expense-form">
    @if ($actions['save_expenses']['available'])
        @include('insurance-analyses.partials.expense-reanalysis', ['saveExpenseAction' => $actions['save_expenses'], 'expenses' => $pageData['lead']['despesas'] ?? []])
    @elseif ($errors->expenseReanalysis->any())
        <div class="alert alert-warning" role="alert">{{ $errors->expenseReanalysis->first() }}</div>
    @endif
</div>
