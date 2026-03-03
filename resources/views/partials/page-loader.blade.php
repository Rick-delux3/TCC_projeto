<style>
    #page-loader-modal {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(31, 29, 89, 0.52);
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
        box-shadow: 0 16px 36px rgba(31, 29, 89, 0.24);
        border: 1px solid rgba(33, 40, 191, 0.16);
    }

    .page-loader-spinner {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        margin: 0 auto 10px;
        border: 4px solid rgba(33, 40, 191, 0.2);
        border-top-color: #EE1D23;
        animation: page-loader-spin 0.75s linear infinite;
    }

    .page-loader-text {
        margin: 0;
        color: #1F1D59;
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

<div id="page-loader-modal" role="dialog" aria-modal="true" aria-label="Carregando">
    <div class="page-loader-card">
        <div class="page-loader-spinner"></div>
        <p class="page-loader-text">Carregando plataforma...</p>
    </div>
</div>

<script>
    (function () {
        let getModal = function () {
            return document.getElementById('page-loader-modal');
        };

        var showLoader = function () {
            var modal = getModal();
            if (!modal) return;
            modal.classList.remove('is-hidden');
        };

        var hideLoader = function () {
            var modal = getModal();
            if (!modal) return;
            modal.classList.add('is-hidden');
        };

        document.addEventListener('submit', function (event) {
            let form = event.target;
            if (!form || form.tagName !== 'FORM') return;
            if (form.hasAttribute('data-no-loader')) return;
            showLoader();
        }, true);

        document.addEventListener('click', function (event) {
            let trigger = event.target.closest('[data-show-loader]');
            if (!trigger) return;
            showLoader();
        });

        window.PageLoader = {
            show: showLoader,
            hide: hideLoader,
        };

        window.addEventListener('load', hideLoader);
        window.addEventListener('pageshow', hideLoader);
        setTimeout(hideLoader, 8000);
    })();
</script>
