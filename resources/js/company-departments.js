export function buildDepartmentPayload(departments, enabled) {
    if (!enabled) {
        return [];
    }

    const usedKeys = new Set(departments.map(({ key }) => key.trim().toLowerCase()).filter(Boolean));

    return departments
        .filter(({ custom, email }) => custom || email.trim() !== '')
        .map(({ key, name, email }, index) => {
            let normalizedKey = key.trim().toLowerCase();

            if (!normalizedKey) {
                const slug = name.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60) || 'setor';
                let suffix = index + 1;
                do {
                    normalizedKey = `personalizado_${slug}_${suffix++}`;
                } while (usedKeys.has(normalizedKey));
                usedKeys.add(normalizedKey);
            }

            return { key: normalizedKey, name: name.trim(), email: email.trim().toLowerCase() };
        });
}
