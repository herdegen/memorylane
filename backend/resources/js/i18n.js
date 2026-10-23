// Traduction de l'interface. Le texte français sert de clé, comme __() côté
// Laravel : t('Mes photos') renvoie la traduction trouvée dans
// lang/<langue>.json, sinon le texte français tel quel. Le même fichier
// lang/en.json sert au serveur et au navigateur.
//
//   t('Bonjour :name', { name })            remplacements « :clé »
//   tc(':count photo|:count photos', n)     pluriel (:count fourni d'office)
//   tc('{0} Aucune photo|{1} Une photo|[2,*] :count photos', n)
//
// Dans les gabarits : $t(…) et $tc(…). Le dictionnaire arrive par un script
// classique placé avant l'application (/i18n/<langue>, mis en cache), donc
// disponible dès l'évaluation des modules ; changer de langue recharge la page.

// Langues de l'interface (le français est la langue source) et leur code
// Intl pour les dates, nombres et tris.
const INTL = { fr: 'fr-FR', en: 'en-GB', es: 'es-ES' };
const known = (code) => (Object.prototype.hasOwnProperty.call(INTL, code) ? code : 'fr');

const boot = typeof window !== 'undefined' ? window.__ML_I18N__ : null;
let current = known(boot?.locale);
let messages = boot?.messages ?? {};

// Tests / outils : remplacer la langue et le dictionnaire.
export function setMessages(code, dict) {
  current = known(code);
  messages = dict ?? {};
}

export const locale = () => current;

// Code pour Intl / toLocaleDateString (dates, nombres, tri alphabétique).
export const intlLocale = () => INTL[current];

// Comme Laravel : « :name » → valeur, « :Name » → avec majuscule,
// « :NAME » → en capitales ; la clé la plus longue l'emporte.
function replace(line, params) {
  if (!params) return line;
  const map = {};
  for (const [key, raw] of Object.entries(params)) {
    const value = String(raw ?? '');
    map[`:${key.charAt(0).toUpperCase()}${key.slice(1)}`] = value.charAt(0).toUpperCase() + value.slice(1);
    map[`:${key.toUpperCase()}`] = value.toUpperCase();
    map[`:${key}`] = value;
  }
  const keys = Object.keys(map).sort((a, b) => b.length - a.length);
  if (!keys.length) return line;
  const pattern = new RegExp(keys.map((k) => k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'), 'g');
  return line.replace(pattern, (m) => map[m]);
}

export function t(key, params) {
  const line = Object.prototype.hasOwnProperty.call(messages, key) ? messages[key] : key;
  return replace(line, params);
}

// Pluriel au format Laravel (trans_choice) : formes séparées par « | »,
// conditions explicites facultatives {n}, [a,b], [a,*] ; sinon règle de la
// langue (français : 0 et 1 au singulier ; anglais, espagnol : 1 seul).
function choose(line, count) {
  const parts = line.split('|');
  for (const part of parts) {
    const m = part.match(/^\s*(\{\s*-?\d+(?:\s*,\s*-?\d+)*\s*\}|\[\s*(?:-?\d+|\*)\s*,\s*(?:-?\d+|\*)\s*\])\s?([\s\S]*)$/);
    if (!m) continue;
    const cond = m[1];
    if (cond.startsWith('{')) {
      if (cond.slice(1, -1).split(',').map((v) => Number(v.trim())).includes(count)) return m[2];
    } else {
      const [from, to] = cond.slice(1, -1).split(',').map((v) => v.trim());
      if ((from === '*' || count >= Number(from)) && (to === '*' || count <= Number(to))) return m[2];
    }
  }
  const plain = parts.map((p) => p.replace(/^\s*(\{[^}]*\}|\[[^\]]*\])\s?/, ''));
  if (plain.length === 1) return plain[0];
  const singular = current === 'fr' ? Math.abs(count) < 2 : Math.abs(count) === 1;
  return singular ? plain[0] : plain[1];
}

export function tc(key, count, params) {
  const line = Object.prototype.hasOwnProperty.call(messages, key) ? messages[key] : key;
  return replace(choose(line, Number(count)), { count, ...params });
}

export default {
  install(app) {
    app.config.globalProperties.$t = t;
    app.config.globalProperties.$tc = tc;
    app.config.globalProperties.$locale = locale;
  },
};
