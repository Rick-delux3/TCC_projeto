<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <x-brand-favicon />

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" 
        rel="stylesheet" 
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" 
        crossorigin="anonymous"
    >

    @vite(['resources/css/simulation.css', 'resources/css/branding.css', 'resources/js/simulation.js'])

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link 
        href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400..900&family=Press+Start+2P&family=Roboto+Condensed:ital,wght@0,100..900;1,100..900&family=Sansation:ital,wght@0,300;0,400;0,700;1,300;1,400;1,700&family=TASA+Explorer:wght@400..800&display=swap" 
        rel="stylesheet"
    >

    <title>{{ config('branding.profiles.'.config('branding.active', 'tcc').'.name', 'NVS Seguros') }}</title>
    @stack('styles')
</head>

<body class="auth-layout-body simulation-public" data-brand="{{ config('branding.active', 'tcc') }}">

    <main class="auth-layout-main @yield('main-class')">
        @yield('content')
    </main>

    @if ($errors->any() && ! View::hasSection('inline-feedback'))
        <x-task-message id="modalErrors" title="Erro no formulário" tone="error" header-class="bg-danger text-white">
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-task-message>
    @endif

    @if (session('success'))
        <x-task-message id="modalSuccess" title="Sucesso" tone="success" action="Fechar" header-class="bg-success text-white">
            {{ session('success') }}
        </x-task-message>
    @endif

    @include('partials.page-loader')

    <script 
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" 
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" 
        crossorigin="anonymous">
    </script>

    @stack('scripts')
</body>
</html>
