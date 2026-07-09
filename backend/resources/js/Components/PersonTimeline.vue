<template>
  <div class="bg-white rounded-xl border border-surface-200 shadow-xs p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-surface-900">Sa vie</h3>
      <div class="flex items-center gap-2">
        <button
          v-if="hasPlayable"
          @click="playSlideshow"
          class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-800"
        >
          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
          Diaporama
        </button>
        <button
          v-if="canManage"
          @click="openCreate"
          class="text-sm font-medium text-brand-600 hover:text-brand-800"
        >
          + Ajouter un moment
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-sm text-surface-500">Chargement de la frise…</div>

    <div v-else-if="items.length === 0" class="text-sm text-surface-500">
      Aucun événement daté pour l'instant.
      <span v-if="canManage">Ajoutez un moment pour commencer la frise.</span>
    </div>

    <!-- Frise verticale -->
    <ol v-else class="relative border-l-2 border-surface-200 ml-3 space-y-5">
      <li v-for="(item, i) in items" :key="i" class="ml-6">
        <!-- Puce -->
        <span
          class="absolute -left-[13px] flex items-center justify-center w-6 h-6 rounded-full bg-white border-2 border-surface-200 text-xs"
          :title="kindLabel(item.kind)"
        >{{ kindIcon(item.kind) }}</span>

        <div class="flex items-start gap-3 rounded-lg border border-surface-100 bg-surface-50 p-3">
          <!-- Vignette : photo du moment, sinon avatar du related -->
          <img
            v-if="thumb(item)"
            :src="thumb(item)"
            class="w-14 h-14 rounded-lg object-cover shrink-0 border border-surface-200"
          />
          <div class="min-w-0 flex-1">
            <p class="text-xs font-medium text-brand-600">{{ formatDate(item.date) }}<span v-if="item.end_date"> → {{ formatDate(item.end_date) }}</span></p>
            <p class="text-sm font-semibold text-surface-900">
              <Link v-if="item.related" :href="`/people/${item.related.id}`" class="hover:text-brand-700">{{ item.title }}</Link>
              <Link v-else-if="item.kind === 'photo' && item.media" :href="`/media/${item.media.id}`" class="hover:text-brand-700">{{ item.title }}</Link>
              <span v-else>{{ item.title }}</span>
            </p>
            <p v-if="item.place" class="text-xs text-surface-500">{{ item.place }}</p>
            <p v-if="item.description" class="text-sm text-surface-600 mt-1 whitespace-pre-wrap">{{ item.description }}</p>
          </div>

          <!-- Actions gestionnaire sur les moments -->
          <div v-if="canManage && item.life_event_id" class="flex flex-col gap-1 shrink-0">
            <button @click="openEdit(item)" class="text-surface-400 hover:text-brand-600" title="Modifier">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            </button>
            <button @click="removeEvent(item)" class="text-surface-400 hover:text-red-500" title="Supprimer">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M4 7h16" /></svg>
            </button>
          </div>
        </div>
      </li>
    </ol>

    <LifeEventFormModal
      v-if="showModal"
      :person-id="personId"
      :event="editing"
      :photo-options="photoItems"
      @close="showModal = false"
      @saved="onSaved"
    />

    <TimelineDiaporama ref="diaporama" :items="items" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import LifeEventFormModal from '@/Components/LifeEventFormModal.vue';
import TimelineDiaporama from '@/Components/TimelineDiaporama.vue';

const props = defineProps({
  personId: { type: String, required: true },
  canManage: { type: Boolean, default: false },
});

const items = ref([]);
const loading = ref(true);
const showModal = ref(false);
const editing = ref(null);
const diaporama = ref(null);

const photoItems = computed(() => items.value.filter(i => i.kind === 'photo' && i.media));
const hasPlayable = computed(() => items.value.length > 0);

const load = async () => {
  loading.value = true;
  try {
    const { data } = await axios.get(`/people/${props.personId}/timeline`);
    items.value = data || [];
  } catch (e) {
    console.error('Erreur chargement frise:', e);
  } finally {
    loading.value = false;
  }
};

const openCreate = () => { editing.value = null; showModal.value = true; };
const openEdit = (item) => { editing.value = item; showModal.value = true; };
const onSaved = () => { showModal.value = false; load(); };

const removeEvent = async (item) => {
  if (!confirm('Supprimer ce moment ?')) return;
  try {
    await axios.delete(`/life-events/${item.life_event_id}`);
    load();
  } catch (e) {
    alert(e.response?.data?.message || 'Erreur');
  }
};

const playSlideshow = () => diaporama.value?.open(0);

const thumb = (item) => item.media?.thumbnail_url || item.media?.medium_url || item.related?.avatar_url || null;

const ICONS = { birth: '🎂', death: '🕯️', marriage: '💍', child: '👶', job: '💼', education: '🎓', residence: '🏠', photo: '📷', moment: '★', custom: '★' };
const LABELS = { birth: 'Naissance', death: 'Décès', marriage: 'Mariage', child: 'Enfant', job: 'Emploi', education: 'Études', residence: 'Résidence', photo: 'Photo', moment: 'Moment', custom: 'Moment' };
const kindIcon = (k) => ICONS[k] || '★';
const kindLabel = (k) => LABELS[k] || 'Moment';

const formatDate = (d) => {
  if (!d) return '';
  const date = new Date(d);
  if (isNaN(date)) return d;
  return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
};

onMounted(load);
</script>
