<nav class="navbar navbar-expand-lg py-3">
    <div class="container">

        <!-- Logo -->
        <a class="navbar-brand d-flex align-items-center" href="#">
            <img src="{{ asset('imgs/logo-header.jpg') }}" alt="Logo" width="110" class="me-2">
        </a>

        <!-- Botão de abrir menu no mobile -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMenu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Links -->
        <div class="background-nav collapse navbar-collapse" id="navbarMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center">

                <li class="nav-item mx-2">
                    <a class="nav-link" href="{{ route('empresa.logout') }}">Sair</a>
                </li>

                <li class="nav-item mx-2">
                    <a class="nav-link" href="#">Companhias</a>
                </li>

                <li class="nav-item mx-2">
                    <a class="nav-link" href="#">Quem somos?</a>
                </li>

                <!-- BOTÃO DE WHATSAPP -->
                <li class="nav-item ms-lg-3 mt-3 mt-lg-0">
                    <a href="https://api.whatsapp.com/send?phone=5511999999999&text=Olá,%20gostaria%20de%20ajuda"
                       class="btn-style px-4">
                        Conversar com o corretor
                    </a>
                </li>

            </ul>
        </div>

    </div>
</nav>