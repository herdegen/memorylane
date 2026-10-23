<template>
  <Head :title="$t('Tags')" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="page-header">
          <h1>{{ $t('Gestion des tags') }}</h1>
          <p>{{ $t('Organisez vos médias avec des tags personnalisés') }}</p>
        </div>

        <!-- Create new tag form -->
        <div class="card card--padded mb-6">
          <h2 class="card-title">{{ $t('Créer un nouveau tag') }}</h2>

          <FormError
            v-if="errorMessage"
            type="error"
            :message="errorMessage"
            dismissible
            @dismiss="errorMessage = null"
          />

          <form @submit.prevent="createTag" class="flex gap-4 items-end">
            <div class="flex-1">
              <FormField
                v-model="form.name"
                id="tag-name"
                type="text"
                :label="$t('Nom du tag')"
                :placeholder="$t('Ex: Famille, Vacances, Paris...')"
                :error="form.errors.name"
                required
              />
            </div>
            <div>
              <label class="form-label">{{ $t('Couleur') }}</label>
              <input
                v-model="form.color"
                type="color"
                class="form-input-color"
              />
            </div>
            <FormButton
              type="submit"
              :text="$t('Créer')"
              :loading-text="$t('Création...')"
              :loading="form.processing"
            />
          </form>
        </div>

        <!-- Tags list -->
        <div class="card card--padded mb-6">
          <h2 class="card-title">{{ $t('Tous les tags (:count)', { count: tags.length }) }}</h2>

          <!-- Empty state -->
          <div v-if="tags.length === 0" class="text-center py-12">
            <svg class="mx-auto h-12 w-12 text-brand-300 mb-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
            </svg>
            <h3 class="text-lg font-medium text-surface-900 mb-2">{{ $t('Aucun tag') }}</h3>
            <p class="text-surface-600">{{ $t('Créez votre premier tag pour commencer à organiser vos médias') }}</p>
          </div>

          <!-- Tags grid -->
          <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div
              v-for="tag in tags"
              :key="tag.id"
              class="tag-item"
            >
              <Link
                :href="`/media?tags[]=${tag.id}`"
                class="flex items-center gap-3 flex-1 hover:opacity-75 transition"
              >
                <div
                  class="w-4 h-4 rounded-full shrink-0"
                  :style="{ backgroundColor: tag.color || '#0D9488' }"
                ></div>
                <div>
                  <h3 class="font-medium text-surface-900">{{ tag.name }}</h3>
                  <p class="text-sm text-surface-500">
                    {{ $tc(':count média|:count médias', tag.media_count) }}
                  </p>
                </div>
              </Link>
              <button
                v-if="isAdmin"
                @click.stop="deleteTag(tag)"
                class="btn-icon-danger"
                :title="$t('Supprimer ce tag')"
              >
                <TrashIcon class="w-5 h-5" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/Forms/FormField.vue';
import FormError from '@/Components/Forms/FormError.vue';
import FormButton from '@/Components/Forms/FormButton.vue';
import TrashIcon from '@/Components/Icons/TrashIcon.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { t } from '@/i18n';

const props = defineProps({
  tags: {
    type: Array,
    required: true,
  },
});

const { confirm } = useConfirm();

// Les tags sont globaux : seuls les admins peuvent les supprimer (aligné sur TagController)
const isAdmin = computed(() => usePage().props.auth?.user?.role === 'admin');

const form = useForm({
  name: '',
  color: '#0D9488',
});

const errorMessage = ref(null);

const createTag = () => {
  errorMessage.value = null;
  form.post('/tags', {
    onSuccess: () => form.reset(),
    onError: (errors) => {
      if (errors.message) {
        errorMessage.value = errors.message;
      } else {
        errorMessage.value = t('Une erreur est survenue lors de la création du tag.');
      }
    },
  });
};

const deleteTag = async (tag) => {
  const ok = await confirm({
    title: t('Supprimer le tag'),
    message: t('Supprimer le tag « :name » ? Il sera retiré de tous les médias.', { name: tag.name }),
    confirmLabel: t('Supprimer'),
    danger: true,
  });
  if (!ok) return;

  router.delete(`/tags/${tag.id}`, {
    onError: (errors) => {
      errorMessage.value = errors.message || t('Erreur lors de la suppression du tag.');
    }
  });
};
</script>

<style scoped>
/* Tailwind v4 : un <style> scoped ne voit pas le thème, il faut le référencer pour @apply */
@reference "../../../css/app.css";

.form-input-color {
  @apply h-10 w-20 border border-surface-300 rounded-lg cursor-pointer;
}

.btn-icon-danger {
  @apply text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200 transition;
}
</style>
