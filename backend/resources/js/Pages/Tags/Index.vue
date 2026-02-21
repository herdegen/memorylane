<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="page-header">
          <h1>Gestion des tags</h1>
          <p>Organisez vos médias avec des tags personnalisés</p>
        </div>

        <!-- Create new tag form -->
        <div class="card">
          <h2>Créer un nouveau tag</h2>

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
                label="Nom du tag"
                placeholder="Ex: Famille, Vacances, Paris..."
                :error="form.errors.name"
                required
              />
            </div>
            <div>
              <label class="form-label">Couleur</label>
              <input
                v-model="form.color"
                type="color"
                class="form-input-color"
              />
            </div>
            <FormButton
              type="submit"
              text="Créer"
              loading-text="Création..."
              :loading="form.processing"
            />
          </form>
        </div>

        <!-- Tags list -->
        <div class="card">
          <h2>Tous les tags ({{ tags.length }})</h2>

          <!-- Empty state -->
          <div v-if="tags.length === 0" class="text-center py-12">
            <div class="text-gray-400 text-5xl mb-4">🏷️</div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Aucun tag</h3>
            <p class="text-gray-600">Créez votre premier tag pour commencer à organiser vos médias</p>
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
                  class="w-4 h-4 rounded-full flex-shrink-0"
                  :style="{ backgroundColor: tag.color || '#6366f1' }"
                ></div>
                <div>
                  <h3 class="font-medium text-gray-900">{{ tag.name }}</h3>
                  <p class="text-sm text-gray-500">
                    {{ tag.media_count }} {{ tag.media_count > 1 ? 'médias' : 'média' }}
                  </p>
                </div>
              </Link>
              <button
                @click.stop="deleteTag(tag)"
                class="btn-icon-danger"
                title="Supprimer ce tag"
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
import { ref } from 'vue';
import { useForm, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/Forms/FormField.vue';
import FormError from '@/Components/Forms/FormError.vue';
import FormButton from '@/Components/Forms/FormButton.vue';
import TrashIcon from '@/Components/Icons/TrashIcon.vue';

const props = defineProps({
  tags: {
    type: Array,
    required: true,
  },
});

const form = useForm({
  name: '',
  color: '#6366f1',
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
        errorMessage.value = 'Une erreur est survenue lors de la création du tag.';
      }
    },
  });
};

const deleteTag = (tag) => {
  if (!confirm(`Supprimer le tag "${tag.name}" ? Il sera retiré de tous les médias.`)) {
    return;
  }

  router.delete(`/tags/${tag.id}`, {
    onError: (errors) => {
      errorMessage.value = errors.message || 'Erreur lors de la suppression du tag.';
    }
  });
};
</script>

<style lang="scss" scoped>
.page-header {
  @apply mb-6;

  h1 {
    @apply text-3xl font-bold text-gray-900;
  }

  p {
    @apply mt-2 text-gray-600;
  }
}

.card {
  @apply bg-white rounded-lg shadow-sm p-6 mb-6;

  h2 {
    @apply text-lg font-semibold text-gray-900 mb-4;
  }
}

.form-label {
  @apply block text-sm font-medium text-gray-700 mb-2;
}

.form-input {
  @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.form-input-color {
  @apply h-10 w-20 border border-gray-300 rounded-lg cursor-pointer;
}

.form-error {
  @apply text-red-500 text-xs mt-1;
}

.btn-primary {
  @apply px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition;
}

.tag-item {
  @apply flex items-center justify-between p-4 border border-gray-200 rounded-lg hover:border-indigo-300 transition;
}

.btn-icon-danger {
  @apply text-red-600 hover:text-red-800 transition;
}
</style>
