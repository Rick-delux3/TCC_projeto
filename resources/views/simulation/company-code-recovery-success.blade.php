<svg class="access-icon" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="12" width="48" height="36" rx="3"/><path d="m4 15 24 18 24-18"/><circle cx="47" cy="46" r="13" fill="white"/><path class="accent" d="m40 46 5 5 9-10"/></svg>
<div class="flex flex-col gap-3" role="status" data-recovery-state="success">
    <h1 id="recovery-title">Solicitação recebida</h1>
    <p>{{ session('status') }}</p>
</div>
<p>Confira sua caixa de entrada e a pasta de spam. O envio pode levar alguns minutos.</p>
<a class="access-button" href="{{ route('simulation.registered-company.access') }}">Já tenho meu código</a>
