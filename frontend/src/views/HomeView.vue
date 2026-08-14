<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue'
</script>

<template>
  <div class="home-page">
    <AppHeader />

    <main class="home-hero">
      <h1 class="home-title">Tu veux partager un fichier ?</h1>

      <!-- TODO lot téléversement -->
      <button
        class="home-upload-button"
        type="button"
        disabled
        aria-disabled="true"
        aria-label="Téléverser un fichier"
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
  .app-footer {
    display: block;
    padding: 1rem;
    text-align: center;
  }
}
</style>
