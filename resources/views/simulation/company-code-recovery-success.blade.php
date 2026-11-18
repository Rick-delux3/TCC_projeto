<div class="registered-access__intro" role="status" data-recovery-state="success">
    <p class="registered-access__context">Recuperação de código</p>
    <h1 id="recovery-title">Solicitação recebida</h1>
    <p>{{ session('status') }}</p>
    <p class="registered-access__notice">Confira a caixa de entrada e a pasta de spam. O envio pode levar alguns minutos.</p>
</div>

<a class="registered-access__submit" href="{{ route('simulation.registered-company.access') }}">
    Já tenho meu código
</a>
