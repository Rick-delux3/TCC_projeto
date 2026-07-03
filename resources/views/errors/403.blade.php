@extends('layout-inicial.app')

@section('content')
<div class="container py-4 py-lg-5" style="font-family: 'Sansation', sans-serif;">
    <section class="row g-0 overflow-hidden bg-white border shadow-lg rounded-3 mx-auto" style="max-width: 960px;">
        <aside
            class="col-lg-5 position-relative overflow-hidden text-white"
            style="min-height: 420px; background: linear-gradient(150deg, #030133 0%, #0c1d72 54%, #146FB6 100%);"
        >
            <img
                src="{{ asset('imgs/divulgar-imoveis-online-site-para-imobiliaria.jpg') }}"
                alt="Acesso bloqueado"
                class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover"
            >

            <div
                class="position-absolute top-0 start-0 w-100 h-100"
                style="background: linear-gradient(180deg, rgba(19, 37, 66, 0.16), rgba(16, 27, 55, 0.88));"
            ></div>

            <img
                src="{{ asset('imgs/Logo_NVS.png') }}"
                alt="Logo NVS"
                class="position-absolute top-0 start-0 m-3"
                style="width: 46px; height: 46px; object-fit: contain; filter: drop-shadow(0 8px 16px rgba(7, 15, 35, 0.3));"
            >

            <div class="position-relative d-flex h-100 flex-column justify-content-end p-4 p-md-5" style="min-height: 420px;">
                <span class="badge rounded-pill text-bg-danger align-self-start mb-3 px-3 py-2 text-uppercase">
                    Acesso restrito
                </span>

                <h2 class="h3 fw-bold mb-3">Área protegida da plataforma</h2>

                <p class="mb-0 text-white-50">
                    Este ambiente é reservado para acessos autorizados da corretora.
                </p>
            </div>
        </aside>

        <div class="col-lg-7 d-flex flex-column justify-content-center p-4 p-md-5">
            <header class="mb-4">
                <span
                    class="badge rounded-pill mb-3 px-3 py-2 text-uppercase"
                    style="background: rgba(253, 30, 110, 0.12); color: #FD1E6E;"
                >
                    Erro 403
                </span>

                <h1 class="display-6 fw-bold mb-3" style="color: #030133;">
                    Acesso bloqueado
                </h1>

                <p class="text-secondary mb-0">
                    {{ $exception->getMessage() ?: 'Você não tem permissão para acessar esta página.' }}
                </p>
            </header>

            <div class="alert alert-light border d-flex align-items-start gap-2 py-3" role="alert">
                <span class="badge text-bg-danger flex-shrink-0">NVS</span>
                <small class="text-secondary">
                    Se você acredita que deveria ter acesso, entre em contato com o responsável pela plataforma.
                </small>
            </div>

            <div class="d-grid gap-2">
                <a
                    href="{{ route('admin.login') }}"
                    class="btn btn-lg text-white fw-bold"
                    style="border: 0; background: linear-gradient(125deg, #030133, #146FB6);"
                >
                    Ir para login
                </a>

                <a
                    href="{{ route('index') }}"
                    class="btn btn-lg fw-bold"
                    style="--bs-btn-color: #030133; --bs-btn-border-color: rgba(3, 1, 51, 0.35); --bs-btn-hover-bg: #030133; --bs-btn-hover-border-color: #030133; --bs-btn-hover-color: #ffffff;"
                >
                    Voltar para o início
                </a>
            </div>
        </div>
    </section>
</div>
@endsection
