<script setup lang="ts">
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const router = useRouter()

async function handleLogout(): Promise<void> {
  await authStore.logout()
  await router.push('/')
}
</script>

<template>
  <header class="app-header">
    <span class="app-header-logo">DataShare</span>
    <div v-if="authStore.token" class="app-header-actions">
      <router-link class="app-header-login" to="/mon-espace">Mon espace</router-link>
      <button type="button" class="app-header-logout" @click="handleLogout">
        Se déconnecter
      </button>
    </div>
    <router-link v-else class="app-header-login" to="/login">Se connecter</router-link>
  </header>
</template>

<style scoped>
.app-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--ds-space-md) var(--ds-space-lg);
}

.app-header-logo {
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-h1);
  line-height: var(--ds-line-height-h1);
  font-weight: var(--ds-font-weight-h1);
  color: var(--ds-color-text);
}

.app-header-actions {
  display: flex;
  align-items: center;
  gap: var(--ds-space-sm);
}

.app-header-logout {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: var(--ds-space-sm);
  border: var(--ds-border-width) solid var(--ds-color-primary);
  border-radius: var(--ds-radius-button);
  background: transparent;
  color: var(--ds-color-primary);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-input);
  line-height: var(--ds-line-height-input);
  font-weight: var(--ds-font-weight-input);
}

.app-header-logout:hover {
  background: var(--ds-color-primary);
  color: var(--ds-color-primary-text);
}

.app-header-logout:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.app-header-login {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: var(--ds-space-sm);
  border-radius: var(--ds-radius-button);
  background: var(--ds-color-primary);
  color: var(--ds-color-primary-text);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-input);
  line-height: var(--ds-line-height-input);
  font-weight: var(--ds-font-weight-input);
  text-decoration: none;
}

.app-header-login:hover {
  filter: brightness(1.15);
}

.app-header-login:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}
</style>
