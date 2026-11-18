@push('scripts')
<script>
    document.querySelectorAll('[data-company-access-form]').forEach(form => {
        form.addEventListener('submit', event => {
            if (form.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }

            if (!form.checkValidity()) {
                return;
            }

            form.dataset.submitting = 'true';
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = 'Aguarde…';
        });
    });

    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            window.location.reload();
        }
    });
</script>
@endpush
