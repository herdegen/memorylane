import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Fiches communes avec d'autres familles : faut-il montrer l'entrée du menu,
 * et la pastille (propositions à trancher + nouveautés depuis la dernière visite).
 */
export function useSharedPeople() {
  const page = usePage();
  const counts = computed(() => page.props.sharedPeople || null);
  const visible = computed(() => !!counts.value && (counts.value.shared > 0 || counts.value.to_decide > 0 || counts.value.news > 0));
  const badge = computed(() => (counts.value ? counts.value.to_decide + counts.value.news : 0));

  return { counts, visible, badge };
}
