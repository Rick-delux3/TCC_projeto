@php($retryMinutes = max(1, (int) ceil(session('company_code_retry_after') / 60)))

<div class="registered-access__intro" role="alert" data-recovery-state="limited">
    <p class="registered-access__context">Recuperação de código</p>
    <h1 id="recovery-title">Aguarde para solicitar novamente</h1>
    <p>Você atingiu o limite de solicitações. Tente novamente em cerca de {{ $retryMinutes }} {{ $retryMinutes === 1 ? 'minuto' : 'minutos' }}.</p>
    <p class="registered-access__notice">Se você já solicitou o código, confira a caixa de entrada e a pasta de spam enquanto aguarda.</p>
</div>

<a class="registered-access__submit" href="{{ route('simulation.registered-company.access') }}">
    Voltar para o acesso
</a>
