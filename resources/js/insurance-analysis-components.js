export function createAnalysisComponents({ root = document, onUpdated = () => {} } = {}) {
    const pending = new Map();
    const rendered = new Map();
    const allowed = /^(batch|comparison|actions|expense-form|(?:provider|details)-(?:pottencial|too|tokio|porto))$/;

    function apply(key, replacement) {
        const current = root.querySelector(`[data-analysis-component="${key}"]`);
        if (!current || (rendered.get(key) ?? current.outerHTML) === replacement.outerHTML) {
            pending.delete(key);
            return;
        }
        if (current.querySelector('dialog[open]')) {
            pending.set(key, replacement);
            return;
        }
        rendered.set(key, replacement.outerHTML);
        current.replaceWith(replacement);
        pending.delete(key);
    }

    function update(html) {
        if (typeof html !== 'string') return;
        const template = root.createElement('template');
        template.innerHTML = html;
        template.content.querySelectorAll('[data-analysis-component]').forEach(component => {
            const key = component.dataset.analysisComponent;
            if (allowed.test(key)) apply(key, component);
        });
        onUpdated();
    }

    function flush() {
        pending.forEach((component, key) => apply(key, component));
        onUpdated();
    }

    return { update, flush };
}
