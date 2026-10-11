<template>
  <Head title="Cercles" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="mb-6">
          <h1 class="text-display text-4xl text-surface-900">Cercles</h1>
          <p class="mt-2 text-surface-600">
            Un cercle réunit des comptes qui partagent une mémoire commune : la famille (arbre, fiches ouvertes à toute la famille)
            ou un groupe d'amis (réseau tissé par les photos, fiches visibles du seul cercle).
          </p>
        </div>

        <!-- Créer un cercle (administrateur) -->
        <form
          v-if="canManage"
          class="mb-8 bg-white rounded-2xl border border-surface-200 shadow-warm-sm p-5 flex flex-col gap-4"
          @submit.prevent="createHousehold"
        >
          <div class="text-sm font-semibold text-surface-900">Nouveau cercle</div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <button
              v-for="k in ['family', 'friends']"
              :key="k"
              type="button"
              class="flex items-center gap-3 p-3 rounded-xl border-2 text-left transition-colors"
              :class="newKind === k
                ? (k === 'friends' ? 'border-teal-500 bg-teal-50 dark:bg-teal-500/10' : 'border-amber-500 bg-amber-50 dark:bg-amber-500/10')
                : 'border-surface-200 hover:border-surface-300'"
              :aria-pressed="newKind === k"
              @click="newKind = k"
            >
              <span
                class="w-10 h-10 shrink-0 rounded-lg flex items-center justify-center"
                :class="k === 'friends' ? 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'"
              >
                <CircleKindIcon :kind="k" class="w-5 h-5" />
              </span>
              <span>
                <span class="block font-semibold text-surface-900">{{ KIND_LABELS[k] }}</span>
                <span class="block text-xs text-surface-500">{{ k === 'friends' ? 'Réseau, fiches réservées au cercle' : 'Arbre, fiches ouvertes à la famille' }}</span>
              </span>
            </button>
          </div>
          <div class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[220px]">
              <label for="circle-name" class="block text-sm font-medium text-surface-700 mb-1">Nom du cercle</label>
              <input
                id="circle-name"
                v-model="newName"
                type="text"
                :placeholder="newKind === 'friends' ? 'Ex. Les copains de Lyon' : 'Ex. Famille Herdegen'"
                maxlength="120"
                class="form-input"
              />
            </div>
            <label class="flex items-center gap-2 text-sm text-surface-700 h-10">
              <input v-model="join" type="checkbox" class="rounded border-surface-300 text-brand-600 focus:ring-brand-500" />
              J'en fais partie
            </label>
            <button type="submit" class="btn-primary" :disabled="!newName.trim() || creating">
              Créer le cercle
            </button>
          </div>
        </form>

        <!-- Liste -->
        <div v-if="households.length > 0" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <Link
            v-for="h in households"
            :key="h.id"
            :href="`/households/${h.id}`"
            class="block bg-white rounded-2xl border border-surface-200 p-5 hover:shadow-warm-md transition"
          >
            <div class="flex items-start justify-between gap-2">
              <h2 class="text-lg font-semibold text-surface-900">{{ h.name }}</h2>
              <span
                class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold"
                :class="h.kind === 'friends' ? 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'"
              >
                <CircleKindIcon :kind="h.kind" class="w-3.5 h-3.5" />
                {{ KIND_LABELS[h.kind] }}
              </span>
            </div>
            <p class="mt-2 text-sm text-surface-500">
              {{ h.members_count }} membre{{ h.members_count > 1 ? 's' : '' }}
              · {{ h.invite_policy === 'admin' ? "invitations par l'admin" : 'tout le monde invite' }}
              <span v-if="canManage && !h.is_member"> · vous n'en faites pas partie</span>
            </p>
          </Link>
        </div>

        <div v-else class="text-center py-16 bg-white rounded-2xl border border-surface-200">
          <h3 class="text-lg font-medium text-surface-900">Aucun cercle</h3>
          <p class="mt-2 text-surface-500">
            {{ canManage ? 'Créez un cercle pour partager vos souvenirs avec vos proches.' : "Un administrateur peut vous ajouter à un cercle." }}
          </p>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import CircleKindIcon from '@/Components/CircleKindIcon.vue';
import { KIND_LABELS } from '@/Composables/useCircle';
import { useToast } from '@/Composables/useToast';

const toast = useToast();

const props = defineProps({
  households: { type: Array, default: () => [] },
  canManage: { type: Boolean, default: false },
  defaultKind: { type: String, default: 'family' },
});

const newName = ref('');
const newKind = ref(props.defaultKind);
const join = ref(true);
const creating = ref(false);

const createHousehold = async () => {
  const name = newName.value.trim();
  if (!name || creating.value) return;
  creating.value = true;
  try {
    const { data } = await axios.post('/households', { name, kind: newKind.value, join: join.value });
    router.visit(`/households/${data.id}`);
  } catch (error) {
    toast.error(error.response?.data?.message || 'Erreur lors de la création du cercle.');
    creating.value = false;
  }
};
</script>
