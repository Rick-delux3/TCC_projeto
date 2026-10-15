<dialog class="simulation-constitution-modal w-full max-w-md rounded-xl border p-6" data-company-constitution-modal
    aria-labelledby="company-constitution-title" aria-describedby="company-constitution-message">
    <div class="flex flex-col gap-5">
        <h2 id="company-constitution-title" class="text-xl font-semibold">Análise com CPF</h2>
        <p id="company-constitution-message" class="text-sm leading-relaxed" data-company-constitution-message>{{ \App\Support\CompanyConstitution::REJECTION_MESSAGE }}</p>
        <div class="flex flex-col gap-3">
            <button type="button" class="simulation-button simulation-button-primary" data-company-constitution-correct autofocus>Ir para o campo CPF/CNPJ</button>
            <button type="button" class="simulation-button simulation-button-outline" data-company-constitution-close>Voltar ao formulário</button>
        </div>
    </div>
</dialog>
