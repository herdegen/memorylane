<template>
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <div class="flex items-center justify-between mb-6">
              <h1 class="text-2xl font-semibold text-surface-900">Mon Profil</h1>
              <Link
                href="/profile/edit"
                class="inline-flex items-center px-4 py-2 bg-brand-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-700 focus:bg-brand-700 active:bg-brand-900 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 transition ease-in-out duration-150"
              >
                Modifier
              </Link>
            </div>

            <div v-if="flash.success" class="mb-4 rounded-md bg-green-50 p-4">
              <p class="text-sm text-green-700">{{ flash.success }}</p>
            </div>

            <div class="border-t border-surface-200">
              <dl>
                <div class="bg-surface-50 px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                  <dt class="text-sm font-medium text-surface-500">Nom</dt>
                  <dd class="mt-1 text-sm text-surface-900 sm:mt-0 sm:col-span-2">
                    {{ user.name }}
                  </dd>
                </div>
                <div class="bg-white px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                  <dt class="text-sm font-medium text-surface-500">Email</dt>
                  <dd class="mt-1 text-sm text-surface-900 sm:mt-0 sm:col-span-2">
                    {{ user.email }}
                  </dd>
                </div>
                <div class="bg-surface-50 px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                  <dt class="text-sm font-medium text-surface-500">Code PIN</dt>
                  <dd class="mt-1 text-sm text-surface-900 sm:mt-0 sm:col-span-2">
                    {{ user.pin_code || 'Non défini' }}
                  </dd>
                </div>
                <div class="bg-white px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                  <dt class="text-sm font-medium text-surface-500">Membre depuis</dt>
                  <dd class="mt-1 text-sm text-surface-900 sm:mt-0 sm:col-span-2">
                    {{ formatDate(user.created_at) }}
                  </dd>
                </div>
              </dl>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useAuth } from '@/Composables/useAuth';

const props = defineProps({
  user: Object,
});

const { flash } = useAuth();

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('fr-FR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });
};
</script>
