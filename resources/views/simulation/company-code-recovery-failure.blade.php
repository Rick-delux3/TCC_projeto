<svg class="access-icon" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="32" cy="32" r="26"/><path d="M32 15v18l12 7"/><path class="accent" d="M49 7h10v10M59 7l-8 8"/></svg>
<div class="flex flex-col gap-3" role="alert" data-recovery-state="limited">
    <h1 id="recovery-title">Aguarde para solicitar novamente</h1>
    <p>Você atingiu o limite de solicitações. Tente novamente em aproximadamente {{ max(1, (int) ceil(session('company_code_retry_after') / 60)) }} minuto(s).</p>
</div>
<p>Se você já solicitou o código, confira sua caixa de entrada e a pasta de spam enquanto aguarda.</p>
<a class="access-button" href="{{ route('simulation.registered-company.access') }}">Voltar e informar meu código</a>
