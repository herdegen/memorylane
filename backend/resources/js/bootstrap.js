import { listenForInstallPrompt } from '@/Composables/useInstallApp';
import axios from 'axios';
window.axios = axios;

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.withCredentials = true;

// Get CSRF token from meta tag
const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
} else {
    console.error('CSRF token not found');
}

// PWA : rend l'app installable et active le partage natif depuis le téléphone.
// L'invitation d'installation d'Android n'arrive qu'une fois, au chargement.
listenForInstallPrompt();
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
