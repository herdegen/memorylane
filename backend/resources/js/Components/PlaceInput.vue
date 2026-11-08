<template>
  <!-- Champ « lieu » avec suggestions du monde entier (ville, région, pays) -->
  <div class="relative">
    <input
      :value="modelValue"
      type="text"
      maxlength="255"
      autocomplete="off"
      :class="inputClass"
      :placeholder="placeholder"
      :aria-label="placeholder"
      role="combobox"
      :aria-expanded="open && suggestions.length > 0"
      @input="onInput($event.target.value)"
      @keydown.down.prevent="move(1)"
      @keydown.up.prevent="move(-1)"
      @keydown.enter="onEnter"
      @keydown.escape="open = false"
      @blur="close"
    />
    <ul
      v-if="open && suggestions.length"
      class="absolute z-50 left-0 right-0 mt-1 max-h-72 overflow-y-auto rounded-xl border border-surface-200 bg-white shadow-warm-md dark:bg-surface-50"
      role="listbox"
    >
      <li
        v-for="(s, i) in suggestions"
        :key="s.label"
        role="option"
        :aria-selected="i === active"
        class="px-3 py-2 cursor-pointer text-sm"
        :class="i === active ? 'bg-brand-50 dark:bg-brand-500/10' : 'hover:bg-surface-50'"
        @mousedown.prevent="choose(s)"
      >
        <span class="block font-medium text-surface-900">{{ s.name }}</span>
        <span v-if="s.detail" class="block text-xs text-surface-500">{{ s.detail }}</span>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';

defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  inputClass: { type: [String, Array, Object], default: 'w-full h-11 px-3 text-[15px] border border-surface-300 rounded-lg bg-white focus:outline-hidden focus:ring-2 focus:ring-brand-500 focus:border-brand-500' },
});
// select : { label, lat, lng } quand on choisit une suggestion ; un texte
// tapé à la main garde le lieu sans coordonnées (géocodé ensuite).
const emit = defineEmits(['update:modelValue', 'select']);

const suggestions = ref([]);
const open = ref(false);
const active = ref(-1);
let timer = null;
let seq = 0;

function onInput(value) {
  emit('update:modelValue', value);
  emit('select', null);
  clearTimeout(timer);
  const q = value.trim();
  if (q.length < 3) {
    suggestions.value = [];
    open.value = false;
    return;
  }
  timer = setTimeout(async () => {
    const mine = ++seq;
    try {
      const { data } = await axios.get('/places/suggest', { params: { q } });
      if (mine !== seq) return;
      suggestions.value = data;
      active.value = -1;
      open.value = true;
    } catch {
      suggestions.value = [];
    }
  }, 350);
}

function move(step) {
  if (!suggestions.value.length) return;
  open.value = true;
  active.value = (active.value + step + suggestions.value.length) % suggestions.value.length;
}

function onEnter(e) {
  if (open.value && active.value >= 0) {
    e.preventDefault();
    choose(suggestions.value[active.value]);
  }
}

function choose(s) {
  emit('update:modelValue', s.label);
  emit('select', { label: s.label, lat: s.lat, lng: s.lng });
  open.value = false;
}

function close() {
  setTimeout(() => { open.value = false; }, 150);
}
</script>
