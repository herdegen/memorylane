import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Composable for accessing authentication state
 * Centralizes auth logic to avoid undefined errors across pages
 */
export function useAuth() {
    const page = usePage();

    const user = computed(() => page.props.auth?.user || null);
    const isAuthenticated = computed(() => !!user.value);
    const isAdmin = computed(() => user.value?.role === 'admin');
    const flash = computed(() => page.props.flash || {});

    return {
        user,
        isAuthenticated,
        isAdmin,
        flash,
    };
}
