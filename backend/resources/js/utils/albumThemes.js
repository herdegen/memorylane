// Thèmes d'album : liste fermée, alignée sur Album::THEMES (PHP).
// null = « Moments », l'affichage par défaut.
import { t } from '@/i18n';

export const ALBUM_THEMES = ['voyage', 'reunion', 'mariage', 'bapteme', 'anniversaire', 'fete', 'archives', 'moment'];

const EMOJI = {
  voyage: '🧭', reunion: '👨‍👩‍👧', mariage: '💍', bapteme: '🕊️',
  anniversaire: '🎈', fete: '🎉', archives: '📜', moment: '★',
};

export const themeKey = (k) => (k && EMOJI[k] ? k : 'moment');
export const themeEmoji = (k) => EMOJI[themeKey(k)];

// Libellé d'un album (formulaire) : au singulier.
export const themeLabel = (k) => ({
  voyage: t('Voyage'),
  reunion: t('Réunion de famille'),
  mariage: t('Mariage'),
  bapteme: t('Baptême ou communion'),
  anniversaire: t('Anniversaire'),
  fete: t('Fête'),
  archives: t('Archives'),
  moment: t('Moment'),
})[themeKey(k)];

// Libellé d'un filtre (page Albums) : au pluriel.
export const themePlural = (k) => ({
  voyage: t('Voyages'),
  reunion: t('Réunions de famille'),
  mariage: t('Mariages'),
  bapteme: t('Baptêmes & communions'),
  anniversaire: t('Anniversaires'),
  fete: t('Fêtes'),
  archives: t('Archives'),
  moment: t('Moments'),
})[themeKey(k)];
