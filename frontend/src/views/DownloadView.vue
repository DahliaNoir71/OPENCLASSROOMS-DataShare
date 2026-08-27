<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'

import AppCallout from '@/components/AppCallout.vue'
import AppHeader from '@/components/AppHeader.vue'
import {
  LinkGoneError,
  LinkMessageError,
  LinkNotFoundError,
  LinkPasswordError,
  LinkValidationError,
  useLinksStore,
  type LinkMetadata,
} from '@/stores/links'
import { formatFileSize } from '@/utils/formatFileSize'
import { formatMimeType } from '@/utils/formatMimeType'
import { saveBlob } from '@/utils/saveBlob'

const route = useRoute()
const linksStore = useLinksStore()

/** Le paramètre de route peut être un tableau si l'URL répète le segment. */
const rawToken = route.params.token
const token = Array.isArray(rawToken) ? (rawToken[0] ?? '') : (rawToken ?? '')

const loading = ref(true)
const errorMessage = ref('')
const errorFocusOnMount = ref(false)
const link = ref<LinkMetadata | null>(null)
const password = ref('')
const downloading = ref(false)
const done = ref(false)
const globalError = ref('')

const fieldErrors = reactive({
  password: [] as string[],
})

const relativeFormatter = new Intl.RelativeTimeFormat('fr-FR', { numeric: 'auto' })

/**
 * Arrondi au jour supérieur : 12h restantes affichent « demain », jamais un
 * compte en heures — la maquette ne montre que des jours. Le calcul s'appuie
 * sur l'horloge du navigateur, mais ne décide jamais de l'accès : le 200 déjà
 * reçu fait foi, seul le libellé en dépend.
 */
const daysRemaining = computed(() => {
  if (!link.value) {
    return 1
  }

  const diffMs = new Date(link.value.expires_at).getTime() - Date.now()

  return Math.ceil(diffMs / (24 * 60 * 60 * 1000))
})

const expiryCalloutType = computed(() => (daysRemaining.value <= 1 ? 'warning' : 'info'))

const expiryLabel = computed(() => {
  const days = Math.max(daysRemaining.value, 1)

  return `Ce fichier expirera ${relativeFormatter.format(days, 'day')}.`
})

const canSubmit = computed(
  () =>
    link.value !== null &&
    (!link.value.protected || password.value !== '') &&
    !downloading.value &&
    !done.value,
)

async function fetchMetadata(): Promise<void> {
  loading.value = true

  try {
    link.value = await linksStore.metadata(token)
  } catch (error) {
    if (
      error instanceof LinkNotFoundError ||
      error instanceof LinkGoneError ||
      error instanceof LinkMessageError
    ) {
      errorMessage.value = error.message
    } else {
      errorMessage.value = 'Une erreur est survenue. Veuillez réessayer plus tard.'
    }
  } finally {
    loading.value = false
  }
}

async function onSubmit(): Promise<void> {
  if (!link.value || !canSubmit.value) {
    return
  }

  fieldErrors.password = []
  globalError.value = ''
  downloading.value = true

  try {
    const blob = await linksStore.download(
      token,
      link.value.protected ? password.value : undefined,
    )
    saveBlob(blob, link.value.original_name)
    done.value = true
  } catch (error) {
    if (error instanceof LinkPasswordError) {
      fieldErrors.password = [error.message]
    } else if (error instanceof LinkValidationError) {
      fieldErrors.password = error.errors.password ?? [error.message]
    } else if (error instanceof LinkGoneError || error instanceof LinkNotFoundError) {
      // Le lien a changé d'état entre l'affichage et le clic (expiration, ou
      // suppression manuelle US06) : l'écran bascule entièrement, et c'est ce
      // clic qui justifie de déplacer le focus sur le nouveau bandeau.
      link.value = null
      errorMessage.value = error.message
      errorFocusOnMount.value = true
    } else if (error instanceof LinkMessageError) {
      globalError.value = error.message
    } else {
      globalError.value = 'Une erreur est survenue. Veuillez réessayer plus tard.'
    }
  } finally {
    downloading.value = false
  }
}

onMounted(() => {
  void fetchMetadata()
})
</script>

