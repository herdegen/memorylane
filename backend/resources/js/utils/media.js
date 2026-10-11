// Helpers partagés autour des objets média sérialisés par l'API.
//
// Conversions générées côté serveur : `thumbnail` (carré recadré), `small`,
// `medium`, `large` (images) et `web` (vidéo transcodée). Chaque entrée de
// `media.conversions` porte `conversion_name`, `url`, `width` et `height`.

// Objet conversion `name` d'un média (url, dimensions…), sinon undefined.
export function findConversion(media, name) {
  return media?.conversions?.find((conv) => conv.conversion_name === name);
}

// URL de vignette d'un média : conversion `small` ou `thumbnail` si
// disponible, sinon l'original (auparavant dupliqué dans 3 composants).
export function thumbnailUrl(media) {
  const conversion = (media.conversions || []).find(
    (conv) => conv.conversion_name === 'small' || conv.conversion_name === 'thumbnail'
  );
  return conversion?.url || media.url || null;
}

// URL de la première conversion disponible parmi `names` (ordre de
// préférence), sinon null. Ex. conversionUrl(media, 'large', 'medium').
export function conversionUrl(media, ...names) {
  for (const name of names) {
    const url = findConversion(media, name)?.url;
    if (url) return url;
  }
  return null;
}

// Image à afficher en grand (fiche média) : la conversion `large` (1600 px),
// sinon `medium`, sinon l'original. Les GIF gardent l'original (les
// conversions JPEG perdraient l'animation).
export function displayUrl(media) {
  if (!media) return null;
  if (media.mime_type === 'image/gif') return media.url || null;
  return conversionUrl(media, 'large', 'medium') || media.url || null;
}

// Vidéo à lire : la version transcodée `web` (lisible partout), sinon
// l'original.
export function webVideoUrl(media) {
  return conversionUrl(media, 'web') || media?.url || null;
}

// Source d'une diapositive du diaporama plein écran : la vidéo `web`, ou la
// photo en `medium` (suffisante à l'écran, rapide à précharger), sinon
// l'original.
export function slideUrl(media) {
  if (media?.type === 'video') return webVideoUrl(media);
  return conversionUrl(media, 'medium') || media?.url || null;
}
