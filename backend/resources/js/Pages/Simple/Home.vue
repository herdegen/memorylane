<template>
  <Head :title="$t('Accueil')" />
  <ImpersonationBanner />
  <div class="min-h-screen bg-page text-surface-900 px-5 py-6 sm:px-10 sm:py-9 flex flex-col gap-6 sm:gap-8">

    <!-- Bonjour -->
    <header class="flex items-end justify-between gap-4 flex-wrap">
      <div class="flex flex-col gap-1">
        <div class="text-2xl text-surface-600">{{ todayLabel }}</div>
        <h1 class="font-display text-5xl sm:text-6xl font-bold leading-tight">{{ $t('Bonjour :name', { name: firstName }) }}</h1>
      </div>
      <div class="flex items-center gap-3">
        <PersonAvatar v-if="avatarUrl" :src="avatarUrl" eager class="w-16 h-16 bg-surface-200 shadow-warm-md" />
        <Link
          v-if="!isSimpleAccount"
          href="/dashboard"
          class="text-lg text-surface-600 underline underline-offset-4"
        >{{ $t('Quitter le mode simplifié') }}</Link>
      </div>
    </header>

    <!-- Souvenir du jour + prochain anniversaire -->
    <div class="grid gap-6 flex-1" :class="celebration ? 'lg:grid-cols-[1.55fr_1fr]' : ''">
      <Link
        v-if="memory"
        :href="`/simple/photos?start=${memory.id}`"
        class="relative block min-h-[320px] rounded-[28px] overflow-hidden bg-surface-300 shadow-warm-lg"
      >
        <img :src="memory.url" alt="" class="absolute inset-0 w-full h-full object-cover" />
        <div class="absolute inset-x-0 bottom-0 px-7 py-6 bg-[#1c1917]/65 text-[#fff] flex flex-col gap-1">
          <div class="text-[22px] font-semibold text-brand-200">{{ memory.heading }}</div>
          <div v-if="memory.title" class="font-display text-3xl sm:text-4xl font-bold">{{ memory.title }}</div>
          <div v-if="captionLine(memory)" class="text-[22px]">{{ captionLine(memory) }}</div>
        </div>
      </Link>
      <div v-else class="min-h-[240px] rounded-[28px] bg-white border-2 border-dashed border-surface-300 flex items-center justify-center text-center px-8 text-2xl text-surface-600">
        {{ $t('Les photos de la famille apparaîtront ici.') }}
      </div>

      <section
        v-if="celebration"
        class="rounded-[28px] bg-white p-7 flex flex-col gap-5 shadow-warm-md border-2 border-brand-300"
      >
        <div class="text-[22px] font-bold text-brand-800 uppercase tracking-wide">{{ celebrationWhen }}</div>
        <div class="flex items-center gap-5">
          <PersonAvatar
            :person="celebration"
            class="w-24 h-24 bg-surface-200"
            fallback-class="text-3xl font-bold text-surface-600"
          />
          <div class="flex flex-col gap-1">
            <div class="text-3xl font-bold leading-tight">{{ celebrationTitle }}</div>
            <div v-if="celebration.relation" class="text-[22px] text-surface-600">{{ celebration.relation }}</div>
          </div>
        </div>
        <Link
          :href="`/simple/photos?person=${celebration.person_id}`"
          class="mt-auto min-h-[88px] rounded-[22px] bg-brand-700 text-[#fff] text-[26px] font-bold flex items-center justify-center gap-3 px-6 text-center"
        >
          {{ $t('Voir ses photos') }}
        </Link>
      </section>
    </div>

    <!-- Les trois actions -->
    <nav :aria-label="$t('Que voulez-vous faire ?')" class="grid gap-5 sm:grid-cols-3">
      <Link href="/simple/photos" class="min-h-[132px] rounded-[28px] bg-white border-2 border-surface-200 shadow-warm-md flex items-center gap-5 px-7">
        <span class="w-20 h-20 rounded-3xl bg-brand-100 text-brand-800 flex items-center justify-center shrink-0">
          <svg class="w-11 h-11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><circle cx="9" cy="11" r="2" /><path d="M21 17l-5-5-9 7" /></svg>
        </span>
        <span class="text-[28px] font-bold leading-tight">{{ $t('Regarder les photos') }}</span>
      </Link>
      <Link href="/simple/famille" class="min-h-[132px] rounded-[28px] bg-white border-2 border-surface-200 shadow-warm-md flex items-center gap-5 px-7">
        <span class="w-20 h-20 rounded-3xl bg-brand-100 text-brand-800 flex items-center justify-center shrink-0">
          <svg class="w-11 h-11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.2" /><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6" /><circle cx="17" cy="9" r="2.6" /><path d="M16 14.2c2.8.3 5 2.7 5 5.8" /></svg>
        </span>
        <span class="text-[28px] font-bold leading-tight">{{ $t('Ma famille') }}</span>
      </Link>
      <Link
        :href="memory ? `/simple/photos/${memory.id}/raconter` : '/simple/photos'"
        class="min-h-[132px] rounded-[28px] bg-brand-800 text-[#fff] shadow-warm-md flex items-center gap-5 px-7"
      >
        <span class="w-20 h-20 rounded-3xl bg-[#fff]/15 flex items-center justify-center shrink-0">
          <svg class="w-11 h-11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5 11a7 7 0 0 0 14 0M12 18v3" /></svg>
        </span>
        <span class="text-[28px] font-bold leading-tight">{{ $t('Raconter un souvenir') }}</span>
      </Link>
    </nav>
  </div>
</template>

<script setup>
import ImpersonationBanner from '@/Components/ImpersonationBanner.vue';
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import { useAuth } from '@/Composables/useAuth';
import { t, intlLocale } from '@/i18n';

const props = defineProps({
  firstName: { type: String, required: true },
  avatarUrl: { type: String, default: null },
  today: { type: String, required: true },
  memory: { type: Object, default: null },
  celebration: { type: Object, default: null },
});

const { user } = useAuth();
const isSimpleAccount = computed(() => !!user.value?.simple_mode);

const todayLabel = computed(() => {
  const label = new Date(`${props.today}T12:00:00`).toLocaleDateString(intlLocale(), { weekday: 'long', day: 'numeric', month: 'long' });
  return label.charAt(0).toUpperCase() + label.slice(1);
});

const celebrationWhen = computed(() => {
  const d = props.celebration?.days_until;
  if (d === 0) return t("Aujourd'hui");
  if (d === 1) return t('Demain');
  return t('Dans :days jours', { days: d });
});

const celebrationTitle = computed(() => {
  const c = props.celebration;
  return c.days_until === 0
    ? t(':name a :age ans', { name: c.name, age: c.age })
    : t(':name aura :age ans', { name: c.name, age: c.age });
});

/** « juillet 1978 · avec vous et Paul » */
function captionLine(photo) {
  const parts = [];
  if (photo.when) parts.push(photo.when);
  if (photo.who?.length) parts.push(t('avec :names', { names: joinNames(photo.who) }));
  return parts.join(' · ');
}

function joinNames(names) {
  return names.length < 2
    ? names.join('')
    : t(':first et :last', { first: names.slice(0, -1).join(', '), last: names.at(-1) });
}
</script>
