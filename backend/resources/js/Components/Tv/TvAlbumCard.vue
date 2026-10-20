<template>
  <!-- Carte d'album : au focus, la couverture fait défiler les photos de l'album. -->
  <Link
    :href="`/tv/ecran/albums/${album.id}`"
    data-tv
    class="tv-card shrink-0 flex flex-col gap-3 rounded-[1.25rem]"
    :style="{ width: width + 'rem' }"
    @focus="onFocus"
    @blur="onBlur"
  >
    <div class="relative rounded-[1.25rem] overflow-hidden bg-[#2c261e]" :style="{ height: width * 0.6 + 'rem' }">
      <img v-if="image" :key="image" :src="image" alt="" loading="lazy" class="tv-cover absolute inset-0 w-full h-full object-cover" />
    </div>
    <div class="px-1 text-[1.625rem] font-bold truncate">{{ album.name }}</div>
    <div class="px-1 -mt-2 text-[1.375rem] text-[#b9ad9c] truncate">{{ album.meta }}</div>
  </Link>
</template>

<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
  album: { type: Object, required: true },
  width: { type: Number, default: 21 },
});
const emit = defineEmits(['focus']);

const index = ref(-1);
const image = computed(() => (index.value >= 0 ? props.album.previews[index.value] : null) || props.album.cover || props.album.previews[0]);

let timer = null;
const onFocus = () => {
  emit('focus', props.album);
  if (props.album.previews.length < 2) return;
  clearInterval(timer);
  timer = setInterval(() => { index.value = (index.value + 1) % props.album.previews.length; }, 1600);
};
const onBlur = () => {
  clearInterval(timer);
  timer = null;
};
onBeforeUnmount(onBlur);
</script>

<style scoped>
.tv-cover { animation: tv-cover-in .5s ease; }
@keyframes tv-cover-in { from { opacity: .3; } to { opacity: 1; } }
</style>
