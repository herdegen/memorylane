<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <!-- Profile Information -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">
              Informations du profil
            </h2>
            <p class="mt-1 text-sm text-gray-600 mb-6">
              Mettez à jour les informations de votre compte.
            </p>

            <FormError
              v-if="flash.success"
              type="success"
              :message="flash.success"
              dismissible
              @dismiss="flash.success = null"
            />

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
                <Link
                  href="/profile"
                  class="text-sm text-gray-600 hover:text-gray-900 transition-colors"
                >
                  Annuler
                </Link>
              </div>
            </form>
          </div>
        </div>

        <!-- Password Update -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">
              Modifier le mot de passe
            </h2>
            <p class="mt-1 text-sm text-gray-600 mb-6">
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
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/Forms/FormField.vue';
import FormError from '@/Components/Forms/FormError.vue';
import FormButton from '@/Components/Forms/FormButton.vue';
import { useAuth } from '@/Composables/useAuth';

const props = defineProps({
  user: Object,
});

const { flash } = useAuth();

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
