@extends('layout-inicial.index-app')

@section('content')
<style>
    .showcase-cta{
        position: relative;
        overflow: hidden;
        transition: transform 260ms ease, box-shadow 260ms ease, filter 260ms ease;
    }

    .showcase-cta::after{
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(120deg, transparent 18%, rgba(255, 255, 255, 0.22) 50%, transparent 82%);
        transform: translateX(-140%);
        transition: transform 620ms ease;
        pointer-events: none;
    }

    .showcase-cta:hover{
        transform: translateY(-3px);
    }

    .showcase-cta:hover::after{
        transform: translateX(140%);
    }

    .showcase-card{
        position: relative;
        overflow: hidden;
        transition: transform 300ms ease, box-shadow 300ms ease, border-color 300ms ease;
        will-change: transform;
    }

    .showcase-card::after{
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(125deg, transparent 25%, rgba(255, 255, 255, 0.2) 50%, transparent 75%);
        opacity: 0;
        transform: translateX(-120%);
        transition: transform 680ms ease, opacity 240ms ease;
        pointer-events: none;
    }

    .showcase-card:hover{
        transform: translateY(-8px);
        box-shadow: 0 24px 60px rgba(14, 47, 88, 0.18);
    }

    .showcase-card:hover::after{
        opacity: 1;
        transform: translateX(120%);
    }

    .showcase-card:hover .showcase-icon{
        transform: scale(1.08) rotate(-4deg);
        box-shadow: 0 18px 36px rgba(20, 111, 182, 0.16);
    }

    .showcase-card:hover .showcase-metric{
        transform: translateY(-2px);
    }

    .showcase-icon,
    .showcase-metric{
        transition: transform 260ms ease, box-shadow 260ms ease, background-color 260ms ease;
    }

    .showcase-mockup{
        animation: showcaseFloat 7.5s ease-in-out infinite;
    }

    .showcase-mockup-panel{
        transition: transform 280ms ease, box-shadow 280ms ease, border-color 280ms ease;
    }

    .showcase-mockup:hover .showcase-mockup-panel{
        transform: translateY(-6px);
        box-shadow: 0 28px 70px rgba(14, 47, 88, 0.28);
    }

    .showcase-mockup:hover .showcase-metric{
        transform: translateY(-2px) scale(1.02);
    }

    @keyframes showcaseFloat{
        0%, 100%{
            transform: translateY(0px);
        }
        50%{
            transform: translateY(-10px);
        }
    }

    @media (prefers-reduced-motion: reduce){
        .showcase-cta,
        .showcase-card,
        .showcase-icon,
        .showcase-metric,
        .showcase-mockup,
        .showcase-mockup-panel{
            transition: none !important;
            animation: none !important;
            transform: none !important;
        }

        .showcase-cta::after,
        .showcase-card::after{
            display: none !important;
        }
    }
