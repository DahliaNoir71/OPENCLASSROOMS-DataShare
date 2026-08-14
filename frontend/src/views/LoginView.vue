<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

import AppHeader from '@/components/AppHeader.vue'
import { AuthMessageError, RegisterValidationError, useAuthStore } from '@/stores/auth'

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const router = useRouter()
const authStore = useAuthStore()

const email = ref('')
const password = ref('')
const loading = ref(false)
const globalError = ref('')

const fieldErrors = reactive({
  email: [] as string[],
  password: [] as string[],
})

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
    await router.push('/')
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

    <main class="login-main">
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

    <footer class="app-footer">Copyright DataShare® 2025</footer>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.login-main {
  flex: 1;
  display: flex;
  justify-content: center;
  padding: var(--ds-space-lg) var(--ds-space-md);
}

.login-card {
  width: 100%;
  padding: var(--ds-space-lg);
}

.login-title {
  margin-bottom: var(--ds-space-lg);
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

.form-error,
.form-error-global {
  margin: 0;
}

.login-register-link {
  display: block;
  width: 100%;
  margin-top: var(--ds-space-md);
  padding: var(--ds-space-sm);
  text-align: center;
}

.login-submit {
  width: 100%;
  padding: var(--ds-space-sm);
}

.app-footer {
  display: none;
}

@media (min-width: 768px) {
  .login-main {
    align-items: center;
  }

  .login-card {
    max-width: 420px;
  }

  .app-footer {
    display: block;
    padding: var(--ds-space-md);
    text-align: center;
  }
}
</style>
