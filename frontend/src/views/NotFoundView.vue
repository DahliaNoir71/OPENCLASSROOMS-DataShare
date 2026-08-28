<script setup lang="ts">
import AppCallout from '@/components/AppCallout.vue'
import AppHeader from '@/components/AppHeader.vue'
</script>

<template>
  <div class="not-found-page">
    <AppHeader />

    <main id="main-content" class="not-found-main" tabindex="-1">
      <section class="not-found-card">
        <h1 class="not-found-title">Page introuvable</h1>

        <!--
          Erreur de navigation front (route inconnue), pas un message serveur :
          la règle « le front n'invente aucun libellé d'erreur métier » ne
          s'applique pas ici.
        -->
        <AppCallout type="error" label="Cette page n'existe pas." />

        <router-link class="not-found-link" to="/">Retour à l'accueil</router-link>
      </section>
    </main>

    <footer class="app-footer">Copyright DataShare® 2025</footer>
  </div>
</template>

<style scoped>
.not-found-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: var(--ds-gradient-bg);
}

.not-found-main {
  flex: 1;
  display: flex;
  justify-content: center;
  padding: var(--ds-space-lg) var(--ds-space-md);
}

.not-found-card {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-lg);
  background: var(--ds-color-surface);
  border-radius: var(--ds-radius-card);
  box-shadow: var(--ds-shadow-card);
  padding: var(--ds-space-lg);
}

.not-found-title {
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-h2);
  line-height: var(--ds-line-height-h2);
  font-weight: var(--ds-font-weight-h2);
  color: var(--ds-color-text);
  text-align: center;
}

.not-found-link {
  align-self: center;
  color: var(--ds-color-accent-strong);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-input);
  line-height: var(--ds-line-height-input);
  font-weight: var(--ds-font-weight-input);
}

.not-found-link:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.app-footer {
  display: none;
  color: var(--ds-color-text-inverse);
}

@media (min-width: 768px) {
  .not-found-main {
    align-items: center;
  }

  .not-found-card {
    max-width: 420px;
  }

  .app-footer {
    display: block;
    padding: 1rem;
    text-align: center;
  }
}
</style>
