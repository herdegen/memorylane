<script>
// Phrase traduite contenant des éléments (lien, gras, composant) : chaque
// « :nom » de la phrase est remplacé par l'emplacement (slot) du même nom,
// sinon par values[nom]. La phrase reste entière pour le traducteur.
//
//   <Trans text="Cliquez sur :link pour continuer.">
//     <template #link><Link href="/x">{{ $t('ce lien') }}</Link></template>
//   </Trans>
import { t } from '@/i18n';

export default {
  name: 'Trans',
  props: {
    text: { type: String, required: true },
    values: { type: Object, default: () => ({}) },
  },
  setup(props, { slots }) {
    return () => {
      const line = t(props.text);
      const out = [];
      let last = 0;
      for (const m of line.matchAll(/:([A-Za-z_][A-Za-z0-9_]*)/g)) {
        const name = m[1];
        const has = slots[name] || Object.prototype.hasOwnProperty.call(props.values, name);
        if (!has) continue;
        if (m.index > last) out.push(line.slice(last, m.index));
        out.push(slots[name] ? slots[name]() : String(props.values[name] ?? ''));
        last = m.index + m[0].length;
      }
      if (last < line.length) out.push(line.slice(last));
      return out.flat();
    };
  },
};
</script>
