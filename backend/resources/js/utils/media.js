// Helpers partagés autour des objets média sérialisés par l'API.

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
  const conversions = media?.conversions || [];
  for (const name of names) {
    const conversion = conversions.find((conv) => conv.conversion_name === name);
    if (conversion?.url) return conversion.url;
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
