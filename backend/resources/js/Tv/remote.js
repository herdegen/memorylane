// Télécommande de l'interface TV (/tv/ecran).
//
// Navigation « spatiale » : les éléments marqués data-tv sont focusables ;
// une flèche donne le focus au plus proche dans cette direction (même rangée
// d'abord). Une rangée (data-tv-row) se souvient du dernier élément choisi :
// on y revient en remontant/redescendant. Une fenêtre (data-tv-scope) garde
// le focus pour elle tant qu'elle est ouverte.
//
// Les pages peuvent intercepter une touche (onTvKey) : la visionneuse prend
// les flèches pour changer de photo, Retour pour se fermer, etc. Le dernier
// gestionnaire enregistré passe en premier ; il rend true s'il a traité la
// touche.

import { onBeforeUnmount, onMounted } from 'vue';

const SELECTOR = '[data-tv]:not([disabled])';

/** Touche → action (codes Android TV / navigateurs de téléviseurs inclus). */
export function actionOf(e) {
    switch (e.key) {
        case 'ArrowUp': return 'up';
        case 'ArrowDown': return 'down';
        case 'ArrowLeft': return 'left';
        case 'ArrowRight': return 'right';
        case 'Enter': case 'NumpadEnter': case 'Select': return 'ok';
        case 'Escape': case 'GoBack': case 'BrowserBack': case 'Backspace': return 'back';
        case 'MediaPlayPause': case 'MediaPlay': case 'MediaPause': case 'Play': case 'Pause': return 'playpause';
        case 'MediaTrackNext': case 'MediaFastForward': return 'next';
        case 'MediaTrackPrevious': case 'MediaRewind': return 'prev';
        default: break;
    }
    switch (e.keyCode) {
        case 23: case 66: return 'ok';            // DPAD_CENTER / ENTER (Android)
        case 4: case 461: case 10009: return 'back'; // BACK (Android, webOS, Tizen)
        case 179: case 415: case 19: return 'playpause';
        case 417: case 228: return 'next';
        case 412: case 227: return 'prev';
        default: return null;
    }
}

const handlers = [];

/**
 * Activité de la télécommande (cadre photo après inactivité). `blockers` :
 * écrans qui tournent déjà tout seuls (diaporama, cadre) et ne doivent pas
 * être recouverts.
 */
export const tvActivity = { last: Date.now(), blockers: 0 };

/** Intercepter les touches tant que le composant est monté (ou que `active()` est vrai). */
export function onTvKey(fn) {
    onMounted(() => handlers.push(fn));
    onBeforeUnmount(() => {
        const i = handlers.indexOf(fn);
        if (i >= 0) handlers.splice(i, 1);
    });
}

const visible = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width <= 0 || r.height <= 0) return false;
    const style = getComputedStyle(el);
    return style.visibility !== 'hidden' && style.display !== 'none';
};

function scopeRoot() {
    const scopes = document.querySelectorAll('[data-tv-scope]');
    return scopes.length ? scopes[scopes.length - 1] : document;
}

const candidates = () => [...scopeRoot().querySelectorAll(SELECTOR)].filter(visible);

const rowMemory = new WeakMap();

export function focusEl(el, { scroll = true } = {}) {
    if (!el) return;
    el.focus({ preventScroll: true });
    const row = el.closest('[data-tv-row]');
    if (row) rowMemory.set(row, el);
    if (scroll) el.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
}

/** Premier focus d'une page : [data-tv-autofocus], sinon le premier élément. */
export function focusInitial() {
    const list = candidates();
    const auto = list.find((el) => el.hasAttribute('data-tv-autofocus'));
    focusEl(auto || list[0], { scroll: false });
}

const gap = (a1, a2, b1, b2) => Math.max(0, Math.max(a1, b1) - Math.min(a2, b2));

