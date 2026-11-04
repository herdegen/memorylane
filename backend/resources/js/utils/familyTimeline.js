// « La famille dans le temps » (carte) : logique d'animation, sans Vue.
// Le serveur envoie des pistes anonymes, chacune une suite de segments
// { from, to, lat, lng } en années décimales (déplacement déjà placé à la
// médiane entre deux étapes, cf. FamilyTimelineService). Ici :
//   - createAnimator : à chaque image, où est chaque point et avec quelle
//     opacité. Au passage d'une médiane, le point glisse vers son nouveau
//     lieu en suivant une courbe, avec easing, sur une courte durée (en
//     temps réel, quelle que soit la vitesse de lecture) ; il apparaît en
//     fondu à la naissance et s'efface au décès. À l'arrêt, tout est à sa
//     place exacte (personne « en vol » sur un arrêt sur image).
//   - createGlowLayer : calque Leaflet (canvas) des petits points lumineux,
//     avec traînée pendant les déplacements et onde à l'apparition.
// Aucune dépendance (Leaflet est passé en paramètre) : la page d'aperçu
// réutilise ce fichier tel quel.

// Web Mercator (projection de la carte) : un trajet interpolé dans ce plan
// est une jolie courbe à l'écran, même entre deux continents.
const RAD = Math.PI / 180;
const toX = (lng) => lng * RAD;
const toY = (lat) => Math.log(Math.tan(Math.PI / 4 + (lat * RAD) / 2));
const toLng = (x) => x / RAD;
const toLat = (y) => (2 * Math.atan(Math.exp(y)) - Math.PI / 2) / RAD;

export const easeInOutCubic = (u) => (u < 0.5 ? 4 * u * u * u : 1 - ((-2 * u + 2) ** 3) / 2);
export const easeOutCubic = (u) => 1 - (1 - u) ** 3;

/** Année décimale de maintenant (1er juillet 2026 → ~2026,5). */
export function decimalYearNow(date = new Date()) {
  const y = date.getFullYear();
  const start = Date.UTC(y, 0, 1);
  return y + (date.getTime() - start) / (Date.UTC(y + 1, 0, 1) - start);
}

/** Durée d'un parcours complet à vitesse 1 : ~0,12 s par an, entre 10 et 20 s. */
export const playDuration = (span) => Math.min(20, Math.max(10, span * 0.12));

/**
 * Cadre des données : tous les lieux, sans les plus excentrés quand il y
 * en a beaucoup (5 % de chaque côté) — un seul émigré au bout du monde ne
 * doit pas réduire la famille à un point.
 * @returns {[[number, number], [number, number]] | null} [[sud, ouest], [nord, est]]
 */
export function dataBounds(tracks) {
  const lats = [];
  const lngs = [];
  for (const track of tracks) {
    for (const s of track) {
      lats.push(Number(s.lat));
      lngs.push(Number(s.lng));
    }
  }
  if (!lats.length) return null;
  lats.sort((a, b) => a - b);
  lngs.sort((a, b) => a - b);
  const trim = lats.length >= 20 ? Math.floor(lats.length * 0.05) : 0;
  const lo = trim;
  const hi = lats.length - 1 - trim;
  return [[lats[lo], lngs[lo]], [lats[hi], lngs[hi]]];
}

/**
 * Animateur : frame(année, maintenant en ms) renvoie les points à dessiner.
 *
 * @param {Array<Array<{from:number,to:number,lat:number,lng:number}>>} tracks
 * @param {{ rangeTo:number, moveMs?:number, fadeMs?:number, ringMs?:number, bend?:number }} options
 */
