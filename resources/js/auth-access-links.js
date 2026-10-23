export function protectAuthAccessLinks(root, now = () => performance.now()) {
    let nextAllowedAt = 0;

    const handleActivation = (event) => {
        if (event.defaultPrevented || event.button > 1) {
            return;
        }

        const link = event.target.closest?.('a[data-auth-access]');

        if (!link || link.target !== '_blank') {
            return;
        }

        const currentTime = now();

        if (currentTime < nextAllowedAt) {
            event.preventDefault();
            return;
        }

        nextAllowedAt = currentTime + 1000;
    };

    root.addEventListener('click', handleActivation, true);
    root.addEventListener('auxclick', handleActivation, true);
}
