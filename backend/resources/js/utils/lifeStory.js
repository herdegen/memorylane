// Récit de vie : icônes et libellés des événements de la frise, et
// préparation des chapitres du diaporama (site et télévision).
import { formatPartialDate } from '@/utils/format';

export const ICONS = {
  birth: '🎂', death: '🕯️', marriage: '💍', child: '👶',
  job: '💼', education: '🎓', residence: '🏠', photo: '📷',
  voyage: '🧭', military: '🎖️', distinction: '🏅',
  // Fêtes (« sous-moments » définis, cf. LifeEventFormModal)
  bapteme: '🕊️', communion: '🕊️', confirmation: '🕊️', mariage_religieux: '⛪',
  mariage: '💍', fiancailles: '💍', anniversaire: '🎈', diplome: '🎓', fete: '🎉',
  moment: '★', custom: '★',
};
export const LABELS = {
  birth: 'Naissance', death: 'Décès', marriage: 'Mariage', child: 'Enfant',
  job: 'Emploi', education: 'Études', residence: 'Résidence', photo: 'Photo',
  voyage: 'Voyage', military: 'Parcours militaire', distinction: 'Distinction',
  bapteme: 'Baptême', communion: 'Communion', confirmation: 'Confirmation', mariage_religieux: 'Mariage religieux',
  mariage: 'Mariage', fiancailles: 'Fiançailles', anniversaire: 'Anniversaire', diplome: 'Remise de diplôme', fete: 'Fête',
  moment: 'Moment', custom: 'Moment',
};
export const kindIcon = (k) => ICONS[k] || '★';
export const kindLabel = (k) => LABELS[k] || 'Moment';

export const formatEventDate = (d, precision = 'day') => formatPartialDate(d, precision) || d || '';

/** Photos au plus dans la rafale d'un chapitre. */
const BURST_MAX = 8;

// Fourchette de dates d'un événement, élargie à sa précision (un moment
// « en 1950 » couvre toute l'année 1950).
export const storyWindow = (ev) => {
  const d = ev.date || '';
  const end = ev.end_date || d;
  const p = ev.date_precision || 'day';
  if (p === 'year') return [`${d.slice(0, 4)}-01-01`, `${end.slice(0, 4)}-12-31`];
  if (p === 'month') return [`${d.slice(0, 7)}-01`, `${end.slice(0, 7)}-31`];
  return [d, end];
};

/**
 * Chapitres du récit de vie, depuis les éléments de /people/{id}/timeline :
 * les événements datés (hors photos), triés, enrichis de leur icône, de leur
 * date lisible et de leur RAFALE de photos. La galerie d'un moment, ce sont
 * ses photos rattachées (ou, à défaut, son album lié), illustration en tête ;
 * à défaut, les photos de la personne prises dans la fourchette de
 * l'événement — sauf celles déjà « prises » par un autre moment (sinon un
 * moment étalé sur deux ans rejouerait les photos de tous ses voisins).
 */
export function buildStoryEvents(items) {
  const events = (items || [])
    .filter((i) => i.kind !== 'photo')
    .slice()
    .sort((a, b) => ((a.date || '') < (b.date || '') ? -1 : (a.date || '') > (b.date || '') ? 1 : 0));
  const photos = (items || []).filter((i) => i.kind === 'photo' && i.media);

  const claimed = new Set();
  for (const ev of events) {
    if (ev.media?.id) claimed.add(ev.media.id);
    for (const m of ev.photos || []) claimed.add(m.id);
    for (const m of ev.album?.media || []) claimed.add(m.id);
  }

  return events.map((ev) => {
    const burst = [];
    const push = (m) => {
      const src = m?.medium_url || m?.url;
      if (src && burst.length < BURST_MAX && !burst.some((b) => b.src === src)) {
        burst.push({ src, full: m.url || src, title: m.title || '' });
      }
    };
    push(ev.media);
    const gallery = ev.photos?.length ? ev.photos : (ev.album?.media || []);
    if (gallery.length) {
      gallery.forEach(push);
    } else {
      const [from, to] = storyWindow(ev);
      for (const p of photos) {
        if (p.date && p.date >= from && p.date <= to && !claimed.has(p.media?.id)) push(p.media);
      }
    }
    return {
      ...ev,
      icon: kindIcon(ev.kind),
      dateLabel: formatEventDate(ev.date, ev.date_precision),
      // Une seule photo n'est pas une rafale : la carte-chapitre suffit.
      burst: burst.length > 1 ? burst : [],
    };
  });
}
