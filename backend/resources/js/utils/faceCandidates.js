// Classement des personnes proposées pour un visage (fiche photo, « Qui est-ce ? »).
//
// Le plus évident d'abord : quelqu'un de vivant à la date de la photo, dont
// l'âge colle au visage, déjà reconnu sur d'autres photos, proche de la
// famille. Les personnes impossibles (pas encore nées, déjà décédées) passent
// en dernier — le serveur refuserait de toute façon l'identification.
//
// `photo` = { from, to, face_age } renvoyé par le serveur (années possibles de
// la photo selon sa précision, âge apparent du visage) ; null si inconnu.
import { matchesPerson, relevanceTier } from '@/utils/personSearch';

const yearOf = (date) => {
  const y = date ? Number(String(date).slice(0, 4)) : NaN;
  return Number.isFinite(y) && y > 0 ? y : null;
};

const birthYear = (p) => p?.birth_year ?? yearOf(p?.birth_date);
const deathYear = (p) => p?.death_year ?? yearOf(p?.death_date);

// « 1952 », « 1952–2010 », « † 2010 » : de quoi départager deux homonymes.
export function lifeYears(p) {
  const b = birthYear(p);
  const d = deathYear(p);
  if (b && d) return `${b}–${d}`;
  if (b) return String(b);
  if (d) return `† ${d}`;
  return '';
}

// Tolérance d'un an, comme le garde-fou serveur (vieilles photos mal datées).
const TOLERANCE = 1;

// Vraisemblance sur la photo : 0 âge cohérent, 1 inconnu, 2 âge très éloigné
// du visage, 3 impossible (pas encore né·e ou déjà décédé·e).
export function photoFit(p, photo) {
  if (!photo?.from) return 1;
  const b = birthYear(p);
  const d = deathYear(p);
  if (b && b > photo.to + TOLERANCE) return 3;
  if (d && d < photo.from - TOLERANCE) return 3;
  if (!b) return 1;
  const age = photo.face_age;
  if (age == null) return 0;
  const lo = photo.from - b;
  const hi = photo.to - b;
  const gap = age < lo ? lo - age : age > hi ? age - hi : 0;
  // L'âge apparent est un signal faible : large marge, plus large avec l'âge.
  return gap <= Math.max(10, age * 0.3) ? 0 : 2;
}

export function isImpossible(p, photo) {
  return photoFit(p, photo) === 3;
}

// Comparateur « le plus évident d'abord » (hors pertinence textuelle).
export function compareForFace(a, b, photo) {
  const fa = photoFit(a, photo);
  const fb = photoFit(b, photo);
  // Impossible, puis âge très éloigné : relégués en fin de liste.
  const ba = fa >= 2 ? fa : 0;
  const bb = fb >= 2 ? fb : 0;
  if (ba !== bb) return ba - bb;

  // Déjà reconnu·e sur des photos : bien plus probable qu'un ancêtre sans photo.
  const sa = (a.matched_faces_count ?? 0) > 0;
  const sb = (b.matched_faces_count ?? 0) > 0;
  if (sa !== sb) return sa ? -1 : 1;

  // Proximité de parenté avec le compte connecté.
  const pa = a.proximity;
  const pb = b.proximity;
  if (pa == null && pb != null) return 1;
  if (pa != null && pb == null) return -1;
  if (pa != null && pb != null && pa !== pb) return pa - pb;

  // Âge cohérent avant âge inconnu.
  if (fa !== fb) return fa - fb;

  const ca = a.matched_faces_count ?? 0;
  const cb = b.matched_faces_count ?? 0;
  if (ca !== cb) return cb - ca;

  const ya = birthYear(a);
  const yb = birthYear(b);
  if (ya && yb && ya !== yb) return yb - ya; // récent d'abord
  if (ya && !yb) return -1;
  if (!ya && yb) return 1;

  return (a.name ?? '').localeCompare(b.name ?? '');
}

// Filtre (recherche tolérante) et classe une liste de personnes pour un visage.
// Avec une recherche : les impossibles en dernier, puis la pertinence du nom
// (exact, commence par…), puis le plus évident.
export function rankForFace(people, query, photo) {
  const q = (query ?? '').trim();
  const list = q ? people.filter((p) => matchesPerson(q, p.name)) : people.slice();
  return list.sort((a, b) => {
    if (q) {
      const ia = isImpossible(a, photo);
      const ib = isImpossible(b, photo);
      if (ia !== ib) return ia ? 1 : -1;
      const ta = relevanceTier(q, a.name);
      const tb = relevanceTier(q, b.name);
      if (ta !== tb) return ta - tb;
    }
    return compareForFace(a, b, photo);
  });
}
