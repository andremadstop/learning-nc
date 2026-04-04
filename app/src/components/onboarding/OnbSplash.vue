<template>
  <div class="onb-screen onb-splash">
    <div class="onb-splash__content">
      <CharacterAvatar character-id="nova" :size="96" state="idle" />
      <h1 class="onb-splash__title">
        {{ t('learning', 'Hi {name}, ready for your mission?', { name: firstName }) }}
      </h1>
      <button
        type="button"
        class="onb-btn onb-btn--primary"
        @click="$emit('next')"
      >
        {{ t('learning', 'Mission starten') }}
      </button>
    </div>
    <button
      type="button"
      class="onb-skip-link"
      @click="store.skip(); $emit('next')"
    >
      {{ t('learning', 'Skip') }}
    </button>
  </div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import CharacterAvatar from '../CharacterAvatar.vue'
import { useOnboardingStore } from '../../stores/onboardingStore.js'

export default {
  name: 'OnbSplash',
  components: { CharacterAvatar },
  emits: ['next'],

  setup() {
    const store = useOnboardingStore()
    return { store }
  },

  data() {
    return {
      autoTimer: null,
    }
  },

  computed: {
    firstName() {
      const display = (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function'
        && OC.getCurrentUser()?.displayName) || ''
      return display.split(' ')[0] || ''
    },
    reducedMotion() {
      return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
    },
  },

  mounted() {
    this.autoTimer = setTimeout(() => {
      this.$emit('next')
    }, this.reducedMotion ? 1200 : 4000)
  },

  beforeUnmount() {
    if (this.autoTimer) clearTimeout(this.autoTimer)
  },

  methods: { t },
}
</script>

<style scoped>
.onb-splash {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100%;
}

.onb-splash__content {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1.5rem;
  animation: onb-fade-in 1s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

.onb-splash__title {
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--lnc-text-primary, #e8ecf4);
  margin: 0;
}

.onb-skip-link {
  position: absolute;
  inset-block-end: 24px;
  inset-inline-end: 24px;
  appearance: none;
  border: none;
  background: transparent;
  color: rgba(255, 255, 255, 0.35);
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
  transition: color 0.15s;
}

.onb-skip-link:hover,
.onb-skip-link:focus-visible {
  color: rgba(255, 255, 255, 0.7);
}

@keyframes onb-fade-in {
  from { opacity: 0; transform: translateY(16px); }
  to   { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
  .onb-splash__content {
    animation: none;
  }
}

@media (max-width: 480px) {
  .onb-splash__title {
    font-size: 1.35rem;
  }
}
</style>
