/**
 * Message d'erreur affichable à l'utilisateur pour un échec d'appel axios.
 *
 * Seules les erreurs de validation (422) remontent le message du serveur :
 * il est rédigé en français et s'adresse à l'utilisateur. Tout le reste
 * (500 « Server Error », « Network Error », « Request failed with status
 * code… ») est technique et remplacé par le message français `fallback`.
 *
 * @param {unknown} error     erreur attrapée (axios ou autre)
 * @param {string}  fallback  message français à afficher par défaut
 * @returns {string}
 */
export function apiError(error, fallback) {
  const response = error?.response;
  if (response?.status === 422) {
    const data = response.data || {};
    if (typeof data.message === 'string' && data.message) return data.message;
    // Repli : première erreur de champ si le message global manque.
    const first = Object.values(data.errors || {})[0];
    if (Array.isArray(first) && first[0]) return first[0];
  }
  return fallback;
}
