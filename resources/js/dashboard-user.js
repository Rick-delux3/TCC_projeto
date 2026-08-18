document.addEventListener('DOMContentLoaded', function () {
    const configElement = document.getElementById('dashboardUserConfig');

    let config = {};

    if (configElement) {
        try {
            config = JSON.parse(configElement.textContent || '{}');
        } catch (error) {
            console.warn('Não foi possível carregar a configuração do dashboard.');
        }
    }

    const realtimeUrl = config.routes?.realtimeStatus || null;

    const leadFormUrl = config.leadFormUrl || null;
    const leadAccessCode = config.leadAccessCode || null;

    const dashboardThemeRoot = document.getElementById('dashboardThemeRoot');
    const dashboardThemeToggle = document.getElementById('dashboardThemeToggle');
    const dashboardThemeStorageKey = 'dashboard-theme';

    const dashboardLeadAccessCodeCopyButton = document.getElementById('dashboardLeadAccessCodeCopyButton');
    const dashboardLeadAccessCodeInput = document.getElementById('dashboardLeadAccessCode');

    const dashboardLeadFormCopyButton = document.getElementById('dashboardLeadFormCopyButton');
    const dashboardLeadFormInput = document.getElementById('dashboardLeadFormLink');
    const dashboardLeadFormCopyStatus = document.getElementById('dashboardLeadFormCopyStatus');
    const dashboardLeadFormOpenButton = document.getElementById('dashboardLeadFormOpenButton');

    /*
    |--------------------------------------------------------------------------
    | Tema claro/escuro do dashboard
    |--------------------------------------------------------------------------
    */
    function applyDashboardTheme(theme) {
        if(!dashboardThemeRoot || !dashboardThemeToggle) {
            return;
        }


        const normalizeTheme = (theme === 'dark' ? 'dark' : 'light');
        dashboardThemeRoot.setAttribute('data-dashboard-theme', normalizeTheme);

        dashboardThemeToggle.textContent = normalizeTheme === 'dark' ? 'Modo claro' : 'Modo escuro';
        dashboardThemeToggle.classList.toggle('btn-outline-light', normalizeTheme === 'dark');
        dashboardThemeToggle.classList.toggle('btn-outline-secondary', normalizeTheme !== 'dark');
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
    | Controle isolado dos formulários de edição de lead
    |--------------------------------------------------------------------------
    */
    const leadUpdateForms = document.querySelectorAll('.lead-update-form');

    function leadSubmitButtons(form) {
        const leadId = form.dataset.leadId;

        if (!leadId) {
            return [];
        }

        return Array.from(document.querySelectorAll(
            `[data-lead-submit][data-lead-id="${leadId}"]`
        ));
    }

    function resetLeadUpdateForm(form) {
        delete form.dataset.submitting;

        leadSubmitButtons(form).forEach(function (submitButton) {
            const spinner = submitButton.querySelector('[data-lead-spinner]');
            const label = submitButton.querySelector('[data-lead-submit-label]');

            submitButton.disabled = false;
            spinner?.classList.add('d-none');

            if (label && label.dataset.defaultLabel) {
                label.textContent = label.dataset.defaultLabel;
            }
        });
    }

    leadUpdateForms.forEach(function (form) {
        const leadId = form.dataset.leadId;
        const alertBox = document.getElementById(`leadNoChangesAlert${leadId}`);

        form.dataset.changed = 'false';

        const fields = Array.from(
            form.querySelectorAll('input[name], select[name], textarea[name]')
        ).filter(function (field) {
            return !['_token', '_method', 'lead_context_id'].includes(field.name);
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
            if (form.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }

            if (!formHasChanges()) {
                event.preventDefault();

                const dataTab = document.getElementById(form.dataset.leadTabId);

                if (dataTab && window.bootstrap?.Tab) {
                    window.bootstrap.Tab.getOrCreateInstance(dataTab).show();
                }

                if (alertBox) {
                    alertBox.classList.remove('d-none');
                    alertBox.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center',
                    });
                }

                return;
            }

            if (!form.checkValidity()) {
                return;
            }

            form.dataset.submitting = 'true';

            leadSubmitButtons(form).forEach(function (submitButton) {
                const spinner = submitButton.querySelector('[data-lead-spinner]');
                const label = submitButton.querySelector('[data-lead-submit-label]');

                submitButton.disabled = true;
                spinner?.classList.remove('d-none');

                if (label) {
                    label.dataset.defaultLabel ||= label.textContent.trim();
                    label.textContent = 'Salvando...';
                }
            });

            window.setTimeout(function () {
                if (event.defaultPrevented) {
                    resetLeadUpdateForm(form);
                }
            }, 0);
        });
    });

    window.addEventListener('pageshow', function () {
        leadUpdateForms.forEach(resetLeadUpdateForm);
    });

    const leadValidationTargets = config.leadValidationTargets || null;

    if (
        leadValidationTargets
        && window.bootstrap?.Modal
        && window.bootstrap?.Tab
    ) {
        const modalElement = document.getElementById(leadValidationTargets.modal);
        const tabElement = document.getElementById(leadValidationTargets.tab);
        const fieldElement = document.getElementById(leadValidationTargets.field);

        if (modalElement && tabElement && fieldElement) {
            const revealLeadValidationError = function () {
                window.bootstrap.Tab.getOrCreateInstance(tabElement).show();

                window.setTimeout(function () {
                    fieldElement.focus();
                }, 0);
            };

            if (modalElement.classList.contains('show')) {
                revealLeadValidationError();
            } else {
                modalElement.addEventListener(
                    'shown.bs.modal',
                    revealLeadValidationError,
                    { once: true }
                );

                window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
            }
        }
    }

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
