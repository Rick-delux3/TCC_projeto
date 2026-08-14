const teamReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const animateTeamCounter = (counter) => {
    if (counter.dataset.teamCounted === 'true') {
        return;
    }

    counter.dataset.teamCounted = 'true';
    const target = Number.parseInt(counter.dataset.teamCount ?? '0', 10);

    if (teamReducedMotion || !Number.isFinite(target) || target <= 0) {
        counter.textContent = new Intl.NumberFormat('pt-BR').format(Math.max(target, 0));
        return;
    }

    const duration = Math.min(1050, 620 + (target * 14));
    const startedAt = performance.now();
    counter.textContent = '0';

    const update = (now) => {
        const progress = Math.min((now - startedAt) / duration, 1);
        const easedProgress = 1 - ((1 - progress) ** 3);
        counter.textContent = new Intl.NumberFormat('pt-BR').format(
            Math.round(target * easedProgress),
        );

        if (progress < 1) {
            window.requestAnimationFrame(update);
        }
    };

    window.requestAnimationFrame(update);
};

const revealTeamElement = (element) => {
    element.classList.add('is-team-revealed');
    element.querySelectorAll('[data-team-count]').forEach(animateTeamCounter);
};

const teamRevealElements = document.querySelectorAll('.team-motion-page [data-team-reveal]');

if (teamRevealElements.length > 0 && !teamReducedMotion && 'IntersectionObserver' in window) {
    document.documentElement.classList.add('team-motion-enabled');

    const teamRevealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            revealTeamElement(entry.target);
            observer.unobserve(entry.target);
        });
    }, {
        threshold: 0.12,
        rootMargin: '0px 0px -6% 0px',
    });

    teamRevealElements.forEach((element) => teamRevealObserver.observe(element));
} else {
    teamRevealElements.forEach(revealTeamElement);
}

document.querySelectorAll('[data-team-form]').forEach((form) => {
    const activeInput = form.querySelector('#active');
    const activeLabel = form.querySelector('[data-team-status-label]');
    const permissionInputs = Array.from(form.querySelectorAll('input[name="permissions[]"]'));
    const permissionInputsByKey = new Map(
        permissionInputs
            .filter((input) => input.dataset.teamPermissionKey)
            .map((input) => [input.dataset.teamPermissionKey, input]),
    );
    const permissionCount = form.querySelector('[data-team-permission-count]');
    const submitButton = form.querySelector('[data-team-submit]');
    const originalSubmitContent = submitButton?.innerHTML;

    const updateActiveState = () => {
        if (!activeInput || !activeLabel) {
            return;
        }

        const isActive = activeInput.checked;
        activeLabel.textContent = isActive ? 'Ativo' : 'Inativo';
        activeLabel.classList.toggle('is-active', isActive);
        activeLabel.classList.toggle('is-inactive', !isActive);
    };

    const syncPermissionDependencies = () => {
        permissionInputs.forEach((input) => {
            const requiredKeys = (input.dataset.teamPermissionRequires ?? '')
                .split(/\s+/)
                .filter(Boolean);

            if (requiredKeys.length === 0) {
                return;
            }

            const dependenciesSatisfied = requiredKeys.every(
                (permissionKey) => permissionInputsByKey.get(permissionKey)?.checked === true,
            );

            if (!dependenciesSatisfied) {
                input.checked = false;
            }

            input.disabled = !dependenciesSatisfied;

            if (dependenciesSatisfied) {
                input.removeAttribute('aria-disabled');
            } else {
                input.setAttribute('aria-disabled', 'true');
            }
        });
    };

    const updatePermissions = () => {
        permissionInputs.forEach((input) => {
            const option = input.closest('.team-permission-option');

            option?.classList.toggle('is-selected', input.checked);
            option?.classList.toggle('is-disabled', input.disabled);
        });

        if (!permissionCount) {
            return;
        }

        const selectedCount = permissionInputs.filter((input) => input.checked).length;
        permissionCount.hidden = false;
        permissionCount.textContent = selectedCount === 1
            ? '1 selecionada'
            : `${selectedCount} selecionadas`;
        permissionCount.classList.add('is-updating');
        window.setTimeout(() => permissionCount.classList.remove('is-updating'), 180);
    };

    const handlePermissionChange = () => {
        syncPermissionDependencies();
        updatePermissions();
    };

    activeInput?.addEventListener('change', updateActiveState);
    permissionInputs.forEach((input) => input.addEventListener('change', handlePermissionChange));
    updateActiveState();
    syncPermissionDependencies();
    updatePermissions();

    form.querySelectorAll('[data-team-panel]').forEach((panel) => {
        panel.addEventListener('focusin', () => panel.classList.add('is-team-active'));
        panel.addEventListener('focusout', () => {
            window.setTimeout(() => {
                if (!panel.contains(document.activeElement)) {
                    panel.classList.remove('is-team-active');
                }
            }, 0);
        });
    });

    const resetSubmitState = () => {
        form.removeAttribute('aria-busy');

        if (submitButton) {
            submitButton.disabled = false;
            submitButton.innerHTML = originalSubmitContent;
        }
    };

    form.addEventListener('submit', () => {
        syncPermissionDependencies();
        form.setAttribute('aria-busy', 'true');

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Processando…';
        }
    });

    window.addEventListener('pageshow', resetSubmitState);
});
