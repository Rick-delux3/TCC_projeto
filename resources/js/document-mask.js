export const initializeDocumentMask = (documentInput) => {
    const format = () => {
        const value = documentInput.value;
        const cursor = documentInput.selectionStart ?? value.length;
        const digitsBeforeCursor = value.slice(0, cursor).replace(/\D/g, '').length;
        const normalized = value.replace(/\D/g, '').slice(0, 14);
        const pattern = normalized.length > 11 ? '##.###.###/####-##' : '###.###.###-##';
        let formatted = '';
        let digitIndex = 0;

        for (const character of pattern) {
            if (digitIndex >= normalized.length) {
                break;
            }

            formatted += character === '#' ? normalized[digitIndex++] : character;
        }

        if (value !== formatted) {
            documentInput.value = formatted;

            if (document.activeElement === documentInput) {
                let position = 0;
                let digits = 0;

                while (position < formatted.length && digits < digitsBeforeCursor) {
                    if (/\d/.test(formatted[position])) {
                        digits++;
                    }
                    position++;
                }

                documentInput.setSelectionRange(position, position);
            }
        }

        return normalized;
    };

    documentInput.addEventListener('beforeinput', (event) => {
        const start = documentInput.selectionStart;
        const end = documentInput.selectionEnd;

        if (start === null || start !== end) {
            return;
        }

        const backwards = event.inputType === 'deleteContentBackward';
        const forwards = event.inputType === 'deleteContentForward';
        const adjacent = documentInput.value[backwards ? start - 1 : start];

        if ((!backwards && !forwards) || !adjacent || !/[.\/-]/.test(adjacent)) {
            return;
        }

        event.preventDefault();
        documentInput.setRangeText('', backwards ? Math.max(0, start - 2) : start, backwards ? start : start + 2, 'end');
        documentInput.dispatchEvent(new Event('input', { bubbles: true }));
    });
    documentInput.addEventListener('input', format);
    documentInput.addEventListener('change', format);
    format();
    return format;
};
