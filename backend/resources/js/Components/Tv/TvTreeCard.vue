<template>
  <!-- Une personne de l'arbre : visage, prénom, dates, lien avec soi. -->
  <button
    type="button"
    data-tv
    class="tv-tree-card shrink-0 flex flex-col items-center text-center rounded-[1.75rem]"
    :class="big ? 'w-[27rem] gap-2 px-6 py-5 bg-[#2c261e]' : 'w-[13rem] gap-1.5 px-3 py-3'"
    @click="$emit('pick', person)"
  >
    <span class="relative">
      <img
        v-if="person.avatar_url"
        :src="person.avatar_url"
        alt=""
        class="rounded-full object-cover bg-[#3a2f1e]"
        :class="[big ? 'w-[9.5rem] h-[9.5rem]' : 'w-[6.5rem] h-[6.5rem]', person.deceased ? 'tv-tree-past' : '']"
      />
      <span
        v-else
        class="rounded-full bg-[#3a2f1e] text-[#fcd34d] font-bold flex items-center justify-center"
        :class="big ? 'w-[9.5rem] h-[9.5rem] text-[4rem]' : 'w-[6.5rem] h-[6.5rem] text-[2.75rem]'"
      >{{ (person.first_name || person.name || '?').slice(0, 1) }}</span>
    </span>
    <span class="font-bold leading-tight line-clamp-2 max-w-full" :class="big ? 'text-[2.25rem] font-display max-w-full truncate' : 'text-[1.375rem]'">{{ big ? person.name : person.first_name }}</span>
    <span v-if="badge" class="px-3.5 py-1 rounded-full bg-[#3a2f1e] text-[#fcd34d] font-bold" :class="big ? 'text-[1.375rem]' : 'text-[1.125rem]'">{{ badge }}</span>
    <span v-else-if="person.relation" class="text-[#fcd34d] font-semibold line-clamp-1" :class="big ? 'text-[1.625rem]' : 'text-[1.25rem]'">{{ person.relation }}</span>
    <span v-if="person.life" class="text-[#b9ad9c] line-clamp-1" :class="big ? 'text-[1.5rem]' : 'text-[1.125rem]'">{{ person.life }}</span>
    <span v-if="big && person.photos" class="text-[1.375rem] text-[#c9bfb1]">{{ person.photos }} photo{{ person.photos > 1 ? 's' : '' }}</span>
  </button>
</template>

<script setup>
defineProps({
  person: { type: Object, required: true },
  big: { type: Boolean, default: false },
  // Remplace le lien de parenté (conjoint : « Mariés en 1955 »).
  badge: { type: String, default: null },
});
defineEmits(['pick']);
</script>

<style scoped>
.tv-tree-card:focus { background: #3a2f1e; transform: scale(1.04); }
.tv-tree-past { filter: grayscale(.35); }
</style>
