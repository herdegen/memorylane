// Bandeaux du haut de page (démo, « se connecter en tant que ») : leur
// hauteur est publiée dans la variable CSS --top-banners, que les pages plein
// écran (.app-viewport : carte, arbre, réseau) retranchent — sinon le bas de
// la carte et son lecteur passent sous la barre d'onglets du téléphone.
import { onBeforeUnmount, watch } from 'vue';

const heights = new Map();

function publish() {
  const total = [...heights.values()].reduce((sum, h) => sum + h, 0);
  document.documentElement.style.setProperty('--top-banners', `${total}px`);
}

export function useTopBanner(key, elementRef) {
  let observer = null;
  const forget = () => {
    observer?.disconnect();
    observer = null;
    heights.delete(key);
    publish();
  };
  const stop = watch(elementRef, (el) => {
    forget();
    if (!el) return;
    observer = new ResizeObserver(() => {
      heights.set(key, el.offsetHeight);
      publish();
    });
    observer.observe(el);
  }, { immediate: true });

  onBeforeUnmount(() => {
    stop();
    forget();
  });
}
