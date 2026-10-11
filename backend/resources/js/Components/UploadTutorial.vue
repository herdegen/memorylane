<template>
  <BaseModal title="Comment ajouter vos photos" max-width="2xl" @close="$emit('close')">
    <div class="px-5 sm:px-6 pt-4 pb-6">
      <!-- Appareil -->
      <div class="flex rounded-lg bg-surface-100 p-1 text-sm font-medium mb-5">
        <button
          v-for="tab in tabs"
          :key="tab.value"
          type="button"
          class="flex-1 rounded-md px-2 py-1.5 transition"
          :class="current === tab.value ? 'bg-white text-surface-900 shadow-xs' : 'text-surface-500 hover:text-surface-700'"
          @click="current = tab.value"
        >
          {{ tab.label }}
        </button>
      </div>

      <ol class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <li v-for="(step, i) in steps" :key="current + i" class="rounded-2xl border border-surface-200 bg-surface-50 p-4 flex sm:flex-col gap-4">
          <!-- Illustration -->
          <div class="shrink-0 w-28 sm:w-full h-28 rounded-xl bg-white border border-surface-200 flex items-center justify-center overflow-hidden">
            <!-- Bouton -->
            <div v-if="step.art === 'button'" class="px-3 py-2 rounded-lg bg-brand-600 text-white text-[11px] font-semibold shadow-warm-sm flex items-center gap-1">
              <span class="text-sm leading-none">+</span>{{ current === 'desktop' ? 'Choisir des photos' : 'Choisir dans mes photos' }}
            </div>
            <!-- Sélecteur de photos -->
            <div v-else-if="step.art === 'picker'" class="w-[88%]">
              <div class="flex justify-between text-[9px] font-semibold text-blue-600 mb-1 px-0.5">
                <span>Annuler</span><span>{{ current === 'android' ? 'OK' : 'Ajouter' }}</span>
              </div>
              <div class="grid grid-cols-4 gap-0.5">
                <div v-for="n in 8" :key="n" class="relative aspect-square rounded-[3px]" :class="tileColors[n % tileColors.length]">
                  <span v-if="[1, 4, 6].includes(n)" class="absolute bottom-0.5 right-0.5 w-3 h-3 rounded-full bg-blue-600 border border-white flex items-center justify-center">
                    <svg class="w-2 h-2 text-white" fill="none" stroke="currentColor" stroke-width="4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                  </span>
                </div>
              </div>
            </div>
            <!-- Glisser-déposer -->
            <div v-else-if="step.art === 'drop'" class="w-[85%] h-[70%] rounded-lg border-2 border-dashed border-brand-400 bg-brand-50 flex items-center justify-center gap-1">
              <div v-for="n in 3" :key="n" class="w-6 h-6 rounded-[3px] -rotate-6" :class="tileColors[n]"></div>
            </div>
            <!-- Envoi -->
            <div v-else class="w-[80%] text-center">
              <div class="mx-auto mb-2 w-8 h-8 rounded-full bg-green-500 flex items-center justify-center">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
              </div>
              <div class="h-1.5 rounded-full bg-surface-200 overflow-hidden"><div class="h-1.5 w-full bg-brand-600"></div></div>
              <p class="mt-1 text-[10px] font-semibold text-surface-600">12 photos ajoutées !</p>
            </div>
          </div>

          <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-700">Étape {{ i + 1 }}</p>
            <p class="mt-1 text-sm text-surface-800 leading-relaxed" v-html="step.text"></p>
          </div>
        </li>
      </ol>

      <p v-if="tip" class="mt-5 text-sm text-surface-500" v-html="tip"></p>
    </div>

    <template #footer>
      <button type="button" class="btn-primary" @click="$emit('close')">J'ai compris</button>
    </template>
  </BaseModal>
</template>

<script setup>
import { computed, ref } from 'vue';
import BaseModal from '@/Components/BaseModal.vue';

const props = defineProps({
  // 'ios' | 'android' | 'desktop' : onglet ouvert par défaut.
  device: { type: String, default: 'desktop' },
});
defineEmits(['close']);

const tabs = [
  { value: 'ios', label: 'iPhone / iPad' },
  { value: 'android', label: 'Android' },
  { value: 'desktop', label: 'Ordinateur' },
];
const current = ref(props.device);

const tileColors = ['bg-amber-200', 'bg-sky-200', 'bg-rose-200', 'bg-emerald-200', 'bg-violet-200'];

// Textes statiques (aucune donnée utilisateur) : v-html sert juste au gras.
const STEPS = {
  ios: [
    { art: 'button', text: 'Touchez <strong>« Choisir dans mes photos »</strong>, puis <strong>« Photothèque »</strong>.' },
    { art: 'picker', text: 'Touchez les photos à envoyer : elles se cochent. Astuce : <strong>glissez le doigt</strong> pour en cocher plusieurs d\'un coup. Puis <strong>« Ajouter »</strong>.' },
    { art: 'done', text: 'Touchez <strong>« Envoyer »</strong> et <strong>gardez la page ouverte</strong> jusqu\'à la coche verte.' },
  ],
  android: [
    { art: 'button', text: 'Touchez <strong>« Choisir dans mes photos »</strong>, puis votre galerie (<strong>Photos</strong>).' },
    { art: 'picker', text: '<strong>Appui long</strong> sur une première photo, puis touchez les autres pour les cocher. Validez avec <strong>« OK »</strong>.' },
    { art: 'done', text: 'Touchez <strong>« Envoyer »</strong> et <strong>gardez la page ouverte</strong> jusqu\'à la coche verte.' },
  ],
  desktop: [
    { art: 'drop', text: '<strong>Glissez</strong> vos photos (ou tout un dossier sélectionné) dans le cadre, ou cliquez sur <strong>« Choisir des photos »</strong>.' },
    { art: 'picker', text: 'Pour en prendre plusieurs : <strong>Ctrl</strong> (ou <strong>⌘</strong> sur Mac) + clic, ou <strong>Ctrl + A</strong> pour tout le dossier.' },
    { art: 'done', text: 'Cliquez sur <strong>« Envoyer »</strong> et <strong>laissez l\'onglet ouvert</strong> jusqu\'à la fin (même pour des centaines de photos).' },
  ],
};

const TIPS = {
  ios: 'Les photos sont partagées avec la famille par défaut : décochez la case avant d\'envoyer pour les garder pour vous.',
  android: 'Encore plus simple après avoir installé l\'appli : depuis la Galerie, <strong>Partager › MemoryLane</strong>.',
  desktop: 'Vos photos sont chez Google Photos ? Utilisez plutôt « Autres façons d\'importer » en bas de la page.',
};

const steps = computed(() => STEPS[current.value] ?? STEPS.desktop);
const tip = computed(() => TIPS[current.value]);
</script>
