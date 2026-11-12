<template>
  <FullscreenSlideshow ref="slideshow" :slides="slides" :photo-duration="4500" ken-burns big-captions :title="title" />
</template>

<script setup>
import { ref, nextTick } from 'vue';
import axios from 'axios';
import FullscreenSlideshow from '@/Components/FullscreenSlideshow.vue';
import { slideUrl } from '@/utils/media';
import { useToast } from '@/Composables/useToast';
import { t } from '@/i18n';

// Lecteur de récap : charge les diapositives (cartes + photos) et les joue
// en plein écran ; marque le récap comme vu.
const emit = defineEmits(['seen']);
const toast = useToast();
const slideshow = ref(null);
const slides = ref([]);
const title = ref('');
let loading = false;

const play = async (recap) => {
  if (loading) return;
  loading = true;
  try {
    const { data } = await axios.get(recap.url);
    const media = new Map((data.media || []).map((m) => [m.id, m]));
    slides.value = data.slides.map((s, i) => {
      if (s.type === 'card') return { key: `card-${i}`, type: 'card', card: s.card };
      const m = media.get(s.media_id);
      return m ? { key: m.id, type: 'photo', src: slideUrl(m), chapter: s.chapter, label: s.label || null } : null;
    }).filter(Boolean);
    title.value = data.title;
    await nextTick();
    slideshow.value?.open(0);
    if (recap.key && recap.seen === false) {
      axios.post('/recaps/seen', { key: recap.key }).catch(() => {});
      emit('seen', recap.key);
    }
  } catch {
    toast.error(t('Impossible d’ouvrir ce récap.'));
  } finally {
    loading = false;
  }
};

defineExpose({ play });
</script>
