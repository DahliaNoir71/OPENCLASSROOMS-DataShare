<script setup lang="ts">
const year = new Date().getFullYear()
</script>

<template>
  <footer class="app-footer">Copyright DataShare® {{ year }}</footer>
</template>

<style scoped>
.app-footer {
  display: none;
  color: var(--ds-color-text-inverse);
}

@media (min-width: 768px) {
  .app-footer {
    display: block;
    padding: var(--ds-space-md);
    text-align: center;
  }
}
</style>
