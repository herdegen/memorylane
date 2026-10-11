import axios from 'axios';
import { router, usePage } from '@inertiajs/vue3';

const DEFAULT_TTL_MS = 5 * 60 * 1000;

// Chemin d'une URL axios (relative ou absolue), sans la requête.
const pathOf = (url) => {
  try {
    return new URL(url, window.location.origin).pathname;
  } catch {
    return '';
  }
};

// Liste dépendant du compte et du cercle courant (famille / amis) : un
// changement de l'un ou de l'autre rend le cache caduc.
const scopeKey = () => {
  const props = usePage().props || {};
  return `${props.auth?.user?.id ?? ''}|${props.circle?.current?.id ?? ''}`;
};

/**
 * Liste JSON partagée par tous les composants (annuaire des personnes,
 * tags…) : une seule requête tant qu'elle est fraîche, au lieu d'un GET par
 * composant monté (ex. un par visage ouvert sur la fiche média).
 *
 * La promesse est mise en cache au niveau du MODULE (elle survit aux
 * navigations Inertia) et devient caduque :
 *  - au bout de `ttl` (5 min par défaut) ;
 *  - quand le compte ou le cercle courant change ;
 *  - après une écriture axios réussie sur `url` ou ses sous-chemins
 *    (création, renommage, suppression…), sauf attach/detach qui ne
 *    changent que des compteurs ;
 *  - après toute visite Inertia non-GET (formulaire, changement de cercle,
 *    déconnexion…) ;
 *  - sur appel explicite de invalidate() / refresh().
 *
 * @param {string} url  Point d'entrée JSON (GET), ex. '/people'.
 * @returns {{ load: Function, refresh: Function, invalidate: Function }}
 *   load() → Promise<Array> : copie superficielle de la liste (chaque
 *   composant peut la compléter ou la trier sans toucher aux autres).
 */
export function createCachedList(url, { ttl = DEFAULT_TTL_MS } = {}) {
  let entry = null; // { key, at, promise }

  const invalidate = () => {
    entry = null;
  };

  const load = ({ force = false } = {}) => {
    const key = scopeKey();
    if (force || !entry || entry.key !== key || Date.now() - entry.at > ttl) {
      const current = { key, at: Date.now(), promise: null };
      current.promise = axios
        .get(url, { headers: { Accept: 'application/json' } })
        // Tableau brut ou enveloppe paginée { data: [...] }.
        .then(({ data }) => (Array.isArray(data) ? data : (data?.data || [])))
        .catch((error) => {
          if (entry === current) entry = null; // un échec n'est pas mis en cache
          throw error;
        });
      entry = current;
    }
    return entry.promise.then((list) => [...list]);
  };

  const refresh = () => load({ force: true });

  axios.interceptors.response.use((response) => {
    const method = (response.config?.method || 'get').toLowerCase();
    if (method !== 'get') {
      const path = pathOf(response.config?.url || '');
      if ((path === url || path.startsWith(`${url}/`)) && !/\/(attach|detach)$/.test(path)) {
        invalidate();
      }
    }
    return response;
  });

  router.on('before', (event) => {
    if ((event.detail?.visit?.method || 'get').toLowerCase() !== 'get') invalidate();
  });

  return { load, refresh, invalidate };
}
