<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import {
  UploadMessageError,
  UploadUnauthenticatedError,
  UploadValidationError,
  useFilesStore,
  type UploadedFile,
} from '@/stores/files'

/** 1 Go, borne annoncée par le contrat et par la maquette. */
const MAX_FILE_SIZE_BYTES = 2 ** 30

const FILE_TOO_LARGE_MESSAGE = 'La taille des fichiers est limitée à 1 Go'
const PASSWORD_MIN_LENGTH = 6

/** Les 7 durées de l'API (1..7 jours), libellées comme dans la maquette. */
const EXPIRY_OPTIONS = [
  { days: 1, label: 'Une journée' },
  { days: 2, label: 'Deux jours' },
  { days: 3, label: 'Trois jours' },
  { days: 4, label: 'Quatre jours' },
  { days: 5, label: 'Cinq jours' },
  { days: 6, label: 'Six jours' },
  { days: 7, label: 'Une semaine' },
]

const DEFAULT_EXPIRY_DAYS = 7
const COPY_FEEDBACK_MS = 2000
const SIZE_UNITS = ['o', 'Ko', 'Mo', 'Go']

function formatFileSize(bytes: number): string {
  let value = bytes
  let unit = 0

  while (value >= 1024 && unit < SIZE_UNITS.length - 1) {
    value /= 1024
    unit += 1
  }

  // Octets et kilo-octets restent entiers ; au-delà, une décimale suffit à
  // situer la taille sans donner une fausse précision.
  const rounded = unit < 2 ? Math.round(value) : Math.round(value * 10) / 10

  return `${rounded.toString().replace('.', ',')} ${SIZE_UNITS[unit]}`
}

const router = useRouter()
const route = useRoute()
const filesStore = useFilesStore()

const fileInput = useTemplateRef<HTMLInputElement>('fileInput')

const selectedFile = ref<File | null>(null)
const password = ref('')
const expiresInDays = ref(DEFAULT_EXPIRY_DAYS)
const loading = ref(false)
const globalError = ref('')

const uploaded = ref<UploadedFile | null>(null)
const uploadedExpiryLabel = ref('')
const copied = ref(false)
const copyError = ref('')

let copyResetTimer: ReturnType<typeof setTimeout> | undefined

const fieldErrors = reactive({
  file: [] as string[],
  password: [] as string[],
  expires_in_days: [] as string[],
})

const formattedSize = computed(() =>
  selectedFile.value ? formatFileSize(selectedFile.value.size) : '',
)

const isTooLarge = computed(
  () => selectedFile.value !== null && selectedFile.value.size > MAX_FILE_SIZE_BYTES,
)

const canSubmit = computed(() => selectedFile.value !== null && !isTooLarge.value && !loading.value)

function expiryLabel(days: number): string {
  return EXPIRY_OPTIONS.find((option) => option.days === days)?.label ?? ''
}

function openFilePicker(): void {
  fileInput.value?.click()
}

function onFileChange(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null

  selectedFile.value = file
  globalError.value = ''
  fieldErrors.file = []

  // Le dépassement de taille se voit dès la sélection : inutile d'attendre une
  // soumission qui, de toute façon, est bloquée.
  if (file && file.size > MAX_FILE_SIZE_BYTES) {
    fieldErrors.file.push(FILE_TOO_LARGE_MESSAGE)
  }
}

function validate(): boolean {
  fieldErrors.file = []
  fieldErrors.password = []
  fieldErrors.expires_in_days = []

  if (!selectedFile.value) {
    fieldErrors.file.push('Le fichier est requis.')
  } else if (selectedFile.value.size > MAX_FILE_SIZE_BYTES) {
    fieldErrors.file.push(FILE_TOO_LARGE_MESSAGE)
  }

  // Les extensions refusées ne sont pas rejouées ici : la liste appartient au
  // serveur, le front se contente d'afficher son 422.
  if (password.value && password.value.length < PASSWORD_MIN_LENGTH) {
    fieldErrors.password.push(
      `Le mot de passe doit contenir au moins ${PASSWORD_MIN_LENGTH} caractères.`,
    )
  }

  return fieldErrors.file.length === 0 && fieldErrors.password.length === 0
}

async function onSubmit(): Promise<void> {
  globalError.value = ''

  if (!validate() || !selectedFile.value) {
    return
  }

  const chosenDays = expiresInDays.value

  loading.value = true
  try {
    const file = await filesStore.upload(selectedFile.value, {
      password: password.value,
      expiresInDays: chosenDays,
    })
    uploadedExpiryLabel.value = expiryLabel(chosenDays).toLowerCase()
    uploaded.value = file
  } catch (error) {
    if (error instanceof UploadValidationError) {
      fieldErrors.file = error.errors.file ?? []
      fieldErrors.password = error.errors.password ?? []
      fieldErrors.expires_in_days = error.errors.expires_in_days ?? []
    } else if (error instanceof UploadUnauthenticatedError) {
      await router.push({ path: '/login', query: { redirect: route.fullPath } })
    } else if (error instanceof UploadMessageError) {
      globalError.value = error.message
    } else {
      globalError.value = 'Une erreur est survenue. Veuillez réessayer plus tard.'
    }
  } finally {
    loading.value = false
  }
}

