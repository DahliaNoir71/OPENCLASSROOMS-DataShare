<script setup lang="ts">
import { onMounted, useTemplateRef } from 'vue'

export type CalloutType = 'info' | 'warning' | 'error'

const props = defineProps<{
  type: CalloutType
  label: string
  /**
   * Déplace le focus sur le bandeau au montage. Réservé aux transitions
   * déclenchées par un clic (US02) : un callout affiché dès le chargement
   * n'a rien à voler au focus, aucun contrôle n'ayant encore reçu l'attention
   * de l'utilisateur.
   */
  focusOnMount?: boolean
}>()

const rootRef = useTemplateRef<HTMLElement>('root')

onMounted(() => {
  if (props.focusOnMount) {
    rootRef.value?.focus()
  }
})
</script>

<template>
  <div
    ref="root"
    class="app-callout"
    :class="`app-callout--${type}`"
    :role="type === 'error' ? 'alert' : undefined"
    :tabindex="type === 'error' ? -1 : undefined"
  >
    <svg
      v-if="type === 'info'"
      class="app-callout-icon"
      viewBox="0 0 16 16"
      fill="none"
      stroke="currentColor"
      stroke-width="1.6"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <circle cx="8" cy="8" r="6.2" />
      <line x1="8" y1="7.2" x2="8" y2="11" />
      <circle cx="8" cy="5.2" r="0.15" fill="currentColor" stroke="none" />
    </svg>

    <svg
      v-else-if="type === 'warning'"
      class="app-callout-icon"
      viewBox="0 0 16 16"
      fill="none"
      stroke="currentColor"
      stroke-width="1.6"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <path d="M8 2L14.5 13.5H1.5L8 2Z" />
      <line x1="8" y1="6.5" x2="8" y2="9.5" />
      <circle cx="8" cy="11.5" r="0.15" fill="currentColor" stroke="none" />
    </svg>

    <svg
      v-else
      class="app-callout-icon"
      viewBox="0 0 16 16"
      fill="none"
      stroke="currentColor"
      stroke-width="1.6"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <path d="M5 1.5H11L14.5 5V11L11 14.5H5L1.5 11V5L5 1.5Z" />
      <line x1="8" y1="5" x2="8" y2="9" />
      <circle cx="8" cy="11" r="0.15" fill="currentColor" stroke="none" />
    </svg>

    <span class="app-callout-label">{{ label }}</span>
  </div>
</template>

<style scoped>
.app-callout {
  display: flex;
  align-items: center;
  gap: var(--ds-space-xs);
  padding: var(--ds-space-xs);
  border-radius: var(--ds-radius-callout);
  border: var(--ds-border-width) solid transparent;
}

.app-callout-icon {
  flex-shrink: 0;
  width: var(--ds-size-icon);
  height: var(--ds-size-icon);
}

.app-callout-label {
  font-family: var(--ds-font-family-heading);
  font-size: var(--ds-font-size-small);
  line-height: var(--ds-line-height-small);
  font-weight: var(--ds-font-weight-small);
}

.app-callout--info {
  background: var(--ds-color-info-bg);
  border-color: var(--ds-color-info-border);
  color: var(--ds-color-info-text);
}

.app-callout--warning {
  background: var(--ds-color-warning-bg);
  border-color: var(--ds-color-warning-border);
  color: var(--ds-color-warning-text);
}

.app-callout--error {
  background: var(--ds-color-error-bg);
  border-color: var(--ds-color-error-border);
  color: var(--ds-color-error-text);
}

.app-callout:focus-visible {
  outline: 2px solid var(--ds-color-accent-border);
  outline-offset: 2px;
}
</style>
