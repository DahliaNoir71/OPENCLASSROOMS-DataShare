<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import AppHeader from '@/components/AppHeader.vue'
import { useAuthStore } from '@/stores/auth'
import {
  ListMessageError,
  ListUnauthenticatedError,
  ListValidationError,
  RemoveMessageError,
  RemoveNotFoundError,
  RemoveUnauthenticatedError,
  useFilesStore,
  type FilesPage,
  type FileStatus,
} from '@/stores/files'
import { formatFileSize } from '@/utils/formatFileSize'

const STATUS_OPTIONS: { value: FileStatus; label: string }[] = [
  { value: 'all', label: 'Tous' },
  { value: 'active', label: 'Actifs' },
  { value: 'expired', label: 'Expiré' },
]

const COPY_FEEDBACK_MS = 2000

const dateFormatter = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' })

function formatDate(iso: string): string {
  return dateFormatter.format(new Date(iso))
}

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const filesStore = useFilesStore()

const status = ref<FileStatus>('all')
const page = ref(1)
const loading = ref(false)
const globalError = ref('')
const filesPage = ref<FilesPage | null>(null)
const copiedId = ref<number | null>(null)
const removingId = ref<number | null>(null)
const copyError = ref('')
const copyErrorId = ref<number | null>(null)

let copyResetTimer: ReturnType<typeof setTimeout> | undefined

async function fetchPage(): Promise<void> {
  loading.value = true
  globalError.value = ''

  try {
    filesPage.value = await filesStore.list({ status: status.value, page: page.value })
  } catch (error) {
    if (error instanceof ListUnauthenticatedError) {
      await router.push({ path: '/login', query: { redirect: route.fullPath } })
    } else if (error instanceof ListValidationError) {
      // Une page ou un statut périmé (ex. dernière page devenue invalide après
      // une suppression) : on retombe sur les valeurs par défaut du filtre.
      status.value = 'all'
      page.value = 1
      await fetchPage()
      globalError.value = error.message
      return
    } else if (error instanceof ListMessageError) {
      globalError.value = error.message
    } else {
      globalError.value = 'Une erreur est survenue. Veuillez réessayer plus tard.'
    }
  } finally {
    loading.value = false
  }
}

function selectStatus(value: FileStatus): void {
  if (status.value === value) {
    return
  }

  status.value = value
  page.value = 1
  void fetchPage()
}

function goToPage(target: number): void {
  page.value = target
  void fetchPage()
}

async function copyLink(file: FilesPage['data'][number]): Promise<void> {
  copyError.value = ''
  copyErrorId.value = null

  try {
    await navigator.clipboard.writeText(file.link)
  } catch {
    copyError.value = 'La copie a échoué, copie le lien manuellement.'
    copyErrorId.value = file.id
    return
  }

  copiedId.value = file.id
  clearTimeout(copyResetTimer)
  copyResetTimer = setTimeout(() => {
    copiedId.value = null
  }, COPY_FEEDBACK_MS)
}

async function removeFile(file: FilesPage['data'][number]): Promise<void> {
  const confirmed = window.confirm(
    `Supprimer définitivement « ${file.original_name} » ? Cette action est irréversible.`,
  )
  if (!confirmed) {
    return
  }

  removingId.value = file.id
  globalError.value = ''

  try {
    await filesStore.remove(file.id)
    await fetchPage()
  } catch (error) {
    if (error instanceof RemoveUnauthenticatedError) {
      await router.push({ path: '/login', query: { redirect: route.fullPath } })
    } else if (error instanceof RemoveNotFoundError) {
      // Un 404 signale une liste périmée (fichier déjà supprimé ailleurs),
      // pas un problème de droits : on se contente de la rafraîchir.
      await fetchPage()
    } else if (error instanceof RemoveMessageError) {
      globalError.value = error.message
    } else {
      await fetchPage()
      globalError.value = 'Une erreur est survenue. Veuillez réessayer plus tard.'
    }
  } finally {
    removingId.value = null
  }
}

