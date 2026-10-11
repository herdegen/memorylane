<template>
  <div class="video-player-wrapper rounded-xl overflow-hidden bg-black">
    <!-- src directement sur <video> : le navigateur sniffe le contenu, alors qu'un
         <source type="..."> serait rejeté sans essai si le type déclaré (ex.
         video/quicktime) n'est pas reconnu, même quand le codec interne est lisible.
         `controls` : lecteur natif tant que Plyr se charge (ou s'il échoue) ;
         Plyr retire l'attribut en prenant la main. -->
    <video
      ref="videoEl"
      controls
      playsinline
      :src="src"
      :poster="poster || undefined"
      class="w-full"
    >
      Votre navigateur ne supporte pas la lecture de vidéos.
    </video>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
  src: { type: String, required: true },
  poster: { type: String, default: null },
});

const videoEl = ref(null);
let player = null;
let unmounted = false;

// Plyr (JS + CSS, ~35 ko gzip) n'est chargé qu'au montage du lecteur, donc
// seulement quand une vidéo est affichée.
onMounted(async () => {
  let Plyr;
  try {
    [Plyr] = await Promise.all([
      import('plyr').then((m) => m.default),
      import('plyr/dist/plyr.css'),
    ]);
  } catch (error) {
    console.error('Lecteur vidéo enrichi indisponible :', error);
    return; // le lecteur natif reste utilisable
  }
  if (unmounted || !videoEl.value) return;
  player = new Plyr(videoEl.value, {
    controls: [
      'play-large',
      'play',
      'progress',
      'current-time',
      'duration',
      'mute',
      'volume',
      'fullscreen',
    ],
    hideControls: true,
  });
});

onBeforeUnmount(() => {
  unmounted = true;
  player?.destroy();
});

// Exposé pour l'éditeur de découpe : lire / positionner le temps courant.
defineExpose({
  getCurrentTime: () => player?.currentTime ?? videoEl.value?.currentTime ?? 0,
  seekTo: (seconds) => {
    if (player) player.currentTime = seconds;
    else if (videoEl.value) videoEl.value.currentTime = seconds;
  },
});
</script>

<style>
/* Harmonise Plyr avec la palette brand/surface */
:root {
  --plyr-color-main: #f59e0b; /* brand-400 */
  --plyr-video-control-color: #fafaf9; /* stone-50 */
  --plyr-video-background: #000;
  --plyr-font-family: inherit;
}

/* Les vidéos portrait ne doivent pas dépasser l'écran : les contrôles restent visibles */
.video-player-wrapper video {
  max-height: 70vh;
}
</style>
