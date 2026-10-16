// Fichiers choisis depuis la feuille « Ajouter » (barre d'onglets) avant
// d'arriver sur la page d'envoi : gardés en mémoire le temps de la
// navigation Inertia (même document), repris une seule fois par la page.
let pending = [];

export function stashPendingFiles(files) {
  pending = Array.from(files);
}

export function takePendingFiles() {
  const files = pending;
  pending = [];
  return files;
}
