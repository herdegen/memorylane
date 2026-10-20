<template>
  <!-- Écran d'une télévision pas encore reliée : un code et un QR à valider
       depuis le téléphone. La TV interroge le serveur toutes les 3 s et
       s'ouvre d'elle-même une fois le code validé. -->
  <Head title="Relier la télévision" />
  <TvLayout bare fill :hints="[]">
    <div class="flex-1 grid grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] gap-24 items-center px-24 py-[3.375rem]">
      <div class="flex flex-col gap-10">
        <div class="font-display text-[3rem] font-bold">MemoryLane</div>
        <h1 class="font-display text-[4.5rem] leading-[1.08] font-bold">Relier cette télévision à votre compte</h1>
        <ol class="flex flex-col gap-7 text-[2rem] leading-[1.35] text-[#e7e0d6]">
          <li class="flex gap-5.5 items-start">
            <span class="tv-step">1</span>
            <span>Sur votre téléphone, scannez le code, ou ouvrez <strong class="text-[#f7f2ea]">{{ linkLabel }}</strong></span>
          </li>
          <li class="flex gap-5.5 items-start">
            <span class="tv-step">2</span>
            <span>Connecté à votre compte, entrez le code ci-contre</span>
          </li>
          <li class="flex gap-5.5 items-start">
            <span class="tv-step">3</span>
            <span>La télévision s'ouvre toute seule. Rien à taper à la télécommande.</span>
          </li>
        </ol>
      </div>

      <div class="rounded-[2.25rem] bg-[#211d17] p-16 flex flex-col items-center gap-10">
        <div class="w-[21.25rem] h-[21.25rem] rounded-3xl bg-snow p-7 flex items-center justify-center">
          <img v-if="qr" :src="qr" alt="QR code vers la page de liaison, avec le code" class="w-full h-full" />
        </div>
        <div class="flex flex-col items-center gap-3">
          <span class="text-[1.625rem] text-[#b9ad9c]">{{ linked ? 'C\'est relié !' : 'Votre code' }}</span>
          <span class="text-[7rem] font-extrabold tracking-[.12em] text-[#fcd34d] tabular-nums">{{ linked ? '✓' : display }}</span>
          <span class="text-[1.5rem] text-[#a89c8b]">{{ status }}</span>
        </div>
      </div>
    </div>
  </TvLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import TvLayout from '@/Layouts/TvLayout.vue';

const props = defineProps({
  linkLabel: String,
  minutes: Number,
});

const code = ref('');
const qr = ref('');
const remaining = ref(0);
const linked = ref(false);
const failed = ref(false);

const display = computed(() => (code.value ? `${code.value.slice(0, 3)} ${code.value.slice(3)}` : '··· ···'));
const status = computed(() => {
  if (linked.value) return 'Ouverture…';
  if (failed.value) return 'Pas de connexion. Nouvel essai dans un instant…';
  const m = Math.ceil(remaining.value / 60);
  return `Valable ${m} minute${m > 1 ? 's' : ''} · un nouveau code s'affiche ensuite`;
});

let poll = null;
let tick = null;

const newCode = async () => {
  try {
    const { data } = await axios.post('/tv/ecran/code');
    code.value = data.code;
    qr.value = data.qr;
    remaining.value = data.expires_in;
    failed.value = false;
  } catch (e) {
    failed.value = true;
    code.value = '';
    // Session trop vieille (jeton CSRF périmé) : on repart d'une page neuve.
    if (e.response?.status === 419) window.location.reload();
  }
};

const check = async () => {
  if (!code.value) return newCode();
  try {
    const { data } = await axios.get('/tv/ecran/code');
    if (data.status === 'linked') {
      linked.value = true;
      clearInterval(poll);
      window.location.href = data.redirect;
    } else if (data.status === 'expired') {
      await newCode();
    }
  } catch (e) {
    failed.value = true;
  }
};

onMounted(async () => {
  await newCode();
  poll = setInterval(check, 3000);
  tick = setInterval(() => { remaining.value = Math.max(0, remaining.value - 1); }, 1000);
});
onBeforeUnmount(() => {
  clearInterval(poll);
  clearInterval(tick);
});
</script>

<style scoped>
.tv-step { width: 3.5rem; height: 3.5rem; flex: none; border-radius: 999px; background: #3a2f1e; color: #fcd34d; font-weight: 800; display: flex; align-items: center; justify-content: center; }
</style>
