<!DOCTYPE html>
<html lang="pt-BR" data-type="software">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="{{ config('branding.profiles.tcc.colors.primary') }}">
    <meta name="color-scheme" content="dark">
    <meta name="page-type" content="software">
    <meta name="site-kind" content="software">
    <meta name="description" content="Centralize solicitações de seguro para aluguel e acompanhe cada atendimento no painel da sua imobiliária.">
    <x-brand-favicon profile="tcc" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sansation:wght@400;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/css/tcc-index.css', 'resources/js/app.js'])
    <title>NVS Seguros · Seguro para aluguel</title>
</head>
<body class="nvs-index antialiased">
    <a href="#inicio" class="nvs-skip sr-only focus:not-sr-only">Ir para o conteúdo</a>
    @include('layout-inicial.partials.tcc-index-header')
    <main class="nvs-main">
        @yield('content')
    </main>
    <footer class="nvs-footer">
        <div class="nvs-wrap flex flex-wrap items-center justify-between gap-5">
            <span>© {{ now()->year }} NVS Seguros</span>
            <a href="#inicio" class="nvs-link">Voltar ao início <span aria-hidden="true">↑</span></a>
        </div>
    </footer>
</body>
</html>
