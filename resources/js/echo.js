import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    ?.getAttribute('content');

const authHeaders = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
};

if (csrfToken) {
    authHeaders['X-CSRF-TOKEN'] = csrfToken;
}

const reverbScheme =
    import.meta.env.VITE_REVERB_SCHEME ?? 'https';

const reverbPort = Number(
    import.meta.env.VITE_REVERB_PORT
        ?? (reverbScheme === 'https' ? 443 : 80)
);

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost:
        import.meta.env.VITE_REVERB_HOST
        || window.location.hostname,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS: reverbScheme === 'https',
    enabledTransports: ['ws', 'wss'],

    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: authHeaders,
    },
});
