import { onMounted, onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';

/**
 * Visionneuse PhotoSwipe partagée (galerie, album, album partagé, fiche
 * personne, foyer). Photos ET vidéos : les vidéos sont des slides à contenu
 * personnalisé (<video> natif, lecture auto quand le slide devient actif,
 * pause quand on le quitte).
 *
 * @param {() => Array} getMedia  Renvoie la liste de médias courante (les
 *                                documents sont ignorés : ils ont leur page).
 * @param {object} options
 *   - watchSource : source réactive qui reconstruit la visionneuse quand la
 *     liste change (scroll infini, rechargement…)
 *   - detailLink : ajoute un bouton « i » (infos) dans la barre du haut qui
 *     ferme la visionneuse et ouvre la fiche /media/{id} — pour les surfaces
 *     où la page média existe (pas l'album partagé anonyme).
 *
 * Mobile (< 768 px) : aucune marge autour de l'image (elle occupe tout
 * l'écran), pas de légende ni de bouton zoom — voir `.pswp` dans app.css
 * (PhotoSwipe est monté sur <body>, un style scoped ne l'atteindrait pas).
 */
export function usePhotoSwipe(getMedia, options = {}) {
  let lightbox = null;

  const MOBILE_MAX = 767;

  const viewableItems = () =>
    (getMedia() || []).filter((m) => m.type === 'photo' || m.type === 'video');

  const conversion = (media, name) =>
    media.conversions?.find((c) => c.conversion_name === name);

  const imageUrl = (media) =>
    conversion(media, 'large')?.url || conversion(media, 'medium')?.url || media.url;

  const videoUrl = (media) => conversion(media, 'web')?.url || media.url;

  const posterUrl = (media) =>
    conversion(media, 'medium')?.url || conversion(media, 'small')?.url || null;

  const imageDimensions = (media) => {
    const large = conversion(media, 'large');
    if (large?.width && large?.height) return { width: large.width, height: large.height };
    return { width: media.width || 1600, height: media.height || 1200 };
  };

  // Dimensions de la vidéo web (redimensionnée) sinon de l'original ; à
  // défaut un 16:9 générique — PhotoSwipe n'en a besoin que pour le cadrage.
  const videoDimensions = (media) => {
    const web = conversion(media, 'web');
    if (web?.width && web?.height) return { width: web.width, height: web.height };
    return { width: media.width || 1280, height: media.height || 720 };
  };

  const toSlide = (media) => {
    const label = media.title || media.original_name;
    if (media.type === 'video') {
      const dims = videoDimensions(media);
      return {
        type: 'video',
        videoSrc: videoUrl(media),
        msrc: posterUrl(media),
        width: dims.width,
        height: dims.height,
        alt: label,
        caption: label,
        mediaId: media.id,
      };
    }
    const dims = imageDimensions(media);
    return {
      src: imageUrl(media),
      width: dims.width,
      height: dims.height,
      alt: label,
      caption: label,
      mediaId: media.id,
    };
  };

  const destroy = () => {
    if (lightbox) {
      lightbox.destroy();
      lightbox = null;
    }
  };

  const openDetail = (mediaId) => {
    if (!mediaId) return;
    lightbox?.pswp?.close();
    router.visit(`/media/${mediaId}`);
  };

  // ---- Slides vidéo : contenu personnalisé ---------------------------------
  const registerVideoHandlers = (lb) => {
    lb.on('contentLoad', (e) => {
      const { content } = e;
      if (content.type !== 'video') return;
      e.preventDefault();

      const video = document.createElement('video');
      video.src = content.data.videoSrc;
      if (content.data.msrc) video.poster = content.data.msrc;
      video.controls = true;
      video.playsInline = true;
      video.preload = 'metadata';
      video.className = 'pswp__video';
      content.element = video;
    });

    // Un geste qui démarre sur la vidéo est laissé au lecteur natif (lecture,
    // barre de progression…) : PhotoSwipe ne doit ni le capter ni le bloquer.
    // On change de slide en glissant sur le fond noir ou avec les flèches.
    lb.on('pointerDown', (e) => {
      if (e.originalEvent?.target?.tagName === 'VIDEO') e.preventDefault();
    });

    // Lecture auto quand le slide devient visible, pause quand on le quitte
    // (le clic/geste qui amène au slide vaut interaction utilisateur).
    lb.on('contentActivate', ({ content }) => {
      if (content.type === 'video' && content.element) {
        content.element.play?.().catch(() => {});
      }
    });
    lb.on('contentDeactivate', ({ content }) => {
      if (content.type === 'video' && content.element) {
        content.element.pause?.();
      }
    });
    lb.on('close', () => {
      const el = lb.pswp?.currSlide?.content?.element;
      if (el?.tagName === 'VIDEO') el.pause();
    });
  };

  // ---- Barre du haut : bouton « i » vers la fiche -------------------------
  const registerInfoButton = (lb) => {
    if (!options.detailLink) return;
    lb.pswp.ui.registerElement({
      name: 'info',
      order: 8,
      isButton: true,
      title: 'Ouvrir la fiche',
      ariaLabel: 'Ouvrir la fiche du média',
      html: {
        isCustomSVG: true,
        inner:
          '<g id="pswp__icn-info" fill="none" stroke="currentColor" stroke-linecap="round">' +
          '<circle cx="16" cy="16" r="10" stroke-width="2"/>' +
          '<path d="M16 14.5v7M16 10.5v.5" stroke-width="2.5"/>' +
          '</g>',
        outlineID: 'pswp__icn-info',
      },
      onClick: (event, el, pswp) => openDetail(pswp.currSlide?.data?.mediaId),
    });
  };

  // ---- Légende (desktop uniquement, masquée en CSS sur mobile) ------------
  const registerCaption = (lb) => {
    lb.pswp.ui.registerElement({
      name: 'custom-caption',
      order: 9,
      isButton: false,
      appendTo: 'root',
      html: '',
      onInit: (el) => {
        lb.pswp.on('change', () => {
          const caption = lb.pswp.currSlide?.data?.caption || '';
          el.innerHTML = caption ? `<div class="pswp__custom-caption">${caption}</div>` : '';
        });
      },
    });
  };

  const rebuild = () => {
    destroy();

    const items = viewableItems();
    if (items.length === 0) return;

    lightbox = new PhotoSwipeLightbox({
      dataSource: items.map(toSlide),
      pswpModule: () => import('photoswipe'),
      // Mobile : l'image occupe 100 % de l'écran ; desktop : marge confortable.
      paddingFn: (viewportSize) =>
        viewportSize.x <= MOBILE_MAX
          ? { top: 0, bottom: 0, left: 0, right: 0 }
          : { top: 50, bottom: 50, left: 50, right: 50 },
      // Mobile : fond opaque (vrai plein écran) ; desktop : la page transparaît.
      bgOpacity: window.innerWidth <= MOBILE_MAX ? 1 : 0.9,
      // Un tap sur un slide non zoomable (vidéo) ne ferme pas la visionneuse.
      clickToCloseNonZoomable: false,
      showHideAnimationType: 'zoom',
      appendToEl: document.body,
    });

    registerVideoHandlers(lightbox);

    lightbox.on('uiRegister', () => {
      registerInfoButton(lightbox);
      registerCaption(lightbox);
    });

    lightbox.init();
  };

  /** Ouvre la visionneuse sur ce média (photo ou vidéo). Renvoie false sinon. */
  const open = (media) => {
    const items = viewableItems();
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