</style>
<section id="hero" class="relative overflow-hidden bg-[linear-gradient(180deg,#08173f_0%,#10316b_54%,#146FB6_100%)] text-zinc-100">
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.12),transparent_28%),radial-gradient(circle_at_82%_18%,rgba(253,30,110,0.18),transparent_18%),radial-gradient(circle_at_50%_100%,rgba(210,234,255,0.18),transparent_26%),linear-gradient(180deg,rgba(3,1,51,0.14)_0%,rgba(3,1,51,0.08)_100%)]"></div>
    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/55 to-transparent"></div>

    <div class="relative mx-auto flex min-h-[calc(100vh-92px)] w-full max-w-7xl items-center px-5 py-16 sm:py-20 lg:px-8 lg:py-24">
        <div class="grid w-full gap-12 lg:grid-cols-[minmax(0,1.618fr)_minmax(0,1fr)] lg:items-center">
            <div class="max-w-3xl">
                <span class="inline-flex items-center rounded-full border border-white/18 bg-white/12 px-4 py-2 text-xs font-semibold uppercase tracking-[0.28em] text-white shadow-[0_12px_30px_rgba(3,1,51,0.18)] backdrop-blur">
                    CRM Imobiliario de Alto Padrao
                </span>

                <h1 class="mt-6 max-w-4xl font-['Playfair_Display'] text-5xl font-bold leading-[1.05] text-white sm:text-6xl">
                    O CRM definitivo para o mercado imobiliario de alto padrao.
                </h1>

                <p class="mt-6 max-w-2xl text-base leading-8 text-blue-50/88">
                    Centralize leads, automatize o relacionamento com locatarios e acompanhe a
                    performance das analises em um ambiente elegante, rapido e pronto para operacoes premium.
                </p>

                <div class="mt-10 flex flex-col gap-4 sm:flex-row">
                    <a href="{{ route('empresa.register.form') }}" class="showcase-cta inline-flex items-center justify-center rounded-full border border-white/12 bg-gradient-to-r from-[#030133] via-[#145ca5] to-[#146FB6] px-7 py-3.5 text-base font-semibold text-white shadow-[0_18px_45px_rgba(3,1,51,0.28)] transition hover:-translate-y-0.5 hover:brightness-105">
                        Assinar Agora
                    </a>
                    <a href="{{ route('empresa.login') }}" class="showcase-cta inline-flex items-center justify-center rounded-full border border-white/18 bg-white/10 px-7 py-3.5 text-base font-semibold text-white transition hover:border-white/26 hover:bg-white/16">
                        Login
                    </a>
                </div>

                <div id="beneficios" class="mt-12 grid gap-4 sm:grid-cols-3">
                    <div class="showcase-card rounded-3xl border border-white/12 bg-white/12 p-5 shadow-[0_16px_42px_rgba(3,1,51,0.18)] backdrop-blur">
                        <p class="showcase-metric text-3xl font-semibold text-white">+38%</p>
                        <p class="mt-2 text-sm text-blue-50/78">mais velocidade no tratamento de oportunidades qualificadas.</p>
                    </div>
                    <div class="showcase-card rounded-3xl border border-white/12 bg-[linear-gradient(180deg,rgba(255,255,255,0.16),rgba(255,255,255,0.08))] p-5 shadow-[0_16px_42px_rgba(3,1,51,0.18)] backdrop-blur">
                        <p class="showcase-metric text-3xl font-semibold text-white">1 painel</p>
                        <p class="mt-2 text-sm text-blue-50/78">para acompanhar leads, seguros, tarefas e contratos.</p>
                    </div>
                    <div class="showcase-card rounded-3xl border border-[#FD1E6E]/18 bg-[linear-gradient(180deg,rgba(253,30,110,0.16),rgba(255,255,255,0.06))] p-5 shadow-[0_16px_42px_rgba(3,1,51,0.18)] backdrop-blur">
                        <p class="showcase-metric text-3xl font-semibold text-white">24/7</p>
                        <p class="mt-2 text-sm text-blue-50/78">sincronizacao operacional para equipes comerciais e administrativas.</p>
                    </div>
                </div>
            </div>

            <div class="showcase-mockup relative">
                <div class="absolute -inset-6 rounded-[2rem] bg-gradient-to-br from-white/18 via-[#93d1ff]/10 to-[#FD1E6E]/14 blur-3xl"></div>
                <div class="showcase-mockup-panel relative mx-auto max-w-md rounded-[2rem] border border-[#146FB6]/14 bg-[linear-gradient(180deg,rgba(255,255,255,0.96),rgba(235,244,252,0.96))] p-5 shadow-[0_25px_70px_rgba(14,47,88,0.24)] backdrop-blur">
                    <div class="flex items-center justify-between border-b border-[#146FB6]/10 pb-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">Painel Executivo</p>
                            <h2 class="mt-2 text-3xl font-semibold text-[#030133]">Portfolio Prime</h2>
                        </div>
                        <div class="showcase-metric rounded-2xl border border-[#FD1E6E]/18 bg-[#fff2f7] px-3 py-2 text-right shadow-[0_10px_24px_rgba(20,111,182,0.08)]">
                            <p class="text-[10px] uppercase tracking-[0.24em] text-[#FD1E6E]">Conversao</p>
                            <p class="mt-1 text-xl font-semibold text-[#030133]">72%</p>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4">
                        <div class="showcase-card rounded-3xl border border-[#146FB6]/12 bg-white p-4 shadow-[0_12px_28px_rgba(20,111,182,0.08)]">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Funil de Leads</p>
                                    <p class="mt-2 text-2xl font-semibold text-[#030133]">128 ativos</p>
                                </div>
                                <div class="flex items-end gap-1">
                                    <span class="h-7 w-2 rounded-full bg-[#7eb4df]"></span>
                                    <span class="h-10 w-2 rounded-full bg-[#146FB6]"></span>
                                    <span class="h-14 w-2 rounded-full bg-[#030133]"></span>
                                    <span class="h-9 w-2 rounded-full bg-[#FD1E6E]"></span>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="showcase-card rounded-3xl border border-[#146FB6]/12 bg-[linear-gradient(180deg,#f8fbff_0%,#edf5fe_100%)] p-4">
                                <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Tickets</p>
                                <p class="mt-2 text-2xl font-semibold text-[#030133]">R$ 2,4 mi</p>
                                <p class="mt-2 text-sm text-[#146FB6]">+12% no ultimo mes</p>
                            </div>
                            <div class="showcase-card rounded-3xl border border-[#FD1E6E]/10 bg-[linear-gradient(180deg,#fff8fb_0%,#fff2f7_100%)] p-4">
                                <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Imoveis</p>
                                <p class="mt-2 text-2xl font-semibold text-[#030133]">316</p>
                                <p class="mt-2 text-sm text-slate-600">curadoria em tempo real</p>
                            </div>
                        </div>

                        <div class="showcase-card rounded-3xl border border-[#146FB6]/12 bg-white p-4 shadow-[0_12px_28px_rgba(20,111,182,0.08)]">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Relatorios inteligentes</p>
                                    <p class="mt-2 text-base font-semibold text-[#030133]">Dashboard, comissao e risco em um unico fluxo.</p>
                                </div>
                                <div class="showcase-icon h-14 w-14 rounded-full border border-[#146FB6]/12 bg-[#eff7ff] p-3">
                                    <div class="h-full w-full rounded-full bg-gradient-to-br from-[#030133] via-[#146FB6] to-[#FD1E6E]"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="features" class="bg-[linear-gradient(180deg,#edf4fb_0%,#dfeaf5_100%)] text-slate-900">
    <div class="mx-auto max-w-7xl px-5 py-20 lg:px-8">
        <div class="max-w-3xl">
            <span class="text-sm font-semibold uppercase tracking-[0.3em] text-[#146FB6]">Por que escolher</span>
            <h2 class="mt-4 font-['Playfair_Display'] text-3xl font-bold text-[#030133]">
                Uma vitrine operacional desenhada para imobiliarias que vendem experiencia.
            </h2>
            <p class="mt-5 text-base leading-8 text-slate-600">
                Cada recurso foi pensado para acelerar o comercial, sofisticar o atendimento e dar visibilidade executiva
                sobre carteira, conversao e recorrencia.
            </p>
        </div>

        <div class="mt-12 grid gap-6 lg:grid-cols-3">
            <article class="showcase-card rounded-[1.75rem] border border-[#146FB6]/10 bg-white p-7 shadow-[0_18px_50px_rgba(15,76,129,0.10)]">
                <div class="showcase-icon flex h-14 w-14 items-center justify-center rounded-2xl border border-[#146FB6]/16 bg-[#eef6ff] text-[#146FB6]">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12a9 9 0 1 1-9-9" />
                        <path d="M21 3v9h-9" />
                        <path d="M12 7v5l3 3" />
                    </svg>
                </div>
                <h3 class="mt-6 text-3xl font-semibold text-[#030133]">Sincronizacao em Tempo Real</h3>
                <p class="mt-4 text-base leading-7 text-slate-600">
                    Atualize funil, contratos e analises instantaneamente para manter equipes comerciais e operacionais no mesmo ritmo.
                </p>
            </article>

            <article class="showcase-card rounded-[1.75rem] border border-[#FD1E6E]/10 bg-white p-7 shadow-[0_18px_50px_rgba(15,76,129,0.10)]">
                <div class="showcase-icon flex h-14 w-14 items-center justify-center rounded-2xl border border-[#FD1E6E]/16 bg-[#fff2f7] text-[#FD1E6E]">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                    </svg>
                </div>
                <h3 class="mt-6 text-3xl font-semibold text-[#030133]">Gestao de Leads</h3>
                <p class="mt-4 text-base leading-7 text-slate-600">
                    Priorize oportunidades, acompanhe origem, status e performance do relacionamento com visao clara do pipeline.
                </p>
            </article>

            <article class="showcase-card rounded-[1.75rem] border border-[#55658C]/12 bg-white p-7 shadow-[0_18px_50px_rgba(15,76,129,0.10)]">
                <div class="showcase-icon flex h-14 w-14 items-center justify-center rounded-2xl border border-[#55658C]/16 bg-[#f1f4fa] text-[#55658C]">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 3v18h18" />
                        <path d="M7 14.5 10.5 11 13 13.5l5-5" />
                        <path d="M17 8h1v1" />
                    </svg>
                </div>
                <h3 class="mt-6 text-3xl font-semibold text-[#030133]">Analise de Alugueis</h3>
                <p class="mt-4 text-base leading-7 text-slate-600">
                    Consolide indicadores de risco, previsibilidade de receita e produtividade do time com paineis objetivos.
                </p>
            </article>
        </div>
    </div>
</section>

<footer class="border-t border-[#146FB6]/14 bg-[linear-gradient(180deg,#030133_0%,#0c2f59_100%)] text-blue-50/88">
    <div class="mx-auto flex max-w-7xl flex-col gap-4 px-5 py-8 text-sm sm:flex-row sm:items-center sm:justify-between lg:px-8">
        <p>NVS Seguros CRM. Operacao imobiliaria com design, controle e performance.</p>
        <p class="text-blue-100/70">&copy; {{ now()->year }} Todos os direitos reservados.</p>
    </div>
</footer>
@endsection
