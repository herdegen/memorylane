// Pile des surcouches ouvertes (BaseModal, BottomSheet) — UNE pour toute
// l'app : seule la surcouche du dessus réagit à Échap, et le verrou de
// défilement de la page n'est rendu qu'à la fermeture de la dernière (une
// feuille ouverte par-dessus une modale ne doit pas le libérer).
export const openModals = [];

export function pushOverlay(handler) {
  openModals.push(handler);
  document.body.style.overflow = 'hidden';
}

export function popOverlay(handler) {
  const index = openModals.indexOf(handler);
  if (index !== -1) openModals.splice(index, 1);
  if (openModals.length === 0) document.body.style.overflow = '';
}

export const isTopOverlay = (handler) => openModals[openModals.length - 1] === handler;
