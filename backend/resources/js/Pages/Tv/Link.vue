<template>
  <Head :title="$t('Relier une télévision')" />
  <AppLayout>
    <div class="page-container max-w-xl mx-auto px-4 sm:px-6">
      <div class="page-header">
        <h1>{{ $t('Relier une télévision') }}</h1>
        <p>{{ $t('Regardez les albums en grand et jouez à « Qui est-ce ? » au salon, à la télécommande.') }}</p>
      </div>

      <form class="bg-white rounded-2xl border border-surface-200 p-5 space-y-4" @submit.prevent="submit">
        <div>
          <label for="tv-code" class="form-label">{{ $t('Code affiché sur la télévision') }}</label>
          <input
            id="tv-code"
            v-model="form.code"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="7"
            placeholder="482 917"
            class="form-input w-full h-14 text-2xl font-bold tracking-[.2em] text-center tabular-nums"
            :autofocus="!form.code"
          />
          <p v-if="form.errors.code" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ form.errors.code }}</p>
        </div>
        <div>
          <label for="tv-name" class="form-label">{{ $t('Nom de la télévision') }} <span class="font-normal text-surface-500">{{ $t('(facultatif)') }}</span></label>
          <input id="tv-name" v-model="form.name" type="text" maxlength="60" :placeholder="$t('Télévision du salon')" class="form-input w-full" />
        </div>
        <p class="text-sm text-surface-600">
          <Trans text="La télévision affichera le cercle :circle, en lecture seule : on y regarde, on ne peut rien y supprimer ni y envoyer.">
            <template #circle><strong class="text-surface-900">{{ $page.props.circle?.current?.name || $t('courant') }}</strong></template>
          </Trans> {{ $t('On change de cercle depuis la télévision.') }}
        </p>
        <button type="submit" class="btn-primary w-full h-12" :disabled="form.processing || digits.length !== 6">{{ $t('Relier') }}</button>
      </form>

      <section v-if="devices.length" class="mt-6">
        <h2 class="text-base font-semibold text-surface-900 mb-2">{{ $t('Vos télévisions') }}</h2>
        <ul class="bg-white rounded-2xl border border-surface-200 divide-y divide-surface-100">
          <li v-for="d in devices" :key="d.id" class="flex items-center gap-3 px-4 py-3">
            <svg class="w-6 h-6 text-surface-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="4" width="20" height="13" rx="2" /><path d="M8 21h8M12 17v4" /></svg>
            <span class="flex-1 min-w-0">
              <span class="block text-sm font-semibold text-surface-900 truncate">{{ d.name }}</span>
              <span class="block text-xs text-surface-500">
                {{ d.circle ? d.circle + ' · ' : '' }}{{ d.last_seen_at ? $t('vue le :date', { date: formatDate(d.last_seen_at) }) : $t('reliée le :date', { date: formatDate(d.linked_at) }) }}
              </span>
            </span>
            <button type="button" class="text-sm font-semibold text-red-600 dark:text-red-400 px-2 py-1" @click="unlink(d)">{{ $t('Délier') }}</button>
          </li>
        </ul>
      </section>

      <!-- Installer l'application sur la télévision (APK hors Play Store) -->
      <section class="mt-6 bg-white rounded-2xl border border-surface-200 p-5 space-y-3">
        <h2 class="text-base font-semibold text-surface-900">{{ $t('Installer l\'application sur la télévision') }}</h2>
        <p class="text-sm text-surface-600">{{ $t('Pour les téléviseurs et vidéoprojecteurs Android TV ou Google TV (Formovie, Sony, Philips, TCL…).') }}</p>
        <ol class="list-decimal pl-5 space-y-1.5 text-sm text-surface-700">
          <li>
            <Trans text="Sur la télévision, installez l'application gratuite :app depuis le Play Store.">
              <template #app><strong>Downloader</strong></template>
            </Trans>
          </li>
          <li>
            <Trans text="Dans Downloader, tapez l'adresse :url : l'application se télécharge.">
              <template #url><strong class="font-mono">{{ apkUrl }}</strong></template>
            </Trans>
          </li>
          <li>{{ $t('Acceptez l\'installation quand Android le demande (autoriser les « sources inconnues » pour Downloader).') }}</li>
          <li>
            <Trans text="Ouvrez :app : le code s'affiche, il reste à le saisir ci-dessus.">
              <template #app><strong>MemoryLane</strong></template>
            </Trans>
          </li>
        </ol>
        <p class="text-sm text-surface-600">
          {{ $t('Sans Play Store : téléchargez le fichier sur un ordinateur, copiez-le sur une clé USB, puis ouvrez-le depuis le gestionnaire de fichiers de la télévision.') }}
        </p>
        <div class="flex flex-wrap items-center gap-3 pt-1">
          <a href="/tv/application" class="btn-secondary">{{ $t('Télécharger l\'application') }}</a>
          <span v-if="app" class="text-xs text-surface-500">{{ $t('Version :version · :size Ko', { version: app.version, size: Math.max(1, Math.round(app.size / 1024)) }) }}</span>
        </div>
        <p class="text-xs text-surface-500">
          <Trans text="Sans application : le navigateur de la télévision, à l'adresse :url, fonctionne aussi.">
            <template #url><span class="font-mono">{{ screenUrl }}</span></template>
          </Trans>
        </p>
      </section>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate } from '@/utils/format';
import { useConfirm } from '@/Composables/useConfirm';
import Trans from '@/Components/Trans.vue';
import { t } from '@/i18n';

const props = defineProps({
  code: { type: String, default: '' },
  devices: { type: Array, default: () => [] },
  app: { type: Object, default: null },
});

const { confirm } = useConfirm();
const form = useForm({ code: props.code ? `${props.code.slice(0, 3)} ${props.code.slice(3)}` : '', name: '' });
const digits = computed(() => form.code.replace(/\D/g, ''));
const screenUrl = `${window.location.host}/tv/ecran`;
const apkUrl = `${window.location.host}/apk`;

const submit = () => {
  form.transform((data) => ({ ...data, code: digits.value })).post('/tv', {
    preserveScroll: true,
    onSuccess: () => {
      form.defaults({ code: '', name: '' });
      form.reset();
    },
  });
};

const unlink = async (d) => {
  const ok = await confirm({
    title: t('Délier « :name » ?', { name: d.name }),
    message: t('La télévision revient à l\'écran du code. Il faudra la relier à nouveau pour l\'utiliser.'),
    confirmLabel: t('Délier'),
    danger: true,
  });
  if (ok) router.delete(`/tv/${d.id}`, { preserveScroll: true });
};
</script>
