<template>
  <!-- Proposition sur une fiche commune : correction ou désaccord, discussion, décision -->
  <article class="bg-white rounded-2xl border border-surface-200 shadow-warm-sm p-4 sm:p-5">
    <div class="flex items-start gap-3">
      <Link :href="`/people/${proposal.person.id}`" class="shrink-0">
        <PersonAvatar :name="proposal.person.name" :src="proposal.person.avatar" :size="10" />
      </Link>
      <div class="min-w-0 flex-1">
        <Link :href="`/people/${proposal.person.id}`" class="font-semibold text-surface-900 hover:underline">{{ proposal.person.name }}</Link>
        <p class="text-sm text-surface-600">
          <template v-if="proposal.origin === 'dispute'">
            {{ $t(':name n’est pas d’accord avec une modification de :author.', { name: proposal.disputed_by || $t('Quelqu’un'), author: authorLabel }) }}
          </template>
          <template v-else>
            {{ $t(':author propose une correction.', { author: authorLabel }) }}
          </template>
          <span class="text-surface-400"> · {{ formatDate(proposal.created_at) }}</span>
        </p>
      </div>
      <span v-if="proposal.status !== 'pending'" class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="STATUS_CLASS[proposal.status]">
        {{ statusLabel }}
      </span>
    </div>

    <ul class="mt-3 space-y-1.5 text-sm">
      <li v-for="(c, i) in proposal.changes" :key="i" class="flex flex-wrap items-baseline gap-x-2">
        <span class="font-medium text-surface-700">{{ c.label }} :</span>
        <span class="text-surface-500 line-through decoration-surface-400">{{ c.old ?? '∅' }}</span>
        <span aria-hidden="true" class="text-surface-400">→</span>
        <span class="font-semibold text-surface-900">{{ c.new ?? $t('(effacé)') }}</span>
        <span v-if="c.current" class="w-full text-xs text-amber-700 dark:text-amber-300">{{ $t('Depuis, la fiche indique : :value', { value: c.current }) }}</span>
      </li>
      <li v-if="proposal.remove_spouse" class="font-medium text-surface-800">
        {{ $t('Retirer l’union avec :name', { name: proposal.remove_spouse }) }}
      </li>
    </ul>
    <p v-if="proposal.origin === 'dispute' && proposal.status === 'pending'" class="mt-2 text-xs text-surface-500">
      {{ $t('En attendant, la fiche a retrouvé son ancienne valeur.') }}
    </p>
    <p v-if="proposal.source" class="mt-2 text-sm text-surface-600">
      <span class="font-medium">{{ $t('Source') }} :</span> {{ proposal.source }}
    </p>

    <!-- Discussion -->
    <ul v-if="proposal.messages.length" class="mt-3 space-y-2">
      <li v-for="(m, i) in proposal.messages" :key="i" class="rounded-xl px-3 py-2 text-sm" :class="m.mine ? 'bg-brand-50 dark:bg-brand-500/10' : 'bg-surface-50'">
        <span class="font-semibold text-surface-800">{{ m.by || $t('Quelqu’un') }}</span>
        <span class="text-xs text-surface-400"> · {{ formatDate(m.at) }}</span>
        <p class="text-surface-700 whitespace-pre-line">{{ m.body }}</p>
      </li>
    </ul>

    <template v-if="proposal.status === 'pending'">
      <p class="mt-3 text-xs text-surface-500">
        {{ $t('Décision : :families', { families: proposal.deciders.join(', ') || $t('la famille référente') }) }}
      </p>
      <div class="mt-2 flex flex-col sm:flex-row gap-2">
        <textarea
          v-model="reply"
          rows="1"
          maxlength="2000"
          class="flex-1 min-h-[44px] px-3 py-2 text-sm border border-surface-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
          :placeholder="$t('Répondre, citer une source…')"
          :aria-label="$t('Répondre')"
        />
        <button type="button" class="btn-secondary h-11 shrink-0" :disabled="busy || !reply.trim()" @click="send">{{ $t('Envoyer') }}</button>
      </div>
      <div v-if="proposal.can_decide || proposal.can_cancel" class="mt-3 flex flex-wrap gap-2">
        <template v-if="proposal.can_decide">
          <button type="button" class="btn-primary h-11" :disabled="busy" @click="decide('accept')">
            {{ proposal.origin === 'dispute' ? $t('Rétablir la modification') : $t('Accepter') }}
          </button>
          <button type="button" class="btn-secondary h-11" :disabled="busy" @click="decide('reject')">
            {{ proposal.origin === 'dispute' ? $t('Garder l’ancienne valeur') : $t('Refuser') }}
          </button>
        </template>
        <button v-if="proposal.can_cancel" type="button" class="btn-secondary h-11" :disabled="busy" @click="cancel">{{ $t('Retirer ma proposition') }}</button>
      </div>
    </template>
    <p v-else-if="proposal.decided_by" class="mt-3 text-xs text-surface-500">
      {{ $t('Par :name, le :date', { name: proposal.decided_by, date: formatDate(proposal.decided_at) }) }}
    </p>
  </article>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import { useToast } from '@/Composables/useToast';
import { apiError } from '@/utils/apiError';
import { formatDate } from '@/utils/format';
import { t } from '@/i18n';

const props = defineProps({
  proposal: { type: Object, required: true },
});
const emit = defineEmits(['changed']);

const toast = useToast();
const busy = ref(false);
const reply = ref('');

const STATUS_CLASS = {
  accepted: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
  rejected: 'bg-surface-100 text-surface-600',
  cancelled: 'bg-surface-100 text-surface-500',
};
const statusLabel = computed(() => ({
  accepted: props.proposal.origin === 'dispute' ? t('Modification rétablie') : t('Acceptée'),
  rejected: props.proposal.origin === 'dispute' ? t('Ancienne valeur gardée') : t('Refusée'),
  cancelled: t('Retirée'),
}[props.proposal.status] || ''));

const authorLabel = computed(() => {
  const name = props.proposal.by || t('Quelqu’un');
  return props.proposal.family ? t(':name (:family)', { name, family: props.proposal.family }) : name;
});

const run = async (request) => {
  busy.value = true;
  try {
    const { data } = await request();
    toast.success(data.message);
    emit('changed');
  } catch (error) {
    toast.error(apiError(error, t('Erreur lors de l\'enregistrement.')));
  } finally {
    busy.value = false;
  }
};

const decide = (action) => run(() => axios.post(`/shared-people/proposals/${props.proposal.id}/${action}`, reply.value.trim() ? { message: reply.value.trim() } : {}));
const cancel = () => run(() => axios.delete(`/shared-people/proposals/${props.proposal.id}`));
const send = () => run(async () => {
  const response = await axios.post(`/shared-people/proposals/${props.proposal.id}/messages`, { message: reply.value.trim() });
  reply.value = '';
  return response;
});
</script>
