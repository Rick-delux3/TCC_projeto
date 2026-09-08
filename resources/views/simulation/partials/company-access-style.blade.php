@push('styles')
<style>
    body[data-brand] .auth-layout-main:has(.company-access-page) { min-height: calc(100vh - clamp(76px, 6.4vw, 108px)); padding: 40px 20px 64px; background: #03052e; }
    body[data-brand="client"] .auth-layout-main:has(.company-access-page) { background: #001650; }
    body[data-brand="tcc"]:has(.company-access-page) .auth-topbar { border-bottom: 4px solid transparent; border-image: linear-gradient(90deg, #0754ca 60%, #fd1e6e 60%) 1; }
    .company-access-page { --access-blue: #064bc1; --access-accent: #fd1e6e; width: 100%; max-width: 620px; margin: 0 auto; color: #061339; font-family: "Sansation", sans-serif; }
    [data-brand="client"] .company-access-page { --access-blue: #00288f; --access-accent: #00288f; }
    .company-access-card { background: linear-gradient(135deg, #fff, #f5f6f8); border: 1px solid #dce1eb; border-radius: 26px; padding: 48px; box-shadow: 0 16px 40px #0000000d; }
    .company-access-card h1 { margin: 0; font-size: clamp(23px, 2vw, 29px); font-weight: 700; line-height: 1.25; letter-spacing: -.7px; color: #061339; }
    .company-access-card p { margin: 0; color: #566583; font-size: 18px; line-height: 1.6; }
    .company-access-card form { margin: 0; }
    .company-access-card label { font-size: 16px; font-weight: 700; }
    .company-access-card input { width: 100%; min-height: 54px; padding: 12px 15px; border: 1px solid #cbd2e0; border-radius: 0; background: #fff; color: #061339; font-size: 18px; }
    .company-access-card input::placeholder { color: #65748d; opacity: 1; }
    .company-access-card input[aria-invalid="true"] { border-color: #b42335; }
    .company-access-card .access-error { color: #b42335; font-size: 15px; }
    .company-access-card a { color: var(--access-blue); text-decoration: none; }
    .company-access-card a:hover { text-decoration: underline; }
    .company-access-card .access-button { display: flex; align-items: center; justify-content: center; min-height: 56px; width: 100%; padding: 12px 18px; border: 1px solid transparent; border-radius: 12px; background: var(--access-blue); color: #fff !important; font-weight: 700; font-size: 18px; text-align: center; text-decoration: none !important; transition: filter .15s; }
    .company-access-card .access-button:hover { filter: brightness(.9); }
    .company-access-card .access-button:disabled { opacity: .65; cursor: wait; }
    .company-access-card :is(input, a, button):focus-visible { outline: 3px solid var(--access-blue); outline-offset: 4px; }
    .access-icon { width: 64px; height: 64px; color: var(--access-blue); }
    .access-icon .accent { stroke: var(--access-accent); }
    [data-brand="client"] .access-icon { align-self: center; }
    .access-back { display: flex; align-items: center; gap: 10px; min-height: 44px; font-size: 17px; }
    .access-divider { border-top: 1px solid #d9deea; padding-top: 20px; }
    .access-forgot { display: flex; justify-content: flex-end; align-items: center; gap: 10px; min-height: 44px; font-size: 17px; }
    @media (max-width: 600px) { body[data-brand] .auth-layout-main:has(.company-access-page) { padding: 24px 16px 40px; } .company-access-card { padding: 28px 24px; border-radius: 22px; } .company-access-card p { font-size: 16px; } }
    @media (prefers-reduced-motion: reduce) { .company-access-card .access-button { transition: none; } }
</style>
@endpush
@push('scripts')
<script>
    document.querySelectorAll('[data-company-access-form]').forEach(form => {
        form.addEventListener('submit', event => {
            if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
            if (!form.checkValidity()) { return; }
            form.dataset.submitting = 'true';
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = 'Aguarde…';
        });
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) { window.location.reload(); }
    });
</script>
@endpush
