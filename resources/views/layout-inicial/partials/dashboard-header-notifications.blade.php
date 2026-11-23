@php
    $notificationCount = (int) ($notificationCount ?? 0);
    $notificationDescription = $notificationDescription
        ?? 'Acompanhe as novidades do painel.';
    $notificationItemLabel = $notificationItemLabel
        ?? 'nova(s) notificação(ões)';
@endphp

<div class="dropdown dashboard-notification">
    <button
        class="btn dashboard-notification-btn position-relative"
        type="button"
        data-bs-toggle="dropdown"
        aria-expanded="false"
        aria-label="Abrir notificações"
    >
        <i class="bi bi-bell" aria-hidden="true"></i>

        @if ($notificationCount > 0)
            <span class="dashboard-notification-count">
                {{ $notificationCount > 99 ? '99+' : $notificationCount }}
            </span>
        @endif
    </button>

    <div class="dropdown-menu dropdown-menu-end dashboard-notification-menu shadow border-0 rounded-4 p-0">
        <div class="p-3 border-bottom">
            <p class="h6 fw-bold mb-1">Notificações</p>
            <p class="text-muted small mb-0">{{ $notificationDescription }}</p>
        </div>

        <div class="p-3">
            @if ($notificationCount > 0)
                <div class="d-flex gap-3 align-items-start">
                    <span class="dashboard-notification-icon bg-primary-subtle text-primary">
                        <i class="bi bi-person-plus" aria-hidden="true"></i>
                    </span>

                    <div>
                        <div class="fw-semibold">
                            {{ $notificationCount }} {{ $notificationItemLabel }}
                        </div>
                        <div class="small text-muted">
                            Existem leads recentes aguardando acompanhamento.
                        </div>
                        <form method="POST" action="{{ $readNotificationsRoute }}">
                            @csrf
                            <button type="submit" class="btn-link small fw-semibold text-decoration-none bg-transparent border-0 p-0">
                                Ver leads
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <div class="text-center py-3">
                    <i class="bi bi-check-circle text-success fs-4" aria-hidden="true"></i>
                    <p class="small text-muted mb-0 mt-2">
                        Nenhuma nova notificação no momento.
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
