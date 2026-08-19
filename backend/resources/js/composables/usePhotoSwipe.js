import { onMounted, onUnmounted, watch } from 'vue';
import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';

/**
 * Visionneuse PhotoSwipe partagée (galerie, album, album partagé).
 *
 * @param {() => Array} getMedia  Renvoie la liste de médias courante (les
 *                                photos en sont extraites ; les vidéos ont
 *                                leur propre page/lecteur).
 * @param {object} options
 *   - watchSource : source réactive qui reconstruit la visionneuse quand la
 *     liste change (scroll infini, rechargement…)
 *   - detailLink : ajoute « Ouvrir la fiche » dans la légende (lien vers
 *     /media/{id}) — pour les surfaces où la page média existe.
 *
 * Style de la légende : `.pswp__custom-caption` dans app.css (PhotoSwipe est
 * monté sur <body>, un style scoped ne l'atteindrait pas).
 */
export function usePhotoSwipe(getMedia, options = {}) {
  let lightbox = null;

  const photoItems = () => (getMedia() || []).filter((m) => m.type === 'photo');

  const conversion = (media, name) =>
    media.conversions?.find((c) => c.conversion_name === name);

  const imageUrl = (media) =>
    conversion(media, 'large')?.url || conversion(media, 'medium')?.url || media.url;

  const imageDimensions = (media) => {
    const large = conversion(media, 'large');
    if (large?.width && large?.height) return { width: large.width, height: large.height };
    return { width: media.width || 1600, height: media.height || 1200 };
  };

  const destroy = () => {
    if (lightbox) {
      lightbox.destroy();
      lightbox = null;
    }
  };

  const rebuild = () => {
    destroy();

    const items = photoItems();
    if (items.length === 0) return;

    lightbox = new PhotoSwipeLightbox({
      dataSource: items.map((media) => {
        const dims = imageDimensions(media);
        return {
          src: imageUrl(media),
          width: dims.width,
          height: dims.height,
          alt: media.title || media.original_name,
          caption: media.title || media.original_name,
          mediaId: media.id,
        };
      }),
      pswpModule: () => import('photoswipe'),
      padding: { top: 50, bottom: 50, left: 50, right: 50 },
      bgOpacity: 0.9,
      showHideAnimationType: 'zoom',
      appendToEl: document.body,
    });

    lightbox.on('uiRegister', () => {
      lightbox.pswp.ui.registerElement({
        name: 'custom-caption',
        order: 9,
        isButton: false,
        appendTo: 'root',
        html: '',
        onInit: (el) => {
          lightbox.pswp.on('change', () => {
            const data = lightbox.pswp.currSlide.data;
            const caption = data.caption || '';
            const link = options.detailLink && data.mediaId
              ? ` <a href="/media/${data.mediaId}" class="pswp__caption-link">Ouvrir la fiche</a>`
              : '';
            el.innerHTML = `<div class="pswp__custom-caption">${caption}${link}</div>`;
          });
        },
      });
    });

    lightbox.init();
  };

  /** Ouvre la visionneuse sur ce média (photo). Renvoie false sinon. */
  const open = (media) => {
    const items = photoItems();
    const index = items.findIndex((item) => item.id === media.id);
    if (index !== -1 && lightbox) {
      lightbox.loadAndOpen(index);
      return true;
    }
    return false;
  };

  onMounted(rebuild);
  onUnmounted(destroy);

  if (options.watchSource) {
    watch(options.watchSource, rebuild, { deep: true });
  }

  return { open, rebuild };
}
