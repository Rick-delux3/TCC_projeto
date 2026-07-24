import './bootstrap';
import axios from 'axios';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

axios.defaults.headers.common['X-CSRF-TOKEN'] =
    document.querySelector('meta[name="csrf-token"]').content;

document.querySelectorAll('[data-toggle-password]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.getAttribute('data-toggle-password'));

        if (!input) {
            return;
        }

        const shouldShow = input.type === 'password';
        const showIcon = button.querySelector('[data-password-icon="show"]');
        const hideIcon = button.querySelector('[data-password-icon="hide"]');
        const fieldLabel = input.name === 'password_confirmation'
            ? 'confirmação de senha'
            : 'senha';
        const label = shouldShow
            ? `Ocultar ${fieldLabel}`
            : `Mostrar ${fieldLabel}`;

        input.type = shouldShow ? 'text' : 'password';
        button.setAttribute('aria-label', label);
        button.setAttribute('aria-pressed', String(shouldShow));
        button.setAttribute('title', label);

        if (showIcon && hideIcon) {
            showIcon.hidden = shouldShow;
            hideIcon.hidden = !shouldShow;
        } else {
            button.textContent = shouldShow ? 'Ocultar' : 'Ver';
        }
    });
});

document.querySelectorAll('input[type="tel"], input[name="phone"]').forEach((input) => {
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('maxlength', '15');

    input.addEventListener('input', function (e) {
        let v = e.target.value.replace(/\D/g, '');

        if (v.length > 11) {
            v = v.slice(0, 11);
        }

        if (v.length > 10) {
            e.target.value = v.replace(/(\d{2})(\d{5})(\d{0,4})/, '($1) $2-$3');
        } else if (v.length > 6) {
            e.target.value = v.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
        } else if (v.length > 2) {
            e.target.value = v.replace(/(\d{2})(\d{0,5})/, '($1) $2');
        } else if (v.length > 0) {
            e.target.value = v.replace(/(\d{0,2})/, '($1');
        } else {
            e.target.value = '';
        }
    });
});

const pageLoader = {
    element() {
        return document.getElementById('page-loader-modal');
    },

    show() {
        const modal = this.element();

        if (! modal) {
            return;
        }

        modal.classList.remove('is-hidden');
        modal.setAttribute('aria-hidden', 'false');
    },

    hide() {
        const modal = this.element();

        if (! modal) {
            return;
        }

        modal.classList.add('is-hidden');
        modal.setAttribute('aria-hidden', 'true');
    },
};

window.PageLoader = pageLoader;

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (! form || form.tagName !== 'FORM' || form.hasAttribute('data-no-loader')) {
        return;
    }

    pageLoader.show();
}, true);

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-show-loader]');

    if (trigger) {
        pageLoader.show();
    }
});

document.addEventListener('DOMContentLoaded', () => pageLoader.hide());
window.addEventListener('pageshow', () => pageLoader.hide());
