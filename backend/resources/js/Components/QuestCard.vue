<template>
  <div v-if="question || completedCount > 0" class="space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-400">Complétez la mémoire familiale</h2>
      <span
        v-if="completedCount > 0"
        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-50 dark:bg-brand-500/10 text-brand-700 dark:text-brand-400 text-xs font-semibold"
      >
        ✦ {{ completedCount }} souvenir{{ completedCount > 1 ? 's' : '' }} complété{{ completedCount > 1 ? 's' : '' }}
      </span>
    </div>

    <Transition name="quest-fade" mode="out-in">
      <!-- Une question -->
      <div
        v-if="question"
        :key="question.key"
        class="flex flex-col sm:flex-row gap-5 bg-white border border-surface-200 rounded-2xl shadow-warm-md px-6 py-5"
      >
        <!-- Visuel : avatar / visage / photo -->
        <div class="shrink-0 flex sm:block justify-center">
          <div
            v-if="visualUrl"
            class="rounded-xl overflow-hidden bg-surface-100"
            :class="isMediaQuestion ? 'w-44 h-44' : 'w-24 h-24'"
          >
            <img :src="visualUrl" class="w-full h-full object-cover" />
          </div>
          <div
            v-else-if="question.person"
            class="w-24 h-24 rounded-xl bg-brand-100 flex items-center justify-center text-3xl font-bold text-brand-700"
          >
            {{ question.person.name.charAt(0).toUpperCase() }}
          </div>
        </div>

        <!-- Question + saisie -->
        <div class="flex-1 min-w-0 flex flex-col gap-3">
          <div>
            <div class="text-xs font-semibold uppercase tracking-widest text-brand-700">Le saviez-vous ?</div>
            <div class="font-display text-xl font-semibold text-surface-900 mt-1">{{ question.prompt }}</div>
            <Link
              v-if="question.person"
              :href="`/people/${question.person.id}`"
              class="text-xs text-surface-400 hover:text-brand-700 hover:underline"
            >
              Voir sa fiche<span v-if="question.person.birth_year"> — né·e en {{ question.person.birth_year }}</span>
            </Link>
          </div>

          <!-- Saisie par famille d'UI -->
          <div class="space-y-3">
            <!-- Date simple (naissance, décès ancien, photo sans date) -->
            <input
              v-if="uiKind === 'date'"
              v-model="form.value"
              type="date"
              class="form-input max-w-xs"
            />

            <!-- Texte simple (lieux, nom de jeune fille) -->
            <input
              v-else-if="uiKind === 'text'"
              v-model="form.value"
              type="text"
              class="form-input max-w-md"
              :placeholder="textPlaceholder"
              @keyup.enter="submitAnswer"
            />

            <!-- Encore en vie ? -->
            <div v-else-if="uiKind === 'death_status'" class="space-y-3">
              <div class="flex flex-wrap gap-2">
                <button type="button" class="btn-secondary" @click="answer('no')">Oui, toujours en vie</button>
                <button
                  type="button"
                  class="px-4 py-2 rounded-lg text-sm font-medium border transition"
                  :class="deceased ? 'bg-brand-600 text-white border-brand-600' : 'bg-white border-surface-300 text-surface-700 hover:bg-surface-50'"
                  @click="deceased = true"
                >
                  Non, décédé·e
                </button>
              </div>
              <div v-if="deceased" class="flex flex-wrap items-center gap-2">
                <input v-model="form.death_date" type="date" class="form-input max-w-xs" />
                <span class="text-xs text-surface-400">(date facultative si inconnue)</span>
              </div>
            </div>

            <!-- Genre -->
            <div v-else-if="uiKind === 'gender'" class="flex gap-2">
              <button type="button" class="btn-secondary" @click="answerValue('M')">Un homme</button>
              <button type="button" class="btn-secondary" @click="answerValue('F')">Une femme</button>
            </div>

            <!-- Parent manquant -->
            <div v-else-if="uiKind === 'parent'" class="max-w-md">
              <RelationshipPicker
                :label="question.type === 'parent_father' ? 'Père' : 'Mère'"
                :current-person="pickedPerson"
                :exclude-ids="[question.person?.id].filter(Boolean)"
                @select="pickedPerson = $event"
                @remove="pickedPerson = null"
              />
              <p class="text-xs text-surface-400 mt-1.5">
                Seules les personnes ayant déjà une fiche sont proposées.
              </p>
            </div>

            <!-- Statut marital -->
            <div v-else-if="uiKind === 'marital'" class="space-y-3">
              <div v-if="!maritalYes" class="flex flex-wrap gap-2">
                <button type="button" class="btn-secondary" @click="maritalYes = true">Oui</button>
                <button type="button" class="btn-secondary" @click="answer('no')">Non, jamais</button>
              </div>
              <div v-else class="max-w-md space-y-2.5">
                <RelationshipPicker
                  label="Avec qui ?"
                  :current-person="pickedPerson"
                  :exclude-ids="[question.person?.id].filter(Boolean)"
                  @select="pickedPerson = $event"
                  @remove="pickedPerson = null"
                />
                <div class="flex flex-wrap gap-2.5">
                  <select v-model="form.type" class="form-select w-auto">
                    <option value="spouse">Mariage</option>
                    <option value="partner">Union libre</option>
                  </select>
                  <input
                    v-model="form.year"
                    type="number"
                    class="form-input w-32"
                    :min="1500"
                    :max="currentYear"
                    placeholder="Année"
                  />
                </div>
              </div>
            </div>

            <!-- Métier / études / résidence : texte + année -->
            <div v-else-if="uiKind === 'text_year'" class="flex flex-wrap gap-2.5 max-w-lg">
              <input
                v-model="form.text"
                type="text"
                class="form-input flex-1 min-w-48"
                :placeholder="textPlaceholder"
              />
              <input
                v-model="form.year"
                type="number"
                class="form-input w-36"
                :min="1500"
                :max="currentYear"
                placeholder="Vers quelle année ?"
              />
            </div>

            <!-- Qui est-ce ? (visage) -->
            <div v-else-if="uiKind === 'face'" class="space-y-3">
              <div v-if="question.face.suggestions.length" class="flex flex-wrap gap-2">
                <button
                  v-for="s in question.face.suggestions"
                  :key="s.person.id"
                  type="button"
                  class="btn-secondary"
                  @click="answerPerson(s.person.id)"
                >
                  {{ s.person.name }}
                  <span class="text-xs text-surface-400 ml-1">{{ Math.round(s.score * 100) }}%</span>
                </button>
              </div>
              <div class="max-w-md">
                <RelationshipPicker
                  label="Ou choisir quelqu'un d'autre"
                  :current-person="null"
                  @select="answerPerson($event.id)"
                />
              </div>
            </div>

            <!-- Photo sans lieu : carte -->
            <div v-else-if="uiKind === 'media_geo'">
              <button type="button" class="btn-secondary" @click="showGeoPicker = true">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Choisir le lieu sur la carte
              </button>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex flex-wrap items-center gap-2 mt-auto pt-1">
            <button
              v-if="showSubmit"
              type="button"
              class="btn-primary"
              :disabled="!canSubmit || sending"
              @click="submitAnswer"
            >
              Valider
            </button>
            <button
              v-if="noLabel"
              type="button"
              class="btn-secondary"
              :disabled="sending"
              @click="answer('no')"
            >
              {{ noLabel }}
            </button>
            <span class="flex-1"></span>
            <button
              type="button"
              class="text-sm font-medium text-surface-500 hover:text-surface-700 px-2 py-1"
              :disabled="sending"
              @click="answer('dont_know')"
            >
              Je ne sais pas
            </button>
            <button
              type="button"
              class="text-sm font-medium text-surface-400 hover:text-surface-600 px-2 py-1"
              :disabled="sending"
              @click="answer('skipped')"
            >
              Passer →
            </button>
          </div>
        </div>
      </div>

      <!-- Lot épuisé mais des contributions au compteur : remerciement discret -->
      <div
        v-else-if="exhausted"
        class="flex items-center gap-4 bg-white border border-dashed border-surface-300 rounded-2xl px-6 py-4 text-sm text-surface-500"
      >
        <span class="text-xl">🎉</span>
        Toutes les questions du moment sont complétées — merci pour votre contribution !
      </div>
    </Transition>

    <!-- Sélecteur de lieu (photo sans géolocalisation) -->
    <GeolocatePickerModal
      v-if="showGeoPicker"
      title="Où cette photo a-t-elle été prise ?"
      description="Recherchez une adresse ou cliquez sur la carte."
      @close="showGeoPicker = false"
      @apply="applyGeo"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import RelationshipPicker from '@/Components/RelationshipPicker.vue';
