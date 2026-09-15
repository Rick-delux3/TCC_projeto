@push('styles')
<style>
    body:has(.simulation-wizard) { --simulation-ink: #080c59; --simulation-muted: #6676a3; --simulation-line: #d6dff0; --simulation-blue: #0644f7; --simulation-red: #f00020; font-family: 'TASA Explorer', Arial, sans-serif; }
    body.auth-layout-body[data-brand]:has(.simulation-wizard) .auth-layout-main { padding: 30px 3% 26px; min-height: calc(100dvh - 72px); background: #f2f6fd; }
    body:has(.simulation-wizard) .auth-topbar { --auth-topbar-height: 72px; box-shadow: none; }
    body:has(.simulation-wizard) .auth-topbar__inner { padding-inline: 3.4%; }
    body:has(.simulation-wizard) .auth-topbar__product { color: var(--simulation-ink); }
    body:has(.simulation-wizard) .auth-topbar__help { color: var(--simulation-blue); }
    body:has(.simulation-wizard) .auth-topbar__access { min-height: 44px; color: var(--simulation-blue); border-color: var(--simulation-blue); border-radius: 5px; }
    body.auth-layout-body[data-brand='client']:has(.simulation-wizard) .auth-topbar__brand-mark { width: 120px; height: 50px; flex-basis: 120px; }
    body.auth-layout-body[data-brand]:has(.simulation-wizard) .auth-topbar__nav .auth-topbar__link.auth-topbar__access { min-height: 44px; min-width: 114px; box-shadow: none !important; color: var(--simulation-blue) !important; border-color: var(--simulation-blue) !important; }
    body:has(.simulation-wizard) .auth-topbar__divider { height: 36px; }
    body.auth-layout-body[data-brand]:has(.simulation-wizard) .auth-topbar__surface { box-shadow: none !important; }
    .simulation-wizard { max-width: 1360px; margin-inline: auto; color: var(--simulation-ink); align-items: stretch; gap: 18px !important; }
    .simulation-wizard [hidden] { display: none !important; }
    .simulation-sidebar, .simulation-form-card { border: 1px solid #dfe7f5; border-radius: 13px; background: #fff; }
    .simulation-sidebar { padding: 34px 18px; min-width: 0; }
    .simulation-sidebar-heading { padding-inline: 14px; }
    .simulation-sidebar p { margin: 0; }
    .simulation-eyebrow { color: var(--simulation-muted); font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
    .simulation-sidebar h2 { margin: 8px 0; color: var(--simulation-ink); font-size: 27px; line-height: 1.15; font-weight: 750; }
    .simulation-sidebar-heading > p:not(.simulation-eyebrow) { font-size: 16px; line-height: 1.5; color: var(--simulation-muted); }
    .simulation-sidebar-heading .simulation-company-name { margin-top: 12px; font-weight: 650; color: var(--simulation-ink) !important; overflow-wrap: anywhere; }
    .simulation-step-nav { margin-top: 35px; }
    .simulation-step-nav ol { margin: 0; padding: 0; list-style: none; }
    .simulation-step-nav li { position: relative; }
    .simulation-step-nav li:first-child::after { content: ''; position: absolute; left: 32px; top: 59px; width: 2px; height: 14px; background: var(--simulation-line); }
    .simulation-step { width: 100%; display: flex; gap: 18px; align-items: center; min-height: 60px; padding: 10px 12px; border: 0; border-radius: 10px; background: transparent; color: var(--simulation-muted); font-size: 15px; text-align: left; }
    .simulation-step[aria-current='step'] { background: #e9f1ff; color: var(--simulation-blue); font-weight: 650; }
    .simulation-step-number { display: grid; place-items: center; width: 39px; height: 39px; flex-shrink: 0; border: 1px solid var(--simulation-line); border-radius: 50%; background: #fff; font-size: 18px; }
    .simulation-step[aria-current='step'] .simulation-step-number, .simulation-step.is-complete .simulation-step-number { color: #fff; border-color: var(--simulation-blue); background: var(--simulation-blue); box-shadow: inset 0 0 0 3px #ffffff14; }
    .simulation-sidebar-help { display: flex; align-items: flex-start; gap: 12px; margin: 36px 14px 0; padding-top: 35px; border-top: 1px solid var(--simulation-line); }
    .simulation-sidebar-help > svg { width: 25px; height: 25px; flex-shrink: 0; color: var(--simulation-blue); }
    .simulation-sidebar-help h3 { margin: 0 0 9px; font-size: 15px; font-weight: 700; line-height: 1.5; }
    .simulation-sidebar-help p { color: var(--simulation-muted); font-size: 14px; line-height: 1.6; }
    .simulation-sidebar-help .simulation-help-note { margin-top: 12px; font-size: 13px; }
    .simulation-sidebar-help-bottom { margin-top: auto; }
    .simulation-form-card { min-width: 0; padding: 30px 44px 25px; }
    .simulation-form-heading { margin-bottom: 28px; }
    .simulation-form-heading h1, .simulation-form-heading h2 { margin: 0; color: var(--simulation-ink); font-size: clamp(26px, 2.45vw, 34px); line-height: 1.2; font-weight: 750; letter-spacing: -.025em; }
    .simulation-form-heading p { margin: 5px 0 0; font-size: 20px; color: var(--simulation-muted); line-height: 1.45; }
    .simulation-required-note { margin-top: 12px; color: var(--simulation-muted); font-size: 12px; white-space: nowrap; }
    .simulation-required { color: #eb0024; }
    .simulation-section-title { margin: 0 0 18px; padding-bottom: 5px; border-bottom: 1px solid var(--simulation-line); font-size: 20px; line-height: 1.35; font-weight: 700; color: var(--simulation-ink); }
    .simulation-section-divider { margin-top: 30px; padding-top: 15px; border-top: 1px solid var(--simulation-line); border-bottom: 0; }
    .simulation-section-description { margin: -5px 0 20px; font-size: 14px; line-height: 1.5; color: var(--simulation-muted); }
    .simulation-context { margin-bottom: 20px; padding: 12px 16px; background: #edf3ff; border-radius: 7px; color: var(--simulation-muted); font-size: 14px; }
    .simulation-wizard .simulation-field, .simulation-wizard .col-md-6, .simulation-wizard .col-md-4, .simulation-wizard .col-12 { min-width: 0; width: auto; padding: 0; }
    .simulation-wizard .simulation-field, .simulation-wizard .col-md-6, .simulation-wizard .col-md-4 { display: flex; flex-direction: column; gap: 7px; }
    .simulation-wizard .simulation-field label, .simulation-wizard .form-label, .simulation-field-label { margin: 0; font-size: 14px; font-weight: 550; color: var(--simulation-ink); line-height: 1.45; }
    .simulation-field-label { margin-bottom: 8px; }
    .simulation-form-errors { margin-bottom: 22px; padding: 16px; border: 1px solid #f0b9c3; border-radius: 7px; background: #fff6f7; color: #a6092a; font-size: 14px; }
    .simulation-form-errors ul { margin: 8px 0 0; padding-left: 18px; list-style: disc; }
    .simulation-form-errors button { text-align: left; text-decoration: underline; }
    body[data-brand] .simulation-wizard input:not([type='radio']):not([type='checkbox']):not([type='hidden']), body[data-brand] .simulation-wizard select { display: block; width: 100%; min-width: 0; height: 44px; padding: 10px 14px; border: 1px solid #cfd8e9; border-radius: 5px; background-color: #fff; color: var(--simulation-ink); font-family: inherit; font-size: 14px; line-height: 22px; box-shadow: 0 1px 2px #0c1f4c03; transition: border-color .15s, box-shadow .15s; }
    body[data-brand] .simulation-wizard input::placeholder { color: #8794b4; opacity: 1; }
    body[data-brand] .simulation-wizard input:focus, body[data-brand] .simulation-wizard select:focus { outline: none; border-color: var(--simulation-blue); box-shadow: 0 0 0 2px #0644f718; }
    body[data-brand] .simulation-wizard input[readonly] { background: #f6f8fc; color: var(--simulation-muted); }
    .simulation-wizard input[type='radio'], .simulation-wizard input[type='checkbox'] { width: 20px; height: 20px; margin: 0; flex-shrink: 0; accent-color: var(--simulation-blue); color: var(--simulation-blue); border-color: #9eafd0; }
    .simulation-wizard input[type='radio']:focus-visible, .simulation-wizard input[type='checkbox']:focus-visible { outline: 2px solid var(--simulation-blue); outline-offset: 3px; }
    .simulation-wizard .simulation-field-error { font-size: 12px; color: #c40024; line-height: 1.45; margin: 0; }
    .simulation-wizard [aria-invalid='true'] { border-color: #dc143c !important; }
    .simulation-wizard .simulation-fields > .col-12 { grid-column: 1 / -1; }
    .simulation-wizard #company-fields { display: contents; }
    .simulation-wizard #spouse-fields:has(input) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px 24px; grid-column: 1 / -1; }
    .simulation-wizard #spouse-fields:not(:has(input)) { display: none; }
    .simulation-wizard #spouse-fields > div:has(input:required) label::after { content: ' *'; color: #eb0024; }
    .simulation-wizard #company-fields > div { background: #f7f9fe; padding: 12px; border-radius: 5px; }
    .simulation-requester-choice { min-height: 65px; padding: 13px 16px; border: 1px solid var(--simulation-line); border-radius: 5px; color: var(--simulation-muted); font-size: 14px; cursor: pointer; }
    .simulation-requester-choice:has(input:checked) { border-color: var(--simulation-blue); color: var(--simulation-ink); background: #f8fbff; box-shadow: 0 0 0 1px #0644f710; }
    .simulation-choice-icon { display: flex; padding-right: 16px; border-right: 1px solid var(--simulation-line); }
    .simulation-choice-icon svg { width: 30px; height: 30px; color: var(--simulation-ink); }
    .simulation-button { display: inline-flex; align-items: center; justify-content: center; gap: 14px; min-height: 45px; padding: 10px 28px; border: 1px solid; border-radius: 6px; text-decoration: none; font-size: 15px; line-height: 23px; font-weight: 650; transition: background-color .15s, transform .15s; cursor: pointer; }
    body[data-brand] .simulation-wizard .simulation-button-primary { min-width: 175px; color: #fff; background: var(--simulation-red); border-color: var(--simulation-red); }
    body[data-brand] .simulation-wizard .simulation-button-primary:hover { background: #cf001c; border-color: #cf001c; }
    body[data-brand] .simulation-wizard .simulation-button-back { min-width: 120px; color: #55658c; border-color: #97a8ca; background: #fff; }
    body[data-brand] .simulation-wizard .simulation-button-outline { min-width: 120px; color: var(--simulation-blue); border-color: var(--simulation-blue); background: #fff; }
    .simulation-button:active { transform: translateY(1px); }
    .simulation-button:focus-visible, .simulation-step:focus-visible { outline: 3px solid #7ea7ff; outline-offset: 3px; }
    .simulation-button:disabled { opacity: .7; cursor: wait; }
    .simulation-step-footer { margin-top: 30px; }
    .simulation-next-hint { color: var(--simulation-muted); font-size: 12px; }
    .simulation-submit-footer { margin-top: 20px; }
    .simulation-submit-footer [type='submit'] { min-width: 225px !important; }
    .simulation-money { display: flex; align-items: center; border: 1px solid #cfd8e9; border-radius: 5px; background: #fff; overflow: hidden; }
    .simulation-money > span { padding: 8px 17px; border-right: 1px solid var(--simulation-line); font-size: 14px; font-weight: 650; }
    body[data-brand] .simulation-wizard .simulation-money input { border: 0 !important; border-radius: 0; box-shadow: none !important; height: 42px; }
    .simulation-money:focus-within { border-color: var(--simulation-blue); box-shadow: 0 0 0 2px #0644f718; }
    .simulation-expense-row { display: flex; align-items: flex-start; gap: 8px; }
    .simulation-expense-row > .simulation-field { flex: 1; }
    .simulation-remove-expense { width: 43px; height: 44px; display: grid; place-items: center; border: 1px solid #cfd8e9; border-radius: 5px; background: #fff; margin-top: 27px; color: #52618b; }
    .simulation-remove-expense:hover { color: #ce0025; background: #fff4f6; }
    .simulation-remove-expense svg { width: 18px; height: 18px; }
    .simulation-expense-add { padding-inline: 14px; gap: 6px; white-space: nowrap; font-weight: 500; min-height: 44px; }
    .simulation-expense-info { display: flex; align-items: center; gap: 11px; padding: 12px 14px; border-radius: 8px; background: #eaf2ff; color: #52658e; font-size: 12px; line-height: 1.5; }
    .simulation-expense-info svg { color: var(--simulation-blue); flex-shrink: 0; width: 21px; height: 21px; }
    .simulation-address-title { margin-top: 26px; }
    .simulation-field-hint { color: var(--simulation-muted); font-size: 12px; line-height: 1.5; }
    .simulation-consent { display: flex; align-items: flex-start; gap: 12px; margin-top: 20px; color: #53658d; font-size: 13px; line-height: 1.6; }
    .simulation-consent input { margin-top: 3px !important; }
    .simulation-consent a { color: var(--simulation-blue); text-decoration: underline; }
    .simulation-wizard .simulation-icon { flex-shrink: 0; }
    .simulation-wizard input, .simulation-wizard select, .simulation-requester-choice, .simulation-step { font-size: 16px !important; }
    .simulation-wizard .simulation-field label, .simulation-wizard .form-label { font-size: 16px; }
    .simulation-wizard .simulation-form-heading p { font-size: 24px; }
    .simulation-wizard .simulation-section-title { font-size: 22px; }
    .simulation-wizard #commercial-fields:has(input) { display: block; grid-column: 1 / -1; }
    .simulation-wizard #commercial-fields:not(:has(input)) { display: none; }
    .simulation-wizard.is-enhanced [data-form-step='2'] { display: none; }
    .simulation-wizard.is-enhanced[data-active-step='2'] [data-form-step='1'] { display: none; }
    .simulation-wizard.is-enhanced[data-active-step='2'] [data-form-step='2'] { display: block; }
    @media (min-width: 1024px) {
        .simulation-form-card { min-height: 785px; }
        .simulation-wizard[data-active-step='2'] .simulation-form-card { min-height: 900px; }
        .simulation-wizard [data-form-step='2'] .simulation-form-heading { margin-bottom: 20px; }
        .simulation-wizard [data-form-step='2'] .simulation-form-heading h2 { font-size: 30px; }
        .simulation-wizard [data-form-step='2'] .simulation-form-heading p { font-size: 18px; }
        .simulation-wizard [data-form-step='2'] .simulation-section-title { font-size: 20px; margin-bottom: 14px; }
        .simulation-wizard [data-form-step='2'] .simulation-field label, .simulation-wizard [data-form-step='2'] .form-label { font-size: 14px; }
    }
    @media (max-width: 1023px) {
        .simulation-sidebar { padding: 20px; }
        .simulation-sidebar-heading { padding: 0; }
        .simulation-sidebar-heading h2 { font-size: 23px; }
        .simulation-step-nav { margin-top: 20px; }
        .simulation-step-nav ol { flex-direction: row; gap: 10px; }
        .simulation-step-nav li { flex: 1; }
        .simulation-step-nav li::after { display: none; }
        .simulation-sidebar-help { display: none !important; }
        .simulation-form-card { padding: 28px; }
    }
    @media (max-width: 600px) {
        body.auth-layout-body[data-brand]:has(.simulation-wizard) .auth-layout-main { padding: 16px 12px 24px; }
        .simulation-sidebar { padding: 20px 14px 12px; }
        .simulation-sidebar-heading { padding-inline: 6px; }
        .simulation-sidebar h2 { margin: 5px 0; }
        .simulation-sidebar-heading > p:not(.simulation-eyebrow) { font-size: 14px; }
        .simulation-step { gap: 9px; padding-inline: 6px; font-size: 12px; min-height: 52px; }
        .simulation-wizard .simulation-step { font-size: 12px !important; }
        .simulation-step-number { width: 31px; height: 31px; font-size: 15px; }
        .simulation-form-card { padding: 25px 18px 22px; }
        .simulation-form-heading { margin-bottom: 24px; gap: 8px; }
        .simulation-form-heading h1, .simulation-form-heading h2 { font-size: 26px; }
        .simulation-wizard .simulation-form-heading p { font-size: 16px; }
        .simulation-required-note { margin-top: 0; font-size: 11px; }
        .simulation-wizard .simulation-section-title { font-size: 18px; }
        .simulation-wizard #spouse-fields:has(input) { grid-template-columns: minmax(0, 1fr); }
        .simulation-next-hint { max-width: 170px; text-align: right; }
        .simulation-step-footer .simulation-button { min-width: auto !important; padding-inline: 20px; }
        .simulation-submit-footer { flex-direction: column-reverse; }
        .simulation-submit-footer .simulation-button { width: 100%; }
        body[data-brand] .simulation-wizard input:not([type='radio']):not([type='checkbox']):not([type='hidden']), body[data-brand] .simulation-wizard select { font-size: 16px; }
    }
    .simulation-wizard { --simulation-motion-ease: cubic-bezier(.22, 1, .36, 1); }
    .simulation-wizard :is(.simulation-step, .simulation-step-number, .simulation-requester-choice, .simulation-remove-expense, .simulation-money) {
        transition: color 180ms ease, background-color 180ms ease, border-color 180ms ease, box-shadow 180ms ease, transform 180ms var(--simulation-motion-ease);
    }
    .simulation-wizard .simulation-button {
        transition: color 180ms ease, background-color 180ms ease, border-color 180ms ease, box-shadow 180ms ease, opacity 180ms ease, transform 180ms var(--simulation-motion-ease);
    }
    .simulation-wizard :is(.simulation-field label, .form-label, .simulation-consent label) { transition: color 180ms ease; }
    .simulation-wizard :is(.simulation-field, .col-md-6, .col-md-4, #commercial-fields > div):focus-within > label { color: var(--simulation-blue); }
    .simulation-wizard .simulation-requester-choice:has(input:focus-visible) { outline: 2px solid #7ea7ff; outline-offset: 3px; }
    .simulation-wizard .simulation-remove-expense:focus-visible { outline: 2px solid var(--simulation-blue); outline-offset: 3px; }
    .simulation-wizard .simulation-consent:has(input:checked) label { color: var(--simulation-ink); }
    .simulation-wizard .simulation-consent a { text-underline-offset: 3px; transition: color 180ms ease; }
    .simulation-wizard .simulation-consent a:focus-visible { outline: 2px solid var(--simulation-blue); outline-offset: 3px; border-radius: 2px; }
    .simulation-wizard :is(.simulation-button, .simulation-choice-icon) > svg { transition: transform 180ms var(--simulation-motion-ease); }
    .simulation-wizard .simulation-requester-choice:active, .simulation-wizard .simulation-step:active, .simulation-wizard .simulation-remove-expense:active { transform: translateY(1px); }
    .simulation-wizard .simulation-button:disabled { transform: none; box-shadow: none; }

    @media (hover: hover) and (pointer: fine) {
        body[data-brand] .simulation-wizard :is(input, select):not(:disabled):not([readonly]):not(:focus):not([aria-invalid='true']):hover { border-color: #91a8d0; }
        .simulation-wizard .simulation-money:not(:focus-within):hover { border-color: #91a8d0; }
        .simulation-wizard .simulation-requester-choice:hover { background: #f2f6ff; border-color: #91a8d0; transform: translateY(-1px); }
        .simulation-wizard .simulation-requester-choice:has(input:checked):hover { border-color: var(--simulation-blue); }
        .simulation-wizard .simulation-requester-choice:hover .simulation-choice-icon > svg { transform: translateY(-1px); }
        .simulation-wizard .simulation-step:not([aria-current='step']):hover { background: #f3f6fc; color: var(--simulation-blue); }
        .simulation-wizard .simulation-button:not(:disabled):hover { transform: translateY(-1px); box-shadow: 0 3px 9px #0c1f4c12; }
        .simulation-wizard .simulation-button:not(:disabled):hover > svg { transform: translateX(2px); }
        body[data-brand] .simulation-wizard :is(.simulation-button-back, .simulation-button-outline):hover { background: #f2f6ff; border-color: var(--simulation-blue); }
        .simulation-wizard .simulation-consent a:hover { color: var(--simulation-ink); }
        .simulation-wizard :is(.simulation-button, .simulation-requester-choice, .simulation-step):not(:disabled):active { transform: translateY(1px); box-shadow: none; }
    }

    @keyframes simulation-field-enter {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes simulation-step-enter {
        from { opacity: .65; transform: translateY(4px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @media (prefers-reduced-motion: no-preference) {
        .simulation-wizard [data-simulation-fields] > div,
        .simulation-wizard .expense-field:not(.d-none) { animation: simulation-field-enter 240ms var(--simulation-motion-ease) backwards; }
        .simulation-wizard [data-simulation-fields] > div:nth-child(2) { animation-delay: 35ms; }
        .simulation-wizard.is-enhanced [data-form-step],
        .simulation-wizard [data-step-help]:not([hidden]) { animation: simulation-step-enter 200ms var(--simulation-motion-ease); }
    }
    @media (prefers-reduced-motion: reduce) {
        .simulation-wizard *, .simulation-wizard *::before, .simulation-wizard *::after {
            animation: none !important;
            transition: none !important;
            transform: none !important;
            scroll-behavior: auto !important;
        }
    }
</style>
@endpush
