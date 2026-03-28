<template>
  <div class="practicum-runner">
    <!-- Phase: Session Selection -->
    <div v-if="phase === 'select'" class="practicum-select">
      <div
        v-for="session in sessions"
        :key="session.id"
        class="practicum-select__card"
        role="button"
        tabindex="0"
        @click="startSession(session)"
        @keydown.enter="startSession(session)"
      >
        <span class="practicum-select__title">{{ session.title }}</span>
        <span class="practicum-select__desc">{{ session.description }}</span>
        <div class="practicum-select__meta">
          <span class="practicum-select__badge">{{ session.steps.length }} {{ t('learning', 'Schritte') }}</span>
          <span v-if="hasResumable(session.id)" class="practicum-select__resume">
            {{ t('learning', 'Fortsetzen moeglich') }}
          </span>
        </div>
      </div>
      <p v-if="sessions.length === 0" class="practicum-select__empty">
        {{ t('learning', 'Noch keine Praxis-Sessions fuer diesen Simulator verfuegbar.') }}
      </p>
    </div>

    <!-- Phase: Running Session -->
    <div v-else-if="phase === 'running' && engine" class="practicum-running">
      <!-- Top bar -->
      <div class="practicum-topbar">
        <div class="practicum-topbar__row">
          <span class="practicum-topbar__title">{{ activeSession.title }}</span>
          <span class="practicum-topbar__step">
            {{ t('learning', 'Schritt {current} von {total}', { current: engine.stepIndex + 1, total: engine.totalSteps }) }}
          </span>
        </div>
        <div class="practicum-topbar__progress">
          <div class="practicum-topbar__progress-fill" :style="{ width: progressPercent + '%' }"></div>
        </div>
      </div>

      <!-- Transition overlay between steps -->
      <div v-if="showTransition" class="practicum-transition">
        <span class="practicum-transition__icon">{{ lastResult && lastResult.passed ? '✓' : '✗' }}</span>
        <span
          class="practicum-transition__result"
          :class="lastResult && lastResult.passed ? 'practicum-transition__result--pass' : 'practicum-transition__result--fail'"
        >
          {{ lastResult && lastResult.passed ? t('learning', 'Richtig!') : t('learning', 'Nicht ganz.') }}
        </span>
        <span class="practicum-transition__next">
          {{ t('learning', 'Weiter zu Schritt {next}', { next: engine.stepIndex + 1 }) }}
        </span>
        <button class="practicum-summary__btn practicum-summary__btn--primary" @click="showTransition = false">
          {{ t('learning', 'Weiter') }}
        </button>
      </div>

      <!-- Context panel (collapsible) -->
      <div v-if="!showTransition && engine.currentStep" class="practicum-context">
        <div class="practicum-context__header" @click="contextExpanded = !contextExpanded">
          <span class="practicum-context__label">{{ t('learning', 'Aufgabe') }}</span>
          <span class="practicum-context__toggle">{{ contextExpanded ? t('learning', 'Einklappen') : t('learning', 'Aufklappen') }}</span>
        </div>
        <div v-if="contextExpanded" class="practicum-context__body">
          <p class="practicum-context__text">{{ engine.currentStep.context }}</p>
          <p v-if="engine.currentStep.explanation" class="practicum-context__explanation">
            {{ engine.currentStep.explanation }}
          </p>
        </div>
      </div>

      <!-- Embedded simulator via SimulatorShell -->
      <SimulatorShell
        v-if="!showTransition && engine.currentStep"
        :key="engine.currentStep.scenarioId"
        :type="simulatorType"
        :scenario-id="engine.currentStep.scenarioId"
        @complete="onStepComplete"
      />
    </div>

    <!-- Phase: Summary -->
    <div v-else-if="phase === 'summary' && engine" class="practicum-summary">
      <h3 class="practicum-summary__title">{{ activeSession.title }}</h3>
      <div class="practicum-summary__score">{{ engine.summary.score }}</div>
      <p>{{ t('learning', 'richtig') }}</p>

      <div class="practicum-summary__steps">
        <div
          v-for="(step, idx) in activeSession.steps"
          :key="step.stepId"
          class="practicum-summary__step"
        >
          <span
            class="practicum-summary__step-icon"
            :class="stepResultIcon(idx).cls"
          >{{ stepResultIcon(idx).icon }}</span>
          <span>{{ step.context }}</span>
        </div>
      </div>

      <div class="practicum-summary__actions">
        <button class="practicum-summary__btn practicum-summary__btn--primary" @click="restartSession">
          {{ t('learning', 'Nochmal') }}
        </button>
        <button class="practicum-summary__btn" @click="backToSelect">
          {{ t('learning', 'Zurueck') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import SimulatorShell from './SimulatorShell.vue'
import { PracticumEngine, loadSessionsForSimulator } from '../utils/practicumEngine.js'

export default {
  name: 'PracticumRunner',
  components: {
    SimulatorShell,
  },
  props: {
    simulatorType: {
      type: String,
      required: true,
    },
  },
  data() {
    return {
      phase: 'select',
      sessions: [],
      activeSession: null,
      engine: null,
      contextExpanded: true,
      showTransition: false,
      lastResult: null,
    }
  },
  computed: {
    progressPercent() {
      if (!this.engine) return 0
      return (this.engine.stepIndex / this.engine.totalSteps) * 100
    },
  },
  created() {
    this.sessions = loadSessionsForSimulator(this.simulatorType)
  },
  methods: {
    hasResumable(sessionId) {
      const session = this.sessions.find(s => s.id === sessionId)
      if (!session) return false
      const restored = PracticumEngine.restore(sessionId, session)
      return restored !== null && !restored.isComplete
    },
    startSession(session) {
      this.activeSession = session
      const restored = PracticumEngine.restore(session.id, session)
      if (restored && !restored.isComplete) {
        this.engine = restored
      } else {
        this.engine = new PracticumEngine(session)
      }
      this.contextExpanded = true
      this.showTransition = false
      this.lastResult = null
      this.phase = 'running'
    },
    onStepComplete(passed, score) {
      if (!this.engine) return
      this.lastResult = { passed, score }
      this.engine.recordResult({ passed, score })
      this.engine.persist()

      if (this.engine.isComplete) {
        this.phase = 'summary'
      } else {
        this.showTransition = true
        this.contextExpanded = true
      }
    },
    restartSession() {
      if (!this.engine) return
      this.engine.reset()
      this.showTransition = false
      this.lastResult = null
      this.contextExpanded = true
      this.phase = 'running'
    },
    backToSelect() {
      this.engine = null
      this.activeSession = null
      this.showTransition = false
      this.lastResult = null
      this.phase = 'select'
    },
    stepResultIcon(idx) {
      if (!this.engine) return { icon: '-', cls: '' }
      const results = this.engine.summary.results
      if (idx >= results.length) return { icon: '-', cls: '' }
      return results[idx].passed
        ? { icon: '✓', cls: 'practicum-summary__step-icon--pass' }
        : { icon: '✗', cls: 'practicum-summary__step-icon--fail' }
    },
  },
}
</script>
