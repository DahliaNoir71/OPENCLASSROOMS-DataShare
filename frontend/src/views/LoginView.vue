<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import AppFooter from '@/components/AppFooter.vue'
import AppHeader from '@/components/AppHeader.vue'
import { AuthMessageError, RegisterValidationError, useAuthStore } from '@/stores/auth'
import { EMAIL_REGEX } from '@/utils/email'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const email = ref('')
const password = ref('')
const loading = ref(false)
const globalError = ref('')

const fieldErrors = reactive({
  email: [] as string[],
  password: [] as string[],
})

/**
 * Destination après connexion. Seul un chemin interne est accepté : « //evil.com »
 * comme « https://evil.com » sont des URL absolues qu'un tiers pourrait glisser
 * dans le lien de connexion pour détourner l'utilisateur après authentification.
 */
function redirectTarget(): string {
  const target = route.query.redirect

  if (typeof target === 'string' && target.startsWith('/') && !target.startsWith('//')) {
    return target
  }

  return '/'
}

function validate(): boolean {
  fieldErrors.email = []
  fieldErrors.password = []

  if (!email.value) {
    fieldErrors.email.push("L'email est requis.")
  } else if (!EMAIL_REGEX.test(email.value)) {
    fieldErrors.email.push("Le format de l'email est invalide.")
  }

  // Aucune longueur minimale ici : la vérification du mot de passe appartient au serveur.
  if (!password.value) {
    fieldErrors.password.push('Le mot de passe est requis.')
  }

  return fieldErrors.email.length === 0 && fieldErrors.password.length === 0
}

async function onSubmit(): Promise<void> {
  globalError.value = ''

  if (!validate()) {
    return
  }

  loading.value = true
  try {
    await authStore.login(email.value, password.value)
    await router.push(redirectTarget())
  } catch (error) {
    if (error instanceof RegisterValidationError) {
      fieldErrors.email = error.errors.email ?? []
      fieldErrors.password = error.errors.password ?? []
    } else if (error instanceof AuthMessageError) {
      globalError.value = error.message
    } else {
      globalError.value = 'Une erreur est survenue. Veuillez réessayer plus tard.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <AppHeader />

    <main id="main-content" class="login-main" tabindex="-1">
      <section class="login-card">
        <h1 class="login-title">Connexion</h1>

        <form class="login-form" novalidate @submit.prevent="onSubmit">
          <div class="form-field">
            <label for="login-email">Email</label>
            <input
              id="login-email"
              v-model="email"
              type="email"
              autocomplete="email"
              placeholder="Saisissez votre email..."
              :aria-describedby="fieldErrors.email.length > 0 ? 'login-email-error' : undefined"
            />
            <p v-if="fieldErrors.email.length > 0" id="login-email-error" class="form-error">
              {{ fieldErrors.email.join(' ') }}
            </p>
          </div>

          <div class="form-field">
            <label for="login-password">Mot de passe</label>
            <input
              id="login-password"
              v-model="password"
              type="password"
              autocomplete="current-password"
              placeholder="Saisissez votre mot de passe..."
              :aria-describedby="
                fieldErrors.password.length > 0 ? 'login-password-error' : undefined
              "
            />
            <p v-if="fieldErrors.password.length > 0" id="login-password-error" class="form-error">
              {{ fieldErrors.password.join(' ') }}
            </p>
          </div>

          <router-link class="login-register-link" to="/register">Créer un compte</router-link>

          <p v-if="globalError" class="form-error-global" role="alert">{{ globalError }}</p>

          <button class="login-submit" type="submit" :disabled="loading">
            {{ loading ? 'Connexion...' : 'Connexion' }}
          </button>
        </form>
      </section>
    </main>

    <AppFooter />
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: var(--ds-gradient-bg);
}

.login-main {
  flex: 1;
  display: flex;
  justify-content: center;
  padding: var(--ds-space-lg) var(--ds-space-md);
}

.login-card {
  width: 100%;
  background: var(--ds-color-surface);
  border-radius: var(--ds-radius-card);
  box-shadow: var(--ds-shadow-card);
  padding: var(--ds-space-lg);
}

.login-title {
  margin-bottom: var(--ds-space-lg);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-h2);
  line-height: var(--ds-line-height-h2);
  font-weight: var(--ds-font-weight-h2);
  color: var(--ds-color-text);
  text-align: center;
}

.login-form {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-xs);
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-xs);
}

.form-field:not(:first-child) {
  margin-top: var(--ds-space-xs);
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

.login-register-link {
  display: block;
  width: 100%;
  margin-top: var(--ds-space-md);
  padding: var(--ds-space-sm);
  border-radius: var(--ds-radius-button);
  text-align: center;
  text-decoration: none;
  color: var(--ds-color-accent);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-input);
  line-height: var(--ds-line-height-input);
  font-weight: var(--ds-font-weight-input);
}

.login-register-link:hover {
  background: color-mix(in srgb, var(--ds-color-accent) 8%, transparent);
}

.login-register-link:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.login-submit {
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

.login-submit:hover:not(:disabled) {
  filter: brightness(0.95);
}

.login-submit:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.login-submit:disabled {
  background: var(--ds-color-disabled-bg);
  border-color: var(--ds-color-disabled-text);
  color: var(--ds-color-disabled-text);
}

@media (min-width: 768px) {
  .login-main {
    align-items: center;
  }

  .login-card {
    max-width: 420px;
  }
}
</style>