export function createAnimator(tracks, { rangeTo, moveMs = 900, fadeMs = 700, ringMs = 1400, bend = 0.2 } = {}) {
  const items = tracks
    .filter((segs) => segs.length)
    .map((segs) => {
      const s = segs.map((seg) => ({
        from: Number(seg.from),
        to: Number(seg.to),
        lat: Number(seg.lat),
        lng: Number(seg.lng),
        x: toX(Number(seg.lng)),
        y: toY(Number(seg.lat)),
      }));
      const end = s[s.length - 1].to;
      return {
        segs: s,
        start: s[0].from,
        end,
        // Encore là au bout de la frise : vivant aujourd'hui, pas de fondu final.
        open: end >= rangeTo - 0.05,
        k: -1, // segment visé
        x: s[0].x, y: s[0].y, lat: s[0].lat, lng: s[0].lng, // position affichée
        fx: 0, fy: 0, cx: 0, cy: 0, tx: 0, ty: 0, t0: -Infinity, // déplacement en cours
        a: 0, a0: 0, aim: 0, at0: -Infinity, // opacité
        ring: -Infinity, // onde d'apparition
        u: 1,
      };
    });

  // Résultats réutilisés d'une image à l'autre (pas de ramasse-miettes en lecture).
  const heat = [];
  const dots = [];

  const segmentAt = (it, year) => {
    const { segs } = it;
    let k = it.k >= 0 ? it.k : 0;
    while (k < segs.length - 1 && year >= segs[k].to) k++;
    while (k > 0 && year < segs[k].from) k--;
    return k;
  };

  // Point de la courbe (Bézier quadratique) d'un déplacement, progrès e ∈ [0, 1].
  const along = (it, e) => {
    const m = 1 - e;
    return [
      m * m * it.fx + 2 * m * e * it.cx + e * e * it.tx,
      m * m * it.fy + 2 * m * e * it.cy + e * e * it.ty,
    ];
  };

  const startMove = (it, seg, now) => {
    it.fx = it.x;
    it.fy = it.y;
    it.tx = seg.x;
    it.ty = seg.y;
    // Point de contrôle décalé sur le côté : un arc, pas une ligne droite.
    const dx = it.tx - it.fx;
    const dy = it.ty - it.fy;
    it.cx = (it.fx + it.tx) / 2 - dy * bend;
    it.cy = (it.fy + it.ty) / 2 + dx * bend;
    it.t0 = now;
  };

  const place = (it, seg) => {
    it.x = it.tx = seg.x;
    it.y = it.ty = seg.y;
    it.lat = seg.lat;
    it.lng = seg.lng;
    it.t0 = -Infinity;
    it.u = 1;
  };

  /**
   * @returns {{ heat: Array<[number, number, number]>, dots: Array<object>, present: number, busy: boolean }}
   *   busy : un déplacement, un fondu ou une onde est en cours (il faut d'autres images).
   */
  function frame(year, now) {
    let present = 0;
    let busy = false;
    heat.length = 0;
    dots.length = 0;

    for (const it of items) {
      const here = year >= it.start && (year < it.end || (it.open && year <= it.end));
      if (here) {
        present++;
        const k = segmentAt(it, year);
        if (k !== it.k) {
          const seg = it.segs[k];
          // (Ré)apparition : directement au bon endroit ; sinon il voyage.
          if (it.k === -1 || it.a < 0.05 || moveMs <= 0) place(it, seg);
          else startMove(it, seg, now);
          it.k = k;
        }
      }

      // Opacité visée : 1 pendant la vie, 0 avant et après.
      const aim = here ? 1 : 0;
      if (aim !== it.aim) {
        it.a0 = it.a;
        it.aim = aim;
        it.at0 = now;
        if (aim === 1 && it.a < 0.05) it.ring = now;
      }
      const ua = fadeMs > 0 ? (now - it.at0) / fadeMs : 1;
      it.a = ua < 1 ? it.a0 + (it.aim - it.a0) * easeOutCubic(Math.max(0, ua)) : it.aim;
      if (ua < 1) busy = true;
      if (it.a <= 0.002) continue;

      // Position affichée.
      let moving = false;
      if (it.t0 > -Infinity) {
        const u = (now - it.t0) / moveMs;
        if (u < 1) {
          const [x, y] = along(it, easeInOutCubic(Math.max(0, u)));
          it.x = x;
          it.y = y;
          it.lat = toLat(y);
          it.lng = toLng(x);
          it.u = u;
          moving = true;
          busy = true;
        } else {
          place(it, it.segs[it.k]);
        }
      }

      const ring = ringMs > 0 ? (now - it.ring) / ringMs : 1;
      if (ring < 1) busy = true;

      heat.push([it.lat, it.lng, it.a]);
      dots.push({
        lat: it.lat,
        lng: it.lng,
        alpha: it.a,
        // Traînée : positions un peu plus tôt sur la même courbe.
        trail: moving ? trailOf(it) : null,
        ring: ring >= 0 && ring < 1 ? ring : null,
      });
    }

    return { heat, dots, present, busy };
  }

  const TRAIL = 10;
  function trailOf(it) {
    const out = [];
    for (let i = 1; i <= TRAIL; i++) {
      const u = it.u - i * 0.035;
      if (u <= 0) break;
      const [x, y] = along(it, easeInOutCubic(u));
      out.push([toLat(y), toLng(x)]);
    }
    return out;
  }

  /** Nombre de pistes présentes à une année (sans toucher à l'animation). */
  function presentAt(year) {
    let n = 0;
    for (const it of items) if (year >= it.start && (year < it.end || (it.open && year <= it.end))) n++;
    return n;
  }

  return { frame, presentAt, size: items.length };
}

