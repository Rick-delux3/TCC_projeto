import { initializeDocumentMask } from './document-mask';

export const initializeAdminLeadFields = (container) => {
    const field = name => container.querySelector(`[name="${name}"]`);
    const documentInput = field('cpf');
    const formatDocument = initializeDocumentMask(documentInput);
    const sync = (clearHidden = false) => {
        const company = formatDocument().length === 14;
        const status = field('estado_civil').value;
        const profile = field('tipo_solicitante').value;
        const conditions = {
            company,
            spouse: ['casado', 'uniao_estavel', 'divorciado', 'viuvo'].includes(status),
            commercial: field('tipo_locacao').value === 'comercial',
            requester: ['locador', 'imobiliaria_nao_cadastrada'].includes(profile),
            registered: profile === 'imobiliaria_cadastrada',
            'requester-section': ['locador', 'imobiliaria_nao_cadastrada', 'imobiliaria_cadastrada'].includes(profile),
        };
        documentInput.setAttribute('aria-expanded', String(company));
        container.querySelectorAll('[data-admin-condition]').forEach(wrapper => {
            const condition = wrapper.dataset.adminCondition;
            const visible = conditions[condition];
            wrapper.hidden = !visible;
            if (condition === 'requester-section') return;
            const required = visible && (['company', 'commercial'].includes(condition) || (condition === 'spouse' && ['casado', 'uniao_estavel'].includes(status)));
            wrapper.querySelectorAll('input,select,textarea').forEach(input => {
                input.disabled = !visible;
                input.required = required;
                if (!visible && clearHidden) input.value = '';
            });
            const marker = wrapper.querySelector('[data-required-marker]');
            if (marker) marker.hidden = !required;
        });
    };
    ['cpf', 'estado_civil', 'tipo_locacao', 'tipo_solicitante'].forEach(name => {
        field(name).addEventListener(name === 'cpf' ? 'input' : 'change', () => sync(true));
    });
    documentInput.addEventListener('change', () => sync(true));
    window.addEventListener('pageshow', () => sync());
    sync();
};
