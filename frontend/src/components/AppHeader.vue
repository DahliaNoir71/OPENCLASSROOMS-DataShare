<template>
  <header class="app-header">
    <span class="app-header-logo">DataShare</span>
    <!-- TODO US04 : pointer vers /login -->
    <router-link class="app-header-login" to="/register">Se connecter</router-link>
  </header>
</template>

<style scoped>
/* TODO lot CSS final */
.app-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.5rem;
}
</style>