// ---------------------------------------------------------------- calque

/** Longueur maximale de la traînée d'un point en voyage, en pixels. */
const TAIL_PX = 70;

// Lueurs pré-dessinées (une fois par thème) : dessiner une image est bien
// plus rapide que recalculer un dégradé pour chaque point à chaque image.
function glowSprite(dark, px) {
  const c = document.createElement('canvas');
  c.width = c.height = px;
  const g = c.getContext('2d');
  const r = px / 2;
  const grad = g.createRadialGradient(r, r, 0, r, r, r);
  if (dark) {
    grad.addColorStop(0, 'rgba(255,255,255,1)');
    grad.addColorStop(0.16, 'rgba(255,240,200,1)');
    grad.addColorStop(0.32, 'rgba(251,191,36,0.75)');
    grad.addColorStop(0.6, 'rgba(245,158,11,0.22)');
    grad.addColorStop(1, 'rgba(245,158,11,0)');
  } else {
    grad.addColorStop(0, 'rgba(255,255,255,1)');
    grad.addColorStop(0.16, 'rgba(255,247,225,1)');
    grad.addColorStop(0.24, 'rgba(217,119,6,1)');
    grad.addColorStop(0.36, 'rgba(180,83,9,0.55)');
    grad.addColorStop(0.62, 'rgba(245,158,11,0.18)');
    grad.addColorStop(1, 'rgba(245,158,11,0)');
  }
  g.fillStyle = grad;
  g.fillRect(0, 0, px, px);
  return c;
}

/**
 * Calque Leaflet des points lumineux, posé dans le même plan que la
 * heatmap (il suit les déplacements et les zooms de la carte comme elle).
 * Usage : const layer = createGlowLayer(L)({ dark }) ; layer.addTo(map) ;
 * layer.setDots(dots) à chaque image.
 */
