import { ref } from 'vue';

// Clé localStorage : 'dark' | 'light' | absente (= suivre le système).
const STORAGE_KEY = 'theme';

// État partagé (module singleton). Initialisé depuis l'attribut déjà posé
// par le script anti-FOUC dans <head> (app.blade.php), donc pas de flash.
const isDark = ref(document.documentElement.dataset.theme === 'dark');

const themeColorLight = '#d97706';
const themeColorDark = '#17140f';

function apply(dark) {
  isDark.value = dark;
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.setAttribute('content', dark ? themeColorDark : themeColorLight);
}

// Tant qu'aucun choix explicite n'est mémorisé, on suit les changements
// de préférence système en direct.
if (window.matchMedia) {
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
    if (!localStorage.getItem(STORAGE_KEY)) apply(e.matches);
  });
}

export function useTheme() {
  const toggle = () => {
    const next = !isDark.value;
    localStorage.setItem(STORAGE_KEY, next ? 'dark' : 'light');
    apply(next);
  };

  return { isDark, toggle };
}
