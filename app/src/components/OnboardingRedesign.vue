<template>
  <transition name="onb-fade">
    <div v-if="visible" class="onboarding-redesign">
      <button
        class="onb-skip-global"
        type="button"
        @click="handleSkip"
      >
        {{ t('learning', 'Skip') }}
      </button>
      <transition :name="transitionName" mode="out-in">
        <component
          :is="currentStepComponent"
          :key="store.step"
          @next="advance"
        />
      </transition>

      <!-- Skip confirmation dialog -->
      <div
        v-if="showSkipConfirm"
        class="onb-dialog-backdrop"
        @click.self="showSkipConfirm = false"
      >
        <div class="onb-dialog" role="alertdialog" aria-modal="true">
          <p class="onb-dialog__text">
            {{ t('learning', 'NOVA kann dich ohne Profil nicht optimal unterstuetzen. Trotzdem ueberspringen?') }}
          </p>
          <div class="onb-dialog__actions">
            <button
              type="button"
              class="onb-btn onb-btn--ghost"
              @click="showSkipConfirm = false"
            >
              {{ t('learning', 'Zurueck') }}
            </button>
            <button
              type="button"
              class="onb-btn onb-btn--primary"
              @click="confirmSkip"
            >
              {{ t('learning', 'Ueberspringen') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </transition>
</template>

<script>
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useOnboardingStore } from '../stores/onboardingStore.js'
import { INTENSITY_TILES } from '../utils/onboarding-slides.js'
import { buildTelosPayload, createTelosForm } from '../utils/telosProfile.js'
import OnbSplash from './onboarding/OnbSplash.vue'
import OnbRoleSelect from './onboarding/OnbRoleSelect.vue'
import OnbTour from './onboarding/OnbTour.vue'
import OnbPrivacy from './onboarding/OnbPrivacy.vue'
import OnbProfileTiles from './onboarding/OnbProfileTiles.vue'
import consentData from '../../data/ai-consent.json'
import OnbJumpstart from './onboarding/OnbJumpstart.vue'
import OnbHook from './onboarding/OnbHook.vue'

const STEP_ORDER = ['splash', 'role', 'tour', 'privacy', 'profile', 'jumpstart', 'hook', 'done']

const STEP_COMPONENTS = {
  splash: 'OnbSplash',
  role: 'OnbRoleSelect',
  tour: 'OnbTour',
  privacy: 'OnbPrivacy',
  profile: 'OnbProfileTiles',
  jumpstart: 'OnbJumpstart',
  hook: 'OnbHook',
}

export default {
  name: 'OnboardingRedesign',

  components: {
    OnbSplash,
    OnbRoleSelect,
    OnbTour,
    OnbPrivacy,
    OnbProfileTiles,
    OnbJumpstart,
    OnbHook,
  },

  emits: ['done'],

  setup() {
    const store = useOnboardingStore()
    return { store }
  },

  data() {
    return {
      visible: true,
      showSkipConfirm: false,
      transitionDirection: 'next',
    }
  },

  computed: {
    currentStepComponent() {
      return STEP_COMPONENTS[this.store.step] || null
    },
    transitionName() {
      return this.transitionDirection === 'next' ? 'onb-slide-next' : 'onb-slide-prev'
    },
  },

  watch: {
    'store.step'(newStep) {
      if (newStep === 'done') {
        this.finalize()
      }
    },
  },

  mounted() {
    this.store.hydrate()
    // If already done (e.g. corrupt state), finish immediately
    if (this.store.step === 'done') {
      this.finalize()
    }

    this._handleKeydown = (e) => {
      if (e.key === 'Escape' && this.showSkipConfirm) {
        this.showSkipConfirm = false
      }
    }
    document.addEventListener('keydown', this._handleKeydown)
  },

  beforeUnmount() {
    document.removeEventListener('keydown', this._handleKeydown)
  },

  methods: {
    t,

    advance() {
      this.transitionDirection = 'next'
      const currentIdx = STEP_ORDER.indexOf(this.store.step)
      const nextStep = STEP_ORDER[currentIdx + 1]
      if (nextStep) {
        this.store.setStep(nextStep)
      }
    },

    handleSkip() {
      this.showSkipConfirm = true
    },

    confirmSkip() {
      this.showSkipConfirm = false
      this.store.skip()
      // watch triggers finalize via step === 'done'
    },

    async finalize() {
      const uid = this.getUid()

      // Codeberg #4: these three steps used to share ONE try/catch, so the 500 from
      // saveTelosProfile() also silently cancelled the AI consent and the starter-pool import —
      // three losses from one bug, none of them visible to the user, and the empty catch made
      // sure nothing was ever logged. Settle each step on its own so a failure in one can never
      // swallow the others again.
      if (!this.store.skipped) {
        await this.runFinalizeStep('telos profile', () => this.saveTelosProfile())
        await this.runFinalizeStep('AI consent', () => this.saveAiConsent())
      }

      if (this.store.selectedStarterPoolId) {
        await this.runFinalizeStep('starter pool import', () => this.importStarterPool())
      }

      // Codeberg #5: localStorage alone is scoped to one browser on one machine, so a second
      // device brought the whole wizard back. This runs for a skipped wizard too — skipping is a
      // decision, not an accident, and the profile save above deliberately does not.
      await this.runFinalizeStep('acknowledge', () => this.acknowledgeOnboarding())

      // Offline fallback only — the server value above is authoritative from 5.4.2 on.
      try {
        window.localStorage.setItem(`learning:onboarding-seen:${uid}`, 'yes')
      } catch {
        // Ignore
      }

      // Clear progress data
      try {
        window.localStorage.removeItem(`learning:onboarding-progress:${uid}`)
      } catch {
        // Ignore
      }

      this.visible = false
      setTimeout(() => {
        this.$emit('done')
      }, 600)
    },

    /**
     * Runs one finalize step best-effort: onboarding completion must never be blocked by a
     * backend hiccup, but the failure has to leave a trace instead of vanishing.
     */
    async runFinalizeStep(label, step) {
      try {
        await step()
      } catch (e) {
        console.error(`[learning] onboarding finalize step failed: ${label}`, e)
      }
    },

    async acknowledgeOnboarding() {
      await axios.post(generateUrl('/apps/learning/api/settings/personal/onboarding'))
    },

    async saveTelosProfile() {
      const choices = this.store.profileChoices
      const intensityTile = INTENSITY_TILES.find(t => t.id === choices.intensity)

      // Codeberg #4: this wizard used to hand-roll its own payload shape and sent '' for the
      // list fields (help_offer/help_wanted/strengths/weaknesses) where every other caller sends
      // an array — the backend's ?array hint turned that into a TypeError, so onboarding never
      // saved a profile at all. Build the payload through the shared util so there is exactly
      // one payload shape in the app instead of two that can drift apart again.
      const form = createTelosForm()
      form.telos.role = this.store.role || ''
      form.telos.hours_per_week = intensityTile ? String(intensityTile.hours) : ''

      // Map goal tile to telos fields
      if (choices.goal === 'certification') {
        form.telos.target_cert = 'certification'
      } else if (choices.goal === 'career') {
        form.telos.motivation = 'career'
      } else if (choices.goal === 'hobby') {
        form.telos.motivation = 'hobby'
      }

      const payload = buildTelosPayload(form)

      await axios.post(generateUrl('/apps/learning/api/profile/telos'), payload)
    },

    async saveAiConsent() {
      const aiEnabled = this.store.profileChoices.aiConsent === 'yes'
      // AUDIT v5.2.1 (pre-live review R2, finding #2): the backend consent endpoint stores a
      // version string (same as VirtuProf's consent dialog) — it ignores `ai_explanations` and
      // rejects a missing version with 400. The old payload was a silent no-op; with the new
      // universal AI-consent gate that no-op locked opted-in onboarding users out of AI features.
      // Only persist consent when the user actually opted in; "no" simply leaves consent unset.
      if (!aiEnabled) {
        return
      }
      await axios.post(generateUrl('/apps/learning/api/profile/telos/consent'), {
        version: consentData.version,
      })
    },

    async importStarterPool() {
      const poolId = this.store.selectedStarterPoolId
      await axios.post(generateUrl(`/apps/learning/api/starter-pools/${poolId}/import`))
    },

    getUid() {
      return (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function'
        && OC.getCurrentUser()?.uid) || 'user'
    },
  },
}
</script>

<style scoped>
.onboarding-redesign {
  position: fixed;
  inset: 0;
  z-index: 10000;
  background: var(--lnc-bg-canvas, #0a0e17);
  display: flex;
  align-items: center;
  justify-content: center;
}

/* Global skip button */
.onb-skip-global {
  position: absolute;
  /* Codeberg #5: 16px put this straight underneath the Nextcloud header, whose avatar button
     swallowed every click — document.elementFromPoint() on the button's own centre returned
     SPAN.button-vue__icon. The splash screen has its own skip link, so step 1 looked fine while
     every later step had no reachable way out at all. 60px clears the 50px header instead of
     fighting it with z-index, which would only bury the header's own controls. */
  inset-block-start: 60px;
  inset-inline-end: 16px;
  z-index: 10;
  appearance: none;
  border: none;
  background: transparent;
  color: rgba(255, 255, 255, 0.35);
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
  padding: 8px 12px;
  transition: color 0.15s;
}

.onb-skip-global:hover,
.onb-skip-global:focus-visible {
  color: rgba(255, 255, 255, 0.7);
}

/* Step transitions */
.onb-slide-next-enter-active,
.onb-slide-next-leave-active,
.onb-slide-prev-enter-active,
.onb-slide-prev-leave-active {
  transition: opacity 0.35s ease, transform 0.35s ease;
}

.onb-slide-next-enter-from  { opacity: 0; transform: translateX(60px); }
.onb-slide-next-leave-to    { opacity: 0; transform: translateX(-60px); }
.onb-slide-prev-enter-from  { opacity: 0; transform: translateX(-60px); }
.onb-slide-prev-leave-to    { opacity: 0; transform: translateX(60px); }

[dir="rtl"] .onb-slide-next-enter-from  { transform: translateX(-60px); }
[dir="rtl"] .onb-slide-next-leave-to    { transform: translateX(60px); }
[dir="rtl"] .onb-slide-prev-enter-from  { transform: translateX(60px); }
[dir="rtl"] .onb-slide-prev-leave-to    { transform: translateX(-60px); }

/* Fade out whole component */
.onb-fade-leave-active {
  transition: opacity 0.6s ease;
}

.onb-fade-leave-to {
  opacity: 0;
}

/* Skip confirmation dialog */
.onb-dialog-backdrop {
  position: fixed;
  inset: 0;
  z-index: 10001;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
}

.onb-dialog {
  background: var(--lnc-bg-surface, #141926);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 16px;
  padding: 2rem;
  max-width: 400px;
  width: calc(100% - 48px);
}

.onb-dialog__text {
  font-size: 1rem;
  color: var(--lnc-text-primary, #e8ecf4);
  line-height: 1.6;
  margin: 0 0 1.5rem;
}

.onb-dialog__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
}

/* Shared button styles (also used by sub-components via global scope) */
@media (prefers-reduced-motion: reduce) {
  .onb-slide-next-enter-active,
  .onb-slide-next-leave-active,
  .onb-slide-prev-enter-active,
  .onb-slide-prev-leave-active {
    transition-duration: 0.05s;
  }

  .onb-slide-next-enter-from,
  .onb-slide-next-leave-to,
  .onb-slide-prev-enter-from,
  .onb-slide-prev-leave-to {
    transform: none;
  }
}
</style>

<style>
/* Unscoped shared styles for onboarding sub-components */
.onb-screen {
  position: relative;
  width: 100%;
  height: 100%;
}

.onb-btn {
  appearance: none;
  border: none;
  border-radius: 10px;
  padding: 10px 22px;
  font: inherit;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s, opacity 0.15s, transform 0.1s;
}

.onb-btn:active {
  transform: scale(0.97);
}

.onb-btn:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.onb-btn--primary {
  background: var(--lnc-cyan, #06b6d4);
  color: var(--lnc-bg-canvas, #0a0e17);
}

.onb-btn--primary:hover:not(:disabled) {
  filter: brightness(1.15);
}

.onb-btn--ghost {
  background: transparent;
  color: rgba(255, 255, 255, 0.5);
}

.onb-btn--ghost:hover {
  color: rgba(255, 255, 255, 0.8);
}

@media (prefers-reduced-motion: reduce) {
  .onb-btn {
    transition-duration: 0.05s;
  }
}
</style>
