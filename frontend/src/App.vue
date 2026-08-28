<script setup lang="ts">
import { RouterView } from 'vue-router'
</script>

<template>
  <a class="skip-link" href="#main-content">Aller au contenu</a>
  <RouterView />
</template>

<style scoped>
.skip-link {
  position: absolute;
  top: -40px;
  left: 0;
  z-index: 1000;
  padding: var(--ds-space-sm) var(--ds-space-md);
  border-radius: 0 0 var(--ds-radius-button) 0;
  background: var(--ds-color-primary);
  color: var(--ds-color-primary-text);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-input);
  line-height: var(--ds-line-height-input);
  font-weight: var(--ds-font-weight-input);
  text-decoration: none;
}

.skip-link:focus {
  top: 0;
}
</style>
