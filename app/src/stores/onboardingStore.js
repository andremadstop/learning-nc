import { defineStore, getActivePinia } from 'pinia'

const STEPS = ['splash', 'role', 'tour', 'privacy', 'profile', 'jumpstart', 'hook', 'done']

function getUid() {
  return (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function' && OC.getCurrentUser()?.uid) || 'user'
}

function storageKey() {
  return `learning:onboarding-progress:${getUid()}`
}

export const useOnboardingStore = defineStore('onboarding', {
  state: () => ({
    step: 'splash',
    role: null,
    tourSlideIndex: 0,
    profileChoices: { goal: null, intensity: null, aiConsent: null },
    selectedStarterPoolId: null,
    skipped: false,
  }),

  actions: {
    setStep(step) {
      if (STEPS.includes(step)) {
        this.step = step
        this.persist()
      }
    },

    setRole(role) {
      this.role = role
      this.persist()
    },

    setTourSlide(idx) {
      this.tourSlideIndex = idx
      this.persist()
    },

    setProfileChoice(key, value) {
      if (key in this.profileChoices) {
        this.profileChoices[key] = value
        this.persist()
      }
    },

    setStarterPool(id) {
      this.selectedStarterPoolId = id
      this.persist()
    },

    skip() {
      this.skipped = true
      this.step = 'done'
      this.persist()
    },

    reset() {
      this.step = 'splash'
      this.role = null
      this.tourSlideIndex = 0
      this.profileChoices = { goal: null, intensity: null, aiConsent: null }
      this.selectedStarterPoolId = null
      this.skipped = false
      try {
        window.localStorage.removeItem(storageKey())
      } catch {
        // Ignore
      }
    },

    hydrate() {
      try {
        const raw = window.localStorage.getItem(storageKey())
        if (!raw) return
        const data = JSON.parse(raw)
        if (data.step && STEPS.includes(data.step)) this.step = data.step
        if (data.role) this.role = data.role
        if (typeof data.tourSlideIndex === 'number') this.tourSlideIndex = data.tourSlideIndex
        if (data.profileChoices && typeof data.profileChoices === 'object') {
          this.profileChoices = { ...this.profileChoices, ...data.profileChoices }
        }
        if (data.selectedStarterPoolId != null) this.selectedStarterPoolId = data.selectedStarterPoolId
        if (data.skipped === true) this.skipped = true
      } catch {
        // Ignore corrupt data
      }
    },

    persist() {
      try {
        window.localStorage.setItem(storageKey(), JSON.stringify({
          step: this.step,
          role: this.role,
          tourSlideIndex: this.tourSlideIndex,
          profileChoices: this.profileChoices,
          selectedStarterPoolId: this.selectedStarterPoolId,
          skipped: this.skipped,
        }))
      } catch {
        // Ignore quota errors
      }
    },
  },
})

export function useOptionalOnboardingStore() {
  const pinia = getActivePinia()
  return pinia ? useOnboardingStore(pinia) : null
}
