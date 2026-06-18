document.addEventListener('DOMContentLoaded', function () {
    const configElement = document.getElementById('dashboardUserConfig');

    const config = configElement
        ? JSON.parse(configElement.textContent || '{}')
        : {};

    const statusUrl = config.routes?.syncStatus || null;
    const realtimeUrl = config.routes?.realtimeStatus || null;

    const currentStatus = config.syncStatus || 'idle';
    const initialSyncError = config.syncError || null;
    const initialTotalLeads = Number(config.totalLeads || 0);
    const syncJustQueued = Boolean(config.syncJustQueued);
    const leadFormUrl = config.leadFormUrl || null;
    const leadAccessCode = config.leadAccessCode || null;
    const shouldAutoShowSyncToast = Boolean(config.shouldAutoShowSyncToast);

    const dashboardThemeRoot = document.getElementById('dashboardThemeRoot');
    const dashboardThemeToggle = document.getElementById('dashboardThemeToggle');
    const dashboardThemeStorageKey = 'dashboard-theme';

    const syncFloatingPanel = document.getElementById('syncFloatingPanel');
    const syncPanelCloseButton = document.getElementById('sync-panel-close-button');
    const syncPanelRefreshButton = document.getElementById('sync-panel-refresh-button');

    const toastBadgeEl = document.getElementById('sync-toast-badge');
    const toastTitleEl = document.getElementById('sync-toast-title');
    const toastDescriptionEl = document.getElementById('sync-toast-description');
    const toastProgressEl = document.getElementById('sync-toast-progress-bar');
    const toastPercentEl = document.getElementById('sync-toast-percent');
    const toastSummaryEl = document.getElementById('sync-toast-summary');
    const toastRetryButtonEl = document.getElementById('sync-toast-retry-button');
    const toastRetryFormEl = document.getElementById('sync-toast-retry-form');

    const dashboardLeadAccessCodeCopyButton = document.getElementById('dashboardLeadAccessCodeCopyButton');
    const dashboardLeadAccessCodeInput = document.getElementById('dashboardLeadAccessCode');

    const dashboardLeadFormCopyButton = document.getElementById('dashboardLeadFormCopyButton');
    const dashboardLeadFormInput = document.getElementById('dashboardLeadFormLink');
    const dashboardLeadFormCopyStatus = document.getElementById('dashboardLeadFormCopyStatus');
    const dashboardLeadFormOpenButton = document.getElementById('dashboardLeadFormOpenButton');

    let intervalId = null;
    let doneReloadTimeout = null;

    /*
    |--------------------------------------------------------------------------
    | Tema claro/escuro do dashboard
    |--------------------------------------------------------------------------
    */
    function applyDashboardTheme(theme) {
        if (!dashboardThemeRoot || !dashboardThemeToggle) {
            return;
        }

        const normalizedTheme = theme === 'dark' ? 'dark' : 'light';

        dashboardThemeRoot.setAttribute('data-dashboard-theme', normalizedTheme);
        dashboardThemeToggle.textContent = normalizedTheme === 'dark' ? 'Modo claro' : 'Modo escuro';

        dashboardThemeToggle.classList.toggle('btn-outline-light', normalizedTheme === 'dark');
        dashboardThemeToggle.classList.toggle('btn-outline-secondary', normalizedTheme !== 'dark');
    }

    if (dashboardThemeRoot && dashboardThemeToggle) {
        let savedTheme = 'light';

        try {
            savedTheme = localStorage.getItem(dashboardThemeStorageKey) || 'light';
        } catch (error) {
            savedTheme = 'light';
        }

        applyDashboardTheme(savedTheme);

        dashboardThemeToggle.addEventListener('click', function () {
            const currentTheme = dashboardThemeRoot.getAttribute('data-dashboard-theme') || 'light';
            const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';

            try {
                localStorage.setItem(dashboardThemeStorageKey, nextTheme);
            } catch (error) {
                console.warn('Não foi possível salvar o tema no navegador.', error);
            }

            applyDashboardTheme(nextTheme);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Painel flutuante de sincronização
    |--------------------------------------------------------------------------
    */
    function showSyncPanel() {
        if (syncFloatingPanel) {
            syncFloatingPanel.classList.remove('d-none');
        }
    }

    function hideSyncPanel() {
        if (syncFloatingPanel) {
            syncFloatingPanel.classList.add('d-none');
        }
    }

    if (syncPanelCloseButton) {
        syncPanelCloseButton.addEventListener('click', hideSyncPanel);
    }

    if (syncPanelRefreshButton) {
        syncPanelRefreshButton.addEventListener('click', function () {
            window.location.reload();
        });
    }

    function stopPolling() {
        if (intervalId) {
            clearInterval(intervalId);
            intervalId = null;
        }
    }

    function progressForStatus(status, totalLeads) {
        if (status === 'queued') {
            return 18;
        }

        if (status === 'running') {
            return Math.min(84, 46 + Math.min(Number(totalLeads || 0), 38));
        }

        if (status === 'completed' || status === 'completed_with_warning' || status === 'failed') {
            return 100;
        }

        return 0;
    }

    function getToastCopy(status, payload) {
        const leadsCount = Number(payload.totalLeads || 0);
        const progress = progressForStatus(status, leadsCount);

        if (status === 'queued') {
            return {
                variant: 'warning',
                badge: 'Na fila',
                title: 'Preparando sincronização',
                description: 'A importação foi colocada na fila e será processada em instantes.',
                progress,
                summary: 'Aguardando início do processamento.',
                retry: false,
                refresh: false,
            };
        }

        if (status === 'running') {
            return {
                variant: 'primary',
                badge: 'Sincronizando',
                title: 'Sincronização em andamento',
                description: 'Os leads estão sendo sincronizados em segundo plano.',
                progress,
                summary: leadsCount > 0
                    ? `${leadsCount} leads disponíveis até agora.`
                    : 'Lendo registros da integração.',
                retry: false,
                refresh: false,
            };
        }

        if (status === 'completed') {
            return {
                variant: 'success',
                badge: 'Atualizado',
                title: 'Sincronização concluída',
                description: 'A base local foi atualizada com sucesso.',
                progress: 100,
                summary: `${leadsCount} leads disponíveis no painel.`,
                retry: false,
                refresh: true,
            };
        }

        if (status === 'completed_with_warning') {
            return {
                variant: 'warning',
                badge: 'Parcial',
                title: 'Sincronização parcial concluída',
                description: payload.syncError || 'A sincronização foi finalizada com uma quantidade suficiente de leads para o painel.',
                progress: 100,
                summary: `${leadsCount} leads disponíveis no painel.`,
                retry: false,
                refresh: true,
            };
        }

        if (status === 'failed') {
            return {
                variant: 'danger',
                badge: 'Falhou',
                title: 'Falha na sincronização',
                description: payload.syncError || 'Não foi possível concluir a sincronização.',
                progress: 100,
                summary: 'Revise a integração ou tente novamente.',
                retry: true,
                refresh: false,
            };
        }

        return {
            variant: 'secondary',
            badge: 'Aguardando',
            title: 'Sincronização aguardando',
            description: 'Nenhuma sincronização em andamento.',
            progress: 0,
            summary: 'Aguardando atualização.',
            retry: false,
            refresh: false,
        };
    }

    function renderToast(copy) {
        if (!syncFloatingPanel) {
            return;
        }

        if (toastBadgeEl) {
            toastBadgeEl.className = `badge text-bg-${copy.variant} me-2`;
            toastBadgeEl.textContent = copy.badge;
        }

        if (toastTitleEl) {
            toastTitleEl.textContent = copy.title;
        }

        if (toastDescriptionEl) {
            toastDescriptionEl.textContent = copy.description;
        }

        if (toastPercentEl) {
            toastPercentEl.textContent = `${copy.progress}%`;
        }

        if (toastSummaryEl) {
            toastSummaryEl.textContent = copy.summary;
        }

        if (toastProgressEl) {
            toastProgressEl.style.width = `${copy.progress}%`;
            toastProgressEl.setAttribute('aria-valuenow', copy.progress);
            toastProgressEl.className = `progress-bar progress-bar-striped bg-${copy.variant}`;

            if (copy.progress < 100) {
                toastProgressEl.classList.add('progress-bar-animated');
            } else {
                toastProgressEl.classList.remove('progress-bar-animated');
            }
        }

        if (toastRetryButtonEl) {
            toastRetryButtonEl.classList.toggle('d-none', !copy.retry);
        }

        if (syncPanelRefreshButton) {
            syncPanelRefreshButton.classList.toggle('d-none', !copy.refresh);
        }

        showSyncPanel();
    }

    /*
    |--------------------------------------------------------------------------
    | Copiar chave e link da simulação
    |--------------------------------------------------------------------------
    */
    function setCopyStatus(target, message, type = 'muted') {
        if (!target) {
            return;
        }

        target.textContent = message;
        target.className = `small text-${type}`;
    }

    function bindCopyButton(copyButton, input, statusEl, valueToCopy, successMessage, defaultMessage) {
        if (!copyButton || !input) {
            return;
        }

        copyButton.addEventListener('click', async function () {
            const value = valueToCopy || input.value;

            if (!value) {
                if (statusEl) {
                    setCopyStatus(statusEl, 'Informação indisponível para cópia.', 'danger');
                }

                return;
            }

            try {
                await navigator.clipboard.writeText(value);

                if (statusEl) {
                    setCopyStatus(statusEl, successMessage, 'success');

                    setTimeout(function () {
                        setCopyStatus(statusEl, defaultMessage, 'muted');
                    }, 2600);
                } else {
                    const originalText = copyButton.textContent;
                    copyButton.textContent = 'Copiado';

                    setTimeout(function () {
                        copyButton.textContent = originalText;
                    }, 2000);
                }
            } catch (error) {
                input.focus();
                input.select();

                if (statusEl) {
                    setCopyStatus(statusEl, 'Não foi possível copiar automaticamente. Use Ctrl+C.', 'danger');
                }
            }
        });
    }

    function bindOpenButton(openButton, url) {
        if (!openButton) {
            return;
        }

        openButton.addEventListener('click', function (event) {
            if (!url) {
                event.preventDefault();
            }
        });
    }

    if (toastRetryButtonEl) {
        toastRetryButtonEl.addEventListener('click', function () {
            if (toastRetryFormEl) {
                toastRetryFormEl.submit();
            }
        });
    }

    bindOpenButton(dashboardLeadFormOpenButton, leadFormUrl);

    bindCopyButton(
        dashboardLeadAccessCodeCopyButton,
        dashboardLeadAccessCodeInput,
        null,
        leadAccessCode,
        'Chave copiada com sucesso.',
        ''
    );

    bindCopyButton(
        dashboardLeadFormCopyButton,
        dashboardLeadFormInput,
        dashboardLeadFormCopyStatus,
        leadFormUrl,
        'Link copiado com sucesso.',
        'Envie o link e a chave para quem for preencher.'
    );

    /*
    |--------------------------------------------------------------------------
    | Consulta do status da sincronização
    |--------------------------------------------------------------------------
    */
    async function checkSyncStatus() {
        if (!statusUrl) {
            return;
        }

        try {
            const response = await fetch(statusUrl, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                stopPolling();
                return;
            }

            const data = await response.json();

            if (!data.authenticated) {
                stopPolling();
                return;
            }

            const status = data.sync_status;

            if (status === 'queued' || status === 'running') {
                renderToast(getToastCopy(status, {
                    totalLeads: data.total_leads,
                    syncError: data.sync_error,
                }));

                return;
            }

            if (status === 'completed' || status === 'completed_with_warning') {
                stopPolling();

                renderToast(getToastCopy(status, {
                    totalLeads: data.total_leads,
                    syncError: data.sync_error,
                }));

                return;
            }

            if (status === 'failed') {
                stopPolling();

                renderToast(getToastCopy('failed', {
                    totalLeads: data.total_leads,
                    syncError: data.sync_error,
                }));

                showSyncPanel();

                return;
            }

            stopPolling();
        } catch (error) {
            console.error('Erro ao consultar status da sincronização:', error);
        }
    }

    window.addEventListener('beforeunload', function () {
        stopPolling();

        if (doneReloadTimeout) {
            clearTimeout(doneReloadTimeout);
        }
    });

    if (currentStatus === 'queued' || currentStatus === 'running' || syncJustQueued) {
        const statusToRender = currentStatus === 'queued' || currentStatus === 'running'
            ? currentStatus
            : 'queued';

        renderToast(getToastCopy(statusToRender, {
            totalLeads: initialTotalLeads,
            syncError: initialSyncError,
        }));

        showSyncPanel();

        intervalId = setInterval(checkSyncStatus, 5000);
        checkSyncStatus();
    }

    if (currentStatus === 'failed') {
        renderToast(getToastCopy('failed', {
            totalLeads: initialTotalLeads,
            syncError: initialSyncError,
        }));

        if (shouldAutoShowSyncToast) {
            showSyncPanel();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Bloqueia envio do formulário do modal se nada foi alterado
    |--------------------------------------------------------------------------
    */
    const leadUpdateForms = document.querySelectorAll('.lead-update-form');

    leadUpdateForms.forEach(function (form) {
        const leadId = form.dataset.leadId;
        const alertBox = document.getElementById(`leadNoChangesAlert${leadId}`);

        form.dataset.changed = 'false';

        const fields = Array.from(
            form.querySelectorAll('input[name], select[name], textarea[name]')
        ).filter(function (field) {
            return !['_token', '_method'].includes(field.name);
        });

        const initialValues = new Map();

        fields.forEach(function (field) {
            initialValues.set(field.name, field.value ?? '');
        });

        function formHasChanges() {
            return fields.some(function (field) {
                const initialValue = initialValues.get(field.name) ?? '';
                const currentValue = field.value ?? '';

                return currentValue !== initialValue;
            });
        }

        function handleFieldChange() {
            form.dataset.changed = formHasChanges() ? 'true' : 'false';

            if (alertBox) {
                alertBox.classList.add('d-none');
            }
        }

        fields.forEach(function (field) {
            field.addEventListener('input', handleFieldChange);
            field.addEventListener('change', handleFieldChange);
        });

        form.addEventListener('submit', function (event) {
            if (!formHasChanges()) {
                event.preventDefault();

                if (alertBox) {
                    alertBox.classList.remove('d-none');
                    alertBox.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center',
                    });
                }
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Limpeza de backdrop dos modais
    |--------------------------------------------------------------------------
    */
    document.querySelectorAll('.lead-details-modal').forEach(function (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function () {
            document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                backdrop.remove();
            });

            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Atualização automática do dashboard
    |--------------------------------------------------------------------------
    | Só roda se você criar a rota Dashboard.realtimeStatus e enviar a URL.
    |--------------------------------------------------------------------------
    */
    if (realtimeUrl) {
        let currentDashboardActivityHash = config.dashboardActivityHash || null;
        let isReloadScheduled = false;

        const realtimeNotice = document.getElementById('dashboardRealtimeNotice');
        const realtimeRefreshButton = document.getElementById('dashboardRealtimeRefreshButton');

        const totalLeadsEl = document.getElementById('dashboardTotalLeads');
        const newLeadsEl = document.getElementById('dashboardNewLeads');
        const withPhoneEl = document.getElementById('dashboardWithPhone');
        const recentLeadsEl = document.getElementById('dashboardRecentLeads');
        const notificationBadgeEl = document.getElementById('dashboardNotificationBadge');

        function hasOpenLeadModal() {
            return document.querySelector('.modal.show') !== null;
        }

        function hasDirtyLeadForm() {
            return Array.from(document.querySelectorAll('.lead-update-form')).some(function (form) {
                return form.dataset.changed === 'true';
            });
        }

        function showRealtimeNotice() {
            if (realtimeNotice) {
                realtimeNotice.classList.remove('d-none');
            }
        }

        function updateDashboardCounters(data) {
            if (totalLeadsEl) {
                totalLeadsEl.textContent = data.total_leads;
            }

            if (newLeadsEl) {
                newLeadsEl.textContent = data.new_leads;
            }

            if (withPhoneEl) {
                withPhoneEl.textContent = data.with_phone;
            }

            if (recentLeadsEl) {
                recentLeadsEl.textContent = data.recent_leads;
            }

            if (notificationBadgeEl) {
                const count = Number(data.new_leads || 0);

                notificationBadgeEl.textContent = count > 99 ? '99+' : count;
                notificationBadgeEl.classList.toggle('d-none', count <= 0);
            }
        }

        async function checkDashboardRealtimeUpdates() {
            try {
                const response = await fetch(realtimeUrl, {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json();

                if (!data.authenticated) {
                    return;
                }

                updateDashboardCounters(data);

                if (!currentDashboardActivityHash) {
                    currentDashboardActivityHash = data.activity_hash;
                    return;
                }

                if (data.activity_hash !== currentDashboardActivityHash) {
                    currentDashboardActivityHash = data.activity_hash;

                    if (!hasOpenLeadModal() && !hasDirtyLeadForm() && !isReloadScheduled) {
                        isReloadScheduled = true;

                        setTimeout(function () {
                            window.location.reload();
                        }, 900);

                        return;
                    }

                    showRealtimeNotice();
                }
            } catch (error) {
                console.warn('Não foi possível verificar atualizações em tempo real.', error);
            }
        }

        if (realtimeRefreshButton) {
            realtimeRefreshButton.addEventListener('click', function () {
                window.location.reload();
            });
        }

        setInterval(checkDashboardRealtimeUpdates, 10000);
    }
});