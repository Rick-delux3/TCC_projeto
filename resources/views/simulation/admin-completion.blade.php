@extends('layout-inicial.simulation')

@section('content')

<x-simulation-completion>
    <h1 id="completion-title" class="simulation-panel__title h4 mb-3">Lead cadastrado com sucesso!</h1>

    <p class="text-muted mb-2">
        {{ $message }}
    </p>

    <p class="text-muted mb-4">
        O dashboard está sendo atualizado. Esta guia será fechada automaticamente.
    </p>

    <a href="{{ $dashboardUrl }}" class="btn simulation-btn simulation-btn--primary" id="adminSimulationDashboardFallback">
        Voltar ao dashboard
    </a>

    <p class="small text-muted mt-3 mb-0">
        Use o botão acima se o navegador não permitir fechar esta guia.
    </p>
</x-simulation-completion>

<script>
    (function () {
        const completionStorageKey = 'adminSimulationCompletion';
        const dashboardUrl = @json($dashboardUrl);
        const expectedOrigin = window.location.origin;
        const payload = {
            type: 'admin-simulation:completed',
            channel: @json($adminSimulationChannel),
            leadId: @json($leadId),
            message: @json($message),
            receivedAt: Date.now(),
        };

        const storeCompletion = function () {
            try {
                window.sessionStorage.setItem(
                    completionStorageKey,
                    JSON.stringify(payload)
                );
            } catch (error) {
                // O redirecionamento manual continua disponível sem sessionStorage.
            }
        };

        storeCompletion();

        document.getElementById('adminSimulationDashboardFallback')
            ?.addEventListener('click', function (event) {
                storeCompletion();

                if (window.opener && !window.opener.closed) {
                    event.preventDefault();
                    window.opener.focus();
                    window.close();
                }
            });

        if (!window.opener || window.opener.closed) {
            window.location.replace(dashboardUrl);
            return;
        }

        try {
            window.opener.postMessage(payload, expectedOrigin);
            window.opener.focus();

            window.setTimeout(function () {
                window.close();
            }, 150);
        } catch (error) {
            window.location.replace(dashboardUrl);
        }
    })();
</script>

@endsection
