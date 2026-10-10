import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Cercle courant et mode (famille / amis), partagés par HandleInertiaRequests.
 * Le mode pilote les libellés de navigation (Personnes/Arbre ↔ Amis/Réseau)
 * et l'accent (ambre ↔ teal, via data-mode sur <html>).
 */
export function useCircle() {
  const page = usePage();
  const circle = computed(() => page.props.circle || null);

  const mode = computed(() => circle.value?.mode ?? 'family');
  const isFriends = computed(() => mode.value === 'friends');

  return {
    circle,
    mode,
    isFriends,
    current: computed(() => circle.value?.current ?? null),
    circles: computed(() => circle.value?.list ?? []),
    canSeeFamily: computed(() => circle.value?.family ?? true),
    canManage: computed(() => !!circle.value?.can_manage),
    peopleLabel: computed(() => (isFriends.value ? 'Amis' : 'Personnes')),
  };
}

export const KIND_LABELS = { family: 'Famille', friends: 'Amis' };
