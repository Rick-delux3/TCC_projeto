<style>
    #page-loader-modal {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(var(--brand-primary-rgb, 3, 1, 51), 0.52);
        backdrop-filter: blur(2px);
        opacity: 1;
        visibility: visible;
        transition: opacity 0.22s ease, visibility 0.22s ease;
    }

    #page-loader-modal.is-hidden {
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
    }

    .page-loader-card {
        width: min(90vw, 280px);
        background: #ffffff;
        border-radius: 10px;
        padding: 18px 16px;
        text-align: center;
        box-shadow: 0 16px 36px rgba(var(--brand-primary-rgb, 3, 1, 51), 0.24);
        border: 1px solid rgba(var(--brand-primary-rgb, 3, 1, 51), 0.16);
    }

    .page-loader-spinner {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        margin: 0 auto 10px;
        border: 4px solid rgba(var(--brand-primary-rgb, 3, 1, 51), 0.2);
        border-top-color: var(--brand-accent, #FD1E6E);
        animation: page-loader-spin 0.75s linear infinite;
    }

    .page-loader-text {
        margin: 0;
        color: var(--brand-primary, #030133);
        font-family: 'Sansation', sans-serif;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.2px;
    }

    @keyframes page-loader-spin {
        to {
            transform: rotate(360deg);
        }
    }
</style>

<div
    id="page-loader-modal"
    class="is-hidden"
    role="status"
    aria-live="polite"
    aria-label="Carregando"
    aria-hidden="true"
>
    <div class="page-loader-card">
        <div class="page-loader-spinner"></div>
        <p class="page-loader-text">Carregando plataforma...</p>
    </div>
</div>
