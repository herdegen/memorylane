// Cartes de la télévision (carte des lieux, récit de vie), pensées pour le
// petit processeur graphique d'un projecteur : fond déjà sombre (aucun
// filtre CSS à recalculer sur chaque tuile), pas d'animation de zoom ni de
// fondu des tuiles. Pour changer de lieu : un glissement court si le lieu
// est proche, sinon un fondu rapide (on ne survole pas la carte).

import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const ESRI = 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas';

export function createTvMap(el) {
    const map = L.map(el, {
        zoomControl: false,
        attributionControl: true,
        dragging: false,
        keyboard: false,
        scrollWheelZoom: false,
        doubleClickZoom: false,
        boxZoom: false,
        touchZoom: false,
        zoomAnimation: false,
        fadeAnimation: false,
        markerZoomAnimation: false,
        inertia: false,
    });
    const tiles = { maxZoom: 16, keepBuffer: 1, updateWhenIdle: true };
    const base = L.tileLayer(`${ESRI}/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}`, { ...tiles, attribution: '© Esri' }).addTo(map);
    // Noms des villes, en surimpression transparente.
    L.tileLayer(`${ESRI}/World_Dark_Gray_Reference/MapServer/tile/{z}/{y}/{x}`, tiles).addTo(map);
    map.tvBase = base;
    el.style.transition = 'opacity .25s ease';
    return map;
}

// Fondu : on cache, on change la vue d'un coup, on remontre quand les
// tuiles sont arrivées (au plus tard après 900 ms).
function cut(map, change) {
    const el = map.getContainer();
    el.style.opacity = '0';
    setTimeout(() => {
        change();
        let shown = false;
        const show = () => {
            if (shown) return;
            shown = true;
            el.style.opacity = '1';
        };
        map.tvBase?.once('load', show);
        setTimeout(show, 900);
    }, 260);
}

/**
 * Montrer un lieu à ce zoom. `offsetY` (part de la hauteur, 0 à 1) : le
 * lieu est remonté d'autant (un panneau occupe le bas de l'écran).
 */
export function showPlace(map, latlng, zoom, { offsetY = 0 } = {}) {
    const center = (z) => map.unproject(map.project(latlng, z).add([0, Math.round(map.getSize().y * offsetY)]), z);
    const inView = map.getZoom() === zoom && map.getBounds().pad(-0.15).contains(latlng);
    if (inView) {
        map.panTo(center(zoom), { animate: true, duration: 0.8 });
    } else {
        cut(map, () => map.setView(center(zoom), zoom, { animate: false }));
    }
}

/** Vue d'ensemble de plusieurs points (avec un fondu si la carte est déjà affichée). */
export function showAll(map, points, { maxZoom = 9, bottom = 0, animate = true } = {}) {
    if (!points.length) return;
    const fit = () => map.fitBounds(L.latLngBounds(points).pad(0.15), {
        animate: false,
        maxZoom,
        paddingBottomRight: [0, Math.round(map.getSize().y * bottom)],
    });
    if (animate) cut(map, fit);
    else fit();
}

export { L };
