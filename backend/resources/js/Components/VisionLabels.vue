<template>
  <div v-if="labels && labels.length > 0" class="bg-white rounded-lg shadow-sm p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Labels IA</h2>
    <div class="flex flex-wrap gap-2">
      <span
        v-for="label in labels"
        :key="label.name"
        class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-purple-50 text-purple-700 border border-purple-200"
        :title="`Confiance : ${Math.round(label.score * 100)}%`"
      >
        <svg class="w-3 h-3 mr-1 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
        </svg>
        {{ label.name }}
        <span class="ml-1 text-purple-400">{{ Math.round(label.score * 100) }}%</span>
      </span>
    </div>
  </div>
</template>

<script setup>
defineProps({
  labels: {
    type: Array,
    default: () => [],
  },
});
</script>