async function copyLink(): Promise<void> {
  if (!uploaded.value) {
    return
  }

  copyError.value = ''
  try {
    await navigator.clipboard.writeText(uploaded.value.link)
  } catch {
    copyError.value = 'La copie a échoué, copie le lien manuellement.'
    return
  }

  copied.value = true
  clearTimeout(copyResetTimer)
  copyResetTimer = setTimeout(() => {
    copied.value = false
  }, COPY_FEEDBACK_MS)
}

onBeforeUnmount(() => {
  clearTimeout(copyResetTimer)
})
</script>

<template>
  <section class="upload-card">
    <h1 class="upload-title">Ajouter un fichier</h1>

    <div v-if="uploaded" class="upload-success">
      <p class="upload-success-message">
        Félicitations, ton fichier est en ligne ! Il restera disponible pendant
        {{ uploadedExpiryLabel }}.
      </p>

      <a class="upload-link" :href="uploaded.link">{{ uploaded.link }}</a>

      <button class="upload-copy-button" type="button" @click="copyLink">
        {{ copied ? 'Lien copié !' : 'Copier le lien' }}
      </button>

      <p v-if="copyError" class="form-error" role="alert">{{ copyError }}</p>
    </div>

    <form v-else class="upload-form" novalidate @submit.prevent="onSubmit">
      <div class="form-field">
        <label for="upload-file">Fichier</label>

        <input
          id="upload-file"
          ref="fileInput"
          v-show="!selectedFile"
          class="upload-file-input"
          type="file"
          :aria-describedby="fieldErrors.file.length > 0 ? 'upload-file-error' : undefined"
          @change="onFileChange"
        />

        <div v-if="selectedFile" class="upload-file-row">
          <span class="upload-file-name">{{ selectedFile.name }}</span>
          <span class="upload-file-size">{{ formattedSize }}</span>
          <button class="upload-change-button" type="button" @click="openFilePicker">
            Changer
          </button>
        </div>

        <p v-if="fieldErrors.file.length > 0" id="upload-file-error" class="form-error">
          {{ fieldErrors.file.join(' ') }}
        </p>
      </div>

      <div class="form-field">
        <label for="upload-password">Mot de passe</label>
        <input
          id="upload-password"
          v-model="password"
          type="password"
          autocomplete="new-password"
          placeholder="Optionnel"
          :aria-describedby="fieldErrors.password.length > 0 ? 'upload-password-error' : undefined"
        />
        <p v-if="fieldErrors.password.length > 0" id="upload-password-error" class="form-error">
          {{ fieldErrors.password.join(' ') }}
        </p>
      </div>

      <div class="form-field">
        <label for="upload-expiry">Expiration</label>

        <div class="upload-expiry-control">
          <select
            id="upload-expiry"
            v-model.number="expiresInDays"
            class="upload-expiry-select"
            :aria-describedby="
              fieldErrors.expires_in_days.length > 0 ? 'upload-expiry-error' : undefined
            "
          >
            <option v-for="option in EXPIRY_OPTIONS" :key="option.days" :value="option.days">
              {{ option.label }}
            </option>
          </select>

          <svg
            class="upload-expiry-chevron"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <path d="M4 6l4 4 4-4" />
          </svg>
        </div>

        <p
          v-if="fieldErrors.expires_in_days.length > 0"
          id="upload-expiry-error"
          class="form-error"
        >
          {{ fieldErrors.expires_in_days.join(' ') }}
        </p>
      </div>

      <p v-if="globalError" class="form-error-global" role="alert">{{ globalError }}</p>

      <button class="upload-submit" type="submit" :disabled="!canSubmit">
        {{ loading ? 'Téléversement...' : 'Téléverser' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.upload-card {
  width: 100%;
  background: var(--ds-color-surface);
  border-radius: var(--ds-radius-card);
  box-shadow: var(--ds-shadow-card);
  padding: var(--ds-space-lg);
}

.upload-title {
  margin-bottom: var(--ds-space-lg);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-h2);
  line-height: var(--ds-line-height-h2);
  font-weight: var(--ds-font-weight-h2);
  color: var(--ds-color-text);
  text-align: center;
}

.upload-form,
.upload-success {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-xs);
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-xs);
}

.form-field:not(:first-child) {
  margin-top: var(--ds-space-xs);
}

.form-field label {
  font-family: var(--ds-font-family-body);
  font-size: var(--ds-font-size-body);
  line-height: var(--ds-line-height-body);
  font-weight: var(--ds-font-weight-body);
  color: var(--ds-color-text-secondary);
}

