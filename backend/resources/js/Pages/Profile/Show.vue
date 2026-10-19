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
                v-if="emailChanged"
                v-model="profileForm.current_password"
                id="current_password_email"
                type="password"
                label="Mot de passe actuel"
                placeholder="••••••••"
                :error="profileForm.errors.current_password"
                autocomplete="current-password"
                help="Nécessaire pour changer d'adresse e-mail."
                required
              />

              <ToggleSwitch
                v-model="profileForm.share_address_with_household"
                label="Partager mon adresse avec ma famille"
                help="Les membres de vos cercles familiaux verront votre adresse sur votre fiche (jamais vos cercles d'amis). Sans cela, seule une position approximative (~1 km) alimente la carte."
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

        <!-- Notifications push -->
        <PushSettingsCard :initial-subscriptions="pushSubscriptions" />

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

        <!-- Mes données : tous mes originaux, en archives ZIP envoyées par mail -->
        <div class="bg-white overflow-hidden shadow-xs sm:rounded-lg">
          <div class="p-6 space-y-3">
            <div>
              <h2 class="text-lg font-medium text-surface-900">Mes photos</h2>
              <p class="mt-1 text-sm text-surface-600">Récupérez tous les originaux que vous avez envoyés, rangés par année, avec un index (date, albums, personnes). Vous recevrez les liens par mail.</p>
            </div>
            <ExportMediaButton />
          </div>
        </div>

        <!-- Quitter l'application : page dédiée (récapitulatif, choix, 30 jours) -->
        <div class="bg-white overflow-hidden shadow-xs sm:rounded-lg">
          <div class="p-6 flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 class="text-lg font-medium text-surface-900">Quitter MemoryLane</h2>
              <p class="mt-1 text-sm text-surface-600">Supprimer votre compte, en choisissant ce que vous laissez à la famille.</p>
            </div>
            <Link href="/account/departure" class="px-4 py-2 rounded-lg border border-red-300 dark:border-red-500/40 text-sm font-semibold text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-500/10">
              Quitter MemoryLane…
            </Link>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import ExportMediaButton from '@/Components/ExportMediaButton.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/Forms/FormField.vue';
import FormButton from '@/Components/Forms/FormButton.vue';
import ToggleSwitch from '@/Components/Forms/ToggleSwitch.vue';
import PushSettingsCard from '@/Components/PushSettingsCard.vue';
import { formatLongDate } from '@/utils/format';

// Page unique du compte : consultation ET modification (fusion de l'ancien
// couple Mon profil / Paramètres — une seule entrée dans le menu avatar).
const props = defineProps({
  user: Object,
  pushSubscriptions: { type: Array, default: () => [] },
});

const profileForm = useForm({
  name: props.user.name,
  email: props.user.email,
  current_password: '',
  share_address_with_household: !!props.user.preferences?.share_address_with_household,
});
// Changer d'adresse e-mail demande le mot de passe actuel.
const emailChanged = computed(() => profileForm.email.trim().toLowerCase() !== (props.user.email || '').toLowerCase());

const passwordForm = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
});

const updateProfile = () => {
  profileForm.put('/profile', {
    onFinish: () => profileForm.reset('current_password'),
  });
};

const updatePassword = () => {
  passwordForm.put('/profile/password', {
    onSuccess: () => passwordForm.reset(),
  });
};
</script>