onMounted(() => {
  if (!authStore.token) {
    void router.push({ path: '/login', query: { redirect: route.fullPath } })
    return
  }

  void fetchPage()
})
</script>

<template>
  <div class="my-files-page">
    <AppHeader />

    <main class="my-files-main">
      <section class="my-files-card">
        <h1 class="my-files-title">Mes fichiers</h1>

        <div class="status-switch" role="radiogroup" aria-label="Filtrer par état">
          <button
            v-for="option in STATUS_OPTIONS"
            :key="option.value"
            type="button"
            class="status-switch-option"
            :class="{ 'status-switch-option--selected': status === option.value }"
            role="radio"
            :aria-checked="status === option.value"
            @click="selectStatus(option.value)"
          >
            {{ option.label }}
          </button>
        </div>

        <p v-if="globalError" class="form-error-global" role="alert">{{ globalError }}</p>

        <p v-if="loading" class="my-files-status-text">Chargement…</p>

        <template v-else-if="filesPage">
          <p v-if="filesPage.data.length === 0" class="my-files-status-text">
            Aucun fichier à afficher.
          </p>

          <ul v-else class="file-list">
            <li v-for="file in filesPage.data" :key="file.id" class="file-row">
              <div class="file-row-top">
                <div class="file-row-main">
                  <span class="file-row-name">{{ file.original_name }}</span>
                  <span class="file-row-meta">
                    {{ formatFileSize(file.size) }} · expire le {{ formatDate(file.expires_at) }}
                  </span>
                </div>

                <div class="file-row-actions">
                  <span v-if="file.expired" class="file-row-expired">Expiré</span>
                  <button v-else type="button" class="file-row-copy-button" @click="copyLink(file)">
                    {{ copiedId === file.id ? 'Lien copié !' : 'Copier le lien' }}
                  </button>

                  <button
                    type="button"
                    class="file-row-delete-button"
                    :disabled="removingId === file.id"
                    @click="removeFile(file)"
                  >
                    Supprimer
                  </button>
                </div>
              </div>

              <p v-if="copyErrorId === file.id" class="file-row-copy-error" role="alert">
                {{ copyError }}
              </p>
            </li>
          </ul>

          <div v-if="filesPage.data.length > 0" class="pagination">
            <button
              type="button"
              class="pagination-button"
              :disabled="!filesPage.links.prev"
              @click="goToPage(filesPage.meta.current_page - 1)"
            >
              Précédent
            </button>
            <span class="pagination-status">
              Page {{ filesPage.meta.current_page }} / {{ filesPage.meta.last_page }}
            </span>
            <button
              type="button"
              class="pagination-button"
              :disabled="!filesPage.links.next"
              @click="goToPage(filesPage.meta.current_page + 1)"
            >
              Suivant
            </button>
          </div>
        </template>
      </section>
    </main>

    <footer class="app-footer">Copyright DataShare® 2025</footer>
  </div>
</template>

<style scoped>
.my-files-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: var(--ds-gradient-bg);
}

.my-files-main {
  flex: 1;
  display: flex;
  justify-content: center;
  padding: var(--ds-space-lg) var(--ds-space-md);
}

.my-files-card {
  width: 100%;
  max-width: 640px;
  background: var(--ds-color-surface);
  border-radius: var(--ds-radius-card);
  box-shadow: var(--ds-shadow-card);
  padding: var(--ds-space-lg);
}

.my-files-title {
  margin-bottom: var(--ds-space-lg);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-h2);
  line-height: var(--ds-line-height-h2);
  font-weight: var(--ds-font-weight-h2);
  color: var(--ds-color-text);
  text-align: center;
}

.status-switch {
  display: inline-flex;
  gap: var(--ds-space-xs);
  padding: var(--ds-space-xs);
  border-radius: var(--ds-radius-pill);
  background: var(--ds-color-switch-bg);
  border: var(--ds-border-width) solid var(--ds-color-switch-border);
  margin-bottom: var(--ds-space-lg);
}