import GeolocatePickerModal from '@/Components/GeolocatePickerModal.vue';
import { useToast } from '@/Composables/useToast';

const toast = useToast();

const question = ref(null);
const completedCount = ref(0);
const exhausted = ref(false);
const sending = ref(false);

// État de saisie local, remis à zéro à chaque question.
const form = ref({});
const pickedPerson = ref(null);
const deceased = ref(false);
const maritalYes = ref(false);
const showGeoPicker = ref(false);

const currentYear = new Date().getFullYear();

const resetForm = () => {
  form.value = { type: 'spouse' };
  pickedPerson.value = null;
  deceased.value = false;
  maritalYes.value = false;
  showGeoPicker.value = false;
};

watch(question, resetForm);

// ---- Familles d'UI par type de question ----
const UI_KINDS = {
  birth_date: 'date',
  death_date_old: 'date',
  media_date: 'date',
  birth_place: 'text',
  death_place: 'text',
  maiden_name: 'text',
  death_status: 'death_status',
  gender: 'gender',
  parent_father: 'parent',
  parent_mother: 'parent',
  marital_status: 'marital',
  job: 'text_year',
  education: 'text_year',
  residence: 'text_year',
  face_identify: 'face',
  media_geo: 'media_geo',
};

const uiKind = computed(() => UI_KINDS[question.value?.type]);

