import { buildDepartmentPayload } from './company-departments.js';

const defaults = [
    { key: 'comercial', name: 'Comercial / Vendas' },
    { key: 'contratos', name: 'Contrato / Conferência' },
    { key: 'gerencia', name: 'Gerência' },
    { key: 'financeiro', name: 'Financeiro / Pagamentos' },
    { key: 'socio', name: 'Sócio / Proprietário' },
];

export function editDepartmentRows(departments) {
    const rows = departments.map((department) => ({ ...department, email: department.email ?? '', custom: true }));
    const keys = new Set(rows.map(({ key }) => key));
    defaults.forEach((department) => {
        if (!keys.has(department.key) && rows.length < 50) {
            rows.push({ ...department, email: '', custom: false });
        }
    });
    return rows;
}

export function initializeEditDepartments(form) {
    const list = form.querySelector('[data-edit-department-list]');
    const template = form.querySelector('[data-edit-department-template]');
    const add = form.querySelector('[data-edit-add-department]');
    const feedback = form.querySelector('[data-edit-department-feedback]');
    if (!list || !template || !add) return () => {};

    const rows = () => [...list.querySelectorAll('[data-edit-department-row]')];
    const refresh = () => {
        rows().forEach((row, index) => {
            ['key', 'name', 'email'].forEach((field) => {
                const input = row.querySelector(`[data-department-${field}]`);
                input.name = `setores[${index}][${field}]`;
                input.id = `edit-department-${field}-${index}`;
                row.querySelector(`[data-department-${field}-label]`)?.setAttribute('for', input.id);
            });
        });
        add.disabled = rows().length >= 50;
    };
    const append = (department) => {
        const row = template.content.firstElementChild.cloneNode(true);
        row.dataset.custom = String(department.custom);
        ['key', 'name', 'email'].forEach((field) => {
            row.querySelector(`[data-department-${field}]`).value = department[field] ?? '';
        });
        list.append(row);
        return row;
    };

    add.addEventListener('click', () => {
        if (rows().length >= 50) return;
        const row = append({ custom: true });
        refresh();
        row.querySelector('[data-department-name]').focus();
        feedback.textContent = 'Setor adicionado. Informe o nome e, se desejar, o e-mail.';
    });
    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-edit-remove-department]');
        if (!button) return;
        button.closest('[data-edit-department-row]').remove();
        refresh();
        add.focus();
        feedback.textContent = 'Setor removido. Salve as alterações para confirmar.';
    });
    form.addEventListener('formdata', (event) => {
        const departments = rows().map((row) => ({
            key: row.querySelector('[data-department-key]').value,
            name: row.querySelector('[data-department-name]').value,
            email: row.querySelector('[data-department-email]').value,
            custom: row.dataset.custom === 'true',
        }));
        [...event.formData.keys()].filter((key) => /^setores(?:\[|$)/.test(key))
            .forEach((key) => event.formData.delete(key));
        buildDepartmentPayload(departments, true).forEach((department, index) => {
            Object.entries(department).forEach(([field, value]) => {
                event.formData.append(`setores[${index}][${field}]`, value);
            });
        });
    });
    refresh();

    return (departments) => {
        list.replaceChildren();
        editDepartmentRows(departments).forEach(append);
        feedback.textContent = '';
        refresh();
    };
}
