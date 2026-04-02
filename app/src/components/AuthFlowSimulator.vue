<template>
  <section class="sim-tool" :class="{ 'sim-tool--embedded': isEmbedded }">
    <header v-if="!isEmbedded" class="sim-tool__header">
      <div>
        <p class="sim-tool__eyebrow">{{ t('learning', 'CompTIA Network+ N10-009') }}</p>
        <h2 class="sim-tool__title">{{ t('learning', '802.1X Auth-Flow') }}</h2>
        <p class="sim-tool__subtitle">{{ t('learning', 'Vergleiche EAP-TLS, PEAP und EAP-FAST und bringe die 802.1X-Sequenz in die richtige Reihenfolge.') }}</p>
      </div>
    </header>

    <nav v-if="!isEmbedded" class="sim-tool__tabs" role="tablist">
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'simulator' }" @click="view = 'simulator'">{{ t('learning', 'Simulator') }}</button>
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'exercise' }" @click="view = 'exercise'">{{ t('learning', 'Übung') }}</button>
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'practicum' }" @click="view = 'practicum'">{{ t('learning', 'Praxis') }}</button>
    </nav>

    <section v-if="currentView === 'simulator'" class="sim-tool__panel">
      <div class="sim-tool__methods">
        <button
          v-for="entry in methodComparison"
          :key="entry.method"
          class="sim-tool__tab sim-tool__method"
          :class="{ 'sim-tool__tab--active': activeMethodLabel === entry.method }"
          @click="activeMethod = methodIdFromLabel(entry.method)"
        >
          {{ entry.method }}
        </button>
      </div>

      <div class="sim-tool__actors">
        <span>Client</span>
        <span>Switch</span>
        <span>RADIUS</span>
      </div>
      <ol class="sim-tool__sequence">
        <li v-for="step in sequence" :key="step.id" class="sim-tool__step">
          <strong>{{ step.actorFrom }} -> {{ step.actorTo }}</strong>
          <span>{{ step.label }}</span>
          <small>{{ step.detail }}</small>
        </li>
      </ol>

      <div class="sim-tool__table-wrap">
        <table class="sim-tool__table">
          <thead>
            <tr>
              <th>{{ t('learning', 'Methode') }}</th>
              <th>{{ t('learning', 'Client-Zertifikat') }}</th>
              <th>{{ t('learning', 'Server-Zertifikat') }}</th>
              <th>{{ t('learning', 'Tunnel') }}</th>
              <th>{{ t('learning', 'Sicherheit') }}</th>
              <th>{{ t('learning', 'Aufwand') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in methodComparison" :key="entry.method">
              <td>{{ entry.method }}</td>
              <td>{{ entry.clientCertificate }}</td>
              <td>{{ entry.serverCertificate }}</td>
              <td>{{ entry.tunnel }}</td>
              <td>{{ entry.security }}</td>
              <td>{{ entry.effort }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-else-if="currentView === 'exercise'" class="sim-tool__panel">
      <div v-if="!isEmbedded" class="sim-tool__scenario-grid">
        <button v-for="entry in scenarios" :key="entry.id" class="sim-tool__scenario" :class="{ 'sim-tool__scenario--active': activeScenario && activeScenario.id === entry.id }" @click="loadScenario(entry)">
          <strong>{{ entry.title }}</strong>
          <span>{{ entry.question }}</span>
        </button>
      </div>

      <NcEmptyContent
        v-if="!activeScenario"
        :name="t('learning', 'Noch keine 802.1X-Übung')"
        :description="t('learning', 'Wähle ein Auth-Flow-Szenario aus.')"
      />

      <div v-else class="sim-tool__exercise">
        <h3>{{ activeScenario.title }}</h3>
        <p>{{ activeScenario.question }}</p>
        <ol class="sim-tool__reorder">
          <li v-for="(step, index) in exerciseSteps" :key="step.id" class="sim-tool__reorder-item">
            <div>
              <strong>{{ step.label }}</strong>
              <small>{{ step.actorFrom }} -> {{ step.actorTo }}</small>
            </div>
            <div class="sim-tool__reorder-actions">
              <button class="sim-tool__btn sim-tool__btn--secondary sim-tool__btn--icon" type="button" :disabled="index === 0" :aria-label="t('learning', 'Schritt nach oben verschieben')" @click="moveStep(index, index - 1)">↑</button>
              <button class="sim-tool__btn sim-tool__btn--secondary sim-tool__btn--icon" type="button" :disabled="index === exerciseSteps.length - 1" :aria-label="t('learning', 'Schritt nach unten verschieben')" @click="moveStep(index, index + 1)">↓</button>
            </div>
          </li>
        </ol>
        <button class="sim-tool__btn" type="button" @click="checkSequence">{{ t('learning', 'Reihenfolge prüfen') }}</button>
        <NcNoteCard v-if="validation" :type="validation.isCorrect ? 'success' : 'error'">
          {{ validation.isCorrect
            ? t('learning', 'Perfekt. Die 802.1X-Sequenz ist korrekt.')
            : t('learning', 'Noch nicht korrekt. Richtige Positionen: {count}/8', { count: validation.correctCount }) }}
        </NcNoteCard>
      </div>
    </section>

    <section v-else-if="currentView === 'practicum'" class="sim-tool__panel">
      <PracticumRunner simulator-type="authflow" />
    </section>
  </section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import PracticumRunner from './PracticumRunner.vue'
import authScenarios from '../../data/authflow_scenarios.json'
import {
  buildAuthSequence,
  buildShuffledSequence,
  getMethodComparison,
  validateSequence,
} from '../utils/authFlowEngine.js'

export default {
  name: 'AuthFlowSimulator',
  components: {
    NcEmptyContent,
    NcNoteCard,
    PracticumRunner,
  },
  props: {
    mode: {
      type: String,
      default: 'standalone',
    },
    scenario: {
      type: Object,
      default: null,
    },
  },
  data() {
    return {
      view: this.mode === 'embedded' ? 'exercise' : 'simulator',
      activeMethod: 'eap-tls',
      methodComparison: getMethodComparison(),
      scenarios: authScenarios,
      activeScenario: null,
      exerciseSteps: [],
      validation: null,
    }
  },
  computed: {
    isEmbedded() {
      return this.mode === 'embedded'
    },
    currentView() {
      return this.isEmbedded ? 'exercise' : this.view
    },
    sequence() {
      return buildAuthSequence(this.activeMethod)
    },
    activeMethodLabel() {
      return this.methodComparison.find((entry) => this.methodIdFromLabel(entry.method) === this.activeMethod)?.method
    },
  },
  created() {
    if (this.scenario) {
      this.loadScenario(this.scenario)
    }
  },
  beforeDestroy() {
    // Keine aktiven Timer oder globalen Event-Listener registriert.
    // Hook vorhanden für sauberes Embedded-Rendering in SimulatorShell.
  },
  methods: {
    methodIdFromLabel(label) {
      return String(label || '').trim().toLowerCase()
    },
    loadScenario(entry) {
      this.activeScenario = entry
      this.exerciseSteps = buildShuffledSequence(entry.method).map((step) => ({ ...step }))
      this.validation = null
    },
    moveStep(fromIndex, toIndex) {
      const next = [...this.exerciseSteps]
      const [item] = next.splice(fromIndex, 1)
      next.splice(toIndex, 0, item)
      this.exerciseSteps = next
    },
    checkSequence() {
      this.validation = validateSequence(this.exerciseSteps.map((step) => step.id), this.activeScenario.method)
      this.$emit('result', this.validation)
    },
  },
}
</script>

<style scoped>
.sim-tool__methods,
.sim-tool__actors {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}
.sim-tool__actors {
  justify-content: space-between;
  color: var(--sim-text-muted);
  font-family: var(--sim-text-mono);
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.sim-tool__sequence,
.sim-tool__reorder {
  display: grid;
  gap: 0.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}
.sim-tool__step,
.sim-tool__reorder-item {
  display: grid;
  gap: 0.3rem;
  padding: 0.95rem;
  border: 1px solid var(--sim-border);
  border-radius: 16px;
  background: var(--sim-panel-elevated);
  color: var(--sim-text);
}
.sim-tool__reorder-item {
  grid-template-columns: 1fr auto;
  align-items: center;
}
.sim-tool__reorder-actions {
  display: flex;
  gap: 0.4rem;
}

@media (max-width: 768px) {
  .sim-tool__reorder-item {
    grid-template-columns: 1fr;
  }
}
</style>
