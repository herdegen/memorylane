<template>
  <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100">
    <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
      <h2 class="text-2xl font-bold text-center mb-6 text-gray-900">Connexion</h2>

      <div v-if="status" class="mb-4 font-medium text-sm text-green-600">
        {{ status }}
      </div>

      <form @submit.prevent="submit">
        <div>
          <label class="block font-medium text-sm text-gray-700" for="email">Email</label>
          <input
            id="email"
            v-model="form.email"
            type="email"
            class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm mt-1 block w-full px-3 py-2 border"
            required
            autofocus
            autocomplete="username"
          />
          <div v-if="form.errors.email" class="text-red-600 text-sm mt-2">{{ form.errors.email }}</div>
        </div>

        <div class="mt-4">
          <label class="block font-medium text-sm text-gray-700" for="password">Mot de passe</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm mt-1 block w-full px-3 py-2 border"
            required
            autocomplete="current-password"
          />
          <div v-if="form.errors.password" class="text-red-600 text-sm mt-2">{{ form.errors.password }}</div>
        </div>

        <div class="flex items-center justify-end mt-4">
          <button
            class="ml-4 inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
            :class="{ 'opacity-25': form.processing }"
            :disabled="form.processing"
          >
            Se connecter
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';

defineProps({
  status: String,
});

const form = useForm({
  email: '',
  password: '',
  remember: false,
});

const submit = () => {
  form.post('/login', {
    onFinish: () => form.reset('password'),
  });
};
</script>