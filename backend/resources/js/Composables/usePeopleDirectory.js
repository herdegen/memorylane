import { createCachedList } from '@/utils/cachedList';

/**
 * Annuaire des personnes (GET /people en JSON), partagé par les sélecteurs
 * (PersonInput, FaceMatchPanel, RelationshipPicker, AlbumFormModal…) : une
 * requête pour toute la page au lieu d'une par composant.
 *
 * Chaque élément : { id, name, first_name, last_name, maiden_name, gender,
 * birth_date, death_date, avatar_url, avatar_position, media_count,
 * matched_faces_count, proximity, relatives_count }.
 *
 * Après la création d'une fiche hors /people (ex. réponse à une quête,
 * import GEDCOM), appeler invalidate() ; refresh() force le rechargement.
 */
const directory = createCachedList('/people');

export function usePeopleDirectory() {
  return directory;
}
