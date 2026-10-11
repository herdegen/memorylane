import { createCachedList } from '@/utils/cachedList';

/**
 * Liste des tags (GET /tags en JSON, avec media_count), partagée par
 * TagInput, les filtres de la galerie et de la carte, AlbumFormModal… : une
 * requête pour toute la page au lieu d'une par composant.
 *
 * Les écritures sur /tags (axios ou formulaire Inertia) la rendent caduque
 * automatiquement ; invalidate() / refresh() restent disponibles.
 */
const tags = createCachedList('/tags');

export function useTags() {
  return tags;
}
