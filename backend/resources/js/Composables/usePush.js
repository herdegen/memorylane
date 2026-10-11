import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

// Clé VAPID (base64url) → Uint8Array attendu par pushManager.subscribe().
const urlBase64ToUint8Array = (base64String) => {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = window.atob(base64);
  return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
};

const SYNC_FLAG = 'ml:push-synced';
// Id serveur de l'abonnement de CET appareil, appris des réponses de
// POST /push/subscriptions : permet à la déconnexion de le supprimer.
const SUB_ID_KEY = 'ml:push-sub-id';

const rememberSubscriptionId = (endpoint, list) => {
  const row = (list || []).find((s) => s.endpoint === endpoint);
  if (!row) return;
  try { sessionStorage.setItem(SUB_ID_KEY, String(row.id)); } catch { /* navigation privée */ }
};
const storedSubscriptionId = () => {
  try { return sessionStorage.getItem(SUB_ID_KEY); } catch { return null; }
};
const forgetSession = () => {
  try {
    sessionStorage.removeItem(SUB_ID_KEY);
    sessionStorage.removeItem(SYNC_FLAG);
  } catch { /* idem */ }
};

/**
 * Abonnement Web Push de CET appareil (profil, carte du tableau de bord).
 *
 * - `subscribe()` DOIT être appelé depuis un clic : iOS et Chrome refusent la
 *   demande de permission hors geste utilisateur.
 * - iOS n'accepte les pushs que pour une PWA installée (iOS ≥ 16.4) :
 *   `needsIosInstall` permet d'afficher l'astuce « écran d'accueil ».
 */
export function usePush() {
  const page = usePage();

  const supported =
    typeof window !== 'undefined' &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window;

  const permission = ref(supported ? Notification.permission : 'denied');
  // Endpoint abonné sur cet appareil (null si aucun).
  const currentEndpoint = ref(null);

  const isIos =
    /iPhone|iPad|iPod/.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isStandalone =
    window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;
  const needsIosInstall = isIos && !isStandalone;

  const registration = () => navigator.serviceWorker.ready;

  /** Relit l'abonnement local (pour savoir si cet appareil est déjà abonné). */
  const refresh = async () => {
    if (!supported) return null;
    try {
      const sub = await (await registration()).pushManager.getSubscription();
      currentEndpoint.value = sub?.endpoint ?? null;
      permission.value = Notification.permission;
      return sub;
    } catch {
      return null;
    }
  };

  /** Demande la permission, abonne l'appareil et l'enregistre côté serveur. */
  const subscribe = async () => {
    if (!supported) throw new Error('unsupported');

    permission.value = await Notification.requestPermission();
    if (permission.value !== 'granted') return null;

    const key = page.props.push?.vapid_public_key;
    if (!key) throw new Error('no-vapid-key');

    const reg = await registration();
    const sub =
      (await reg.pushManager.getSubscription()) ||
      (await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(key),
      }));

    const { data } = await axios.post('/push/subscriptions', {
      ...sub.toJSON(),
      content_encoding: (PushManager.supportedContentEncodings || ['aes128gcm'])[0],
    });
    currentEndpoint.value = sub.endpoint;
    rememberSubscriptionId(sub.endpoint, data?.subscriptions);
    return data;
  };

  /**
   * Désabonne cet appareil (navigateur + serveur). L'id serveur est cherché
   * dans `subscriptions` (liste du profil), sinon dans celui mémorisé pendant
   * la session ; s'il reste inconnu, seul le navigateur est désabonné (le
   * serveur supprime l'abonnement mort au prochain envoi).
   * `background: true` : requête silencieuse (déconnexion), cf. bootstrap.js.
   */
  const unsubscribeThisDevice = async (subscriptions = [], { background = false } = {}) => {
    if (!supported) return null;
    const sub = await (await registration()).pushManager.getSubscription();
    const row = subscriptions.find((s) => s.endpoint === sub?.endpoint);
    const id = row?.id ?? (sub ? storedSubscriptionId() : null);
    if (sub) await sub.unsubscribe().catch(() => {});
    currentEndpoint.value = null;
    forgetSession();
    if (id) {
      const { data } = await axios.delete(`/push/subscriptions/${id}`, background ? { background: true } : {});
      return data;
    }
    return null;
  };

  /**
   * Resynchronise l'abonnement local une fois par session : les navigateurs
   * peuvent faire tourner l'endpoint, et le re-POST est idempotent.
   */
  const syncOnce = async () => {
    if (!supported || Notification.permission !== 'granted') return;
    if (!page.props.push?.has_subscriptions || sessionStorage.getItem(SYNC_FLAG)) return;
    sessionStorage.setItem(SYNC_FLAG, '1');
    try {
      const sub = await (await registration()).pushManager.getSubscription();
      if (sub) {
        const { data } = await axios.post('/push/subscriptions', { ...sub.toJSON() }, { background: true });
        rememberSubscriptionId(sub.endpoint, data?.subscriptions);
      }
    } catch {
      // Silencieux : au pire l'appareil sera réabonné depuis le profil.
    }
  };

  return {
    supported,
    permission,
    currentEndpoint,
    isIos,
    needsIosInstall,
    refresh,
    subscribe,
    unsubscribeThisDevice,
    syncOnce,
  };
}
