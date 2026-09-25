<header class="fixed top-0 z-50 w-full bg-[#030133] text-white shadow-md">
    <div class="mx-auto grid max-w-7xl grid-cols-[1fr_auto] items-center px-4 sm:px-6 lg:grid-cols-[1fr_auto_1fr] lg:gap-8 lg:px-8">
        <a href="{{ route('index') }}" class="flex h-20 min-w-0 items-center gap-3" aria-label="NVS Seguros — início">
            <x-brand-logo profile="tcc" class="h-14 w-14 shrink-0 object-contain" />
            <div class="flex min-w-0 flex-col gap-1 pr-2">
                <span class="text-xs font-bold leading-tight tracking-wide sm:text-lg">NVS SEGUROS</span>
                <span class="text-[8px] font-medium tracking-wide text-slate-300 sm:text-[10px] sm:tracking-widest">CRM IMOBILIÁRIO PREMIUM</span>
            </div>
        </a>

        <nav aria-label="Navegação principal" class="order-last col-span-2 flex h-11 items-center justify-between gap-3 border-t border-white/10 lg:order-none lg:col-span-1 lg:h-20 lg:gap-8 lg:border-0">
            <a href="#inicio" class="py-3 text-sm font-medium text-slate-300 transition-colors hover:text-white">Início</a>
            <a href="#solucoes" class="py-3 text-sm font-medium text-slate-300 transition-colors hover:text-white">Soluções</a>
            <a href="#recursos" class="py-3 text-sm font-medium text-slate-300 transition-colors hover:text-white">Recursos</a>
            <a href="#suporte" class="py-3 text-sm font-medium text-slate-300 transition-colors hover:text-white">Suporte</a>
        </nav>

        <a href="{{ route('empresa.login') }}" class="justify-self-end rounded-full bg-[#146FB6] px-6 py-2.5 text-sm font-medium text-white shadow-lg transition-all duration-300 hover:bg-blue-600 hover:shadow-xl">
            Entrar
        </a>
    </div>
</header>
