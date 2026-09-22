<!DOCTYPE html>
<html lang="pt-BR" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Conecte sua imobiliária, seus clientes e seguradoras. Conheça a plataforma NVS Seguros e inicie sua simulação de seguro.">
    <x-brand-favicon profile="tcc" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sansation:wght@300;400;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/css/tcc-index.css', 'resources/js/app.js'])
    <title>NVS Seguros — CRM Imobiliário</title>
</head>
<body class="nvs-index bg-slate-50 text-slate-800 antialiased">
    <a href="#inicio" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-xl focus:bg-white focus:p-4 focus:text-[#030133]">Ir para o conteúdo</a>
    @include('layout-inicial.partials.tcc-index-header')

    <main class="pt-[124px] lg:pt-20">
        @yield('content')
    </main>
</body>
</html>
