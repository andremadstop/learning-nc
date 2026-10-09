<template>
  <div class="onb-screen onb-profile-tiles">
    <div class="onb-profile-tiles__content">
      <!-- Goal (students only) -->
      <div v-if="isStudent" class="onb-tile-group">
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

      <!-- Intensity (students only) -->
      <div v-if="isStudent" class="onb-tile-group">
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

      <!-- AI consent: the tile only opens the consent dialog; the choice becomes "yes" once the
           server has stored the consent (AiConsentPrompt is the only write path) -->
      <div class="onb-tile-group">
        <h3 class="onb-tile-group__label">{{ t('learning', 'NOVA KI-Erklaerungen?') }}</h3>
        <div class="onb-tile-group__grid onb-tile-group__grid--two">
          <template v-for="tile in aiConsentTiles" :key="tile.id">
            <button
              v-if="tile.id === 'no' || aiAvailable"
              type="button"
              :class="['onb-tile', { 'onb-tile--selected': store.profileChoices.aiConsent === tile.id }]"
              :aria-pressed="store.profileChoices.aiConsent === tile.id ? 'true' : 'false'"
              :disabled="busy"
              @click="chooseAi(tile.id)"
            >
              <span class="onb-tile__icon">{{ tile.icon }}</span>
              <span class="onb-tile__label">{{ t('learning', tile.labelKey) }}</span>
              <span v-if="tile.id === 'yes' && providerName" class="onb-tile__provider">{{ providerName }}</span>
            </button>
          </template>
        </div>
        <p v-if="!aiAvailable" class="onb-tile-group__note">{{ t('learning', 'Auf diesem Server ist keine KI eingerichtet.') }}</p>
        <p v-if="error" class="onb-tile-group__error" role="alert">{{ error }}</p>
      </div>

      <AiConsentPrompt
        v-if="showConsent"
        source="onboarding"
        @granted="onGranted"
        @declined="showConsent = false"
        @unavailable="onUnavailable" />

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
import AiConsentPrompt from '../AiConsentPrompt.vue'
import { fetchConsentStatus, revokeConsent, notifyConsentChanged } from '../../utils/aiConsent.js'

export default {
  name: 'OnbProfileTiles',
  components: { AiConsentPrompt },
  emits: ['next'],

  setup() {
    const store = useOnboardingStore()
    return { store }
  },

  data() {
    return {
      showConsent: false,
      busy: false,
      error: '',
      // GET .../telos/consent: loaded flag, the recipient to name on the tile, whether a consent is on record
      statusLoaded: false,
      providerName: '',
      consentValid: false,
      // a consent is on record (ai_consent_version), even if it does not count right now
      consentStored: false,
    }
  },

  async mounted() {
    try {
      const status = await fetchConsentStatus()
      this.providerName = status?.recipient?.name || ''
      this.consentValid = !!status?.valid
      this.consentStored = !!status?.ai_consent_version
      this.statusLoaded = true
      if (!status?.recipient) {
        // nothing to consent to on this server
        this.store.setProfileChoice('aiConsent', 'no')
      } else if (status.valid) {
        this.store.setProfileChoice('aiConsent', 'yes')
      }
    } catch (e) {
      // status unknown: the user can still decide, the dialog loads it again
      this.statusLoaded = false
    }
  },

  computed: {
    aiAvailable() { return !this.statusLoaded || !!this.providerName },
    isStudent() { return this.store.role === 'student' },
    goalTiles() { return GOAL_TILES },
    intensityTiles() { return INTENSITY_TILES },
    aiConsentTiles() { return AI_CONSENT_TILES },
    allSelected() {
      const c = this.store.profileChoices
      if (this.isStudent) {
        return c.goal !== null && c.intensity !== null && c.aiConsent !== null
      }
      return c.aiConsent !== null
    },
  },

  methods: {
    t,
    chooseAi(id) {
      if (id === 'yes') {
        this.error = ''
        this.showConsent = true
        return
      }
      return this.chooseNoAi()
    },
    // "Without AI" takes back any consent that may be on record: one given in this wizard or
    // before, one that does not count right now (AI off, recipient changed), and — fail closed —
    // whenever the status could not be read.
    async chooseNoAi() {
      this.error = ''
      if (this.mayHoldConsent()) {
        this.busy = true
        try {
          await revokeConsent()
        } catch (e) {
          this.error = t('learning', 'Deine Einwilligung konnte nicht widerrufen werden. Bitte versuche es erneut.')
          this.busy = false
          return
        }
        this.busy = false
        this.consentValid = false
        this.consentStored = false
        notifyConsentChanged(false)
      }
      this.store.setProfileChoice('aiConsent', 'no')
    },
    mayHoldConsent() {
      return !this.statusLoaded
        || this.consentValid
        || this.consentStored
        || this.store.profileChoices.aiConsent === 'yes'
    },
    onGranted() {
      this.showConsent = false
      this.consentValid = true
      this.consentStored = true
      this.store.setProfileChoice('aiConsent', 'yes')
    },
    onUnavailable() {
      this.showConsent = false
      this.statusLoaded = true
      this.providerName = ''
      this.store.setProfileChoice('aiConsent', 'no')
    },
  },
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

.onb-tile__provider {
  font-size: 0.75rem;
  opacity: 0.75;
}

.onb-tile-group__note {
  margin: 0.75rem 0 0;
  text-align: center;
  font-size: 0.85rem;
  color: var(--lnc-text-secondary, #8b95a8);
}

.onb-tile-group__error {
  margin: 0.75rem 0 0;
  text-align: center;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--color-error-text, #ff6b6b);
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
