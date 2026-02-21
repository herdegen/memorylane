<template>
  <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gradient-to-br from-brand-50 via-white to-surface-50">
    <div class="w-full sm:max-w-md mt-6 px-8 py-8 bg-white shadow-xl rounded-2xl border border-surface-200">
      <!-- Logo / Branding -->
      <div class="text-center mb-8">
        <div class="flex items-center justify-center mb-3">
          <div class="w-12 h-12 bg-brand-100 rounded-xl flex items-center justify-center">
            <svg class="w-7 h-7 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
              />
            </svg>
          </div>
        </div>
        <h1 class="font-display text-2xl font-bold text-surface-900 tracking-tight">MemoryLane</h1>
        <p class="mt-1 text-sm text-surface-500">Connectez-vous à votre galerie de souvenirs</p>
      </div>

      <!-- Success message -->
      <FormError
        v-if="status"
        type="success"
        :message="status"
      />

      <!-- Error message global -->
      <FormError
        v-if="hasErrors"
        type="error"
        :message="globalErrorMessage"
      />

      <form @submit.prevent="submit" class="space-y-6">
        <FormField
          v-model="form.email"
          id="email"
          type="email"
          label="Email"
          placeholder="votre@email.com"
          :error="form.errors.email"
          autocomplete="username"
          required
        />

        <FormField
          v-model="form.password"
          id="password"
          type="password"
          label="Mot de passe"
          placeholder="••••••••"
          :error="form.errors.password"
          autocomplete="current-password"
          required
        />

        <div class="flex items-center justify-between mt-6">
          <label class="flex items-center">
            <input
              v-model="form.remember"
              type="checkbox"
              class="rounded border-surface-300 text-brand-600 shadow-sm focus:ring-brand-500"
            />
            <span class="ml-2 text-sm text-surface-600">Se souvenir de moi</span>
          </label>
        </div>

        <FormButton
          type="submit"
          text="Se connecter"
          loading-text="Connexion en cours..."
          :loading="form.processing"
          full-width
        />
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from '@/Components/Forms/FormField.vue';
import FormError from '@/Components/Forms/FormError.vue';
import FormButton from '@/Components/Forms/FormButton.vue';

defineProps({
  status: String,
});

const form = useForm({
  email: '',
  password: '',
  remember: false,
});

const hasErrors = computed(() => {
  return Object.keys(form.errors).length > 0;
});

const globalErrorMessage = computed(() => {
  if (form.errors.email || form.errors.password) {
    return 'Les identifiants fournis sont incorrects. Veuillez vérifier votre email et mot de passe.';
  }
  return 'Une erreur est survenue lors de la connexion.';
});

const submit = () => {
  form.post('/login', {
    onFinish: () => form.reset('password'),
  });
};
</script>