<template>
  <div class="download-page">
    <AppHeader />

    <main class="download-main">
      <section class="download-card">
        <h1 class="download-title">Télécharger un fichier</h1>

        <p v-if="loading" class="download-status-text">Chargement…</p>

        <AppCallout
          v-else-if="errorMessage"
          type="error"
          :label="errorMessage"
          :focus-on-mount="errorFocusOnMount"
        />

        <template v-else-if="link">
          <form class="download-form" novalidate @submit.prevent="onSubmit">
            <div class="download-fields">
              <div class="download-file">
                <svg
                  class="download-file-icon"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.6"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  aria-hidden="true"
                >
                  <path d="M6 2h9l5 5v15a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z" />
                  <path d="M15 2v5h5" />
                </svg>
                <div class="download-file-info">
                  <span class="download-file-name">{{ link.original_name }}</span>
                  <span class="download-file-meta">
                    {{ formatMimeType(link.mime_type) }} · {{ formatFileSize(link.size) }}
                  </span>
                </div>
              </div>

              <AppCallout :type="expiryCalloutType" :label="expiryLabel" />

              <div v-if="link.protected" class="form-field">
                <label for="download-password">Mot de passe</label>
                <input
                  id="download-password"
                  v-model="password"
                  type="password"
                  autocomplete="current-password"
                  placeholder="Saisissez le mot de passe..."
                  maxlength="72"
                  :disabled="downloading || done"
                  :aria-describedby="
                    fieldErrors.password.length > 0 ? 'download-password-error' : undefined
                  "
                />
                <p
                  v-if="fieldErrors.password.length > 0"
                  id="download-password-error"
                  class="form-error"
                >
                  {{ fieldErrors.password.join(' ') }}
                </p>
              </div>
            </div>

            <div class="download-actions">
              <AppCallout v-if="done" type="info" label="Le téléchargement a démarré." />

              <p v-if="globalError" class="form-error-global" role="alert">{{ globalError }}</p>

              <button class="download-submit" type="submit" :disabled="!canSubmit">
                {{ downloading ? 'Téléchargement...' : 'Télécharger' }}
              </button>
            </div>
          </form>
        </template>
      </section>
    </main>

    <footer class="app-footer">Copyright DataShare® 2025</footer>
  </div>
</template>

<style scoped>
.download-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: var(--ds-gradient-bg);
}

.download-main {
  flex: 1;
  display: flex;
  justify-content: center;
  padding: var(--ds-space-lg) var(--ds-space-md);
}

.download-card {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-lg);
  background: var(--ds-color-surface);
  border-radius: var(--ds-radius-card);
  box-shadow: var(--ds-shadow-card);
  padding: var(--ds-space-lg);
}

.download-title {
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-h2);
  line-height: var(--ds-line-height-h2);
  font-weight: var(--ds-font-weight-h2);
  color: var(--ds-color-text);
  text-align: center;
}

.download-status-text {
  color: var(--ds-color-text-secondary);
  font-family: var(--ds-font-family-body);
  font-size: var(--ds-font-size-body);
  line-height: var(--ds-line-height-body);
  font-weight: var(--ds-font-weight-body);
  text-align: center;
}

.download-form,
.download-fields,
.download-actions {
  display: flex;
  flex-direction: column;
}

.download-fields {
  gap: var(--ds-space-md);
}

.download-actions {
  gap: var(--ds-space-xs);
}

.download-file {
  display: flex;
  align-items: center;
  gap: var(--ds-space-md);
  padding: var(--ds-space-xs);
}

.download-file-icon {
  flex-shrink: 0;
  width: 24px;
  height: 24px;
  color: var(--ds-color-text-secondary);
}

.download-file-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.download-file-name {
  overflow-wrap: anywhere;
  font-family: var(--ds-font-family-body);
  font-size: var(--ds-font-size-body);
  line-height: var(--ds-line-height-body);
  font-weight: var(--ds-font-weight-body);
  color: var(--ds-color-text);
}

.download-file-meta {
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-small);
  line-height: var(--ds-line-height-small);
  font-weight: var(--ds-font-weight-small);
  color: var(--ds-color-text);
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-xs);
}

.form-field label {
  font-family: var(--ds-font-family-body);
  font-size: var(--ds-font-size-body);
  line-height: var(--ds-line-height-body);
  font-weight: var(--ds-font-weight-body);
  color: var(--ds-color-text-secondary);
}

.form-field input {
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

.form-field input::placeholder {
  color: var(--ds-color-text-muted);
}

.form-field input:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.form-field:has(.form-error) input {
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

.form-error-global {
  text-align: center;
}

.download-submit {
  width: 100%;
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

.download-submit:hover:not(:disabled) {
  filter: brightness(0.95);
}

.download-submit:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.download-submit:disabled {
  background: var(--ds-color-disabled-bg);
  border-color: var(--ds-color-disabled-text);
  color: var(--ds-color-disabled-text);
}

.app-footer {
  display: none;
  color: var(--ds-color-text-inverse);
}

@media (min-width: 768px) {
  .download-main {
    align-items: center;
  }

  .download-card {
    max-width: 420px;
  }

  .app-footer {
    display: block;
    padding: 1rem;
    text-align: center;
  }
}
</style>
