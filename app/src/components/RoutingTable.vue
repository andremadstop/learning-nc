<template>
  <section class="sim-tool" :class="{ 'sim-tool--embedded': isEmbedded }">
    <header v-if="!isEmbedded" class="sim-tool__header">
      <div>
        <p class="sim-tool__eyebrow">{{ t('learning', 'CompTIA Network+ N10-009') }}</p>
        <h2 class="sim-tool__title">{{ t('learning', 'Routing-Tabelle') }}</h2>
        <p class="sim-tool__subtitle">{{ t('learning', 'Prüfe Longest Prefix Match, Default-Routes und Metriken mit einer editierbaren Tabelle.') }}</p>
      </div>
    </header>

    <nav v-if="!isEmbedded" class="sim-tool__tabs" role="tablist">
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'simulator' }" @click="view = 'simulator'">{{ t('learning', 'Simulator') }}</button>
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'exercise' }" @click="view = 'exercise'">{{ t('learning', 'Übung') }}</button>
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'practicum' }" @click="view = 'practicum'">{{ t('learning', 'Praxis') }}</button>
    </nav>

    <section v-if="currentView === 'simulator'" class="sim-tool__panel">
      <div class="sim-tool__section-header">
        <h3>{{ t('learning', 'Routen') }}</h3>
        <button class="sim-tool__btn sim-tool__btn--secondary" type="button" @click="addRoute">{{ t('learning', 'Route hinzufügen') }}</button>
      </div>
      <div class="sim-tool__table-wrap">
        <table class="sim-tool__table">
          <thead>
            <tr>
              <th>{{ t('learning', 'Destination') }}</th>
              <th>{{ t('learning', 'Mask') }}</th>
              <th>{{ t('learning', 'Gateway') }}</th>
              <th>{{ t('learning', 'Interface') }}</th>
              <th>{{ t('learning', 'Metric') }}</th>
              <th>{{ t('learning', 'Type') }}</th>
              <th>{{ t('learning', 'Aktion') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(route, index) in routes"
              :key="`route-${index}`"
              :class="{ 'sim-tool__row--matched': decision && decision.bestRoute && decision.bestRoute.destination === route.destination && decision.bestRoute.mask === route.mask }"
            >
              <td><input v-model.trim="route.destination" class="sim-tool__input" type="text"></td>
              <td><input v-model.trim="route.mask" class="sim-tool__input" type="text"></td>
              <td><input v-model.trim="route.gateway" class="sim-tool__input" type="text"></td>
              <td><input v-model.trim="route.interface" class="sim-tool__input" type="text"></td>
              <td><input v-model.number="route.metric" class="sim-tool__input" type="number" min="0"></td>
              <td>
                <select v-model="route.type" class="sim-tool__input">
                  <option value="connected">Connected</option>
                  <option value="static">Static</option>
                  <option value="default">Default</option>
                </select>
              </td>
              <td>
                <button class="sim-tool__btn sim-tool__btn--secondary" type="button" :disabled="routes.length === 1" @click="removeRoute(index)">
                  {{ t('learning', 'Entfernen') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="sim-tool__lookup">
        <label class="sim-tool__field">
          <span>{{ t('learning', 'Ziel-IP') }}</span>
          <input v-model.trim="destinationIp" class="sim-tool__input" type="text">
        </label>
        <button class="sim-tool__btn" type="button" @click="runLookup">{{ t('learning', 'Route suchen') }}</button>
      </div>

      <div v-if="decision" class="sim-tool__decision">
        <div class="sim-tool__matches">
          <article v-for="route in decision.evaluatedRoutes" :key="`${route.destination}-${route.mask}-${route.gateway}`" class="sim-tool__match" :class="{ 'sim-tool__match--active': route.matched, 'sim-tool__match--winner': decision.bestRoute && decision.bestRoute.destination === route.destination && decision.bestRoute.mask === route.mask }">
            <strong>{{ route.destination }}/{{ route.prefix }}</strong>
            <span>{{ route.gateway }} via {{ route.interface }}</span>
            <small>metric {{ route.metric }}</small>
          </article>
        </div>
        <NcNoteCard :type="decision.bestRoute ? 'success' : 'error'">
          <template v-if="decision.bestRoute">
            {{ t('learning', 'Beste Route: {destination}/{prefix} über {gateway}', { destination: decision.bestRoute.destination, prefix: decision.bestRoute.prefix, gateway: decision.bestRoute.gateway }) }}
          </template>
          <template v-else>
            {{ t('learning', 'Kein Match gefunden: Destination Unreachable') }}
          </template>
        </NcNoteCard>
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
        :name="t('learning', 'Noch keine Routing-Übung')"
        :description="t('learning', 'Wähle ein Szenario oder übergib eins eingebettet.')"
      />

      <div v-else class="sim-tool__exercise">
        <h3>{{ activeScenario.title }}</h3>
        <p>{{ activeScenario.question }}</p>
        <div class="sim-tool__matches">
          <article v-for="route in scenarioDecision.evaluatedRoutes" :key="`${route.destination}-${route.mask}-${route.gateway}`" class="sim-tool__match" :class="{ 'sim-tool__match--active': route.matched, 'sim-tool__match--winner': scenarioDecision.bestRoute && scenarioDecision.bestRoute.destination === route.destination && scenarioDecision.bestRoute.mask === route.mask }">
            <strong>{{ route.destination }}/{{ route.prefix }}</strong>
            <span>{{ route.gateway }} via {{ route.interface }}</span>
            <small>metric {{ route.metric }}</small>
          </article>
        </div>
        <div class="sim-tool__options">
          <button v-for="option in activeScenario.options" :key="option.id" class="sim-tool__option" @click="answerScenario(option.id)">
            {{ option.label }}
          </button>
        </div>
        <NcNoteCard v-if="feedback" :type="feedback.correct ? 'success' : 'error'">
          {{ feedback.message }}
        </NcNoteCard>
      </div>
    </section>

    <section v-else-if="currentView === 'practicum'" class="sim-tool__panel">
      <PracticumRunner simulator-type="routing" />
    </section>
  </section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import PracticumRunner from './PracticumRunner.vue'
import routingScenarios from '../../data/routing_scenarios.json'
import { buildRoutingDecision, createDefaultRoute } from '../utils/routingEngine.js'

export default {
  name: 'RoutingTable',
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
      routes: [
        {
          destination: '192.168.10.0',
          mask: '255.255.255.0',
          gateway: '10.0.0.1',
          interface: 'Gi0/1',
          metric: 10,
          type: 'static',
        },
        createDefaultRoute({
          gateway: '192.168.1.1',
          interface: 'Gi0/0',
        }),
      ],
      destinationIp: '192.168.10.44',
      decision: null,
      scenarios: routingScenarios,
      activeScenario: null,
      feedback: null,
    }
  },
  computed: {
    isEmbedded() {
      return this.mode === 'embedded'
    },
    currentView() {
      return this.isEmbedded ? 'exercise' : this.view
    },
    scenarioDecision() {
      if (!this.activeScenario) {
        return null
      }
      return buildRoutingDecision(this.activeScenario.routes, this.activeScenario.destinationIp)
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
    addRoute() {
      this.routes.push({
        destination: '10.0.0.0',
        mask: '255.255.255.0',
        gateway: '10.0.1.1',
        interface: 'Gi0/2',
        metric: 20,
        type: 'static',
      })
    },
    removeRoute(index) {
      this.routes.splice(index, 1)
    },
    runLookup() {
      this.decision = buildRoutingDecision(this.routes, this.destinationIp)
      this.$emit('result', this.decision)
    },
    loadScenario(entry) {
      this.activeScenario = entry
      this.feedback = null
    },
    answerScenario(optionId) {
      const correct = optionId === this.activeScenario.answer
      this.feedback = {
        correct,
        message: correct
          ? t('learning', 'Richtig. Longest Prefix Match und Metrik führen genau zu diesem Ergebnis.')
          : t('learning', 'Noch nicht. Vergleiche Präfixlänge, dann erst die Metrik.'),
      }
      this.$emit('result', {
        scenarioId: this.activeScenario.id,
        correct,
      })
    },
  },
}
</script>

<style scoped>
.sim-tool__lookup {
  display: flex;
  gap: 0.75rem;
  align-items: end;
  flex-wrap: wrap;
}
.sim-tool__matches {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 0.75rem;
}
.sim-tool__match {
  display: grid;
  gap: 0.3rem;
  padding: 0.95rem;
  border: 1px solid var(--sim-border);
  border-radius: 16px;
  background: var(--sim-panel-elevated);
  text-align: left;
  color: var(--sim-text);
}
.sim-tool__match--active {
  border-color: var(--sim-success);
}
.sim-tool__match--winner {
  background: rgba(0, 230, 118, 0.1);
  box-shadow: var(--sim-glow-pass);
}

@media (max-width: 768px) {
  .sim-tool__lookup {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
