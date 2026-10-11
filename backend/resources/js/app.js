import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { rememberNavigation } from './utils/navigation';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME || 'MemoryLane';

// Historique applicatif minimal : l'URL de la page précédente, pour que les
// boutons « Retour » (fiche média…) ramènent d'où l'on vient (fiche personne,
// album, foyer…) plutôt que vers une page fixe. Par onglet (sessionStorage),
// survit au rechargement. Voir resources/js/utils/navigation.js.
router.on('navigate', (event) => rememberNavigation(event.detail.page.url));

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
