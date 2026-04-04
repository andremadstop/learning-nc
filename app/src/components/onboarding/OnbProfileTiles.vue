<template>
  <div class="onb-screen onb-profile-tiles">
    <div class="onb-profile-tiles__content">
      <!-- Goal -->
      <div class="onb-tile-group">
        <h3 class="onb-tile-group__label">{{ t('learning', 'Was ist dein Ziel?') }}</h3>
        <div class="onb-tile-group__grid">
          <button
            v-for="tile in goalTiles"
            :key="tile.id"
            type="button"
            :class="['onb-tile', { 'onb-tile--selected': store.profileChoices.goal === tile.id }]"
            :aria-pressed="store.profileChoices.goal === tile.id ? 'true' : 'false'"
            @click="store.setProfileChoice('goal', tile.id)"
          >
            <span class="onb-tile__icon">{{ tile.icon }}</span>
            <span class="onb-tile__label">{{ t('learning', tile.labelKey) }}</span>
          </button>
        </div>
      </div>

      <!-- Intensity -->
      <div class="onb-tile-group">
        <h3 class="onb-tile-group__label">{{ t('learning', 'Wie intensiv lernst du?') }}</h3>
        <div class="onb-tile-group__grid">
          <button
            v-for="tile in intensityTiles"
            :key="tile.id"
            type="button"
            :class="['onb-tile', { 'onb-tile--selected': store.profileChoices.intensity === tile.id }]"
            :aria-pressed="store.profileChoices.intensity === tile.id ? 'true' : 'false'"
            @click="store.setProfileChoice('intensity', tile.id)"
          >
            <span class="onb-tile__icon">{{ tile.icon }}</span>
            <span class="onb-tile__label">{{ t('learning', tile.labelKey) }}</span>
          </button>
        </div>
      </div>

      <!-- AI Consent -->
      <div class="onb-tile-group">
        <h3 class="onb-tile-group__label">{{ t('learning', 'NOVA KI-Erklaerungen?') }}</h3>
        <div class="onb-tile-group__grid onb-tile-group__grid--two">
          <button
            v-for="tile in aiConsentTiles"
            :key="tile.id"
            type="button"
            :class="['onb-tile', { 'onb-tile--selected': store.profileChoices.aiConsent === tile.id }]"
            :aria-pressed="store.profileChoices.aiConsent === tile.id ? 'true' : 'false'"
            @click="store.setProfileChoice('aiConsent', tile.id)"
          >
            <span class="onb-tile__icon">{{ tile.icon }}</span>
            <span class="onb-tile__label">{{ t('learning', tile.labelKey) }}</span>
          </button>
        </div>
      </div>

      <button
        type="button"
        class="onb-btn onb-btn--primary"
        :disabled="!allSelected"
        @click="$emit('next')"
      >
        {{ t('learning', 'Weiter') }}
      </button>
    </div>
  </div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { useOnboardingStore } from '../../stores/onboardingStore.js'
import { GOAL_TILES, INTENSITY_TILES, AI_CONSENT_TILES } from '../../utils/onboarding-slides.js'

export default {
  name: 'OnbProfileTiles',
  emits: ['next'],

  setup() {
    const store = useOnboardingStore()
    return { store }
  },

  computed: {
    goalTiles() { return GOAL_TILES },
    intensityTiles() { return INTENSITY_TILES },
    aiConsentTiles() { return AI_CONSENT_TILES },
    allSelected() {
      const c = this.store.profileChoices
      return c.goal !== null && c.intensity !== null && c.aiConsent !== null
    },
  },

  methods: { t },
}
</script>

<style scoped>
.onb-profile-tiles {
  display: flex;
  align-items: flex-start;
  justify-content: center;
  min-height: 100%;
  padding: 24px;
  overflow-y: auto;
}

.onb-profile-tiles__content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2rem;
  max-width: 540px;
  width: 100%;
  padding-block: 2rem;
}

.onb-tile-group {
  width: 100%;
}

.onb-tile-group__label {
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--lnc-text-primary, #e8ecf4);
  margin: 0 0 0.75rem;
  text-align: center;
}

.onb-tile-group__grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.75rem;
}

.onb-tile-group__grid--two {
  grid-template-columns: repeat(2, 1fr);
  max-width: 360px;
  margin-inline: auto;
}

.onb-tile {
  appearance: none;
  border: 2px solid rgba(255, 255, 255, 0.1);
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.04);
  padding: 1rem 0.5rem;
  min-height: 100px;
  min-width: 120px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  cursor: pointer;
  transition: border-color 0.2s, background 0.2s, box-shadow 0.2s, transform 0.1s;
  color: var(--lnc-text-primary, #e8ecf4);
  font: inherit;
}

.onb-tile:hover,
.onb-tile:focus-visible {
  border-color: rgba(6, 182, 212, 0.4);
  background: rgba(6, 182, 212, 0.06);
}

.onb-tile--selected {
  border-color: var(--lnc-cyan, #06b6d4);
  background: rgba(6, 182, 212, 0.1);
  box-shadow: 0 0 16px rgba(6, 182, 212, 0.15);
}

.onb-tile:active {
  transform: scale(0.97);
}

.onb-tile__icon {
  font-size: 2rem;
}

.onb-tile__label {
  font-size: 0.85rem;
  font-weight: 500;
  text-align: center;
}

@media (max-width: 480px) {
  .onb-tile-group__grid {
    grid-template-columns: 1fr 1fr 1fr;
    gap: 0.5rem;
  }

  .onb-tile {
    min-width: 0;
    min-height: 80px;
    padding: 0.75rem 0.25rem;
  }

  .onb-tile__icon {
    font-size: 1.5rem;
  }

  .onb-tile__label {
    font-size: 0.75rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .onb-tile {
    transition-duration: 0.05s;
  }
}
</style>
