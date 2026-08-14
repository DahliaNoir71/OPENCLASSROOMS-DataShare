<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

import AppHeader from '@/components/AppHeader.vue'
import { RegisterValidationError, useAuthStore } from '@/stores/auth'

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const router = useRouter()
const authStore = useAuthStore()

const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const loading = ref(false)
const globalError = ref('')

const fieldErrors = reactive({
  email: [] as string[],
  password: [] as string[],
  passwordConfirmation: [] as string[],
})

function validate(): boolean {
  fieldErrors.email = []
  fieldErrors.password = []
  fieldErrors.passwordConfirmation = []

  if (!email.value) {
    fieldErrors.email.push("L'email est requis.")
  } else if (!EMAIL_REGEX.test(email.value)) {
    fieldErrors.email.push("Le format de l'email est invalide.")
  }

  if (!password.value) {
    fieldErrors.password.push('Le mot de passe est requis.')
  } else if (password.value.length < 8) {
    fieldErrors.password.push('Le mot de passe doit contenir au moins 8 caractères.')
  }

  if (!passwordConfirmation.value) {
    fieldErrors.passwordConfirmation.push('La vérification du mot de passe est requise.')
  } else if (passwordConfirmation.value !== password.value) {
    fieldErrors.passwordConfirmation.push('La vérification ne correspond pas au mot de passe.')
  }

  return (
    fieldErrors.email.length === 0 &&
    fieldErrors.password.length === 0 &&
    fieldErrors.passwordConfirmation.length === 0
  )
}

async function onSubmit(): Promise<void> {
  globalError.value = ''

  if (!validate()) {
    return
  }

  loading.value = true
  try {
    await authStore.register(email.value, password.value, passwordConfirmation.value)
    await router.push('/')
  } catch (error) {
    if (error instanceof RegisterValidationError) {
      fieldErrors.email = error.errors.email ?? []
      fieldErrors.password = error.errors.password ?? []
      fieldErrors.passwordConfirmation = error.errors.password_confirmation ?? []
    } else {
      globalError.value = 'Une erreur est survenue. Veuillez réessayer plus tard.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="register-page">
    <AppHeader />

    <main class="register-main">
      <section class="register-card">
        <h1 class="register-title">Créer un compte</h1>

        <form class="register-form" novalidate @submit.prevent="onSubmit">
          <div class="form-field">
            <label for="register-email">Email</label>
            <input
              id="register-email"
              v-model="email"
              type="email"
              autocomplete="email"
              placeholder="Saisissez votre email..."
              :aria-describedby="fieldErrors.email.length > 0 ? 'register-email-error' : undefined"
            />
            <p v-if="fieldErrors.email.length > 0" id="register-email-error" class="form-error">
              {{ fieldErrors.email.join(' ') }}
            </p>
          </div>

          <div class="form-field">
            <label for="register-password">Mot de passe</label>
            <input
              id="register-password"
              v-model="password"
              type="password"
              autocomplete="new-password"
              placeholder="Saisissez votre mot de passe..."
              :aria-describedby="
                fieldErrors.password.length > 0 ? 'register-password-error' : undefined
              "
            />
            <p
              v-if="fieldErrors.password.length > 0"
              id="register-password-error"
              class="form-error"
            >
              {{ fieldErrors.password.join(' ') }}
            </p>
          </div>

          <div class="form-field">
            <label for="register-password-confirmation">Verification du mot de passe</label>
            <input
              id="register-password-confirmation"
              v-model="passwordConfirmation"
              type="password"
              autocomplete="new-password"
              placeholder="Saisissez-le à nouveau"
              :aria-describedby="
                fieldErrors.passwordConfirmation.length > 0
                  ? 'register-password-confirmation-error'
                  : undefined
              "
            />
            <p
              v-if="fieldErrors.passwordConfirmation.length > 0"
              id="register-password-confirmation-error"
              class="form-error"
            >
              {{ fieldErrors.passwordConfirmation.join(' ') }}
            </p>
          </div>

          <router-link class="register-login-link" to="/login">J'ai déjà un compte</router-link>

          <p v-if="globalError" class="form-error-global" role="alert">{{ globalError }}</p>

          <button class="register-submit" type="submit" :disabled="loading">
            Créer mon compte
          </button>
        </form>
      </section>
    </main>

    <footer class="app-footer">Copyright DataShare® 2025</footer>
  </div>
</template>

<style scoped>
.register-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: var(--ds-gradient-bg);
}

.register-main {
  flex: 1;
  display: flex;
  justify-content: center;
  padding: var(--ds-space-lg) var(--ds-space-md);
}

.register-card {
  width: 100%;
  background: var(--ds-color-surface);
  border-radius: var(--ds-radius-card);
  box-shadow: var(--ds-shadow-card);
  padding: var(--ds-space-lg);
}

.register-title {
  margin-bottom: var(--ds-space-lg);
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-h2);
  line-height: var(--ds-line-height-h2);
  font-weight: var(--ds-font-weight-h2);
  color: var(--ds-color-text);
  text-align: center;
}

.register-form {
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

.register-login-link {
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

.register-login-link:hover {
  background: color-mix(in srgb, var(--ds-color-accent) 8%, transparent);
}

.register-login-link:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.register-submit {
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

.register-submit:hover:not(:disabled) {
  filter: brightness(0.95);
}

.register-submit:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}

.register-submit:disabled {
  background: var(--ds-color-disabled-bg);
  border-color: var(--ds-color-disabled-text);
  color: var(--ds-color-disabled-text);
}

.app-footer {
  display: none;
  color: var(--ds-color-text-inverse);
}

@media (min-width: 768px) {
  .register-main {
    align-items: center;
  }

  .register-card {
    max-width: 420px;
  }

  .app-footer {
    display: block;
    padding: var(--ds-space-md);
    text-align: center;
  }
}
</style>
