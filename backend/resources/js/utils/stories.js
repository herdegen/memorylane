// Histoires de famille : libellés partagés (liste, lecture, écriture).
import { t } from '@/i18n';

export const STORY_KINDS = ['documented', 'memory', 'legend'];

export const storyKindLabel = (kind) => ({
  documented: t('Histoire vraie, documentée'),
  memory: t('Souvenir'),
  legend: t('Légende familiale'),
}[kind] || t('Souvenir'));

export const storyKindHint = (kind) => ({
  documented: t('Appuyée sur des documents : actes, journaux, lettres.'),
  memory: t('Ce dont on se souvient, ou ce qu’on nous a raconté.'),
  legend: t('« On raconte que… » : vrai ou pas, ça fait partie de la famille.'),
}[kind] || '');
