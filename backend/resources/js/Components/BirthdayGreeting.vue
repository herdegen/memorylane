<template>
  <Teleport to="body">
    <div
      v-if="open && greeting"
      class="fixed inset-0 z-[90] flex items-center justify-center bg-black/55 backdrop-blur-sm px-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="birthday-title"
      @click.self="close"
    >
      <canvas ref="canvas" class="pointer-events-none fixed inset-0 w-full h-full" aria-hidden="true"></canvas>

      <div class="birthday-card relative w-full max-w-md rounded-2xl bg-white p-7 text-center shadow-warm-lg">
        <div class="text-6xl leading-none" aria-hidden="true">{{ greeting.self ? '🎉' : '🎂' }}</div>

        <!-- Son propre anniversaire -->
        <template v-if="greeting.self">
          <h2 id="birthday-title" class="mt-4 font-display text-3xl font-bold text-surface-900">
            Joyeux anniversaire {{ greeting.self.first_name }}&nbsp;!
          </h2>
          <p class="mt-2 text-surface-600">
            <template v-if="greeting.self.age">{{ greeting.self.age }} ans aujourd’hui. </template>
            Toute la famille pense à toi.
          </p>
          <p v-if="greeting.people.length" class="mt-5 text-sm font-medium text-surface-700">
            Tu partages ce jour avec :
          </p>
        </template>

        <h2 v-else id="birthday-title" class="mt-4 font-display text-3xl font-bold text-surface-900">
          {{ greeting.people.length === 1 ? `C’est l’anniversaire de ${greeting.people[0].first_name}\u00a0!` : 'Des anniversaires aujourd’hui\u00a0!' }}
        </h2>

        <ul v-if="greeting.people.length" class="mt-4 space-y-2 text-left">
          <li v-for="p in greeting.people" :key="p.id">
            <Link
              :href="`/people/${p.id}`"
              class="flex items-center gap-3 rounded-xl px-3 py-2 hover:bg-surface-50 transition"
              @click="close"
            >
              <img v-if="p.avatar_url" :src="p.avatar_url" alt="" class="h-11 w-11 rounded-full object-cover" />
              <span v-else class="flex h-11 w-11 items-center justify-center rounded-full bg-brand-100 font-semibold text-brand-700">
                {{ p.first_name.charAt(0) }}
              </span>
              <span class="min-w-0">
                <span class="block truncate font-medium text-surface-900">{{ p.name }}</span>
                <span v-if="p.age" class="block text-sm text-surface-500">{{ p.age }} ans aujourd’hui</span>
              </span>
            </Link>
          </li>
        </ul>

        <div class="mt-6 flex flex-wrap justify-center gap-3">
          <Link
            v-if="!greeting.self && greeting.people.length === 1"
            :href="`/people/${greeting.people[0].id}`"
            class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 transition"
            @click="close"
          >
            Voir sa fiche
          </Link>
          <button
            ref="closeButton"
            type="button"
            class="rounded-lg px-4 py-2 text-sm font-semibold transition"
            :class="!greeting.self && greeting.people.length === 1 ? 'text-surface-700 hover:bg-surface-100' : 'bg-brand-600 text-white hover:bg-brand-700'"
            @click="close"
          >
            {{ greeting.self ? 'Merci\u00a0!' : 'Fermer' }}
          </button>
        </div>

        <!-- Rappel chaque matin, si ce n'est pas déjà activé sur un appareil -->
        <Link
          v-if="suggestPush"
          href="/profile"
          class="mt-4 inline-block text-xs text-surface-500 underline underline-offset-2 hover:text-brand-700"
          @click="close"
        >
          🔔 Être prévenu des anniversaires chaque matin
        </Link>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';

const page = usePage();
// Lu une fois : la fête ne réapparaît pas au fil de la navigation.
const greeting = page.props.birthdayGreeting || null;
const open = ref(Boolean(greeting));
const canvas = ref(null);
const closeButton = ref(null);

const suggestPush = computed(() => {
  const push = page.props.push;
  return typeof window !== 'undefined' && 'PushManager' in window
    && !(push?.has_subscriptions && push?.preferences?.birthdays);
});

const close = () => {
  open.value = false;
};

const onKey = (e) => {
  if (e.key === 'Escape') close();
};

// Confettis maison (pas de dépendance) : quelques secondes, puis s'arrêtent.
let frame = null;
const COLORS = ['#f59e0b', '#fbbf24', '#ef4444', '#ec4899', '#8b5cf6', '#3b82f6', '#10b981'];
const launchConfetti = () => {
  const el = canvas.value;
  if (!el || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
  const ctx = el.getContext('2d');
  const dpr = window.devicePixelRatio || 1;
  el.width = window.innerWidth * dpr;
  el.height = window.innerHeight * dpr;
  ctx.scale(dpr, dpr);
  const pieces = Array.from({ length: 160 }, () => ({
    x: window.innerWidth / 2 + (Math.random() - 0.5) * 120,
    y: window.innerHeight * 0.35,
    vx: (Math.random() - 0.5) * 14,
    vy: -Math.random() * 13 - 4,
    size: 5 + Math.random() * 6,
    rot: Math.random() * Math.PI,
    spin: (Math.random() - 0.5) * 0.3,
    color: COLORS[Math.floor(Math.random() * COLORS.length)],
  }));
  const start = performance.now();
  const tick = (now) => {
    const t = now - start;
    ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
    for (const p of pieces) {
      p.vy += 0.35;
      p.vx *= 0.99;
      p.x += p.vx;
      p.y += p.vy;
      p.rot += p.spin;
      ctx.save();
      ctx.globalAlpha = Math.max(0, 1 - t / 4500);
      ctx.translate(p.x, p.y);
      ctx.rotate(p.rot);
      ctx.fillStyle = p.color;
      ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
      ctx.restore();
    }
    if (t < 4500) frame = requestAnimationFrame(tick);
    else ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
  };
  frame = requestAnimationFrame(tick);
};

onMounted(async () => {
  if (!greeting) return;
  // Vue : ne se rejoue plus aujourd'hui, sur aucun appareil.
  axios.post('/celebrations/birthday-seen').catch(() => {});
  window.addEventListener('keydown', onKey);
  await nextTick();
  closeButton.value?.focus();
  launchConfetti();
});

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey);
  if (frame) cancelAnimationFrame(frame);
});
</script>

<style scoped>
.birthday-card {
  animation: birthday-pop 0.45s cubic-bezier(0.2, 0.9, 0.3, 1.25) both;
}
@keyframes birthday-pop {
  from { transform: scale(0.85) translateY(12px); opacity: 0; }
  to { transform: none; opacity: 1; }
}
@media (prefers-reduced-motion: reduce) {
  .birthday-card { animation: none; }
}
</style>
