/**
 * "Baixar cotação": o link funciona sozinho (download direto); com JS, o PDF é
 * buscado via fetch para mostrar "Gerando PDF…" enquanto a companhia responde
 * (a Pottencial pode levar perto de 1 minuto) e o erro no próprio card.
 * Os cards são re-renderizados em tempo real: o estado fica por companhia e é
 * reaplicado em apply().
 */
const ERROR_MESSAGE = 'Não foi possível obter o documento, tente novamente.';

export function createQuotePdfDownloads({ root = document, fetchPdf = window.fetch.bind(window) } = {}) {
    const pending = new Set();

    function setLoading(link, loading) {
        link.setAttribute('aria-busy', loading ? 'true' : 'false');
        link.classList.toggle('disabled', loading);
        link.querySelector('[data-loading-indicator]')?.classList.toggle('d-none', !loading);
        link.querySelector('[data-idle-icon]')?.classList.toggle('d-none', loading);
        const label = link.querySelector('[data-button-label]');
        if (label) label.textContent = loading ? 'Gerando PDF…' : 'Baixar cotação';
    }

    function showError(provider, message) {
        const link = root.querySelector(`[data-quote-pdf="${provider}"]`);
        const error = link?.parentElement?.querySelector('[data-quote-pdf-error]');
        if (!error) return;
        error.textContent = message;
        error.classList.toggle('d-none', !message);
    }

    function fileName(response, provider) {
        const header = response.headers.get('Content-Disposition') ?? '';
        const encoded = header.match(/filename\*=UTF-8''([^;]+)/i);
        if (encoded) return decodeURIComponent(encoded[1]);
        const plain = header.match(/filename="?([^";]+)"?/i);

        return plain ? plain[1] : `cotacao-${provider}.pdf`;
    }

    function save(blob, name) {
        const url = URL.createObjectURL(blob);
        const anchor = root.createElement('a');
        anchor.href = url;
        anchor.download = name;
        anchor.hidden = true;
        root.body.append(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    }

    async function download(link) {
        const provider = link.dataset.quotePdf;
        if (pending.has(provider)) return;
        pending.add(provider);
        showError(provider, '');
        setLoading(link, true);
        try {
            const response = await fetchPdf(link.href, {
                credentials: 'same-origin',
                headers: { Accept: 'application/pdf' },
            });
            // Falha da companhia: o servidor redireciona para a página com o aviso (HTML).
            if (!response.ok || !(response.headers.get('Content-Type') ?? '').includes('application/pdf')) {
                showError(provider, ERROR_MESSAGE);
                return;
            }
            save(await response.blob(), fileName(response, provider));
        } catch {
            showError(provider, ERROR_MESSAGE);
        } finally {
            pending.delete(provider);
            apply();
        }
    }

    function apply() {
        root.querySelectorAll('[data-quote-pdf]').forEach(link => setLoading(link, pending.has(link.dataset.quotePdf)));
    }

    root.addEventListener('click', event => {
        const link = event.target.closest('[data-quote-pdf]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
        event.preventDefault();
        download(link);
    });

    apply();

    return { apply };
}
