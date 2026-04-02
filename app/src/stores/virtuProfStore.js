import { defineStore, getActivePinia } from 'pinia'

export const useVirtuProfStore = defineStore('virtuProf', {
  state: () => ({
    pendingTrigger: null,
    context: null,
    examMode: false,
    refreshDuelInvites: 0,
    explainQuestion: null,
    guidePayload: null,
    voiceSettingsPayload: null,
    voiceSettingsVersion: 0,
  }),
  actions: {
    trigger(type, payload = {}) {
      this.pendingTrigger = { type, payload, ts: Date.now() }
    },
    updateContext(ctx = {}) {
      this.context = ctx
    },
    setExamMode(active) {
      this.examMode = !!active
    },
    refreshInvites() {
      this.refreshDuelInvites += 1
    },
    explainLastQuestion(data = {}) {
      this.explainQuestion = { ...data, ts: Date.now() }
    },
    guide(payload = {}) {
      this.guidePayload = { ...payload, ts: Date.now() }
    },
    voiceSettingsChanged(payload = {}) {
      this.voiceSettingsPayload = { ...payload }
      this.voiceSettingsVersion += 1
    },
    consumeTrigger() {
      this.pendingTrigger = null
    },
    consumeExplain() {
      this.explainQuestion = null
    },
    consumeGuide() {
      this.guidePayload = null
    },
  },
})

export function useOptionalVirtuProfStore() {
  const pinia = getActivePinia()
  return pinia ? useVirtuProfStore(pinia) : null
}
