<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @vite(['resources/css/form-register.css'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400..900&family=Press+Start+2P&family=Roboto+Condensed:ital,wght@0,100..900;1,100..900&family=Sansation:ital,wght@0,300;0,400;0,700;1,300;1,400;1,700&family=TASA+Explorer:wght@400..800&display=swap" rel="stylesheet">
    <title>AkiAluga</title>
</head>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            @if ($errors->any())
                var modalErros = new bootstrap.Modal(document.getElementById('modalErros'));
                modalErros.show();
            @endif

            @if (session('success'))
                let ModalSuccess = new bootstrap.Modal(document.getElementById('ModalSucess'));
                ModalSuccess.show();
            @endif
        });
    </script>
<body> 
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>

    @if ($errors->any())
        <div class="modal-error modal fade" id="modalErros" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Erro no formulário</h5>
                </div>

                <div class="modal-body">
                    @foreach ($errors->all() as $erro)
                        <p>• {{ $erro }}</p>
                    @endforeach
                </div>

                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Entendi</button>
                </div>

                </div>
            </div>
        </div>
    @endif
    @if (session('success'))
            <div class="modal fade" id="ModalSucess" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5" id="staticBackdropLabel">Sucesso!!</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                       {{ session('success') }}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    </div>
                    </div>
                </div>
            </div>  
    @endif

   @include('layout-inicial.partials.header_app')

    <main>
        @yield('content')
    </main>

    @include('partials.page-loader')
</body>
</html>
