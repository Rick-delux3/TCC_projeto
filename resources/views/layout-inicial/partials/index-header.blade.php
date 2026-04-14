<header class="sticky top-0 z-50 border-b border-white/10 bg-[linear-gradient(90deg,rgba(3,1,51,0.94)_0%,rgba(12,47,89,0.92)_58%,rgba(20,111,182,0.9)_100%)] shadow-[0_18px_40px_rgba(3,1,51,0.18)] backdrop-blur-xl">
    <div class="mx-auto flex w-full max-w-7xl items-center justify-between gap-6 px-5 py-4 lg:px-8">
        <a href="{{ route('index') }}" class="flex items-center gap-3 text-decoration-none">
            <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="NVS Seguros" class="h-14 w-auto drop-shadow-[0_10px_18px_rgba(0,0,0,0.35)] sm:h-16">
            <div class="hidden sm:block">
                <strong class="block font-['Playfair_Display'] text-lg font-bold tracking-[0.12em] text-white">NVS SEGUROS</strong>
                <span class="block text-xs font-medium uppercase tracking-[0.32em] text-blue-50/78">CRM Imobiliario Premium</span>
            </div>
        </a>

        <nav class="hidden items-center gap-8 lg:flex">
            <a href="#hero" class="text-sm font-medium text-blue-50/74 transition hover:text-white">Inicio</a>
            <a href="#beneficios" class="text-sm font-medium text-blue-50/74 transition hover:text-white">Beneficios</a>
            <a href="#features" class="text-sm font-medium text-blue-50/74 transition hover:text-white">Recursos</a>
        </nav>

        <div class="hidden items-center gap-3 lg:flex">
            <a href="{{ route('empresa.login') }}" class="text-sm font-semibold text-white transition hover:text-[#ffd8e8]">
                Login
            </a>
            <a href="{{ route('empresa.register.form') }}" class="rounded-full border border-white/14 bg-white/10 px-5 py-2.5 text-sm font-semibold text-white shadow-[0_12px_30px_rgba(3,1,51,0.18)] transition hover:bg-white/16">
                Assinar Agora
            </a>
        </div>

        <button
            class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-white/14 bg-white/10 text-white lg:hidden"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#indexMenu"
            aria-controls="indexMenu"
            aria-expanded="false"
            aria-label="Abrir menu"
        >
            <i class="bi bi-list text-2xl"></i>
        </button>
    </div>

    <div class="collapse border-t border-white/10 bg-[linear-gradient(180deg,rgba(3,1,51,0.96)_0%,rgba(12,47,89,0.96)_100%)] px-5 py-4 backdrop-blur-xl lg:hidden" id="indexMenu">
        <nav class="mx-auto flex max-w-7xl flex-col gap-3">
            <a href="#hero" class="rounded-xl px-3 py-2 text-sm font-medium text-white transition hover:bg-white/10">Inicio</a>
            <a href="#beneficios" class="rounded-xl px-3 py-2 text-sm font-medium text-white transition hover:bg-white/10">Beneficios</a>
            <a href="#features" class="rounded-xl px-3 py-2 text-sm font-medium text-white transition hover:bg-white/10">Recursos</a>
            <a href="{{ route('empresa.login') }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/10">Login</a>
            <a href="{{ route('empresa.register.form') }}" class="mt-2 inline-flex items-center justify-center rounded-full border border-white/14 bg-white/10 px-5 py-3 text-sm font-semibold text-white shadow-[0_12px_30px_rgba(3,1,51,0.18)] transition hover:bg-white/16">
                Assinar Agora
            </a>
        </nav>
    </div>
</header>