export function createGlowLayer(L) {
  const GlowLayer = L.Layer.extend({
    initialize(options = {}) {
      this._dark = !!options.dark;
      this._dots = [];
      this._dpr = Math.min(2, window.devicePixelRatio || 1);
    },

    onAdd(map) {
      this._map = map;
      const canvas = (this._canvas = L.DomUtil.create('canvas', 'leaflet-layer ml-glow-layer'));
      canvas.style.pointerEvents = 'none';
      const animated = map.options.zoomAnimation && L.Browser.any3d;
      L.DomUtil.addClass(canvas, `leaflet-zoom-${animated ? 'animated' : 'hide'}`);
      map.getPanes().overlayPane.appendChild(canvas);
      this._ctx = canvas.getContext('2d');
      this._sprite = glowSprite(this._dark, 64);
      map.on('moveend resize', this._reset, this);
      if (animated) map.on('zoomanim', this._animateZoom, this);
      this._reset();
    },

    onRemove(map) {
      this._canvas.remove();
      map.off('moveend resize', this._reset, this);
      map.off('zoomanim', this._animateZoom, this);
    },

    setDark(dark) {
      if (!!dark === this._dark) return;
      this._dark = !!dark;
      if (this._map) {
        this._sprite = glowSprite(this._dark, 64);
        this._draw();
      }
    },

    setDots(dots) {
      this._dots = dots;
      if (this._map) this._draw();
    },

    _reset() {
      const map = this._map;
      const size = (this._size = map.getSize());
      // Coin du canvas en coordonnées « calque » : on dessine relativement à
      // lui, ce qui reste juste pendant qu'on fait glisser la carte.
      this._origin = map.containerPointToLayerPoint([0, 0]);
      this._topLeft = map.containerPointToLatLng([0, 0]);
      L.DomUtil.setPosition(this._canvas, this._origin);
      const w = Math.round(size.x * this._dpr);
      const h = Math.round(size.y * this._dpr);
      if (this._canvas.width !== w || this._canvas.height !== h) {
        this._canvas.width = w;
        this._canvas.height = h;
        this._canvas.style.width = `${size.x}px`;
        this._canvas.style.height = `${size.y}px`;
      }
      this._draw();
    },

    _draw() {
      const map = this._map;
      const ctx = this._ctx;
      const sprite = this._sprite;
      const dpr = this._dpr;
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.clearRect(0, 0, this._canvas.width, this._canvas.height);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      // Fond sombre : les lueurs s'additionnent (vraie lumière) ; fond clair :
      // superposition simple, sinon tout vire au blanc.
      ctx.globalCompositeOperation = this._dark ? 'lighter' : 'source-over';
      const size = this._size;
      const origin = this._origin;
      const at = (lat, lng) => {
        const p = map.latLngToLayerPoint([lat, lng]);
        p.x -= origin.x;
        p.y -= origin.y;
        return p;
      };
      const stamp = (p, radius, alpha) => {
        if (p.x < -radius || p.y < -radius || p.x > size.x + radius || p.y > size.y + radius) return;
        ctx.globalAlpha = alpha;
        ctx.drawImage(sprite, p.x - radius, p.y - radius, radius * 2, radius * 2);
      };

      for (const d of this._dots) {
        const p = at(d.lat, d.lng);
        if (d.trail) {
          // Traînée de comète le long de la courbe, de plus en plus fine et
          // transparente, puis le point, plus gros pendant le voyage.
          // Longueur bornée à l'écran : une queue de comète, pas un trait
          // à travers l'océan pour un départ lointain.
          ctx.lineCap = 'round';
          ctx.strokeStyle = this._dark ? '#fcd34d' : '#d97706';
          let prev = p;
          let length = 0;
          for (const ll of d.trail) {
            const q = at(ll[0], ll[1]);
            const step = Math.hypot(q.x - prev.x, q.y - prev.y);
            if (step < 0.5) continue;
            const room = Math.min(step, TAIL_PX - length);
            const f = 1 - length / TAIL_PX;
            ctx.globalAlpha = d.alpha * 0.75 * f;
            ctx.lineWidth = 0.8 + 3.2 * f;
            ctx.beginPath();
            ctx.moveTo(prev.x, prev.y);
            ctx.lineTo(prev.x + ((q.x - prev.x) * room) / step, prev.y + ((q.y - prev.y) * room) / step);
            ctx.stroke();
            length += room;
            if (length >= TAIL_PX) break;
            prev = q;
          }
          stamp(p, 15, d.alpha);
        } else {
          stamp(p, 10, d.alpha * (this._dark ? 0.7 : 0.95));
        }
        if (d.ring !== null) {
          // Onde qui s'élargit à l'apparition (naissance).
          ctx.globalAlpha = (1 - d.ring) * 0.85 * d.alpha;
          ctx.strokeStyle = this._dark ? '#fcd34d' : '#d97706';
          ctx.lineWidth = 2 * (1 - d.ring) + 0.5;
          ctx.beginPath();
          ctx.arc(p.x, p.y, 4 + 20 * easeOutCubic(d.ring), 0, Math.PI * 2);
          ctx.stroke();
        }
      }
      ctx.globalAlpha = 1;
      ctx.globalCompositeOperation = 'source-over';
    },

    // Pendant l'animation de zoom, le canvas est agrandi autour de son coin
    // (même mécanique que les marqueurs Leaflet), puis redessiné à la fin.
    _animateZoom(e) {
      const map = this._map;
      const offset = map._latLngToNewLayerPoint(this._topLeft, e.zoom, e.center);
      L.DomUtil.setTransform(this._canvas, offset, map.getZoomScale(e.zoom));
    },
  });

  return (options) => new GlowLayer(options);
}
