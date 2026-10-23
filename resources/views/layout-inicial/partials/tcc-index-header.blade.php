<header class="fixed top-0 z-50 w-full bg-[#030133] text-white shadow-md">
    <div class="mx-auto grid max-w-7xl grid-cols-[minmax(0,1fr)_auto] items-center px-4 sm:px-6 lg:grid-cols-[minmax(0,1fr)_auto_auto] lg:gap-6 lg:px-8">
        <a href="{{ route('index') }}" class="flex h-20 min-w-0 items-center gap-3" aria-label="NVS — início">
            <x-brand-logo profile="tcc" class="h-10 w-10 shrink-0 object-contain sm:h-14 sm:w-14" />
            <div class="flex min-w-0 flex-col gap-1 pr-2">
                <span class="text-xs font-bold leading-tight tracking-wide sm:text-lg">NVS</span>
                <span class="text-[8px] font-medium tracking-wide text-slate-300 sm:text-[10px] sm:tracking-widest">CRM EMPRESARIAL</span>
            </div>
        </a>

        <nav aria-label="Navegação principal" class="order-last col-span-2 flex h-11 items-center justify-between gap-3 border-t border-white/10 lg:order-none lg:col-span-1 lg:h-20 lg:gap-8 lg:border-0">
            <a href="#inicio" class="py-3 text-sm font-medium text-slate-300 transition-colors hover:text-white">Início</a>
            <a href="#solucoes" class="py-3 text-sm font-medium text-slate-300 transition-colors hover:text-white">Soluções</a>
            <a href="#recursos" class="py-3 text-sm font-medium text-slate-300 transition-colors hover:text-white">Recursos</a>
            <a href="#suporte" class="py-3 text-sm font-medium text-slate-300 transition-colors hover:text-white">Suporte</a>
        </nav>

        <div class="flex w-36 flex-col items-end gap-1 justify-self-end sm:w-auto sm:flex-row sm:items-center sm:gap-3">
            <a href="{{ route('empresa.login') }}" data-auth-access target="_blank" rel="noopener noreferrer" aria-label="Entrar (abre em nova guia)" class="inline-flex min-h-8 items-center justify-center rounded-full bg-[#146FB6] px-4 py-1.5 text-xs font-medium text-white transition-colors hover:bg-blue-600 sm:min-h-10 sm:px-6 sm:py-2.5 sm:text-sm">
                Entrar
            </a>
            <a href="{{ route('tcc.admin.access') }}" data-auth-access target="_blank" rel="noopener noreferrer" aria-label="Entrar como administrador (abre em nova guia)" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-full border border-white/20 bg-[#d9155f] px-3 py-1 text-center text-xs font-semibold leading-4 text-white shadow-[0_4px_14px_rgba(217,21,95,0.22)] transition duration-200 hover:border-white/40 hover:bg-[#bd1051] motion-safe:hover:-translate-y-0.5 motion-safe:active:translate-y-0 sm:whitespace-nowrap sm:px-5 sm:py-2.5 sm:text-sm">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true" focusable="false">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 4 6v6c0 4 4 7 8 9 4-2 8-5 8-9V6l-8-3Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12 2.5 2.5 4.5-5" />
                </svg>
                <span>Entrar como administrador</span>
            </a>
        </div>
    </div>
</header>
