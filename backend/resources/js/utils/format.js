// Formatteurs partagés (auparavant dupliqués dans 5-6 composants).

// Taille de fichier lisible : 1024 → « 1 Ko », 1536000 → « 1,46 Mo ».
export function formatFileSize(bytes) {
  if (!bytes || bytes <= 0) return '0 o';
  const k = 1024;
  const sizes = ['o', 'Ko', 'Mo', 'Go', 'To'];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(k)), sizes.length - 1);
  const value = Math.round((bytes / Math.pow(k, i)) * 100) / 100;
  return `${value.toLocaleString('fr-FR')} ${sizes[i]}`;
}

// Durée vidéo : 65 → « 1:05 », 3671 → « 1:01:11 ».
export function formatDuration(seconds) {
  if (!seconds) return '';
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = Math.floor(seconds % 60);
  if (hours > 0) {
    return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  }
  return `${minutes}:${secs.toString().padStart(2, '0')}`;
}

// Garde commune : date invalide ou absente → chaîne vide.
const localized = (dateString, options) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleDateString('fr-FR', options);
};

// Date absolue courte : « 3 juin 2024 ».
export function formatDate(dateString) {
  return localized(dateString, { day: 'numeric', month: 'short', year: 'numeric' });
}

// Date absolue longue : « 3 juin 2024 » (mois en toutes lettres —
// naissances, décès, moments de vie).
export function formatLongDate(dateString) {
  return localized(dateString, { day: 'numeric', month: 'long', year: 'numeric' });
}

// Date + heure : « 3 juin 2024, 14:05 ».
export function formatDateTime(dateString) {
  return localized(dateString, {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

// Date relative pour les tuiles : « Aujourd'hui », « Hier », « Il y a N jours »,
// puis la date absolue au-delà d'une semaine.
export function formatRelativeDate(dateString) {
  if (!dateString) return '';
  const date = new Date(dateString);
  if (Number.isNaN(date.getTime())) return '';
  // Différence en jours CALENDAIRES locaux (pas en tranches de 24 h, sinon
  // une photo d'il y a 2 h serait déjà « Hier »).
  const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const diffDays = Math.round((startOfDay(new Date()) - startOfDay(date)) / 86400000);
  if (diffDays <= 0) return "Aujourd'hui";
  if (diffDays === 1) return 'Hier';
  if (diffDays < 7) return `Il y a ${diffDays} jours`;
  return formatDate(dateString);
}
