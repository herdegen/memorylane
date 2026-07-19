// Recherche de personnes tolérante : insensible aux accents et à la casse
// (« eloise » → « Éloïse »), puis tolérante aux fautes de frappe légères
// (« matieu » → « Matthieu »). Tout se fait côté client : les listes de
// personnes sont déjà chargées entièrement (~quelques centaines de fiches).

// Minuscules, sans diacritiques, espaces normalisés.
export function normalize(str) {
  return (str ?? '')
    .toString()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '') // supprime les diacritiques
    .toLowerCase()
    .trim();
}

// Distance d'édition minimale pour retrouver `q` comme sous-chaîne approximative
// de `t` (fenêtre glissante : le match peut commencer et finir n'importe où dans
// `t`). Renvoie 0 si `q` est une sous-chaîne exacte de `t` — un seul mécanisme
// couvre donc la correspondance exacte (phase 1) et les fautes légères (phase 2).
function fuzzySubstringDistance(q, t) {
  const m = q.length;
  const n = t.length;
  if (m === 0) return 0;
  if (n === 0) return m;

  // Ligne 0 remplie de 0 : la correspondance peut démarrer à n'importe quel
  // indice de `t` sans coût.
  let prev = new Array(n + 1).fill(0);
  for (let i = 1; i <= m; i++) {
    const cur = new Array(n + 1);
    cur[0] = i; // supprimer les i premiers caractères de `q`
    for (let j = 1; j <= n; j++) {
      const cost = q[i - 1] === t[j - 1] ? 0 : 1;
      cur[j] = Math.min(
        prev[j] + 1, // suppression dans q
        cur[j - 1] + 1, // insertion
        prev[j - 1] + cost, // substitution / correspondance
      );
    }
    prev = cur;
  }
  // Meilleure fin de fenêtre possible.
  return Math.min(...prev);
}

// Nombre de fautes tolérées selon la longueur de la requête : court = strict
// (évite le bruit en début de frappe), long = plus permissif. Réglé pour
// rattraper des fautes courantes comme « matieu » → « matthieu » (2 lettres
// manquantes) sans exploser les faux positifs.
function typoBudget(len) {
  if (len <= 2) return 0;
  if (len <= 4) return 1;
  if (len <= 7) return 2;
  return 3;
}

// True si `query` correspond à `text` (accents/casse ignorés, fautes légères OK).
export function matchesPerson(query, text) {
  const q = normalize(query);
  if (!q) return true;
  return fuzzySubstringDistance(q, normalize(text)) <= typoBudget(q.length);
}

// Niveau de pertinence textuelle (plus bas = meilleur) : 0 exact, 1 commence
// par la requête, 2 tous les mots présents, 3 sinon (match flou). Insensible
// aux accents/casse. Aligné sur le tri serveur (SearchController) : sert à faire
// PRIMER la pertinence sur la proximité (« loi » → « Loïc » avant un proche qui
// matche seulement en flou).
export function relevanceTier(query, text) {
  const q = normalize(query);
  const t = normalize(text);
  if (!q || t === q) return 0;
  if (t.startsWith(q)) return 1;
  const tokens = q.split(' ').filter(Boolean);
  return tokens.every((tok) => t.includes(tok)) ? 2 : 3;
}

// Filtre + trie une liste par pertinence (meilleure correspondance en tête).
// `getName` extrait le libellé recherchable d'un item ; les items d'origine sont
// renvoyés tels quels. Requête vide → liste inchangée.
export function searchPeople(query, items, getName = (p) => p?.name ?? '') {
  const q = normalize(query);
  if (!q) return items;
  const budget = typoBudget(q.length);

  const scored = [];
  for (const item of items) {
    const t = normalize(getName(item));
    if (!t) continue;
    const dist = fuzzySubstringDistance(q, t);
    if (dist <= budget) scored.push({ item, dist, len: t.length });
  }
  // Distance croissante, puis nom le plus court (correspondance la plus serrée).
  scored.sort((a, b) => a.dist - b.dist || a.len - b.len);
  return scored.map((s) => s.item);
}