.form-field input,
.form-field select {
  border: var(--ds-border-width) solid var(--ds-color-border);
  border-radius: var(--ds-radius-input);
  background: var(--ds-color-surface);
  color: var(--ds-color-text-secondary);
  padding: var(--ds-space-sm) var(--ds-space-md);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-input);
  line-height: var(--ds-line-height-input);
  font-weight: var(--ds-font-weight-input);
}

/* Maquette : boîte de 40px de haut, libellé centré (`align-items: center` sur
   le cadre Figma, le padding de 12px n'y positionne rien). Le trait Figma ne
   consomme pas de hauteur, la bordure CSS si : avec une hauteur fixée à 40px,
   il ne restait que 40 - 2 × 12px de padding - 2 × 1px de bordure = 14px de
   boîte de contenu pour une line-height de 16px, et le libellé était rogné.
   La hauteur de la maquette est donc tenue par `min-height`, le centrage
   vertical par le select lui-même, sans padding vertical qui puisse rogner. */
.form-field select {
  min-height: var(--ds-size-control-height);
  padding-block: 0;
  align-content: center;
}

/* Le chevron de la maquette (icône 16 × 16, nœud `#565:15194`) remplace la
   flèche native : la maquette n'en prévoit pas, et la largeur qu'elle réserve
   varie d'un navigateur à l'autre — donc la place laissée au libellé aussi. */
.upload-expiry-control {
  position: relative;
  display: grid;
}

.form-field .upload-expiry-select {
  appearance: none;
  /* Réserve du chevron, aux valeurs de la maquette : 12px de padding droit,
     8px de gouttière, icône de 16px. */
  padding-right: calc(var(--ds-space-sm) + var(--ds-space-xs) + var(--ds-size-icon));
  text-overflow: ellipsis;
}

.upload-expiry-chevron {
  position: absolute;
  top: 50%;
  right: var(--ds-space-sm);
  translate: 0 -50%;
  width: var(--ds-size-icon);
  height: var(--ds-size-icon);
  color: var(--ds-color-text-secondary);
  pointer-events: none;
}

.form-field input::placeholder {
  color: var(--ds-color-text-muted);
}

.form-field input:focus-visible,
.form-field select:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.form-field:has(.form-error) input,
.form-field:has(.form-error) select {
  border-color: var(--ds-color-error-border);
}

.form-error,
.form-error-global {
  margin: 0;
  color: var(--ds-color-error-text);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-small);
  line-height: var(--ds-line-height-small);
  font-weight: var(--ds-font-weight-small);
}

.upload-file-row {
  display: flex;
  align-items: center;
  gap: var(--ds-space-xs);
}

.upload-file-name {
  flex: 1;
  overflow-wrap: anywhere;
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-accent);
  line-height: var(--ds-line-height-accent);
  font-weight: var(--ds-font-weight-accent);
  color: var(--ds-color-text-secondary);
}

.upload-file-size {
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-small);
  line-height: var(--ds-line-height-small);
  font-weight: var(--ds-font-weight-small);
  color: var(--ds-color-text-muted);
}

.upload-change-button,
.upload-copy-button {
  border-radius: var(--ds-radius-button);
  padding: var(--ds-space-xs) var(--ds-space-sm);
  background: transparent;
  color: var(--ds-color-accent);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-input);
  line-height: var(--ds-line-height-input);
  font-weight: var(--ds-font-weight-input);
}

.upload-change-button:hover,
.upload-copy-button:hover {
  background: color-mix(in srgb, var(--ds-color-accent) 8%, transparent);
}

.upload-change-button:focus-visible,
.upload-copy-button:focus-visible,
.upload-link:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.upload-success-message {
  margin: 0;
  font-family: var(--ds-font-family-body);
  font-size: var(--ds-font-size-body);
  line-height: var(--ds-line-height-body);
  font-weight: var(--ds-font-weight-body);
  color: var(--ds-color-text-secondary);
}

.upload-link {
  overflow-wrap: anywhere;
  color: var(--ds-color-link);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-input);
  line-height: var(--ds-line-height-body);
  font-weight: var(--ds-font-weight-input);
}

.upload-copy-button {
  width: 100%;
  border: var(--ds-border-width) solid var(--ds-color-accent-border-soft);
  margin-top: var(--ds-space-xs);
}

.upload-submit {
  width: 100%;
  margin-top: var(--ds-space-md);
  border: var(--ds-border-width) solid var(--ds-color-accent-border);
  border-radius: var(--ds-radius-button);
  background: var(--ds-color-accent-soft);
  color: var(--ds-color-accent-strong);
  padding: var(--ds-space-sm);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-input);
  line-height: var(--ds-line-height-input);
  font-weight: var(--ds-font-weight-input);
}

.upload-submit:hover:not(:disabled) {
  filter: brightness(0.95);
}

.upload-submit:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.upload-submit:disabled {
  background: var(--ds-color-disabled-bg);
  border-color: var(--ds-color-disabled-text);
  color: var(--ds-color-disabled-text);
}
</style>
