<template>
  <!-- Feuille « Ajouter » du bouton ⊕ : la galerie du téléphone (ou l'appareil
       photo) d'abord, les autres sources ensuite. -->
  <BottomSheet :open="open" title="Ajouter des souvenirs" @close="$emit('close')">
    <div class="flex flex-col gap-3">
      <div class="grid grid-cols-2 gap-2.5">
        <label class="h-[120px] rounded-2xl bg-brand-600 text-white flex flex-col items-start justify-end gap-1 p-3.5 cursor-pointer shadow-[0_14px_30px_-14px_rgba(217,119,6,.8)] dark:shadow-none">
          <input type="file" class="sr-only" multiple accept="image/*,video/*" @change="onFiles">
          <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3" /><path d="M3 16l5-5 4 4 3-3 6 6" /></svg>
          <span class="text-base font-bold leading-tight">Mes photos</span>
          <span class="text-xs opacity-90">Choisir dans la galerie</span>
        </label>
        <label class="h-[120px] rounded-2xl bg-white border border-surface-300 text-surface-900 flex flex-col items-start justify-end gap-1 p-3.5 cursor-pointer">
          <input type="file" class="sr-only" accept="image/*" capture="environment" @change="onFiles">
          <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h3l2-3h6l2 3h3a1 1 0 011 1v10a1 1 0 01-1 1H4a1 1 0 01-1-1V8a1 1 0 011-1z" /><circle cx="12" cy="13" r="3.5" /></svg>
          <span class="text-base font-bold leading-tight">Prendre une photo</span>
          <span class="text-xs text-surface-500">Ou scanner une photo papier</span>
        </label>
      </div>

      <div class="flex gap-2">
        <Link href="/media/google-photos" class="flex-1 h-11 rounded-xl bg-white border border-surface-300 text-sm font-semibold text-surface-900 flex items-center justify-center" @click="$emit('close')">Google Photos</Link>
        <Link href="/media/google-drive" class="flex-1 h-11 rounded-xl bg-white border border-surface-300 text-sm font-semibold text-surface-900 flex items-center justify-center" @click="$emit('close')">Google Drive</Link>
        <Link href="/media/upload" class="flex-1 h-11 rounded-xl bg-white border border-surface-300 text-sm font-semibold text-surface-900 flex items-center justify-center" @click="$emit('close')">Un document</Link>
      </div>
      <p class="text-xs text-surface-500 text-center">
        Les photos sont partagées avec {{ current ? `« ${current.name} »` : 'votre cercle' }} par défaut : vous pourrez changer ça avant l'envoi.
      </p>
    </div>
  </BottomSheet>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import BottomSheet from '@/Components/BottomSheet.vue';
import { useCircle } from '@/Composables/useCircle';
import { stashPendingFiles } from '@/utils/pendingUpload';

defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close']);
const { current } = useCircle();

// Les fichiers choisis sont confiés à la page d'envoi, qui les prend en
// charge à son ouverture (aperçu, album, cercle), sans second sélecteur.
const onFiles = (e) => {
  const files = Array.from(e.target.files || []);
  e.target.value = '';
  if (!files.length) return;
  stashPendingFiles(files);
  emit('close');
  router.visit('/media/upload');
};
</script>
