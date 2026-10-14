<template>
  <Head title="Bienvenue" />
  <div class="min-h-screen flex flex-col sm:justify-center items-center px-4 py-8 bg-linear-to-br from-brand-50 via-white to-surface-50">
    <div class="w-full sm:max-w-md bg-white shadow-xl rounded-2xl border border-surface-200 overflow-hidden">

      <!-- Invitation valable -->
      <template v-if="state === 'ok'">
        <div class="bg-linear-to-br from-brand-100 via-brand-50 to-white px-8 pt-8 pb-6 text-center">
          <div class="text-xs font-semibold uppercase tracking-widest text-brand-700">MemoryLane</div>
          <div class="mt-5 flex justify-center">
            <PersonAvatar
              :name="person.first_name"
              :src="person.portrait"
              :alt="person.name"
              eager
              class="w-28 h-28 bg-brand-100 border-4 border-white shadow-warm-md"
              fallback-class="font-display text-5xl text-brand-700"
            />
          </div>
          <h1 class="mt-4 font-display text-3xl font-bold text-surface-900">
            Bienvenue, {{ person.first_name }}&nbsp;!
          </h1>
          <p class="mt-2 text-sm text-surface-600">
            {{ formal ? 'Votre' : 'Ta' }} fiche
            <strong class="text-surface-800">{{ person.name }}{{ person.birth_year ? ` (${person.birth_year})` : '' }}</strong>
            {{ formal ? 'vous attend' : "t'attend" }} {{ friendsCircle ? `dans « ${friendsCircle} »` : "dans l'arbre de la famille" }}.
          </p>
        </div>

        <div class="px-8 py-6">
          <p v-if="loggedInAs" class="mb-4 rounded-lg bg-surface-50 px-3 py-2 text-xs text-surface-500">
            Vous êtes connecté en tant que {{ loggedInAs }} : créer cet accès vous déconnectera de ce compte.
          </p>

          <p class="text-sm text-surface-600 mb-5">
            Dernière étape : {{ formal ? 'choisissez votre' : 'choisis ton' }} mot de passe.
            {{ formal ? 'Votre' : 'Ton' }} identifiant sera
            <strong class="text-surface-800 break-all">{{ email }}</strong>.
          </p>

          <form @submit.prevent="submit" class="space-y-5">
            <FormField
              v-model="form.name"
              id="name"
              label="Prénom et nom"
              :error="form.errors.name"
              autocomplete="name"
              required
            />
            <FormField
              v-model="form.password"
              id="password"
              type="password"
              label="Mot de passe (8 caractères minimum)"
              placeholder="••••••••"
              :error="form.errors.password"
              autocomplete="new-password"
              required
            />
            <FormField
              v-model="form.password_confirmation"
              id="password_confirmation"
              type="password"
              label="Le même, pour vérifier"
              placeholder="••••••••"
              autocomplete="new-password"
              required
            />
            <FormError v-if="form.errors.email" type="error" :message="form.errors.email" />

            <FormButton
              type="submit"
              text="Créer mon accès"
              loading-text="Création…"
              :loading="form.processing"
              full-width
            />
          </form>

          <p class="mt-5 text-xs text-center text-surface-400">
            Mot de passe oublié plus tard ? Pas de souci : la page de connexion
            {{ formal ? 'vous enverra' : "t'enverra" }} un lien par e-mail.
          </p>
        </div>
      </template>

      <!-- Lien plus valable -->
      <div v-else class="px-8 py-10 text-center">
        <div class="mx-auto w-12 h-12 bg-brand-100 rounded-xl flex items-center justify-center">
          <svg class="w-7 h-7 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
          </svg>
        </div>
        <h1 class="mt-4 font-display text-2xl font-bold text-surface-900">{{ closed.title }}</h1>
        <p class="mt-2 text-sm text-surface-600">{{ closed.text }}</p>
        <Link href="/login" class="btn-primary mt-6 inline-flex">Aller à la connexion</Link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/Forms/FormField.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import FormError from '@/Components/Forms/FormError.vue';
import FormButton from '@/Components/Forms/FormButton.vue';

const props = defineProps({
  // ok | accepted | expired | revoked | invalid
  state: { type: String, required: true },
  token: String,
  formal: Boolean,
  email: String,
  inviter: String,
  person: Object,
  suggestedName: String,
  loggedInAs: String,
  friendsCircle: { type: String, default: null },
});

const form = useForm({
  name: props.suggestedName || '',
  password: '',
  password_confirmation: '',
});

const submit = () => {
  form.post(`/bienvenue/${props.token}`, {
    onFinish: () => form.reset('password', 'password_confirmation'),
  });
};

const closed = computed(() => ({
  accepted: {
    title: 'Cet accès a déjà été créé',
    text: 'Le lien a déjà servi. Connectez-vous avec votre adresse e-mail et votre mot de passe (ou demandez un lien de connexion par e-mail).',
  },
  expired: {
    title: 'Ce lien a expiré',
    text: 'Les liens d\'invitation sont valables 30 jours. Demandez à la personne qui vous a invité de vous en renvoyer un.',
  },
  revoked: {
    title: 'Ce lien n\'est plus valable',
    text: 'Cette invitation a été annulée ou remplacée par un lien plus récent : vérifiez le dernier mail reçu.',
  },
}[props.state] ?? {
  title: 'Lien introuvable',
  text: 'Ce lien d\'invitation n\'existe pas ou n\'est plus valable. Vérifiez qu\'il a été copié en entier.',
}));
</script>
