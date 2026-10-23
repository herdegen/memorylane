import { ref } from 'vue';
import { t } from '@/i18n';

// Boîte de confirmation partagée (module singleton), sur le modèle de
// useToast : n'importe quel composant demande une confirmation, ConfirmDialog
// (monté une seule fois dans AppLayout) l'affiche. Remplace window.confirm(),
// bloquant, en anglais selon le navigateur et impossible à styler.
//
//   const { confirm } = useConfirm();
//   if (!(await confirm({ title, message, confirmLabel: 'Supprimer', danger: true }))) return;
//
// La promesse se résout à true (confirmé) ou false (annulé : bouton, Échap,
// clic hors de la boîte, ou nouvelle demande qui remplace celle-ci).

// Demande en cours affichée par ConfirmDialog (null = aucune).
const pending = ref(null);
let nextId = 1;

// Nombre de ConfirmDialog montés. Sans aucun (page hors AppLayout), on
// retombe sur la boîte native plutôt que de laisser la promesse en suspens.
let hosts = 0;

function confirm({
  title = t('Confirmation'),
  message = '',
  confirmLabel = t('Confirmer'),
  cancelLabel = t('Annuler'),
  danger = false,
} = {}) {
  if (hosts === 0) {
    return Promise.resolve(window.confirm(message || title));
  }
  // Une seule boîte à la fois : une nouvelle demande annule la précédente.
  answer(false);
  return new Promise((resolve) => {
    pending.value = { id: nextId++, title, message, confirmLabel, cancelLabel, danger, resolve };
  });
}

function answer(ok) {
  const current = pending.value;
  if (!current) return;
  pending.value = null;
  current.resolve(ok);
}

// Enregistrement d'une ConfirmDialog montée ; renvoie la fonction de
// désenregistrement (une demande restée ouverte au démontage est annulée).
function registerHost() {
  hosts++;
  return () => {
    hosts--;
    if (hosts === 0) answer(false);
  };
}

export function useConfirm() {
  return { confirm, pending, answer, registerHost };
}
