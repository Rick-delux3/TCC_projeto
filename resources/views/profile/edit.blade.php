@extends('layout-inicial.dashboard_User')

@push('dashboard-styles')
    @vite('resources/css/profile.css')
@endpush

@section('content_w')
    @php
        $companyName = $user->company?->name;
        $initials = collect(preg_split('/\s+/u', trim($user->name), -1, PREG_SPLIT_NO_EMPTY))
            ->take(2)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
        $statusMessage = match (session('status')) {
            'profile-updated' => 'Dados da conta salvos.',
            'password-updated' => 'Senha atualizada com sucesso.',
            default => null,
        };
    @endphp

    <div id="dashboardThemeRoot" class="account-profile" data-dashboard-theme="light">
        <div class="profile-container">
            <a href="{{ route('company.dashboard') }}" class="profile-back">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                Voltar ao painel
            </a>

            <header class="profile-heading">
                <div class="profile-heading__copy">
                    <h1>Minha conta</h1>
                    <p>Gerencie seu perfil e a senha do painel.</p>
                </div>

                <div class="profile-identity">
                    <span class="profile-avatar" aria-hidden="true">{{ $initials ?: '—' }}</span>
                    <div class="profile-identity__copy">
                        <strong>{{ $user->name }}</strong>
                        @if (filled($companyName))
                            <span>{{ $companyName }}</span>
                        @endif
                    </div>
                </div>
            </header>

            @if ($statusMessage)
                <div class="profile-notice" role="status">
                    <i class="bi bi-check-circle" aria-hidden="true"></i>
                    <span>{{ $statusMessage }}</span>
                </div>
            @endif

            <div class="profile-settings">
                @include('profile.partials.update-profile-information-form')
                @include('profile.partials.update-password-form')
            </div>

            @include('profile.partials.delete-user-form')
        </div>
    </div>
@endsection
