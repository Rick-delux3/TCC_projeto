@push('styles')
<style>
    body[data-brand] .auth-layout-main:has(.company-access-page) { min-height: calc(100dvh - clamp(76px, 6.4vw, 108px)); padding: clamp(32px, 5.6vw, 88px) 24px 64px; background: #f2f6fd; }
    body[data-brand="tcc"]:has(.company-access-page) .auth-topbar { border-bottom: 4px solid transparent; border-image: linear-gradient(90deg, #0754ca 60%, #fd1e6e 60%) 1; }
    .company-access-page { --access-blue: #003bad; --access-accent: #fd1e6e; width: 100%; max-width: 1136px; margin: 0 auto; color: #061339; font-family: "Sansation", sans-serif; }
    [data-brand="client"] .company-access-page { --access-blue: #00288f; --access-accent: #00288f; }
    .company-access-layout { display: grid; grid-template-columns: minmax(0, .57fr) minmax(0, 1fr); overflow: hidden; border: 1px solid #d0dcf0; border-radius: 20px; background: #fff; }
    .company-access-art { position: relative; min-width: 0; background: #002354; }
    .company-access-art img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .company-access-card { min-width: 0; background: #fff; border: 1px solid #d0dcf0; border-radius: 20px; padding: clamp(32px, 4.8vw, 76px); }
    .company-access-layout .company-access-card { display: flex; flex-direction: column; justify-content: center; min-height: 632px; border: 0; border-radius: 0; }
    .company-access-card h1 { margin: 0; font-size: clamp(28px, 3vw, 44px); font-weight: 700; line-height: 1.18; letter-spacing: -1.2px; color: #06194e; }
    .company-access-card p { margin: 0; color: #697b9d; font-size: 20px; line-height: 1.5; }
    .company-access-card form { margin: 0; }
    .company-access-card label { font-size: 18px; font-weight: 700; }
    .company-access-card input { width: 100%; min-height: 64px; padding: 14px 18px; border: 1px solid #cbd9ee; border-radius: 4px; background: #fff; color: #061339; font-size: 20px; transition: border-color .18s ease, box-shadow .18s ease; }
    .company-access-card input:hover { border-color: #8ba7d4; }
    .company-access-card input:focus { border-color: var(--access-blue); box-shadow: 0 0 0 3px #003bad12; }
    .company-access-card input::placeholder { color: #65748d; opacity: 1; }
    .company-access-card input[aria-invalid="true"] { border-color: #b42335; }
    .company-access-card .access-error { color: #b42335; font-size: 15px; }
    .company-access-card a { color: var(--access-blue); text-decoration: none; }
    .company-access-card a:hover { text-decoration: underline; }
    .company-access-card .access-button { display: flex; align-items: center; justify-content: center; min-height: 56px; width: 100%; padding: 12px 18px; border: 1px solid transparent; border-radius: 12px; background: var(--access-blue); color: #fff !important; font-weight: 700; font-size: 18px; text-align: center; text-decoration: none !important; transition: filter .15s; }
    .company-access-card .access-button:hover { filter: brightness(.9); }
    .company-access-card .access-button:disabled { opacity: .65; cursor: wait; }
    .company-access-layout .access-button { min-height: 64px; font-size: 20px; border-radius: 10px; }
    .company-access-card :is(input, a, button):focus-visible { outline: 3px solid var(--access-blue); outline-offset: 4px; }
    .access-icon { width: 64px; height: 64px; color: var(--access-blue); }
    .access-icon .accent { stroke: var(--access-accent); }
    [data-brand="client"] .access-icon { align-self: center; }
    .access-back { display: flex; align-items: center; gap: 10px; min-height: 44px; font-size: 17px; }
    .access-divider { border-top: 1px solid #d9deea; padding-top: 20px; }
    .access-forgot { display: flex; justify-content: flex-end; align-items: center; gap: 10px; min-height: 44px; font-size: 17px; }
    @media (max-width: 767px) {
        body[data-brand] .auth-layout-main:has(.company-access-page) { padding: 28px 16px 40px; }
        .company-access-layout { grid-template-columns: minmax(0, 1fr); border-radius: 16px; }
        .company-access-art { display: none; }
        .company-access-card { padding: 36px 24px; }
        .company-access-layout .company-access-card { min-height: 0; }
        .company-access-card h1 { font-size: 30px; letter-spacing: -.7px; }
        .company-access-card p { font-size: 17px; }
        .company-access-card input { min-height: 56px; font-size: 17px; }
    }
    @media (prefers-reduced-motion: reduce) { .company-access-card :is(input, .access-button) { transition: none; } }
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
