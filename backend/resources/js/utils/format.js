// Formatteurs partagés (auparavant dupliqués dans 5-6 composants).
import { intlLocale, t } from '@/i18n';

// Taille de fichier lisible : 1024 → « 1 Ko », 1536000 → « 1,46 Mo ».
export function formatFileSize(bytes) {
  const sizes = [t('o'), t('Ko'), t('Mo'), t('Go'), t('To')];
  if (!bytes || bytes <= 0) return `0 ${sizes[0]}`;
  const k = 1024;
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(k)), sizes.length - 1);
  const value = Math.round((bytes / Math.pow(k, i)) * 100) / 100;
  return `${value.toLocaleString(intlLocale())} ${sizes[i]}`;
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
  return date.toLocaleDateString(intlLocale(), options);
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

// Date à précision variable (moments de vie dont on ne connaît que l'année ou
// le mois) : 'year' → « 1950 », 'month' → « mars 1953 », sinon date longue.
export function formatPartialDate(dateString, precision = 'day') {
  if (precision === 'year') return localized(dateString, { year: 'numeric' });
  if (precision === 'month') return localized(dateString, { month: 'long', year: 'numeric' });
  return formatLongDate(dateString);
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
