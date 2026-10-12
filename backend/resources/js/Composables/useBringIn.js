import { reactive } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import { usePeopleDirectory } from '@/Composables/usePeopleDirectory';

/**
 * Choisir, dans un sélecteur, une fiche d'un autre cercle (ou de la famille)
 * propose de l'ajouter au cercle courant : « Ajouter au cercle » / « Juste
 * l'utiliser ici » / « Annuler ». Une seule fenêtre pour toute l'appli
 * (BringInDialog, montée dans AppLayout).
 */
const state = reactive({ open: false, person: null, circle: null, busy: false, resolve: null });

function close(choice) {
  const resolve = state.resolve;
  Object.assign(state, { open: false, person: null, circle: null, busy: false, resolve: null });
  resolve?.(choice);
}

async function add() {
  if (!state.person || !state.circle) return close('only');
  state.busy = true;
  try {
    await axios.post(`/people/${state.person.id}/circles`, { household_id: state.circle.id });
    usePeopleDirectory().invalidate();
    close('added');
  } catch (e) {
    state.busy = false;
    window.alert(e.response?.data?.message || "Impossible d'ajouter la fiche à ce cercle.");
  }
}

/**
 * À appeler avant d'utiliser une personne choisie dans un sélecteur.
 * Résout 'continue' (rien à demander), 'added', 'only' ou 'cancel'.
 */
export function useBringIn() {
  const page = usePage();

  function confirmBringIn(person) {
    if (!person?.elsewhere) return Promise.resolve('continue');
    const circle = page.props.circle?.current ?? null;
    return new Promise((resolve) => {
      Object.assign(state, { open: true, person, circle, busy: false, resolve });
    });
  }

  return { state, confirmBringIn, add, close };
}
