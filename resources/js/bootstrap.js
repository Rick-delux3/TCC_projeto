import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';


window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    ?.getAttribute('content');

const echoAuthHeaders = {
    'X-Requested-With': 'XMLHttpRequest',
};

if (csrfToken) {
    echoAuthHeaders['X-CSRF-TOKEN'] = csrfToken;
}

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',

    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,

    wsPort: Number(
        import.meta.env.VITE_REVERB_PORT ?? 80
    ),

    wssPort: Number(
        import.meta.env.VITE_REVERB_PORT ?? 443
    ),

    forceTLS:
        (import.meta.env.VITE_REVERB_SCHEME ?? 'https')
        === 'https',

    enabledTransports: ['ws', 'wss'],

    authEndpoint: '/broadcasting/auth',

    auth: {
        headers: echoAuthHeaders,
    },
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
