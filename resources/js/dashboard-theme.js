export function initializeDashboardTheme(document = globalThis.document, browser = globalThis.window) {
    const adminBody = document.body.classList.contains('dashboard-admin-body') ? document.body : null;
    const roots = [...document.querySelectorAll(adminBody ? '.dashboard-shell' : '#dashboardThemeRoot')];
    const toggles = [...document.querySelectorAll('[data-dashboard-theme-toggle], #dashboardThemeToggle')];

    if (!adminBody && roots.length === 0) {
        return;
    }

    const storageKey = 'dashboard-theme';
    let currentTheme = 'light';

    function applyTheme(theme) {
        currentTheme = theme === 'dark' ? 'dark' : 'light';
        const dark = currentTheme === 'dark';
        const label = dark ? 'Modo claro' : 'Modo escuro';

        if (adminBody) {
            adminBody.setAttribute('data-dashboard-theme', currentTheme);
            adminBody.setAttribute('data-bs-theme', currentTheme);
        }

        roots.forEach(root => root.setAttribute('data-dashboard-theme', currentTheme));
        toggles.forEach(toggle => {
            const text = toggle.querySelector('[data-dashboard-theme-label]');
            const icon = toggle.querySelector('[data-dashboard-theme-icon]');

            if (text) {
                text.textContent = label;
            } else if (!icon) {
                toggle.textContent = label;
            }

            if (icon) {
                icon.classList.toggle('bi-sun', dark);
                icon.classList.toggle('bi-moon', !dark);
            }

            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('title', label);
            toggle.setAttribute('aria-pressed', String(dark));
            toggle.classList.toggle('btn-outline-light', dark);
            toggle.classList.toggle('btn-outline-secondary', !dark);
        });
    }

    let savedTheme = 'light';
    try {
        savedTheme = browser.localStorage.getItem(storageKey);
    } catch {
        savedTheme = 'light';
    }
    applyTheme(savedTheme);

    toggles.forEach(toggle => toggle.addEventListener('click', () => {
        applyTheme(currentTheme === 'dark' ? 'light' : 'dark');
        try {
            browser.localStorage.setItem(storageKey, currentTheme);
        } catch {
            // Keep the theme usable when browser storage is unavailable.
        }
    }));
}
