import { onMounted, onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';
import { conversionUrl, findConversion, webVideoUrl } from '@/utils/media';

/**
 * Visionneuse PhotoSwipe partagée (galerie, album, album partagé, fiche
 * personne, foyer). Photos ET vidéos : les vidéos sont des slides à contenu
 * personnalisé (<video> natif, lecture auto quand le slide devient actif,
 * pause quand on le quitte).
 *
 * @param {() => Array} getMedia  Renvoie la liste de médias courante (les
 *                                documents sont ignorés : ils ont leur page).
 * @param {object} options
 *   - watchSource : source réactive (getter de la liste) ; la visionneuse
 *     suit la liste quand sa référence ou sa longueur change (scroll infini,
 *     rechargement…). Surveillance superficielle : modifier un média en
 *     place ne la met pas à jour (remplacer la liste pour cela).
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

  const imageUrl = (media) => conversionUrl(media, 'large', 'medium') || media.url;

  const posterUrl = (media) => conversionUrl(media, 'medium', 'small');

  const imageDimensions = (media) => {
    const large = findConversion(media, 'large');
    if (large?.width && large?.height) return { width: large.width, height: large.height };
    return { width: media.width || 1600, height: media.height || 1200 };
  };

  // Dimensions de la vidéo web (redimensionnée) sinon de l'original ; à
  // défaut un 16:9 générique — PhotoSwipe n'en a besoin que pour le cadrage.
  const videoDimensions = (media) => {
    const web = findConversion(media, 'web');
    if (web?.width && web?.height) return { width: web.width, height: web.height };
    return { width: media.width || 1280, height: media.height || 720 };
  };

  const toSlide = (media) => {
    const label = media.title || media.original_name;
    if (media.type === 'video') {
      const dims = videoDimensions(media);
      return {
        type: 'video',
        videoSrc: webVideoUrl(media),
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

  // ---- Téléphone : bande du bas (titre, personnes sur la photo, visages à
  //      identifier, « Détails ») — visages chargés au besoin et gardés en
  //      mémoire le temps de la visionneuse. Masquée en CSS sur desktop. ----
  const facesCache = new Map();
  const loadFaces = async (mediaId) => {
    if (!facesCache.has(mediaId)) {
      facesCache.set(
        mediaId,
        fetch(`/vision/media/${mediaId}/faces`, { headers: { Accept: 'application/json' } })
          .then((r) => (r.ok ? r.json() : []))
          .catch(() => []),
      );
    }
    return facesCache.get(mediaId);
  };

  const registerMobileBar = (lb) => {
    if (!options.detailLink) return;
    lb.pswp.ui.registerElement({
      name: 'mobile-bar',
      order: 10,
      isButton: false,
      appendTo: 'root',
      html: '',
      onInit: (el, pswp) => {
        el.className += ' pswp__mobile-bar';
        const render = async () => {
          const slide = pswp.currSlide;
          const mediaId = slide?.data?.mediaId;
          const caption = slide?.data?.caption || '';
          el.replaceChildren();
          if (!mediaId) return;

          const text = document.createElement('div');
          text.className = 'pswp__mobile-bar-text';
          const title = document.createElement('div');
          title.className = 'pswp__mobile-bar-title';
          title.textContent = caption;
          text.appendChild(title);
          const chips = document.createElement('div');
          chips.className = 'pswp__mobile-bar-chips';
          text.appendChild(chips);

          const details = document.createElement('button');
          details.type = 'button';
          details.className = 'pswp__mobile-bar-details';
          details.textContent = 'Détails';
          details.addEventListener('click', () => openDetail(mediaId));

          el.append(text, details);

          const faces = await loadFaces(mediaId);
          if (pswp.currSlide?.data?.mediaId !== mediaId) return;
          const named = [];
          let unknown = 0;
          for (const f of faces) {
            if (f.person?.name) {
              if (!named.some((n) => n.id === f.person.id)) named.push(f.person);
            } else if (f.status === 'unmatched') unknown++;
          }
          for (const p of named.slice(0, 4)) {
            const chip = document.createElement('span');
            chip.className = 'pswp__mobile-bar-chip';
            const initials = document.createElement('span');
            initials.className = 'pswp__mobile-bar-initials';
            initials.textContent = p.name.split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
            chip.append(initials, document.createTextNode(p.name.split(/\s+/)[0]));
            chips.appendChild(chip);
          }
          if (named.length > 4) {
            const more = document.createElement('span');
            more.className = 'pswp__mobile-bar-chip';
            more.textContent = `+${named.length - 4}`;
            chips.appendChild(more);
          }
          if (unknown > 0) {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'pswp__mobile-bar-chip pswp__mobile-bar-chip--unknown';
            chip.textContent = `${unknown} à identifier`;
            chip.addEventListener('click', () => openDetail(mediaId));
            chips.appendChild(chip);
          }
        };
        pswp.on('change', render);
      },
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
          // Titre / nom de fichier saisis par un membre : textContent, jamais innerHTML.
          el.replaceChildren();
          if (caption) {
            const div = document.createElement('div');
            div.className = 'pswp__custom-caption';
            div.textContent = caption;
            el.appendChild(div);
          }
        });
      },
    });
  };

  // Même liste complétée (scroll infini) : on remplace seulement les slides
  // de la visionneuse existante, sans la détruire. Ouverte, elle prend aussi
  // les nouveaux slides (compteur, flèches, voisins en boucle) sans se fermer.
  const updateSlides = () => {
    const items = viewableItems();
    if (!lightbox || items.length === 0) {
      rebuild();
      return;
    }
    const slides = items.map(toSlide);
    lightbox.options.dataSource = slides;

    const pswp = lightbox.pswp;
    if (pswp) {
      pswp.options.dataSource = slides;
      [pswp.currIndex - 1, pswp.currIndex + 1].forEach((i) =>
        pswp.refreshSlideContent(pswp.getLoopedIndex(i)),
      );
    }
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
      registerMobileBar(lightbox);
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

  // Surveillance superficielle (référence + longueur) : un `deep: true`
  // reparcourait chaque média (conversions, visages…) à chaque page du
  // défilement infini et recréait la visionneuse.
  if (options.watchSource) {
    watch(
      [options.watchSource, () => options.watchSource()?.length ?? 0],
      ([list], [previousList]) => (list === previousList ? updateSlides() : rebuild()),
    );
  }

  return { open, rebuild };
}
