<footer data-analysis-component="actions" class="border-top pt-3">
    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if ($permissions['edit_lead'] && $permissions['create_analysis'])
        <div class="alert alert-info" role="status" data-reanalysis-mode-only="separate" hidden>
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span>Reanálise separada: clique em <strong>Reanalisar</strong> nas companhias desejadas.</span>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-analysis-dialog="expense-reanalysis" aria-haspopup="dialog" aria-controls="expense-reanalysis">Editar despesas</button>
                    <button type="button" class="btn btn-sm btn-primary" data-reanalysis-mode-set="general">Concluir</button>
                </div>
            </div>
        </div>
    @endif
    <div data-reanalysis-mode-only="general">
        <div class="d-flex flex-wrap gap-2">
            @if ($permissions['edit_lead'] && $permissions['create_analysis'])
                <button type="button" class="btn btn-primary" data-analysis-dialog="expense-reanalysis" aria-haspopup="dialog" aria-controls="expense-reanalysis" @disabled(! $actions['expense_reanalysis']['available'])>Reanalisar</button>
            @endif
        </div>
    </div>
    <p class="small text-body-secondary mt-2 mb-0">Altere as despesas para solicitar novas cotações de todas as companhias ou somente das escolhidas. Companhias ainda em análise aguardam o resultado atual.</p>
</footer>
<div data-analysis-component="expense-form">
    @if ($actions['expense_reanalysis']['available'] || $actions['save_expenses']['available'])
        @include('insurance-analyses.partials.expense-reanalysis', ['expenseAction' => $actions['expense_reanalysis'], 'saveExpenseAction' => $actions['save_expenses'], 'expenses' => $pageData['lead']['despesas'] ?? []])
    @elseif ($errors->expenseReanalysis->any())
        <div class="alert alert-warning" role="alert">{{ $errors->expenseReanalysis->first() }}</div>
    @endif
</div>
