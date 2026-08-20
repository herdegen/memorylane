<template>
  <Head title="Mon profil" />
  <AppLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-xs sm:rounded-lg">
          <div class="p-6">
            <div class="flex items-center justify-between mb-6">
              <h1 class="text-display text-2xl text-surface-900">Mon profil</h1>
              <Link
                href="/profile/edit"
                class="btn-primary"
              >
                Modifier
              </Link>
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
                    {{ formatLongDate(user.created_at) }}
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
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatLongDate } from '@/utils/format';

const props = defineProps({
  user: Object,
});

</script>
