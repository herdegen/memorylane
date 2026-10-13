// Petites fonctions d'API album, partagées par le modal « Ajouter à un album »
// et le sélecteur d'album de l'uploader. `Accept: application/json` est explicite
// pour que `store()` renvoie du JSON (et non une redirection Inertia).
import axios from 'axios';

const JSON_HEADERS = { headers: { Accept: 'application/json' } };

// Albums où l'utilisateur peut ajouter manuellement des médias : les siens et
// non intelligents (les albums intelligents se remplissent via leurs règles).
// Avec `circleId`, seuls les albums de ce cercle et ceux partagés avec aucun
// cercle (privés) sont proposés : un album d'un autre cercle n'a rien à faire
// dans un envoi fait depuis celui-ci.
export async function fetchOwnedAlbums({ circleId = null } = {}) {
  const { data } = await axios.get('/albums', JSON_HEADERS);
  return (data || []).filter((a) => a.is_owner && !a.is_smart
    && (!circleId || !a.household_ids?.length || a.household_ids.includes(circleId)));
}

// Crée un album et y attache directement les médias (appel atomique côté serveur).
export async function createAlbumWithMedia(name, mediaIds) {
  const { data } = await axios.post('/albums', { name, media_ids: mediaIds }, JSON_HEADERS);
  return data.album;
}

// Ajoute des médias à un album existant.
export async function addMediaToAlbum(albumId, mediaIds) {
  await axios.post(`/albums/${albumId}/media`, { media_ids: mediaIds }, JSON_HEADERS);
}
