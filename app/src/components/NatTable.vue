<template>
  <section class="sim-tool nat-tool" :class="{ 'sim-tool--embedded': isEmbedded }">
    <header v-if="!isEmbedded" class="sim-tool__header">
      <div>
        <p class="sim-tool__eyebrow">{{ t('learning', 'CompTIA Network+ N10-009') }}</p>
        <h2 class="sim-tool__title">{{ t('learning', 'NAT-Tabelle') }}</h2>
        <p class="sim-tool__subtitle">{{ t('learning', 'Vergleiche Static NAT, Dynamic NAT und PAT direkt an einem uebersetzten Paketfluss.') }}</p>
      </div>
    </header>

    <nav v-if="!isEmbedded" class="sim-tool__tabs" role="tablist">
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'simulator' }" @click="view = 'simulator'">{{ t('learning', 'Simulator') }}</button>
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'exercise' }" @click="view = 'exercise'">{{ t('learning', 'Uebung') }}</button>
    </nav>

    <section v-if="currentView === 'simulator'" class="sim-tool__panel">
      <div class="sim-tool__grid">
        <label class="sim-tool__field">
          <span>{{ t('learning', 'NAT-Typ') }}</span>
          <select v-model="natType" class="sim-tool__input">
            <option value="static">Static NAT</option>
            <option value="dynamic">Dynamic NAT</option>
            <option value="pat">PAT / Overload</option>
          </select>
        </label>
        <label class="sim-tool__field">
          <span>{{ t('learning', 'Inside Source') }}</span>
          <input v-model.trim="packet.sourceIp" class="sim-tool__input" type="text">
        </label>
        <label class="sim-tool__field">
          <span>{{ t('learning', 'Source Port') }}</span>
          <input v-model.number="packet.sourcePort" class="sim-tool__input" type="number" min="0">
        </label>
        <label class="sim-tool__field">
          <span>{{ t('learning', 'Outside Destination') }}</span>
          <input v-model.trim="packet.destinationIp" class="sim-tool__input" type="text">
        </label>
        <label class="sim-tool__field">
          <span>{{ t('learning', 'Destination Port') }}</span>
          <input v-model.number="packet.destinationPort" class="sim-tool__input" type="number" min="0">
        </label>
      </div>

      <button class="sim-tool__btn" type="button" @click="simulateCurrent">{{ t('learning', 'Uebersetzen') }}</button>

      <div v-if="translation" class="nat-tool__flow">
        <article class="nat-tool__stage">
          <span>{{ t('learning', 'Inside') }}</span>
          <strong>{{ visualization.inside }}</strong>
        </article>
        <article class="nat-tool__stage nat-tool__stage--device">
          <span>NAT Device</span>
          <strong>{{ visualization.device }}</strong>
        </article>
        <article class="nat-tool__stage">
          <span>{{ t('learning', 'Outside') }}</span>
          <strong>{{ visualization.outside }}</strong>
        </article>
      </div>

      <NcNoteCard v-if="translation" :type="translation.status === 'translated' ? 'success' : 'error'">
        {{ translation.status === 'translated'
          ? t('learning', 'Die NAT-Uebersetzung wurde erfolgreich aufgebaut.')
          : t('learning', 'Keine Uebersetzung moeglich: Pool oder Mapping pruefen.') }}
      </NcNoteCard>

      <div v-if="translation && translation.table.length" class="sim-tool__table-wrap">
        <table class="sim-tool__table">
          <thead>
            <tr>
              <th>{{ t('learning', 'Inside Local') }}</th>
              <th>{{ t('learning', 'Inside Global') }}</th>
              <th>{{ t('learning', 'Outside Local') }}</th>
              <th>{{ t('learning', 'Outside Global') }}</th>
              <th>{{ t('learning', 'Protocol') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(entry, index) in translation.table" :key="`nat-entry-${index}`">
              <td>{{ entry.insideLocal }}<template v-if="entry.insidePort">:{{ entry.insidePort }}</template></td>
              <td>{{ entry.insideGlobal }}<template v-if="entry.insideGlobalPort">:{{ entry.insideGlobalPort }}</template></td>
              <td>{{ entry.outsideLocal }}</td>
              <td>{{ entry.outsideGlobal }}</td>
              <td>{{ entry.protocol }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-else class="sim-tool__panel">
      <div v-if="!isEmbedded" class="sim-tool__scenario-grid">
        <button v-for="entry in scenarios" :key="entry.id" class="sim-tool__scenario" :class="{ 'sim-tool__scenario--active': activeScenario && activeScenario.id === entry.id }" @click="loadScenario(entry)">
          <strong>{{ entry.title }}</strong>
          <span>{{ entry.question }}</span>
        </button>
      </div>

      <NcEmptyContent
        v-if="!activeScenario"
        :name="t('learning', 'Noch keine NAT-Uebung')"
        :description="t('learning', 'Waehle ein NAT-Szenario aus.')"
      />

      <div v-else class="nat-tool__exercise">
        <h3>{{ activeScenario.title }}</h3>
        <p>{{ activeScenario.question }}</p>
        <div v-if="hairpinDestination" class="nat-tool__hint sim-tool__status sim-tool__status--info">
          Hairpin -> {{ hairpinDestination }}
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
  </section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import natScenarios from '../../data/nat_scenarios.json'
import { buildNatVisualization, findHairpinTranslation, simulateNat } from '../utils/natEngine.js'

export default {
  name: 'NatTable',
  components: {
    NcEmptyContent,
    NcNoteCard,
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
      natType: 'pat',
      packet: {
        sourceIp: '10.0.0.20',
        sourcePort: 53000,
        destinationIp: '198.51.100.50',
        destinationPort: 443,
        protocol: 'tcp',
      },
      translation: null,
      visualization: null,
      scenarios: natScenarios,
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
    hairpinDestination() {
      if (!this.activeScenario) {
        return null
      }
      const hairpin = findHairpinTranslation(this.activeScenario.packet, this.activeScenario.config.mappings || [])
      return hairpin ? hairpin.destinationIp : null
    },
  },
  created() {
    if (this.scenario) {
      this.loadScenario(this.scenario)
    }
  },
  methods: {
    simulateCurrent() {
      const config = this.natType === 'static'
        ? {
          type: 'static',
          mappings: [{ insideLocal: this.packet.sourceIp, insideGlobal: '203.0.113.10' }],
        }
        : this.natType === 'dynamic'
          ? {
            type: 'dynamic',
            pool: ['203.0.113.10', '203.0.113.11'],
          }
          : {
            type: 'pat',
            publicIp: '203.0.113.10',
          }
      this.translation = simulateNat(this.packet, config)
      this.visualization = buildNatVisualization(this.packet, this.translation)
      this.$emit('result', this.translation)
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
          ? t('learning', 'Richtig. Die NAT-Uebersetzung passt genau zu diesem Typ oder Symptom.')
          : t('learning', 'Noch nicht. Pruefe Mapping, Poolgroesse oder Port-Uebersetzung erneut.'),
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
.nat-tool__flow {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.75rem;
}
.nat-tool__stage {
  display: grid;
  gap: 0.35rem;
  padding: 1rem;
  border: 1px solid var(--sim-border);
  border-radius: 16px;
  background: var(--sim-panel-elevated);
  color: var(--sim-text);
}
.nat-tool__stage--device {
  background: linear-gradient(135deg, rgba(88, 166, 255, 0.12), rgba(0, 230, 118, 0.08));
  box-shadow: var(--sim-glow-accent);
}
.nat-tool__hint {
  padding: 0.75rem 1rem;
}

@media (max-width: 768px) {
  .nat-tool__flow {
    grid-template-columns: 1fr;
  }
}
</style>
