<template>
  <div class="onb-screen onb-privacy">
    <div class="onb-privacy__content">
      <span class="onb-privacy__icon" aria-hidden="true">🛡️</span>
      <h2 class="onb-privacy__title">{{ t('learning', 'Deine Daten') }}</h2>
      <p class="onb-privacy__text">
        {{ t('learning', 'Dein Lernfortschritt bleibt auf diesem Server. Nur du und deine Dozenten sehen deine Ergebnisse.') }}
      </p>
      <a
        class="onb-privacy__link"
        href="/settings/user/privacy"
        target="_blank"
        rel="noopener"
      >
        {{ t('learning', 'Datenschutz-Einstellungen') }}
      </a>
      <button
        type="button"
        class="onb-btn onb-btn--primary"
        @click="$emit('next')"
      >
        {{ t('learning', 'Weiter') }}
      </button>
    </div>
  </div>
</template>

<script>
import { t } from '@nextcloud/l10n'

export default {
  name: 'OnbPrivacy',
  emits: ['next'],
  methods: { t },
}
</script>

<style scoped>
.onb-privacy {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100%;
  padding: 24px;
}

.onb-privacy__content {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  max-width: 420px;
}

.onb-privacy__icon {
  font-size: 3rem;
}

.onb-privacy__title {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--lnc-text-primary, #e8ecf4);
  margin: 0;
}

.onb-privacy__text {
  font-size: 0.95rem;
  line-height: 1.65;
  color: var(--lnc-text-secondary, #8b95a8);
  margin: 0;
}

.onb-privacy__link {
  color: var(--lnc-cyan, #06b6d4);
  font-size: 0.9rem;
  text-decoration: none;
  transition: opacity 0.15s;
}

.onb-privacy__link:hover,
.onb-privacy__link:focus-visible {
  opacity: 0.8;
  text-decoration: underline;
}
</style>
