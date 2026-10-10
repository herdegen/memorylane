import { ref, computed, onBeforeUnmount } from 'vue';
import axios from 'axios';

/**
 * Enregistrement d'un mémo vocal (MediaRecorder) puis envoi sur une photo.
 * Opus/WebM quand le navigateur sait le faire (Chrome, Firefox, Android),
 * sinon AAC/MP4 (Safari, iPad). États : idle → recording → recorded → sending
 * → sent, ou error (micro refusé, envoi raté).
 */
export function useVoiceRecorder({ maxSeconds = 1200 } = {}) {
  const state = ref('idle');
  const elapsed = ref(0);
  const error = ref(null);
  const blob = ref(null);
  const playbackUrl = ref(null);

  let recorder = null;
  let stream = null;
  let chunks = [];
  let timer = null;

  const supported = typeof window !== 'undefined' && !!window.MediaRecorder && !!navigator.mediaDevices?.getUserMedia;

  const elapsedLabel = computed(() => {
    const m = Math.floor(elapsed.value / 60);
    const s = String(elapsed.value % 60).padStart(2, '0');
    return `${m}:${s}`;
  });

  function pickMimeType() {
    for (const type of ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus']) {
      if (MediaRecorder.isTypeSupported?.(type)) return type;
    }
    return '';
  }

  function releaseStream() {
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
    clearInterval(timer);
    timer = null;
  }

  async function start() {
    error.value = null;
    if (!supported) {
      state.value = 'error';
      error.value = "Cet appareil ne permet pas d'enregistrer la voix.";
      return;
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
      state.value = 'error';
      error.value = "Le micro n'est pas autorisé. Demandez à un proche de l'activer dans les réglages de la tablette.";
      return;
    }

    const mimeType = pickMimeType();
    recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
    chunks = [];
    recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
    recorder.onstop = () => {
      blob.value = new Blob(chunks, { type: recorder.mimeType || mimeType || 'audio/webm' });
      if (playbackUrl.value) URL.revokeObjectURL(playbackUrl.value);
      playbackUrl.value = URL.createObjectURL(blob.value);
      releaseStream();
      state.value = 'recorded';
    };

    elapsed.value = 0;
    recorder.start(1000);
    state.value = 'recording';
    timer = setInterval(() => {
      elapsed.value++;
      if (elapsed.value >= maxSeconds) stop();
    }, 1000);
  }

  function stop() {
    if (recorder && recorder.state !== 'inactive') recorder.stop();
  }

  /** Envoie l'enregistrement sur la photo ; renvoie le mémo créé. */
  async function send(mediaId) {
    if (!blob.value) return null;
    state.value = 'sending';
    const ext = blob.value.type.includes('mp4') ? 'm4a' : blob.value.type.includes('ogg') ? 'ogg' : 'webm';
    const form = new FormData();
    form.append('audio', blob.value, `souvenir.${ext}`);
    form.append('duration', String(Math.max(1, elapsed.value)));
    try {
      const { data } = await axios.post(`/media/${mediaId}/voice-notes`, form);
      state.value = 'sent';
      return data.voice_note;
    } catch {
      state.value = 'error';
      error.value = "L'envoi n'a pas marché. Vérifiez la connexion internet puis réessayez.";
      return null;
    }
  }

  function reset() {
    stop();
    releaseStream();
    if (playbackUrl.value) URL.revokeObjectURL(playbackUrl.value);
    playbackUrl.value = null;
    blob.value = null;
    elapsed.value = 0;
    error.value = null;
    state.value = 'idle';
  }

  onBeforeUnmount(() => {
    stop();
    releaseStream();
    if (playbackUrl.value) URL.revokeObjectURL(playbackUrl.value);
  });

  return { state, elapsed, elapsedLabel, error, blob, playbackUrl, supported, start, stop, send, reset };
}
