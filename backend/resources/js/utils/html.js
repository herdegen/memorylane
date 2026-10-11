/**
 * Échappe une valeur avant de l'insérer dans du HTML construit à la main
 * (innerHTML, popups Leaflet, cartes family-chart, driver.js…).
 *
 * Toute donnée saisie par un membre (nom, titre, description, nom de
 * fichier, lieu…) DOIT passer par ici : sans cela, un nom comme
 * `<img src=x onerror=…>` exécuterait du code chez les autres membres.
 * Couvre aussi les valeurs d'attributs entre guillemets (src, href, alt).
 */
const ENTITIES = {
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#39;',
};

export function escapeHtml(value) {
  if (value === null || value === undefined) return '';
  return String(value).replace(/[&<>"']/g, (ch) => ENTITIES[ch]);
}
