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
/* TODO lot CSS final */
.home-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.home-hero {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2rem;
  padding: 1.5rem 1rem;
  text-align: center;
}

.home-upload-button {
  width: 5rem;
  height: 5rem;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.home-upload-icon {
  width: 2rem;
  height: 2rem;
}

.app-footer {
  display: none;
}

@media (min-width: 768px) {
  .app-footer {
    display: block;
    padding: 1rem;
    text-align: center;
  }
}
</style>