.status-switch-option {
  border-radius: var(--ds-radius-pill);
  padding: var(--ds-space-xs) var(--ds-space-md);
  background: transparent;
  color: var(--ds-color-text-secondary);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-input);
  line-height: var(--ds-line-height-input);
  font-weight: var(--ds-font-weight-input);
}

.status-switch-option--selected {
  background: var(--ds-color-switch-selected);
  color: var(--ds-color-primary-text);
}

.my-files-status-text {
  color: var(--ds-color-text-secondary);
  font-family: var(--ds-font-family-body);
  font-size: var(--ds-font-size-body);
  line-height: var(--ds-line-height-body);
  font-weight: var(--ds-font-weight-body);
  text-align: center;
}

.file-list {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-xs);
}

.file-row {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-xs);
  padding: var(--ds-space-md);
  border-radius: var(--ds-radius-input);
  background: var(--ds-color-file-row-bg);
  border: var(--ds-border-width) solid var(--ds-color-file-row-border);
}

.file-row-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--ds-space-md);
}

.file-row-copy-error {
  margin: 0;
  color: var(--ds-color-error-text);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-small);
  line-height: var(--ds-line-height-small);
  font-weight: var(--ds-font-weight-small);
}

.file-row-main {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.file-row-name {
  overflow-wrap: anywhere;
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-accent);
  line-height: var(--ds-line-height-accent);
  font-weight: var(--ds-font-weight-accent);
  color: var(--ds-color-text-secondary);
}

.file-row-meta {
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-small);
  line-height: var(--ds-line-height-small);
  font-weight: var(--ds-font-weight-small);
  color: var(--ds-color-text-muted);
}

.file-row-expired {
  flex-shrink: 0;
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-small);
  line-height: var(--ds-line-height-small);
  font-weight: var(--ds-font-weight-accent);
  color: var(--ds-color-text-expired);
}

.file-row-actions {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: var(--ds-space-xs);
}

.file-row-copy-button,
.file-row-delete-button {
  flex-shrink: 0;
  border: var(--ds-border-width) solid var(--ds-color-accent-border-soft);
  border-radius: var(--ds-radius-button);
  padding: var(--ds-space-xs) var(--ds-space-sm);
  background: transparent;
  color: var(--ds-color-accent);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-input);
  line-height: var(--ds-line-height-input);
  font-weight: var(--ds-font-weight-input);
}

.file-row-copy-button:hover,
.file-row-delete-button:hover {
  background: color-mix(in srgb, var(--ds-color-accent) 8%, transparent);
}

.file-row-delete-button:disabled {
  color: var(--ds-color-disabled-text);
  border-color: var(--ds-color-disabled-text);
}

.file-row-copy-button:focus-visible,
.file-row-delete-button:focus-visible,
.status-switch-option:focus-visible,
.pagination-button:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.form-error-global {
  margin: 0 0 var(--ds-space-md);
  color: var(--ds-color-error-text);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-small);
  line-height: var(--ds-line-height-small);
  font-weight: var(--ds-font-weight-small);
  text-align: center;
}

.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--ds-space-md);
  margin-top: var(--ds-space-lg);
}

.pagination-button {
  border-radius: var(--ds-radius-button);
  padding: var(--ds-space-xs) var(--ds-space-sm);
  background: transparent;
  color: var(--ds-color-accent);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-input);
  line-height: var(--ds-line-height-input);
  font-weight: var(--ds-font-weight-input);
}

.pagination-button:disabled {
  color: var(--ds-color-disabled-text);
}

.pagination-status {
  color: var(--ds-color-text-secondary);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-small);
  line-height: var(--ds-line-height-small);
  font-weight: var(--ds-font-weight-small);
}

.app-footer {
  display: none;
  color: var(--ds-color-text-inverse);
}

@media (min-width: 768px) {
  .app-footer {
    display: block;
    padding: 1rem;
    text-align: center;
  }
}
</style>
