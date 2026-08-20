// Helpers partagés autour des objets média sérialisés par l'API.

// URL de vignette d'un média : conversion `small` ou `thumbnail` si
// disponible, sinon l'original (auparavant dupliqué dans 3 composants).
export function thumbnailUrl(media) {
  const conversion = (media.conversions || []).find(
    (conv) => conv.conversion_name === 'small' || conv.conversion_name === 'thumbnail'
  );
  return conversion?.url || media.url || null;
}
