import tailwindcss from '@tailwindcss/postcss';

// Interface télévision : les anciennes WebView Android (Chrome < 99)
// ignorent les règles placées dans @layer. Pour resources/css/tv.css
// seulement, on retire les couches ; Tailwind les écrit déjà dans l'ordre de
// la cascade (theme, base, components, utilities), le résultat est le même.
const unwrapLayersForTv = () => ({
    postcssPlugin: 'tv-unwrap-layers',
    OnceExit(root) {
        if (!root.source?.input?.file?.endsWith('/resources/css/tv.css')) return;
        let found = true;
        while (found) {
            found = false;
            root.walkAtRules('layer', (rule) => {
                found = true;
                if (rule.nodes && rule.nodes.length) rule.replaceWith(rule.nodes);
                else rule.remove();
            });
        }
    },
});
unwrapLayersForTv.postcss = true;

export default {
    plugins: [tailwindcss(), unwrapLayersForTv()],
};
