import { computed, ref } from 'vue';
import { detectDevice } from '@/utils/device';

/**
 * Installation de MemoryLane comme application (PWA).
 * - Android / Chrome : le navigateur émet `beforeinstallprompt` ; on le garde
 *   pour proposer un vrai bouton « Installer » (un seul geste).
 * - iPhone / iPad : aucune installation programmable, seulement le tuto
 *   (Partager › « Sur l'écran d'accueil »).
 * `listenForInstallPrompt()` doit être appelé au démarrage de l'app (bootstrap),
 * l'événement ne survenant qu'une fois, très tôt.
 */

const deferredPrompt = ref(null);
const justInstalled = ref(false);
let listening = false;

export function listenForInstallPrompt() {
  if (listening || typeof window === 'undefined') return;
  listening = true;
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault(); // on affiche notre propre bannière
    deferredPrompt.value = event;
  });
  window.addEventListener('appinstalled', () => {
    justInstalled.value = true;
    deferredPrompt.value = null;
  });
}

/** Ouverte depuis l'icône de l'écran d'accueil (et non dans le navigateur) ? */
export function isStandalone() {
  if (typeof window === 'undefined') return false;
  return window.matchMedia?.('(display-mode: standalone)').matches
    || window.matchMedia?.('(display-mode: fullscreen)').matches
    || navigator.standalone === true;
}

/** Navigateur sur iPhone : le bouton Partager n'est pas au même endroit. */
export function iosBrowser() {
  const ua = navigator.userAgent || '';
  if (/CriOS/.test(ua)) return 'chrome';
  if (/FxiOS/.test(ua)) return 'firefox';
  if (/EdgiOS/.test(ua)) return 'edge';
  if (/FBAN|FBAV|Instagram|Line\/|GSA\//.test(ua)) return 'in-app';
  return /Safari/.test(ua) ? 'safari' : 'in-app';
}

export function useInstallApp() {
  const device = detectDevice();

  const canPromptNative = computed(() => !!deferredPrompt.value);

  /** Lance la boîte d'installation native (Android). Renvoie true si acceptée. */
  const promptInstall = async () => {
    const event = deferredPrompt.value;
    if (!event) return false;
    event.prompt();
    const choice = await event.userChoice.catch(() => null);
    deferredPrompt.value = null;
    if (choice?.outcome === 'accepted') {
      justInstalled.value = true;
      return true;
    }
    return false;
  };

  return {
    device,
    isMobile: device === 'ios' || device === 'android',
    standalone: isStandalone(),
    canPromptNative,
    promptInstall,
    justInstalled,
  };
}
