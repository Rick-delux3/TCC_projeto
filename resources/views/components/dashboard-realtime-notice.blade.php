<div
    id="dashboardRealtimeNotice"
    class="alert alert-info border-0 shadow position-fixed bottom-0 end-0 m-3 d-none"
    style="z-index: 1090; max-width: min(32rem, calc(100vw - 2rem));"
    role="status"
    aria-live="polite"
>
    <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-3">
        <div class="flex-grow-1">
            <i class="bi bi-broadcast me-1" aria-hidden="true"></i>

            <span id="dashboardRealtimeMessage">
                Novos dados recebidos.
            </span>
        </div>

        <button
            id="dashboardRealtimeReloadButton"
            type="button"
            class="btn btn-sm btn-primary text-nowrap"
        >
            Atualizar agora
        </button>
    </div>
</div>