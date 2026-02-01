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

            <div v-if="flash.success" class="mb-4 rounded-md bg-green-50 p-4">
              <p class="text-sm text-green-700">{{ flash.success }}</p>
            </div>

            <form @submit.prevent="updateProfile" class="space-y-6">
              <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Nom</label>
                <input
                  id="name"
                  v-model="profileForm.name"
                  type="text"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                  :class="{ 'border-red-500': profileForm.errors.name }"
                />
                <p v-if="profileForm.errors.name" class="mt-2 text-sm text-red-600">
                  {{ profileForm.errors.name }}
                </p>
              </div>

              <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input
                  id="email"
                  v-model="profileForm.email"
                  type="email"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                  :class="{ 'border-red-500': profileForm.errors.email }"
                />
                <p v-if="profileForm.errors.email" class="mt-2 text-sm text-red-600">
                  {{ profileForm.errors.email }}
                </p>
              </div>

              <div>
                <label for="pin_code" class="block text-sm font-medium text-gray-700">
                  Code PIN (optionnel)
                </label>
                <input
                  id="pin_code"
                  v-model="profileForm.pin_code"
                  type="text"
                  maxlength="6"
                  placeholder="Code PIN pour accès rapide"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                  :class="{ 'border-red-500': profileForm.errors.pin_code }"
                />
                <p v-if="profileForm.errors.pin_code" class="mt-2 text-sm text-red-600">
                  {{ profileForm.errors.pin_code }}
                </p>
              </div>

              <div class="flex items-center gap-4">
                <button
                  type="submit"
                  :disabled="profileForm.processing"
                  class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
                >
                  <span v-if="profileForm.processing">Enregistrement...</span>
                  <span v-else>Enregistrer</span>
                </button>
                <Link
                  href="/profile"
                  class="text-sm text-gray-600 hover:text-gray-900"
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
              <div>
                <label for="current_password" class="block text-sm font-medium text-gray-700">
                  Mot de passe actuel
                </label>
                <input
                  id="current_password"
                  v-model="passwordForm.current_password"
                  type="password"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                  :class="{ 'border-red-500': passwordForm.errors.current_password }"
                />
                <p v-if="passwordForm.errors.current_password" class="mt-2 text-sm text-red-600">
                  {{ passwordForm.errors.current_password }}
                </p>
              </div>

              <div>
                <label for="password" class="block text-sm font-medium text-gray-700">
                  Nouveau mot de passe
                </label>
                <input
                  id="password"
                  v-model="passwordForm.password"
                  type="password"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                  :class="{ 'border-red-500': passwordForm.errors.password }"
                />
                <p v-if="passwordForm.errors.password" class="mt-2 text-sm text-red-600">
                  {{ passwordForm.errors.password }}
                </p>
              </div>

              <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700">
                  Confirmer le mot de passe
                </label>
                <input
                  id="password_confirmation"
                  v-model="passwordForm.password_confirmation"
                  type="password"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                />
              </div>

              <div class="flex items-center gap-4">
                <button
                  type="submit"
                  :disabled="passwordForm.processing"
                  class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
                >
                  <span v-if="passwordForm.processing">Enregistrement...</span>
                  <span v-else>Modifier le mot de passe</span>
                </button>
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
