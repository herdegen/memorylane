<template>
  <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100">
    <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
      <h2 class="text-2xl font-bold text-center mb-6 text-gray-900">Connexion</h2>

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
              class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
            />
            <span class="ml-2 text-sm text-gray-600">Se souvenir de moi</span>
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
  const count = Object.keys(form.errors).length;
  console.log('hasErrors computed:', count > 0, 'errors:', form.errors);
  return count > 0;
});

const globalErrorMessage = computed(() => {
  console.log('globalErrorMessage computed, errors:', form.errors);
  if (form.errors.email || form.errors.password) {
    return 'Les identifiants fournis sont incorrects. Veuillez vérifier votre email et mot de passe.';
  }
  return 'Une erreur est survenue lors de la connexion.';
});

const submit = () => {
  console.log('=== LOGIN SUBMIT ===');
  console.log('Email:', form.email);
  console.log('Password length:', form.password.length);

  form.post('/login', {
    onFinish: () => {
      console.log('=== LOGIN FINISHED ===');
      console.log('Errors:', form.errors);
      console.log('Has errors:', Object.keys(form.errors).length);
      form.reset('password');
    },
    onError: (errors) => {
      console.log('=== LOGIN ERROR CALLBACK ===');
      console.log('Errors:', errors);
    },
  });
};
</script>