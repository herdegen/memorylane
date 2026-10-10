// Suivi de la page précédente dans l'application (navigation Inertia).
//
// Inertia ne transmet pas de « referer » et document.referrer ne bouge pas en
// SPA ; on note donc nous-mêmes l'URL courante et la précédente dans le
// sessionStorage (par onglet, résiste au rechargement de la page).

const CURRENT_KEY = 'ml:nav:current';
const PREVIOUS_KEY = 'ml:nav:previous';

const storage = () => {
  try {
    return window.sessionStorage;
  } catch {
    return null;
  }
};

/** À appeler à chaque navigation réussie (événement Inertia `navigate`). */
export function rememberNavigation(url) {
  const store = storage();
  if (!store || !url) return;
  const current = store.getItem(CURRENT_KEY);
  if (current === url) return; // rechargement / re-visite de la même page
  if (current) store.setItem(PREVIOUS_KEY, current);
  store.setItem(CURRENT_KEY, url);
}

/**
 * URL (chemin) de la page précédente dans l'app, ou null.
 *
 * Subtilité : Inertia pousse la nouvelle URL dans l'historique puis monte le
 * composant de page, et n'émet `navigate` qu'ensuite. Appelée depuis le
 * setup() d'une page, la valeur « courante » mémorisée est donc encore celle
 * de la page d'avant : on la reconnaît en la comparant à l'URL réelle.
 */
export function previousUrl() {
  const store = storage();
  if (!store) return null;
  const here = window.location.pathname + window.location.search;
  const current = store.getItem(CURRENT_KEY);
  if (current && current !== here) return current;
  return store.getItem(PREVIOUS_KEY) || null;
}

// Libellé « Retour à … » selon l'origine. Ordre : du plus spécifique au plus
// général ; défaut générique si la page n'est pas reconnue.
const BACK_LABELS = [
  [/^\/people\/\d+/, 'Retour à la fiche'],
  [/^\/people/, 'Retour aux personnes'],
  [/^\/albums\/\d+/, "Retour à l'album"],
  [/^\/albums/, 'Retour aux albums'],
  [/^\/households\/\d+/, 'Retour au foyer'],
  [/^\/media\/\d+/, 'Retour au média'],
  [/^\/media(\?|$)/, 'Retour à mes photos'],
  [/^\/map/, 'Retour à la carte'],
  [/^\/tags/, 'Retour aux tags'],
  [/^\/family-tree/, "Retour à l'arbre"],
  [/^\/dashboard|^\/$/, "Retour à l'accueil"],
];

/**
 * Cible et libellé du bouton « Retour » d'une page de détail.
 *   - `href`    : où aller si on navigue explicitement (repli : /media)
 *   - `useHistory` : vrai si la page précédente est dans l'historique du
 *     navigateur (on préfère alors history.back(), qui restaure le scroll)
 */
export function backTarget(fallback = '/media', fallbackLabel = 'Retour à mes photos') {
  const prev = previousUrl();
  if (!prev) return { href: fallback, label: fallbackLabel, useHistory: false };
  const match = BACK_LABELS.find(([re]) => re.test(prev));
  return { href: prev, label: match ? match[1] : 'Retour', useHistory: true };
}
