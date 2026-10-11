import { listenForInstallPrompt } from '@/Composables/useInstallApp';
import axios from 'axios';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.withCredentials = true;

// Jeton CSRF : PAS d'en-tête X-CSRF-TOKEN figé depuis la balise meta. Cette
// valeur, lue une seule fois au chargement, devient périmée après une
// déconnexion/reconnexion sans rechargement (navigation Inertia) ou un
// renouvellement de session — et Laravel la lit AVANT le cookie, d'où des
// 419 en série. axios envoie déjà, à chaque requête de même origine,
// l'en-tête X-XSRF-TOKEN lu dans le cookie XSRF-TOKEN que Laravel tient à
// jour (xsrfCookieName / xsrfHeaderName par défaut, withXSRFToken non défini
// = même origine seulement : toutes nos URLs sont relatives).

// Session expirée (419) : un rechargement récupère un jeton neuf. Garde-fou
// anti-boucle : pas plus d'un rechargement automatique toutes les 10 s.
const RELOAD_KEY = 'ml_419_reload_at';
const RELOAD_COOLDOWN_MS = 10000;

function reloadForFreshToken() {
    try {
        const last = Number(sessionStorage.getItem(RELOAD_KEY) || 0);
        if (Date.now() - last < RELOAD_COOLDOWN_MS) return;
        sessionStorage.setItem(RELOAD_KEY, String(Date.now()));
    } catch (_) { /* stockage indisponible : on recharge quand même */ }
    window.location.reload();
}

// Les requêtes de fond (scan de visages, désabonnement push…) passent
// `{ background: true }` dans leur config : leur échec reste silencieux, sans
// recharger ni rediriger la page sous les yeux de l'utilisateur.
axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;
        if (error.config?.background) {
            // rien : l'appelant gère l'échec
        } else if (status === 419) {
            reloadForFreshToken();
        } else if (status === 401 && window.location.pathname !== '/login') {
            // Plus connecté (session expirée, déconnexion dans un autre onglet).
            window.location.assign('/login');
        }
        return Promise.reject(error);
    },
);

// PWA : rend l'app installable et active le partage natif depuis le téléphone.
// L'invitation d'installation d'Android n'arrive qu'une fois, au chargement.
listenForInstallPrompt();
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
