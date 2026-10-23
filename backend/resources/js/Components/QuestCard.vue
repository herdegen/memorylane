<template>
  <div v-if="question || completedCount > 0" class="space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-sm font-semibold uppercase tracking-wider text-surface-400">{{ isFriends ? $t('Complétez vos souvenirs communs') : $t('Complétez la mémoire familiale') }}</h2>
      <span
        v-if="completedCount > 0"
        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-50 dark:bg-brand-500/10 text-brand-700 dark:text-brand-400 text-xs font-semibold"
      >
        ✦ {{ $tc(':count souvenir complété|:count souvenirs complétés', completedCount) }}
      </span>
    </div>

    <Transition name="quest-fade" mode="out-in">
      <!-- Une question -->
      <div
        v-if="question"
        :key="question.key"
        class="flex flex-col sm:flex-row gap-4 sm:gap-5 bg-white border border-surface-200 rounded-2xl shadow-warm-md px-4 sm:px-6 py-4 sm:py-5"
      >
        <!-- Visuel : avatar / visage / photo (cliquable si une photo entière existe) -->
        <div class="shrink-0 flex sm:block justify-center">
          <component
            :is="fullPhotoUrl ? 'button' : 'div'"
            v-if="visualUrl"
            :type="fullPhotoUrl ? 'button' : undefined"
            class="relative block rounded-xl overflow-hidden bg-surface-100 group"
            :class="[isMediaQuestion ? 'w-44 h-44' : 'w-24 h-24', fullPhotoUrl ? 'cursor-zoom-in' : '']"
            :aria-label="fullPhotoUrl ? $t('Voir la photo en grand') : undefined"
            @click="fullPhotoUrl && (showLightbox = true)"
          >
            <img :src="visualUrl" class="w-full h-full object-cover" />
            <span
              v-if="fullPhotoUrl"
              class="absolute bottom-1.5 right-1.5 w-7 h-7 rounded-full bg-black/50 text-white flex items-center justify-center opacity-80 group-hover:opacity-100 transition"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 8v6m-3-3h6m5 0a8 8 0 11-16 0 8 8 0 0116 0z" />
              </svg>
            </span>
          </component>
          <PersonAvatar
            v-else-if="question.person"
            :name="question.person.name"
            shape="rounded"
            class="w-24 h-24"
            fallback-class="bg-brand-100 text-brand-700 text-3xl font-bold"
          />
        </div>

        <!-- Question + saisie -->
        <div class="flex-1 min-w-0 flex flex-col gap-3">
          <div>
            <div class="text-xs font-semibold uppercase tracking-widest text-brand-700">{{ $t('Le saviez-vous ?') }}</div>
            <div class="font-display text-xl font-semibold text-surface-900 mt-1">{{ question.prompt }}</div>
            <Link
              v-if="question.person"
              :href="`/people/${question.person.id}`"
              class="text-xs text-surface-400 hover:text-brand-700 hover:underline"
            >
              {{ question.person.birth_year ? $t('Voir sa fiche — né·e en :year', { year: question.person.birth_year }) : $t('Voir sa fiche') }}
            </Link>
            <!-- D'où vient la photo : album(s), date, qui l'a envoyée -->
            <p v-if="originText" class="text-xs text-surface-500 mt-0.5">
              📁 <template v-for="(a, i) in origin.albums" :key="a.id"><Link :href="`/albums/${a.id}`" class="hover:text-brand-700 hover:underline">{{ a.name }}</Link><span v-if="i < origin.albums.length - 1">, </span></template>
              <span v-if="!origin.albums.length">{{ $t('Hors album') }}</span>
              <span v-if="originSuffix"> · {{ originSuffix }}</span>
            </p>
            <button
              v-if="fullPhotoUrl"
              type="button"
              class="text-xs text-surface-400 hover:text-brand-700 hover:underline"
              @click="showLightbox = true"
            >
              {{ $t('Voir la photo entière pour mieux distinguer les détails') }}
            </button>
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
                <button type="button" class="btn-secondary" @click="answer('no')">{{ $t('Oui, toujours en vie') }}</button>
                <button
                  type="button"
                  class="px-4 py-2 rounded-lg text-sm font-medium border transition"
                  :class="deceased ? 'bg-brand-600 text-white border-brand-600' : 'bg-white border-surface-300 text-surface-700 hover:bg-surface-50'"
                  @click="deceased = true"
                >
                  {{ $t('Non, décédé·e') }}
                </button>
              </div>
              <div v-if="deceased" class="flex flex-wrap items-center gap-2">
                <input v-model="form.death_date" type="date" class="form-input max-w-xs" />
                <span class="text-xs text-surface-400">{{ $t('(date facultative si inconnue)') }}</span>
              </div>
            </div>

            <!-- Genre -->
            <div v-else-if="uiKind === 'gender'" class="flex gap-2">
              <button type="button" class="btn-secondary" @click="answerValue('M')">{{ $t('Un homme') }}</button>
              <button type="button" class="btn-secondary" @click="answerValue('F')">{{ $t('Une femme') }}</button>
            </div>

            <!-- Parent manquant -->
            <div v-else-if="uiKind === 'parent'" class="max-w-md">
              <RelationshipPicker
                :label="question.type === 'parent_father' ? $t('Père') : $t('Mère')"
                :current-person="pickedPerson"
                :exclude-ids="[question.person?.id].filter(Boolean)"
                allow-create
                :create-defaults="{ gender: question.type === 'parent_father' ? 'M' : 'F' }"
                @select="pickedPerson = $event"
                @remove="pickedPerson = null"
              />
              <p class="text-xs text-surface-400 mt-1.5">
                {{ $t('Choisissez une personne existante, ou créez sa fiche directement depuis la recherche.') }}
              </p>
            </div>

            <!-- Statut marital -->
            <div v-else-if="uiKind === 'marital'" class="space-y-3">
              <div v-if="!maritalYes" class="flex flex-wrap gap-2">
                <button type="button" class="btn-secondary" @click="maritalYes = true">{{ $t('Oui') }}</button>
                <button type="button" class="btn-secondary" @click="answer('no')">{{ $t('Non, jamais') }}</button>
              </div>
              <div v-else class="max-w-md space-y-2.5">
                <RelationshipPicker
                  :label="$t('Avec qui ?')"
                  :current-person="pickedPerson"
                  :exclude-ids="[question.person?.id].filter(Boolean)"
                  allow-create
                  @select="pickedPerson = $event"
                  @remove="pickedPerson = null"
                />
                <div class="flex flex-wrap gap-2.5">
                  <select v-model="form.type" class="form-select w-auto">
                    <option value="spouse">{{ $t('Mariage') }}</option>
                    <option value="partner">{{ $t('Union libre') }}</option>
                  </select>
                  <input
                    v-model="form.year"
                    type="number"
                    class="form-input w-32"
                    :min="1500"
                    :max="currentYear"
                    :placeholder="$t('Année')"
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
                :placeholder="$t('Vers quelle année ?')"
              />
            </div>

            <!-- Comment as-tu connu X ? (mode amis) -->
            <div v-else-if="uiKind === 'how_met'" class="space-y-3 max-w-xl">
              <p v-if="question.how_met.photos" class="text-sm text-surface-500">
                {{ question.how_met.since
                  ? $tc(':count photo ensemble depuis :since.|:count photos ensemble depuis :since.', question.how_met.photos, { since: question.how_met.since })
                  : $tc(':count photo ensemble.|:count photos ensemble.', question.how_met.photos) }}
              </p>
              <div class="flex flex-wrap gap-2">
                <button
                  v-if="question.how_met.via"
                  type="button"
                  class="h-10 px-4 rounded-full text-sm font-semibold border-2 transition-colors"
                  :class="form.context === 'friend' && form.via_person_id === question.how_met.via.id
                    ? 'border-brand-500 bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200'
                    : 'border-surface-200 bg-white text-surface-700 hover:border-surface-300'"
                  @click="pickContext('friend', question.how_met.via.id)"
                >
                  {{ $t('Par :name', { name: question.how_met.via.name }) }}
                </button>
                <button
                  v-for="c in question.how_met.contexts"
                  :key="c.key"
                  type="button"
                  class="h-10 px-4 rounded-full text-sm font-semibold border transition-colors"
                  :class="form.context === c.key && !(c.key === 'friend' && form.via_person_id)
                    ? 'border-brand-500 bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200'
                    : 'border-surface-200 bg-white text-surface-700 hover:border-surface-300'"
                  @click="pickContext(c.key)"
                >
                  {{ c.label }}
                </button>
              </div>
              <div class="flex flex-wrap gap-2.5">
                <input
                  v-model="form.story"
                  type="text"
                  maxlength="500"
                  class="form-input flex-1 min-w-56"
                  :placeholder="$t('Raconte en une phrase (facultatif)')"
                  :aria-label="$t('Raconte en une phrase (facultatif)')"
                />
                <input
                  v-model="form.year"
                  type="number"
                  class="form-input w-32"
                  :min="1900"
                  :max="currentYear"
                  :placeholder="$t('Année ?')"
                  :aria-label="$t('Année de la rencontre (facultatif)')"
                />
              </div>
            </div>

            <!-- Qui est-ce ? (visage) -->
            <div v-else-if="uiKind === 'face'" class="space-y-3">
              <p v-if="question.face.with?.length" class="text-sm text-surface-500">
                {{ $t('Sur cette photo avec :names.', { names: joinNames(question.face.with) }) }}
              </p>
              <!-- Téléphone : le jeu plein écran enchaîne les visages -->
              <Link href="/quests/faces" class="sm:hidden flex items-center justify-center gap-2 h-11 rounded-xl bg-brand-600 text-white text-sm font-bold">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
                {{ $t('Jouer à « Qui est-ce ? »') }}
              </Link>
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
                  :label="$t('Ou choisir quelqu\'un d\'autre')"
                  :current-person="null"
                  allow-create
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
                {{ $t('Choisir le lieu sur la carte') }}
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
              {{ $t('Valider') }}
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
            <!-- Visage : personne de fond (inconnue / hors du groupe : reste
                 visible sur la photo, n'est plus proposée) ou fausse
                 détection (masquée). -->
            <template v-if="uiKind === 'face'">
              <button
                type="button"
                class="btn-secondary"
                :disabled="sending"
                @click="answer('no', { reason: 'stranger' })"
              >
                {{ isFriends ? $t('Personne inconnue / pas du groupe') : $t('Personne inconnue / hors famille') }}
              </button>
              <button
                type="button"
                class="btn-secondary"
                :disabled="sending"
                @click="answer('no', { reason: 'not_face' })"
              >
                {{ $t('Ce n’est pas un visage') }}
              </button>
            </template>
            <span class="flex-1"></span>
            <button
              type="button"
              class="text-sm font-medium text-surface-500 hover:text-surface-700 px-2 py-1"
              :disabled="sending"
              @click="answer('dont_know')"
            >
              {{ $t('Je ne sais pas') }}
            </button>
            <button
              type="button"
              class="text-sm font-medium text-surface-400 hover:text-surface-600 px-2 py-1"
              :disabled="sending"
              @click="answer('skipped')"
            >
              {{ $t('Passer →') }}
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
        {{ $t('Toutes les questions du moment sont complétées — merci pour votre contribution !') }}
      </div>
    </Transition>

    <!-- Visionneuse plein écran : la photo entière, avec le visage encadré
         pour « Qui est-ce ? » (bounding_box en % de l'image). -->
    <Teleport to="body">
      <div
        v-if="showLightbox && fullPhotoUrl"
        class="fixed inset-0 z-[1100] bg-black/90 flex items-center justify-center p-4 cursor-zoom-out"
        @click="showLightbox = false"
      >
        <button
          type="button"
          class="absolute top-4 right-4 w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition"
          :aria-label="$t('Fermer')"
          @click.stop="showLightbox = false"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <div class="relative inline-block max-w-full" @click.stop>
          <img
            :src="fullPhotoUrl"
            class="max-w-full max-h-[85vh] rounded-lg shadow-2xl select-none"
            alt=""
          />
          <div
            v-if="faceBoxStyle"
            class="absolute border-2 border-brand-400 rounded-md shadow-[0_0_0_9999px_rgba(0,0,0,0.35)] pointer-events-none"
            :style="faceBoxStyle"
          ></div>
          <!-- Légende : titre éventuel + d'où vient la photo -->
          <div
            v-if="question?.media?.title || originText"
            class="absolute -bottom-9 left-0 right-0 text-center text-sm text-white/85 truncate"
          >
            <span v-if="question?.media?.title">{{ question.media.title }} — </span>{{ originText }}
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Sélecteur de lieu (photo sans géolocalisation) -->
    <GeolocatePickerModal
      v-if="showGeoPicker"
      :title="$t('Où cette photo a-t-elle été prise ?')"
      :description="$t('Recherchez une adresse ou cliquez sur la carte.')"
      @close="showGeoPicker = false"
      @apply="applyGeo"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, defineAsyncComponent } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import RelationshipPicker from '@/Components/RelationshipPicker.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import { useToast } from '@/Composables/useToast';
import { useCircle } from '@/Composables/useCircle';
import { usePeopleDirectory } from '@/Composables/usePeopleDirectory';
import { t, tc, intlLocale } from '@/i18n';

// Chargé à l'ouverture seulement : la carte embarque Leaflet (~45 ko gzip).
const GeolocatePickerModal = defineAsyncComponent(() => import('@/Components/GeolocatePickerModal.vue'));

const toast = useToast();
const { isFriends } = useCircle();
const peopleDirectory = usePeopleDirectory();

const joinNames = (names) => (names.length > 1 ? t(':names et :last', { names: names.slice(0, -1).join(', '), last: names[names.length - 1] }) : names[0]);

const pickContext = (context, viaId = null) => {
  form.value = { ...form.value, context, via_person_id: viaId };
};

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
const showLightbox = ref(false);

const currentYear = new Date().getFullYear();

const resetForm = () => {
  form.value = { type: 'spouse' };
  pickedPerson.value = null;
  deceased.value = false;
  maritalYes.value = false;
  showGeoPicker.value = false;
  showLightbox.value = false;
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
  address: 'text',
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
  how_met: 'how_met',
};

const uiKind = computed(() => UI_KINDS[question.value?.type]);

const isMediaQuestion = computed(() => !!question.value?.media);

// Origine de la photo (questions visage, date, lieu).
const origin = computed(() => question.value?.face?.origin || question.value?.media?.origin || null);
const originSuffix = computed(() => {
  const o = origin.value;
  if (!o) return '';
  return [
    o.date ? new Date(o.date).toLocaleDateString(intlLocale(), { day: 'numeric', month: 'long', year: 'numeric' }) : null,
    o.owner ? t('envoyée par :name', { name: o.owner }) : null,
  ].filter(Boolean).join(' · ');
});
const originText = computed(() => {
  const o = origin.value;
  if (!o) return '';
  const albums = o.albums.length ? `📁 ${o.albums.map((a) => a.name).join(', ')}` : `📁 ${t('Hors album')}`;
  return [albums, originSuffix.value].filter(Boolean).join(' · ');
});

const visualUrl = computed(() => {
  const q = question.value;
  if (!q) return null;
  return q.face?.crop_url || q.media?.image_url || q.person?.avatar_url || null;
});

// Photo entière pour la visionneuse : questions photo et « Qui est-ce ? ».
const fullPhotoUrl = computed(() => {
  const q = question.value;
  return q?.face?.image_url || q?.media?.full_url || null;
});

// Cadre autour du visage dans la visionneuse (bounding_box en % de l'image).
const faceBoxStyle = computed(() => {
  const box = question.value?.face?.bounding_box;
  if (!box || !showLightbox.value) return null;
  return {
    left: `${box.x}%`,
    top: `${box.y}%`,
    width: `${box.width}%`,
    height: `${box.height}%`,
  };
});

const onKeydown = (e) => {
  if (e.key === 'Escape' && showLightbox.value) showLightbox.value = false;
};

const textPlaceholder = computed(() => (question.value?.self && question.value?.type === 'address'
  ? t('Ta ville (ex. Montreuil)')
  : {
  birth_place: t('Ville, pays…'),
  death_place: t('Ville, pays…'),
  maiden_name: t('Nom de naissance'),
  address: '12 rue des Lilas, 93100 Montreuil',
  job: t('Métier (ex. institutrice, menuisier…)'),
  education: t('École ou diplôme'),
  residence: t('Ville'),
}[question.value?.type]) || '');

// Libellé du bouton « non » selon le type (marital et death_status l'ont déjà
// dans leur propre UI).
const noLabel = computed(() => ({
  parent_father: t('Inconnu / pas de fiche'),
  parent_mother: t('Inconnue / pas de fiche'),
  maiden_name: t('Identique à son nom'),
  education: t('Pas d’études particulières'),
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
    case 'how_met':
      return form.value.context
        ? {
          context: form.value.context,
          via_person_id: form.value.via_person_id || null,
          story: form.value.story || null,
          year: form.value.year || null,
        }
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
      // Une réponse peut créer une fiche à la volée : annuaire partagé caduc.
      peopleDirectory.invalidate();
      toast.success(tc('Merci ! :count souvenir complété.|Merci ! :count souvenirs complétés.', data.completed_count));
    }
    question.value = data.next;
    exhausted.value = data.next === null;
  } catch (error) {
    if (error.response?.status === 409) {
      toast.info(t('Déjà complété par quelqu’un d’autre — question suivante !'));
      completedCount.value = error.response.data.completed_count;
      question.value = error.response.data.next;
      exhausted.value = error.response.data.next === null;
    } else {
      toast.error(error.response?.data?.message || t('Impossible d’enregistrer la réponse.'));
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

onMounted(() => {
  load();
  window.addEventListener('keydown', onKeydown);
});
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
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
