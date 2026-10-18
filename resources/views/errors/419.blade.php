@extends('layout-inicial.app')

@section('body-class', config('branding.active', 'tcc') === 'tcc' ? 'tcc-error-body' : '')
@section('main-class', config('branding.active', 'tcc') === 'tcc' ? 'tcc-error-main' : '')
@section('hide-header', config('branding.active', 'tcc') === 'tcc' ? 'true' : 'false')

@section('content')
<div class="error-page container py-4 py-lg-5" style="font-family: 'Sansation', sans-serif;">
    <section class="error-shell row g-0 overflow-hidden bg-white border shadow-lg rounded-3 mx-auto" style="max-width: 960px;">
        <aside
            class="error-aside col-lg-5 position-relative overflow-hidden text-white"
            style="min-height: 420px; background: linear-gradient(150deg, #030133 0%, #0c1d72 54%, #146FB6 100%);"
        >
            @if (config('branding.active', 'tcc') === 'tcc')
                <img src="{{ asset('imgs/Logo_NVS.png') }}" class="error-background-logo" alt="" aria-hidden="true">
                <div class="error-logo-overlay" aria-hidden="true"></div>
            @else
            <img
                src="{{ asset('imgs/segure-chave-a-mao-ao-ar-livre.jpg') }}"
                alt="Sessão expirada"
                class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover"
            >

            <div
                class="position-absolute top-0 start-0 w-100 h-100"
                style="background: linear-gradient(180deg, rgba(19, 37, 66, 0.16), rgba(16, 27, 55, 0.88));"
            ></div>

            <x-brand-logo
                class="position-absolute top-0 start-0 m-3"
                style="width: 46px; height: 46px; object-fit: contain; filter: drop-shadow(0 8px 16px rgba(7, 15, 35, 0.3));"
            />

            @endif

            <div class="error-aside-copy position-relative d-flex h-100 flex-column justify-content-end p-4 p-md-5" style="min-height: 420px;">
                <span class="badge rounded-pill text-bg-danger align-self-start mb-3 px-3 py-2 text-uppercase">
                    Sessão protegida
                </span>

                <h2 class="h3 fw-bold mb-3">Tempo de acesso encerrado</h2>

                <p class="mb-0 text-white-50">
                    Por segurança, sessões inativas são finalizadas para preservar
                    dados de clientes, leads e operações internas.
                </p>
            </div>
        </aside>

        <div class="error-content col-lg-7 d-flex flex-column justify-content-center p-4 p-md-5">
            <header class="mb-4">
                <span
                    class="badge rounded-pill mb-3 px-3 py-2 text-uppercase"
                    style="background: rgba(253, 30, 110, 0.12); color: #FD1E6E;"
                >
                    Erro 419
                </span>

                <h1 class="display-6 fw-bold mb-3" style="color: #030133;">
                    Sessão expirada
                </h1>

                <p class="text-secondary mb-0">
                    Sua sessão expirou. Entre novamente para continuar com segurança.
                </p>
            </header>

            <div class="alert alert-warning d-flex align-items-start gap-2 py-3" role="alert">
                <span class="badge text-bg-dark flex-shrink-0">Atenção</span>
                <small>
                    Reabra o acesso antes de enviar novos formulários ou consultar informações da plataforma.
                </small>
            </div>

            <div class="d-grid gap-2">
                <a
                    href="{{ route('empresa.login') }}"
                    class="btn btn-lg text-white fw-bold"
                    style="border: 0; background: linear-gradient(125deg, #030133, #146FB6);"
                >
                    Entrar novamente
                </a>

                <a
                    href="{{ route('index') }}"
                    class="btn btn-lg fw-bold"
                    style="--bs-btn-color: #030133; --bs-btn-border-color: rgba(3, 1, 51, 0.35); --bs-btn-hover-bg: #030133; --bs-btn-hover-border-color: #030133; --bs-btn-hover-color: #ffffff;"
                >
                    Ir para o início
                </a>
            </div>
        </div>
    </section>
</div>
@endsection
