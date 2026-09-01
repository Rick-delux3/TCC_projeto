@once
    <style>
        .auth-topbar {
            position: absolute;
            inset: 0 0 auto;
            width: 100%;
            z-index: 20;
            background: transparent;
            border: 0;
            box-shadow: none;
            pointer-events: none;
        }

        .auth-topbar__inner {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 16px 14px 0;
            display: flex;
            align-items: center;
            pointer-events: none;
        }

        .auth-topbar__surface {
            max-width: 100%;
            min-height: 58px;
            padding: 7px 12px;
            display: inline-flex;
            align-items: center;
            gap: 20px;
            border: 1px solid rgba(3, 1, 51, 0.1);
            border-radius: 999px;
            background: linear-gradient(
                100deg,
                rgba(3, 1, 51, 0.12) 0%,
                rgba(20, 111, 182, 0.08) 100%
            );
            box-shadow:
                0 16px 36px rgba(3, 1, 51, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.28);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            pointer-events: auto;
        }

        .auth-topbar__brand {
            min-width: 0;
            display: inline-flex;
            align-items: center;
            gap: 11px;
            color: #030133;
            text-decoration: none;
        }

        .auth-topbar__brand:hover,
        .auth-topbar__brand:focus-visible {
            color: #030133;
        }

        .auth-topbar__brand-mark {
            position: relative;
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            overflow: hidden;
            border-radius: 50%;
            background: #030133;
            box-shadow:
                0 7px 16px rgba(3, 1, 51, 0.24),
                0 0 0 2px rgba(20, 111, 182, 0.12);
        }

        .auth-topbar__brand-mark img {
            position: absolute;
            top: -1px;
            left: -8px;
            width: 58px;
            max-width: none;
            height: auto;
            display: block;
        }

        [data-brand="client"] .auth-topbar__brand-mark .auth-topbar__brand-logo {
            object-fit: contain;
            object-position: center;
        }

        .auth-topbar__brand-copy {
            min-width: 0;
            display: grid;
            line-height: 1;
        }

        .auth-topbar__brand-copy strong {
            color: #030133;
            font-family: var(--font-principal, "Sansation", sans-serif);
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.01em;
            white-space: nowrap;
        }

        .auth-topbar__brand-copy small {
            margin-top: 5px;
            color: #55658c;
            font-family: var(--font-principal, "Sansation", sans-serif);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .auth-topbar__divider {
            width: 1px;
            height: 28px;
            flex: 0 0 1px;
            background: linear-gradient(
                180deg,
                transparent,
                rgba(3, 1, 51, 0.16) 22%,
                rgba(3, 1, 51, 0.16) 78%,
                transparent
            );
        }

        .auth-topbar__nav {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .auth-topbar__link {
            min-height: 38px;
            padding: 0 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid transparent;
            border-radius: 999px;
            color: #55658c;
            font-family: var(--font-principal, "Sansation", sans-serif);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.01em;
            line-height: 1;
            text-decoration: none;
            white-space: nowrap;
            transition:
                color 0.2s ease,
                background-color 0.2s ease,
                border-color 0.2s ease,
                box-shadow 0.2s ease,
                transform 0.2s ease;
        }

        .auth-topbar__link:hover {
            color: #030133;
            background: rgba(20, 111, 182, 0.08);
            transform: translateY(-1px);
        }

        .auth-topbar__link:focus-visible,
        .auth-topbar__brand:focus-visible {
            outline: 3px solid rgba(20, 111, 182, 0.28);
            outline-offset: 2px;
        }

        .auth-topbar__link[aria-current="page"] {
            color: #fff;
            border-color: #fd1e6e;
            background: #fd1e6e;
            box-shadow: 0 8px 18px rgba(253, 30, 110, 0.22);
        }

        .auth-topbar__link--register:not([aria-current="page"]) {
            color: #030133;
            border-color: rgba(253, 30, 110, 0.18);
            background: rgba(253, 30, 110, 0.08);
        }

        .auth-topbar__link--register:not([aria-current="page"]):hover {
            color: #fff;
            border-color: #fd1e6e;
            background: #fd1e6e;
            box-shadow: 0 8px 18px rgba(253, 30, 110, 0.22);
        }

        body.auth-layout-body .auth-layout-main {
            padding-top: 98px;
        }

        @media (max-width: 640px) {
            .auth-topbar__inner {
                padding: 12px 8px 0;
            }

            .auth-topbar__surface {
                width: 100%;
                min-height: 54px;
                padding: 6px;
                gap: 8px;
                justify-content: space-between;
            }

            .auth-topbar__brand {
                gap: 7px;
            }

            .auth-topbar__brand-mark {
                width: 38px;
                height: 38px;
                flex-basis: 38px;
            }

            .auth-topbar__brand-mark img {
                left: -8px;
                width: 54px;
            }

            .auth-topbar__brand-copy small {
                display: none;
            }

            .auth-topbar__divider {
                height: 24px;
            }

            .auth-topbar__nav {
                gap: 1px;
            }

            .auth-topbar__link {
                min-height: 36px;
                padding: 0 9px;
                font-size: 11px;
            }

            body.auth-layout-body .auth-layout-main {
                padding-top: 84px;
            }
        }

        @media (max-width: 420px) {
            .auth-topbar__surface {
                gap: 5px;
            }

            .auth-topbar__brand-copy strong {
                max-width: 28px;
                overflow: hidden;
                font-size: 12px;
                white-space: nowrap;
            }

            .auth-topbar__divider {
                display: none;
            }

            .auth-topbar__link {
                padding: 0 7px;
                font-size: 10px;
            }
        }
    </style>
@endonce

<header class="auth-topbar" aria-label="Identificação e acessos">
    <div class="auth-topbar__inner">
        <div class="auth-topbar__surface">
            <div class="auth-topbar__brand">
                <span class="auth-topbar__brand-mark" aria-hidden="true">
                    <x-brand-logo
                        variant="logo_header"
                        alt=""
                        class="auth-topbar__brand-logo"
                    />
                </span>

                <span class="auth-topbar__brand-copy">
                    <strong>{{ config('branding.profiles.'.config('branding.active', 'tcc').'.name', 'NVS Seguros') }}</strong>
                    <small>Portal imobiliário</small>
                </span>
            </div>

            <span class="auth-topbar__divider" aria-hidden="true"></span>

            <nav class="auth-topbar__nav" aria-label="Opções de acesso">
                <button
                    type="button"
                    class="auth-topbar__link"
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
