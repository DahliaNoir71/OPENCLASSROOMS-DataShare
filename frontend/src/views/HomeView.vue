<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import AppHeader from '@/components/AppHeader.vue'
import UploadCard from '@/components/UploadCard.vue'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const showUploadCard = ref(false)

/**
 * Le téléversement exige un compte : sans jeton, on passe par la connexion en
 * mémorisant la page à rejoindre ensuite. Avec un jeton, la carte s'ouvre ici
 * même — US01 n'a pas de route dédiée.
 */
function onUploadClick(): void {
  if (!authStore.token) {
    void router.push({ path: '/login', query: { redirect: route.fullPath } })
    return
  }

  showUploadCard.value = true
}
</script>

<template>
  <div class="home-page">
    <AppHeader />

    <main v-if="showUploadCard" id="main-content" class="home-main" tabindex="-1">
      <UploadCard />
    </main>

    <main v-else id="main-content" class="home-hero" tabindex="-1">
      <h1 class="home-title">Voulez-vous partager un fichier ?</h1>

      <button
        class="home-upload-button"
        type="button"
        aria-label="Téléverser un fichier"
        @click="onUploadClick"
      >
        <svg
          class="home-upload-icon"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M12 16V4" />
          <path d="M6 10l6-6 6 6" />
          <path d="M4 20h16" />
        </svg>
      </button>
    </main>

    <footer class="app-footer">Copyright DataShare® 2025</footer>
  </div>
</template>

<style scoped>
.home-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: var(--ds-gradient-bg);
}

.home-main {
  flex: 1;
  display: flex;
  justify-content: center;
  padding: var(--ds-space-lg) var(--ds-space-md);
}

.home-hero {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--ds-space-lg);
  padding: var(--ds-space-lg);
  text-align: center;
}

.home-title {
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-xlarge);
  line-height: var(--ds-line-height-xlarge);
  font-weight: var(--ds-font-weight-xlarge);
  color: var(--ds-color-text);
}

.home-upload-button {
  width: calc(var(--ds-space-lg) * 4);
  height: calc(var(--ds-space-lg) * 4);
  border: none;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--ds-color-primary);
  box-shadow: 0 0 0 var(--ds-space-lg) var(--ds-color-upload-halo);
}

.home-upload-button:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.home-upload-icon {
  width: calc(var(--ds-space-lg) * 2);
  height: calc(var(--ds-space-lg) * 2);
  color: var(--ds-color-primary-text);
}

.app-footer {
  display: none;
  color: var(--ds-color-text-inverse);
}

@media (min-width: 768px) {
  .home-main {
    align-items: center;
  }

  .home-main > * {
    max-width: 420px;
  }

  .app-footer {
    display: block;
    padding: 1rem;
    text-align: center;
  }
}
</style>
