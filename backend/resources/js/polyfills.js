// Compléments pour les vieilles WebView des téléviseurs (Chrome 90 sur
// Android TV 11 non mis à jour) : fonctions utilisées par Inertia/Vue ou par
// nos pages, apparues après Chrome 90. Sans effet sur un navigateur récent.

const define = (target, name, value) => {
    if (!(name in target)) {
        Object.defineProperty(target, name, { value, writable: true, configurable: true, enumerable: false });
    }
};

define(Object, 'hasOwn', (obj, key) => Object.prototype.hasOwnProperty.call(obj, key));

function at(n) {
    const len = this.length;
    let i = Math.trunc(n) || 0;
    if (i < 0) i += len;
    return i < 0 || i >= len ? undefined : this[i];
}
define(Array.prototype, 'at', at);
define(String.prototype, 'at', at);

define(Array.prototype, 'findLast', function findLast(fn, thisArg) {
    for (let i = this.length - 1; i >= 0; i--) if (fn.call(thisArg, this[i], i, this)) return this[i];
    return undefined;
});
define(Array.prototype, 'findLastIndex', function findLastIndex(fn, thisArg) {
    for (let i = this.length - 1; i >= 0; i--) if (fn.call(thisArg, this[i], i, this)) return i;
    return -1;
});
define(Array.prototype, 'toSorted', function toSorted(cmp) { return this.slice().sort(cmp); });
define(Array.prototype, 'toReversed', function toReversed() { return this.slice().reverse(); });
define(Array.prototype, 'toSpliced', function toSpliced(...args) { const copy = this.slice(); copy.splice(...args); return copy; });
define(Array.prototype, 'with', function withAt(index, value) {
    const copy = this.slice();
    copy[index < 0 ? copy.length + index : index] = value;
    return copy;
});

// Copie profonde des données de page (Inertia) : objets simples, tableaux,
// dates — ce que contient l'état d'une page.
define(globalThis, 'structuredClone', function structuredClone(value) {
    const clone = (v, seen) => {
        if (v === null || typeof v !== 'object') return v;
        if (seen.has(v)) return seen.get(v);
        if (v instanceof Date) return new Date(v.getTime());
        if (v instanceof Map) { const m = new Map(); seen.set(v, m); v.forEach((val, k) => m.set(clone(k, seen), clone(val, seen))); return m; }
        if (v instanceof Set) { const s = new Set(); seen.set(v, s); v.forEach((val) => s.add(clone(val, seen))); return s; }
        const out = Array.isArray(v) ? [] : {};
        seen.set(v, out);
        for (const k of Object.keys(v)) out[k] = clone(v[k], seen);
        return out;
    };
    return clone(value, new Map());
});
