@php
    $activeBrandProfile = config('branding.active', 'tcc');
    $activeBrandName = config("branding.profiles.{$activeBrandProfile}.name", 'NVS Seguros');
@endphp

@once
    <style>
        .auth-topbar {
            --auth-topbar-height: clamp(76px, 6.4vw, 108px);
            position: relative;
            z-index: 1020;
            width: 100%;
            color: var(--brand-primary-dark, #030133);
            border-bottom: 1px solid var(--brand-border, #d8e1ec);
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(var(--brand-primary-rgb, 3, 1, 51), 0.045);
        }

        .auth-topbar__inner {
            width: 100%;
            max-width: 1680px;
            min-height: var(--auth-topbar-height);
            margin: 0 auto;
            padding: 0 clamp(24px, 4vw, 58px);
            display: flex;
            align-items: center;
        }

        body[data-brand] .auth-topbar__surface {
            width: 100%;
            max-width: none;
            min-height: var(--auth-topbar-height);
            padding: 0;
            display: flex;
            align-items: center;
            gap: clamp(24px, 3vw, 46px);
            border: 0 !important;
            border-radius: 0;
            background: #ffffff !important;
            box-shadow: none !important;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
        }

        .auth-topbar__brand {
            min-width: 0;
            display: inline-flex;
            align-items: center;
            color: var(--brand-primary-dark, #030133);
        }

        [data-brand] .auth-topbar__brand-mark {
            position: relative;
            width: clamp(146px, 12vw, 184px);
            height: 64px;
            flex: 0 0 clamp(146px, 12vw, 184px);
            padding: 0;
            overflow: hidden;
            border: 0;
            border-radius: 0;
            background: #ffffff;
            box-shadow: none;
        }

        [data-brand="client"] .auth-topbar__brand-mark .auth-topbar__brand-logo {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 144%;
            max-width: none;
            height: auto;
            display: block;
            object-fit: contain;
            object-position: center;
            transform: translate(-50%, -50%);
        }

        [data-brand="tcc"] .auth-topbar__brand-mark {
            width: 62px;
            height: 62px;
            flex-basis: 62px;
            border-radius: 14px;
            background: #030133;
        }

        [data-brand="tcc"] .auth-topbar__brand-mark .auth-topbar__brand-logo {
            position: static;
            width: 100%;
            max-width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
            object-position: center;
        }

        .auth-topbar__divider {
            width: 1px;
            height: clamp(34px, 3vw, 48px);
            flex: 0 0 1px;
            background: var(--brand-border, #d8e1ec);
        }

        .auth-topbar__product {
            color: var(--brand-primary-dark, #030133);
            font-family: var(--font-principal, "Sansation", "Segoe UI", sans-serif);
            font-size: clamp(1rem, 1.45vw, 1.35rem);
            font-weight: 400;
            line-height: 1.2;
            white-space: nowrap;
        }

        .auth-topbar__nav {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: clamp(24px, 3vw, 44px);
        }

        .auth-topbar__help {
            color: var(--brand-primary, #146fb6);
            font-family: var(--font-principal, "Sansation", "Segoe UI", sans-serif);
            font-size: clamp(0.92rem, 1.25vw, 1.15rem);
            font-weight: 500;
            line-height: 1.2;
            white-space: nowrap;
        }

        body.auth-layout-body[data-brand] .auth-topbar__nav .auth-topbar__link.auth-topbar__access,
        [data-brand] .auth-topbar__link.auth-topbar__access {
            min-width: clamp(112px, 9vw, 148px);
            min-height: clamp(46px, 4.2vw, 60px);
            padding: 0 clamp(20px, 2.2vw, 34px);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--brand-primary, #146fb6) !important;
            border: 1.5px solid var(--brand-primary, #146fb6) !important;
            border-radius: 6px;
            background: #ffffff !important;
            box-shadow: 0 5px 14px rgba(var(--brand-primary-rgb, 3, 1, 51), 0.055) !important;
            font-family: var(--font-principal, "Sansation", "Segoe UI", sans-serif);
            font-size: clamp(0.95rem, 1.2vw, 1.1rem);
            font-weight: 700;
            line-height: 1;
            text-decoration: none;
            transition:
                color 180ms ease,
                background-color 180ms ease,
                box-shadow 180ms ease,
                transform 180ms ease;
        }

        body.auth-layout-body[data-brand] .auth-topbar__nav .auth-topbar__link.auth-topbar__access:hover,
        [data-brand] .auth-topbar__link.auth-topbar__access:hover {
            color: #ffffff !important;
            background: var(--brand-primary, #146fb6) !important;
            box-shadow: 0 10px 24px rgba(var(--brand-primary-rgb, 3, 1, 51), 0.18) !important;
            transform: translateY(-1px);
        }

        .auth-topbar__link:focus-visible {
            outline: 3px solid var(--brand-focus-ring, rgba(20, 111, 182, 0.28));
            outline-offset: 3px;
        }

        body.auth-layout-body .auth-layout-main {
            padding-top: 0;
        }

        #companyAccessUnavailableModal:not(.show) {
            display: none;
        }

        @media (max-width: 767.98px) {
            .auth-topbar {
                --auth-topbar-height: 74px;
            }

            .auth-topbar__inner {
                padding-inline: 18px;
            }

            body[data-brand] .auth-topbar__surface {
                gap: 18px;
            }

            [data-brand] .auth-topbar__brand-mark {
                width: 124px;
                height: 52px;
                flex-basis: 124px;
            }

            [data-brand="tcc"] .auth-topbar__brand-mark {
                width: 52px;
                height: 52px;
                flex-basis: 52px;
                border-radius: 12px;
            }

            .auth-topbar__divider {
                height: 32px;
            }

            .auth-topbar__product {
                font-size: 0.96rem;
            }

            .auth-topbar__help {
                display: none;
            }

            body.auth-layout-body[data-brand] .auth-topbar__nav .auth-topbar__link.auth-topbar__access,
            [data-brand] .auth-topbar__link.auth-topbar__access {
                min-width: 96px;
                min-height: 44px;
                padding-inline: 18px;
                font-size: 0.92rem;
            }
        }

        @media (max-width: 479.98px) {
            .auth-topbar {
                --auth-topbar-height: 68px;
            }

            .auth-topbar__inner {
                padding-inline: 14px;
            }

            body[data-brand] .auth-topbar__surface {
                gap: 12px;
            }

            [data-brand] .auth-topbar__brand-mark {
                width: 108px;
                height: 46px;
                flex-basis: 108px;
            }

            [data-brand="tcc"] .auth-topbar__brand-mark {
                width: 46px;
                height: 46px;
                flex-basis: 46px;
                border-radius: 10px;
            }

            .auth-topbar__divider,
            .auth-topbar__product {
                display: none;
            }

            body.auth-layout-body[data-brand] .auth-topbar__nav .auth-topbar__link.auth-topbar__access,
            [data-brand] .auth-topbar__link.auth-topbar__access {
                min-width: 88px;
                min-height: 42px;
                padding-inline: 14px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .auth-topbar *,
            .auth-topbar *::before,
            .auth-topbar *::after {
                animation: none !important;
                scroll-behavior: auto !important;
                transition: none !important;
            }
        }
    </style>
@endonce

<header class="auth-topbar" aria-label="Identificação e acessos">
    <div class="auth-topbar__inner">
        <div class="auth-topbar__surface">
            <div class="auth-topbar__brand">
                <span class="auth-topbar__brand-mark">
                    <x-brand-logo
                        variant="logo_header"
                        :alt="$activeBrandName"
                        class="auth-topbar__brand-logo"
                    />
                </span>
            </div>

            <span class="auth-topbar__divider" aria-hidden="true"></span>
            <span class="auth-topbar__product">Seguro fiança locatícia</span>

            <nav class="auth-topbar__nav" aria-label="Opções de acesso">
                <span class="auth-topbar__help">Precisa de ajuda?</span>

                <button
                    type="button"
                    class="auth-topbar__link auth-topbar__access"
                    data-bs-toggle="modal"
                    data-bs-target="#companyAccessUnavailableModal"
                >
                    Entrar
                </button>
            </nav>
        </div>
    </div>
</header>

<div
    class="modal fade"
    id="companyAccessUnavailableModal"
    tabindex="-1"
    aria-labelledby="companyAccessUnavailableModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2
                    class="modal-title fs-5"
                    id="companyAccessUnavailableModalLabel"
                >
                    Acesso indisponível
                </h2>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Fechar"
                ></button>
            </div>

            <div class="modal-body">
                O acesso ao portal das imobiliárias está indisponível nesta versão.
                Os formulários de simulação continuam disponíveis normalmente.
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-primary"
                    data-bs-dismiss="modal"
                >
                    Entendi
                </button>
            </div>
        </div>
    </div>
</div>