export function move(dir) {
    const list = candidates();
    const cur = document.activeElement;
    if (!cur || !list.includes(cur)) {
        focusInitial();
        return;
    }

    const a = cur.getBoundingClientRect();
    const acx = a.left + a.width / 2;
    const acy = a.top + a.height / 2;
    let best = null;
    let bestScore = Infinity;

    for (const el of list) {
        if (el === cur) continue;
        const b = el.getBoundingClientRect();
        const bcx = b.left + b.width / 2;
        const bcy = b.top + b.height / 2;
        let primary;
        let orth;
        if (dir === 'right' || dir === 'left') {
            if (dir === 'right' ? bcx <= acx + 1 || b.right <= a.right : bcx >= acx - 1 || b.left >= a.left) continue;
            orth = gap(a.top, a.bottom, b.top, b.bottom);
            // Gauche / droite : seulement sur la même rangée (pas de saut en diagonale).
            if (orth > 0) continue;
            primary = dir === 'right' ? Math.max(0, b.left - a.right) : Math.max(0, a.left - b.right);
            primary += Math.abs(b.top - a.top) * 0.05;
        } else {
            if (dir === 'down' ? bcy <= acy + 1 || b.bottom <= a.bottom : bcy >= acy - 1 || b.top >= a.top) continue;
            primary = dir === 'down' ? Math.max(0, b.top - a.bottom) : Math.max(0, a.top - b.bottom);
            orth = gap(a.left, a.right, b.left, b.right);
            primary += Math.abs(bcx - acx) * 0.05;
        }
        const score = primary + orth * 4;
        if (score < bestScore) {
            bestScore = score;
            best = el;
        }
    }
    if (!best) return;

    // Entrée dans une autre rangée : on reprend là où on l'avait laissée.
    const fromRow = cur.closest('[data-tv-row]');
    const toRow = best.closest('[data-tv-row]');
    if (toRow && toRow !== fromRow && (dir === 'up' || dir === 'down')) {
        const last = rowMemory.get(toRow);
        if (last && last.isConnected && visible(last)) best = last;
    }
    focusEl(best);
}

/**
 * Écoute globale (installée une fois par la mise en page TV). `onBack` :
 * Retour non intercepté par la page (revenir à la page précédente).
 */
export function installRemote(onBack) {
    // Retour : d'abord la page (fenêtre ouverte, visionneuse…), puis la page
    // précédente. Rend false si rien ne l'a pris (l'appli peut alors se fermer).
    const back = () => {
        for (let i = handlers.length - 1; i >= 0; i--) {
            if (handlers[i]('back', null) === true) return true;
        }
        return onBack?.() === true;
    };
    // Appli Android : la touche Retour ne parvient pas à la page, l'appli
    // appelle ceci et se ferme sur « exit ».
    window.mlTvBack = () => (back() ? 'handled' : 'exit');

    const onActivity = () => { tvActivity.last = Date.now(); };
    const onKeydown = (e) => {
        tvActivity.last = Date.now();
        const action = actionOf(e);
        if (!action) return;
        const typing = ['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName);
        if (typing && (e.key === 'Backspace' || action === 'left' || action === 'right')) return;

        for (let i = handlers.length - 1; i >= 0; i--) {
            if (handlers[i](action, e) === true) {
                e.preventDefault();
                return;
            }
        }

        if (['up', 'down', 'left', 'right'].includes(action)) {
            e.preventDefault();
            move(action);
        } else if (action === 'back') {
            e.preventDefault();
            onBack?.();
        } else if (action === 'ok') {
            const el = document.activeElement;
            // Champ de saisie : OK ouvre le clavier de la télévision.
            if (typing) return;
            // Toujours un clic : la WebView d'Android TV n'active pas d'elle-même
            // un lien sur OK (le comportement natif est annulé pour ne pas cliquer deux fois).
            if (el?.matches?.(SELECTOR)) {
                e.preventDefault();
                el.click();
            } else if (!el || el === document.body) {
                e.preventDefault();
                focusInitial();
            }
        }
    };
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('pointermove', onActivity, { passive: true });
    return () => {
        document.removeEventListener('keydown', onKeydown);
        document.removeEventListener('pointermove', onActivity);
        if (window.mlTvBack) delete window.mlTvBack;
    };
}
