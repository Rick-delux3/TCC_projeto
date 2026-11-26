@php
    $simulationCompanies ??= \App\Models\Imobiliaria::simulationOptions();
@endphp
<div
    class="modal fade dashboard-modal dashboard-modal--chooser"
    id="adminSimulationModal"
    tabindex="-1"
    aria-labelledby="adminSimulationModalLabel"
    aria-describedby="adminSimulationModalDescription"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form
                method="GET"
                action="{{ route('admin.simulations.open') }}"
                target="adminSimulationForm"
                id="adminSimulationSelectionForm"
            >
                <input
                    type="hidden"
                    name="admin_simulation_channel"
                    id="adminSimulationChannel"
                >

                <x-dashboard-modal-heading title="Escolha o formulário" title-id="adminSimulationModalLabel"
                    description-id="adminSimulationModalDescription" eyebrow="Nova solicitação" icon="bi-ui-checks-grid">
                    <x-slot:description>Defina o vínculo e a origem para abrir o formulário adequado.</x-slot:description>
                </x-dashboard-modal-heading>

                <div class="modal-body">
                    <div class="dashboard-modal-notice"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span>O formulário será aberto em uma nova aba.</span></div>
                    @if ($errors->adminSimulation->any())
                        <div class="alert alert-danger mb-0" role="alert">
                            <ul class="mb-0">
                                @foreach ($errors->adminSimulation->all() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="dashboard-modal-choice">
                        <label for="adminSimulationLinkType" class="form-label fw-semibold">
                            <span class="dashboard-modal-choice__step" aria-hidden="true">1</span>Vínculo
                        </label>
                        <select
                            name="vinculo"
                            id="adminSimulationLinkType"
                            class="form-select @error('vinculo', 'adminSimulation') is-invalid @enderror"
                            @error('vinculo', 'adminSimulation') aria-invalid="true" aria-describedby="adminSimulationLinkTypeError" @enderror
                            required
                        >
                            <option value="">Selecione</option>
                            <option
                                value="imobiliaria_cadastrada"
                                @selected(old('vinculo') === 'imobiliaria_cadastrada')
                                @disabled($simulationCompanies->isEmpty())
                            >
                                {{ \App\Support\BrandingText::choose('Vincular a uma imobiliária cadastrada', 'Vincular a uma empresa cadastrada') }}
                            </option>
                            <option
                                value="sem_vinculo"
                                @selected(old('vinculo') === 'sem_vinculo')
                            >
                                {{ \App\Support\BrandingText::choose('Não vincular a uma imobiliária', 'Não vincular a uma empresa') }}
                            </option>
                        </select>
                        @error('vinculo', 'adminSimulation')
                            <div id="adminSimulationLinkTypeError" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div id="adminSimulationCompanyGroup" class="dashboard-modal-choice d-none">
                        <label for="adminSimulationCompany" class="form-label fw-semibold">
                            <span class="dashboard-modal-choice__step" aria-hidden="true">2</span>{{ \App\Support\BrandingText::choose('Imobiliária', 'Empresa') }}
                        </label>
                        <select
                            name="company_id"
                            id="adminSimulationCompany"
                            class="form-select @error('company_id', 'adminSimulation') is-invalid @enderror"
                            @error('company_id', 'adminSimulation') aria-invalid="true" aria-describedby="adminSimulationCompanyError" @enderror
                            disabled
                        >
                            <option value="">Selecione</option>
                            @foreach ($simulationCompanies as $company)
                                @php
                                    $companyCity = data_get($company, 'city')
                                        ?? data_get($company, 'cidade');
                                    $companyState = data_get($company, 'state')
                                        ?? data_get($company, 'uf');
                                    $companyLocation = collect([$companyCity, $companyState])
                                        ->filter(fn ($value) => filled($value))
                                        ->implode('/');
                                @endphp
                                <option
                                    value="{{ data_get($company, 'id') }}"
                                    @selected((string) old('company_id') === (string) data_get($company, 'id'))
                                >
                                    {{ data_get($company, 'name', data_get($company, 'nome', \App\Support\BrandingText::choose('Imobiliária', 'Empresa'))) }}{{ $companyLocation ? ' — '.$companyLocation : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('company_id', 'adminSimulation')
                            <div id="adminSimulationCompanyError" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            {{ \App\Support\BrandingText::choose('O tipo será registrado automaticamente como imobiliária cadastrada.', 'O tipo será registrado automaticamente como empresa cadastrada.') }}
                        </div>
                    </div>

                    <div id="adminSimulationTypeGroup" class="dashboard-modal-choice d-none">
                        <label for="adminSimulationType" class="form-label fw-semibold">
                            <span class="dashboard-modal-choice__step" aria-hidden="true">2</span>Tipo do solicitante
                        </label>
                        <select
                            name="tipo_solicitante"
                            id="adminSimulationType"
                            class="form-select @error('tipo_solicitante', 'adminSimulation') is-invalid @enderror"
                            @error('tipo_solicitante', 'adminSimulation') aria-invalid="true" aria-describedby="adminSimulationTypeError" @enderror
                            disabled
                        >
                            <option value="">Selecione</option>
                            <option
                                value="imobiliaria_nao_cadastrada"
                                @selected(old('tipo_solicitante') === 'imobiliaria_nao_cadastrada')
                            >
                                {{ \App\Support\BrandingText::choose('Imobiliária não cadastrada', 'Empresa não cadastrada') }}
                            </option>
                            <option value="locatario" @selected(old('tipo_solicitante') === 'locatario')>
                                {{ \App\Support\BrandingText::choose('Locatário', 'Beneficiário') }}
                            </option>
                            <option value="locador" @selected(old('tipo_solicitante') === 'locador')>
                                {{ \App\Support\BrandingText::choose('Proprietário / locador', 'Consumidor') }}
                            </option>
                        </select>
                        @error('tipo_solicitante', 'adminSimulation')
                            <div id="adminSimulationTypeError" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        Abrir formulário
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function () {
        const activeChannelStorageKey = 'adminSimulationActiveChannel';
        const completionStorageKey = 'adminSimulationCompletion';
        const dashboardUrl = @json(route('Dashboard-Admin').'#leads-section');
        const expectedOrigin = window.location.origin;
        const simulationWindowName = 'adminSimulationForm';
        let simulationWindow = null;

        const readSessionStorage = function (key) {
            try {
                return window.sessionStorage.getItem(key);
            } catch (error) {
                return null;
            }
        };

        const writeSessionStorage = function (key, value) {
            try {
                window.sessionStorage.setItem(key, value);
                return true;
            } catch (error) {
                return false;
            }
        };

        const removeSessionStorage = function (key) {
            try {
                window.sessionStorage.removeItem(key);
            } catch (error) {
                // O fluxo continua com a validação de origem e referência da guia.
            }
        };

        const createChannelId = function () {
            if (window.crypto?.randomUUID) {
                return window.crypto.randomUUID();
            }

            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(
                /[xy]/g,
                function (character) {
                    const random = Math.floor(Math.random() * 16);
                    const value = character === 'x'
                        ? random
                        : (random & 0x3) | 0x8;

                    return value.toString(16);
                }
            );
        };

        const reloadDashboard = function () {
            const targetUrl = new URL(dashboardUrl, window.location.href);
            const currentUrl = new URL(window.location.href);

            if (
                currentUrl.origin === targetUrl.origin
                && currentUrl.pathname === targetUrl.pathname
                && currentUrl.search === targetUrl.search
            ) {
                window.history.replaceState(null, '', targetUrl.href);
                window.location.reload();
                return;
            }

            window.location.assign(targetUrl.href);
        };

        window.addEventListener('message', function (event) {
            if (event.origin !== expectedOrigin) {
                return;
            }

            const data = event.data;

            if (
                !data
                || typeof data !== 'object'
                || data.type !== 'admin-simulation:completed'
                || typeof data.channel !== 'string'
                || data.channel !== readSessionStorage(activeChannelStorageKey)
                || typeof data.message !== 'string'
                || data.message.length === 0
                || !Number.isInteger(data.leadId)
            ) {
                return;
            }

            if (simulationWindow && event.source !== simulationWindow) {
                return;
            }

            if (!writeSessionStorage(
                completionStorageKey,
                JSON.stringify(data)
            )) {
                return;
            }

            removeSessionStorage(activeChannelStorageKey);
            reloadDashboard();
        });

        document.addEventListener('DOMContentLoaded', function () {
            const successAlert = document.getElementById('adminSimulationSuccessAlert');
            const storedCompletion = readSessionStorage(completionStorageKey);

            if (successAlert && storedCompletion) {
                try {
                    const completion = JSON.parse(storedCompletion);

                    if (
                        completion?.type === 'admin-simulation:completed'
                        && typeof completion.message === 'string'
                        && completion.message.length > 0
                    ) {
                        successAlert.textContent = completion.message;
                        successAlert.classList.remove('d-none');
                    }
                } catch (error) {
                    // Descarta confirmações inválidas sem interromper o dashboard.
                }

                removeSessionStorage(completionStorageKey);
            }

            const selectionForm = document.getElementById('adminSimulationSelectionForm');
            const channelInput = document.getElementById('adminSimulationChannel');
            const linkType = document.getElementById('adminSimulationLinkType');
            const companyGroup = document.getElementById('adminSimulationCompanyGroup');
            const company = document.getElementById('adminSimulationCompany');
            const typeGroup = document.getElementById('adminSimulationTypeGroup');
            const type = document.getElementById('adminSimulationType');

            if (!linkType || !companyGroup || !company || !typeGroup || !type) {
                return;
            }

            const updateSimulationFields = function () {
                const usesRegisteredCompany = linkType.value === 'imobiliaria_cadastrada';
                const hasNoCompanyLink = linkType.value === 'sem_vinculo';

                companyGroup.classList.toggle('d-none', !usesRegisteredCompany);
                company.disabled = !usesRegisteredCompany;
                company.required = usesRegisteredCompany;

                typeGroup.classList.toggle('d-none', !hasNoCompanyLink);
                type.disabled = !hasNoCompanyLink;
                type.required = hasNoCompanyLink;
            };

            updateSimulationFields();
            linkType.addEventListener('change', updateSimulationFields);

            selectionForm?.addEventListener('submit', function () {
                const channel = createChannelId();

                if (channelInput) {
                    channelInput.value = channel;
                }

                writeSessionStorage(activeChannelStorageKey, channel);

                simulationWindow = window.open('', simulationWindowName);

                if (!simulationWindow) {
                    selectionForm.removeAttribute('target');
                    return;
                }

                selectionForm.target = simulationWindowName;
                simulationWindow.focus();
            });

            @if ($errors->adminSimulation->any())
                const modalElement = document.getElementById('adminSimulationModal');

                if (modalElement && window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
                }
            @endif
        });
    })();
</script>
