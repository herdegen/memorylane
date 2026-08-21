<template>
  <Head title="Mon profil" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <!-- En-tête -->
        <div class="bg-white overflow-hidden shadow-xs sm:rounded-lg">
          <div class="p-6">
            <h1 class="text-display text-2xl text-surface-900">Mon profil</h1>
            <p class="mt-1 text-sm text-surface-500">
              Membre depuis le {{ formatLongDate(user.created_at) }}
            </p>
          </div>
        </div>

        <!-- Informations du profil -->
        <div class="bg-white overflow-hidden shadow-xs sm:rounded-lg">
          <div class="p-6">
            <h2 class="text-lg font-medium text-surface-900 mb-4">
              Informations du profil
            </h2>
            <p class="mt-1 text-sm text-surface-600 mb-6">
              Mettez à jour les informations de votre compte.
            </p>

            <form @submit.prevent="updateProfile" class="space-y-6">
              <FormField
                v-model="profileForm.name"
                id="name"
                type="text"
                label="Nom"
                placeholder="Votre nom"
                :error="profileForm.errors.name"
                required
              />

              <FormField
                v-model="profileForm.email"
                id="email"
                type="email"
                label="Email"
                placeholder="votre@email.com"
                :error="profileForm.errors.email"
                autocomplete="email"
                required
              />

              <FormField
                v-model="profileForm.pin_code"
                id="pin_code"
                type="text"
                label="Code PIN (optionnel)"
                placeholder="Code PIN pour accès rapide"
                :error="profileForm.errors.pin_code"
                help="Maximum 6 caractères"
              />

              <div class="flex items-center gap-4">
                <FormButton
                  type="submit"
                  text="Enregistrer"
                  loading-text="Enregistrement..."
                  :loading="profileForm.processing"
                />
              </div>
            </form>
          </div>
        </div>

        <!-- Mot de passe -->
        <div class="bg-white overflow-hidden shadow-xs sm:rounded-lg">
          <div class="p-6">
            <h2 class="text-lg font-medium text-surface-900 mb-4">
              Modifier le mot de passe
            </h2>
            <p class="mt-1 text-sm text-surface-600 mb-6">
              Assurez-vous d'utiliser un mot de passe long et sécurisé.
            </p>

            <form @submit.prevent="updatePassword" class="space-y-6">
              <FormField
                v-model="passwordForm.current_password"
                id="current_password"
                type="password"
                label="Mot de passe actuel"
                placeholder="••••••••"
                :error="passwordForm.errors.current_password"
                autocomplete="current-password"
                required
              />

              <FormField
                v-model="passwordForm.password"
                id="password"
                type="password"
                label="Nouveau mot de passe"
                placeholder="••••••••"
                :error="passwordForm.errors.password"
                autocomplete="new-password"
                help="Minimum 8 caractères recommandés"
                required
              />

              <FormField
                v-model="passwordForm.password_confirmation"
                id="password_confirmation"
                type="password"
                label="Confirmer le mot de passe"
                placeholder="••••••••"
                autocomplete="new-password"
                required
              />

              <div class="flex items-center gap-4">
                <FormButton
                  type="submit"
                  text="Modifier le mot de passe"
                  loading-text="Enregistrement..."
                  :loading="passwordForm.processing"
                />
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/Forms/FormField.vue';
import FormButton from '@/Components/Forms/FormButton.vue';
import { formatLongDate } from '@/utils/format';

// Page unique du compte : consultation ET modification (fusion de l'ancien
// couple Mon profil / Paramètres — une seule entrée dans le menu avatar).
const props = defineProps({
  user: Object,
});

const profileForm = useForm({
  name: props.user.name,
  email: props.user.email,
  pin_code: props.user.pin_code || '',
});

const passwordForm = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
});

const updateProfile = () => {
  profileForm.put('/profile');
};

const updatePassword = () => {
  passwordForm.put('/profile/password', {
    onSuccess: () => passwordForm.reset(),
  });
};
</script>