const isMediaQuestion = computed(() => !!question.value?.media);

const visualUrl = computed(() => {
  const q = question.value;
  if (!q) return null;
  return q.face?.crop_url || q.media?.image_url || q.person?.avatar_url || null;
});

const textPlaceholder = computed(() => ({
  birth_place: 'Ville, pays…',
  death_place: 'Ville, pays…',
  maiden_name: 'Nom de naissance',
  job: 'Métier (ex. institutrice, menuisier…)',
  education: 'École ou diplôme',
  residence: 'Ville',
}[question.value?.type] || ''));

// Libellé du bouton « non » selon le type (marital et death_status l'ont déjà
// dans leur propre UI).
const noLabel = computed(() => ({
  parent_father: 'Inconnu / pas de fiche',
  parent_mother: 'Inconnue / pas de fiche',
  maiden_name: 'Identique à son nom',
  education: 'Pas d’études particulières',
  face_identify: 'Ce n’est pas un visage',
}[question.value?.type] || null));

// ---- Payload et validité de la saisie courante ----
const currentPayload = computed(() => {
  const q = question.value;
  if (!q) return null;
  switch (uiKind.value) {
    case 'date':
    case 'text':
      return form.value.value ? { value: form.value.value } : null;
    case 'death_status':
      return deceased.value ? { death_date: form.value.death_date || null } : null;
    case 'parent':
      return pickedPerson.value ? { parent_id: pickedPerson.value.id } : null;
    case 'marital':
      return pickedPerson.value
        ? { spouse_id: pickedPerson.value.id, type: form.value.type, year: form.value.year || null }
        : null;
    case 'text_year':
      return form.value.text && form.value.year
        ? (q.type === 'residence'
          ? { place: form.value.text, year: form.value.year }
          : { title: form.value.text, year: form.value.year })
        : null;
    default:
      return null;
  }
});

const canSubmit = computed(() => currentPayload.value !== null);

// Le bouton « Valider » n'existe que pour les saisies à validation explicite
// (les boutons genre/visage/carte répondent directement).
const showSubmit = computed(() => !['gender', 'face', 'media_geo'].includes(uiKind.value));

// ---- Aller-retour serveur ----
const load = async () => {
  try {
    const { data } = await axios.get('/quests/next');
    question.value = data.question;
    completedCount.value = data.completed_count;
    exhausted.value = data.question === null;
  } catch {
    // Bloc silencieux : le Dashboard reste utilisable sans les quêtes.
  }
};

const answer = async (kind, payload = null) => {
  if (sending.value || !question.value) return;
  sending.value = true;

  try {
    const { data } = await axios.post('/quests/answer', {
      question_type: question.value.type,
      subject_id: question.value.subject_id,
      answer_kind: kind,
      payload,
    });
    completedCount.value = data.completed_count;
    if (kind === 'answered') {
      toast.success(`Merci ! ${data.completed_count} souvenir${data.completed_count > 1 ? 's' : ''} complété${data.completed_count > 1 ? 's' : ''}.`);
    }
    question.value = data.next;
    exhausted.value = data.next === null;
  } catch (error) {
    if (error.response?.status === 409) {
      toast.info('Déjà complété par quelqu’un d’autre — question suivante !');
      completedCount.value = error.response.data.completed_count;
      question.value = error.response.data.next;
      exhausted.value = error.response.data.next === null;
    } else {
      toast.error(error.response?.data?.message || 'Impossible d’enregistrer la réponse.');
    }
  } finally {
    sending.value = false;
  }
};

const submitAnswer = () => {
  if (canSubmit.value) answer('answered', currentPayload.value);
};

const answerValue = (value) => answer('answered', { value });
const answerPerson = (personId) => answer('answered', { person_id: personId });

const applyGeo = ({ latitude, longitude }) => {
  showGeoPicker.value = false;
  answer('answered', { latitude, longitude });
};

onMounted(load);
</script>

<style scoped>
.quest-fade-enter-active,
.quest-fade-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}
.quest-fade-enter-from {
  opacity: 0;
  transform: translateY(6px);
}
.quest-fade-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}
</style>
