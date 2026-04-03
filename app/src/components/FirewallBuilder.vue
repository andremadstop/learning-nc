<template>
  <section class="sim-tool" :class="{ 'sim-tool--embedded': isEmbedded }">
    <header v-if="!isEmbedded" class="sim-tool__header">
      <div>
        <p class="sim-tool__eyebrow">{{ t('learning', 'CompTIA Network+ N10-009') }}</p>
        <h2 class="sim-tool__title">{{ t('learning', 'Firewall / ACL Builder') }}</h2>
        <p class="sim-tool__subtitle">{{ t('learning', 'Baue Regelwerke, teste Pakete und erkenne First-Match-Effekte direkt im Browser.') }}</p>
      </div>
    </header>

    <nav v-if="!isEmbedded" class="sim-tool__tabs" role="tablist" :aria-label="t('learning', 'Firewall Tabs')">
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'simulator' }" @click="view = 'simulator'">
        {{ t('learning', 'Simulator') }}
      </button>
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'exercise' }" @click="view = 'exercise'">
        {{ t('learning', 'Übung') }}
      </button>
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'practicum' }" @click="view = 'practicum'">
        {{ t('learning', 'Praxis') }}
      </button>
    </nav>

    <section v-if="currentView === 'simulator'" class="sim-tool__panel">
      <div class="sim-tool__section-header">
        <h3>{{ t('learning', 'Regeltabelle') }}</h3>
        <button class="sim-tool__btn sim-tool__btn--secondary" type="button" @click="addRule">
          {{ t('learning', 'Regel hinzufügen') }}
        </button>
      </div>

      <div class="sim-tool__table-wrap">
        <table class="sim-tool__table">
          <thead>
            <tr>
              <th>{{ t('learning', 'Drag') }}</th>
              <th>#</th>
              <th>{{ t('learning', 'Action') }}</th>
              <th>{{ t('learning', 'Protocol') }}</th>
              <th>{{ t('learning', 'Src IP') }}</th>
              <th>{{ t('learning', 'Src Port') }}</th>
              <th>{{ t('learning', 'Dst IP') }}</th>
              <th>{{ t('learning', 'Dst Port') }}</th>
              <th>{{ t('learning', 'Log') }}</th>
              <th>{{ t('learning', 'Aktion') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(rule, index) in rules"
              :key="rule.id"
              class="sim-tool__row"
              :class="[
                result && result.matchedRule.id === rule.id ? 'sim-tool__row--matched' : '',
                rule.action === 'allow' ? 'sim-tool__row--allow' : 'sim-tool__row--deny',
              ]"
              draggable="true"
              @dragstart="dragIndex = index"
              @dragover.prevent
              @drop.prevent="dropRule(index)"
            >
              <td class="sim-tool__drag-cell">
                <span class="sim-tool__drag-handle" aria-hidden="true">≡</span>
              </td>
              <td>{{ index + 1 }}</td>
              <td>
                <select v-model="rule.action" class="sim-tool__input">
                  <option value="allow">Allow</option>
                  <option value="deny">Deny</option>
                </select>
              </td>
              <td>
                <select v-model="rule.protocol" class="sim-tool__input">
                  <option value="any">Any</option>
                  <option value="tcp">TCP</option>
                  <option value="udp">UDP</option>
                  <option value="icmp">ICMP</option>
                </select>
              </td>
              <td><input v-model.trim="rule.srcIp" class="sim-tool__input" type="text"></td>
              <td><input v-model.trim="rule.srcPort" class="sim-tool__input" type="text"></td>
              <td><input v-model.trim="rule.dstIp" class="sim-tool__input" type="text"></td>
              <td><input v-model.trim="rule.dstPort" class="sim-tool__input" type="text"></td>
              <td><input v-model="rule.log" class="sim-tool__checkbox" type="checkbox"></td>
              <td>
                <button class="sim-tool__btn sim-tool__btn--secondary" type="button" :disabled="rules.length === 1" @click="removeRule(index)">
                  {{ t('learning', 'Entfernen') }}
                </button>
              </td>
            </tr>
            <tr class="sim-tool__row sim-tool__row--implicit sim-tool__row--deny" :class="{ 'sim-tool__row--matched': result && result.matchedRule.id === 'implicit-deny' }">
              <td class="sim-tool__drag-cell"><span class="sim-tool__drag-placeholder" aria-hidden="true"></span></td>
              <td>{{ rules.length + 1 }}</td>
              <td>Deny</td>
              <td>Any</td>
              <td>any</td>
              <td>any</td>
              <td>any</td>
              <td>any</td>
              <td>false</td>
              <td>{{ t('learning', 'Implicit') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="sim-tool__section-header sim-tool__section-header--split">
        <h3>{{ t('learning', 'Paket-Test') }}</h3>
        <button class="sim-tool__btn" type="button" @click="evaluateCurrentPacket">
          {{ t('learning', 'Paket prüfen') }}
        </button>
      </div>

      <div class="sim-tool__grid">
        <label class="sim-tool__field">
          <span>Src IP</span>
          <input v-model.trim="packet.srcIp" class="sim-tool__input" type="text">
        </label>
        <label class="sim-tool__field">
          <span>Src Port</span>
          <input v-model.number="packet.srcPort" class="sim-tool__input" type="number" min="0">
        </label>
        <label class="sim-tool__field">
          <span>Dst IP</span>
          <input v-model.trim="packet.dstIp" class="sim-tool__input" type="text">
        </label>
        <label class="sim-tool__field">
          <span>Dst Port</span>
          <input v-model.number="packet.dstPort" class="sim-tool__input" type="number" min="0">
        </label>
        <label class="sim-tool__field">
          <span>{{ t('learning', 'Protocol') }}</span>
          <select v-model="packet.protocol" class="sim-tool__input">
            <option value="tcp">TCP</option>
            <option value="udp">UDP</option>
            <option value="icmp">ICMP</option>
          </select>
        </label>
      </div>

      <div v-if="result" class="sim-tool__result">
        <div class="sim-tool__packet-path">
          <span v-for="entry in result.trace" :key="entry.rule.id" class="sim-tool__packet-hop" :class="{ 'sim-tool__packet-hop--hit': entry.matched }">
            {{ entry.rule.id }}
          </span>
        </div>
        <NcNoteCard :type="result.action === 'allow' ? 'success' : 'error'">
          {{ t('learning', 'Ergebnis: {action} über {rule}', { action: result.action.toUpperCase(), rule: result.matchedRule.id }) }}
        </NcNoteCard>
      </div>
    </section>

    <section v-else-if="currentView === 'exercise'" class="sim-tool__panel">
      <div v-if="!isEmbedded" class="sim-tool__scenario-grid">
        <button
          v-for="entry in scenarios"
          :key="entry.id"
          class="sim-tool__scenario"
          :class="{ 'sim-tool__scenario--active': activeScenario && activeScenario.id === entry.id }"
          @click="loadScenario(entry)"
        >
          <strong>{{ entry.title }}</strong>
          <span>{{ entry.question }}</span>
        </button>
      </div>

      <NcEmptyContent
        v-if="!activeScenario"
        :name="t('learning', 'Noch kein Firewall-Szenario')"
        :description="t('learning', 'Wähle eine Übung aus oder übergib ein eingebettetes Szenario.')"
      />

      <div v-else class="sim-tool__exercise">
        <h3>{{ activeScenario.title }}</h3>
        <p>{{ activeScenario.question }}</p>
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
      <PracticumRunner simulator-type="firewall" />
    </section>
  </section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import PracticumRunner from './PracticumRunner.vue'
import firewallScenarios from '../../data/firewall_scenarios.json'
import { createRule, evaluatePacket, reorderRules } from '../utils/firewallEngine.js'

export default {
  name: 'FirewallBuilder',
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
      rules: [
        createRule({
          id: 'allow-web',
          action: 'allow',
          protocol: 'tcp',
          srcIp: 'any',
          srcPort: 'any',
          dstIp: '172.16.0.10',
          dstPort: '80,443',
          log: true,
        }),
      ],
      packet: {
        srcIp: '10.0.1.25',
        srcPort: 51515,
        dstIp: '172.16.0.10',
        dstPort: 443,
        protocol: 'tcp',
      },
      dragIndex: null,
      result: null,
      scenarios: firewallScenarios,
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
  },
  created() {
    if (this.scenario) {
      this.loadScenario(this.scenario)
    }
  },
  beforeUnmount() {
    // Keine aktiven Timer oder globalen Event-Listener registriert.
    // Hook vorhanden für sauberes Embedded-Rendering in SimulatorShell.
  },
  methods: {
    addRule() {
      this.rules.push(createRule({
        id: `rule-${this.rules.length + 1}`,
        action: 'deny',
        protocol: 'tcp',
        srcIp: 'any',
        srcPort: 'any',
        dstIp: 'any',
        dstPort: 'any',
        log: false,
      }))
    },
    removeRule(index) {
      this.rules.splice(index, 1)
    },
    dropRule(index) {
      this.rules = reorderRules(this.rules, this.dragIndex, index)
      this.dragIndex = null
    },
    evaluateCurrentPacket() {
      this.result = evaluatePacket(this.rules, this.packet)
      this.$emit('result', this.result)
    },
    loadScenario(entry) {
      this.activeScenario = entry
      this.rules = (entry.rules || []).map((rule, index) => createRule({ ...rule, id: rule.id || `scenario-rule-${index + 1}` }))
      this.packet = { ...entry.packet }
      this.feedback = null
      this.result = evaluatePacket(this.rules, this.packet)
    },
    answerScenario(optionId) {
      const correct = optionId === this.activeScenario.answer
      this.feedback = {
        correct,
        message: correct
          ? t('learning', 'Richtig. Die ausgewählte Regel liefert genau diese Entscheidung.')
          : t('learning', 'Nicht ganz. Prüfe erneut First-Match, Protokoll und IP-/Port-Filter.'),
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
.sim-tool__packet-path {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.sim-tool__packet-hop {
  padding: 0.45rem 0.7rem;
  border: 1px solid var(--sim-border);
  border-radius: 999px;
  background: rgba(139, 148, 158, 0.12);
  color: var(--sim-text-muted);
  font-family: var(--sim-text-mono);
  font-size: 0.75rem;
}
.sim-tool__packet-hop--hit {
  border-color: var(--sim-success);
  background: rgba(0, 230, 118, 0.12);
  color: var(--sim-success);
  box-shadow: var(--sim-glow-pass);
}
.sim-tool__drag-cell {
  width: 42px;
  text-align: center;
}
.sim-tool__drag-handle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  color: var(--sim-text-muted);
  cursor: grab;
  font-family: var(--sim-text-mono);
  font-size: 1rem;
}
.sim-tool__checkbox {
  accent-color: var(--sim-accent);
}
.sim-tool__drag-placeholder {
  display: inline-block;
  width: 24px;
}
.sim-tool__result :deep(.note-card) {
  margin-top: 0;
  border-radius: var(--lnc-radius-md);
}

@media (max-width: 768px) {
  .sim-tool__table thead th,
  .sim-tool__table tbody td {
    min-width: 80px;
  }

  .sim-tool__drag-cell {
    min-width: 36px;
  }
}

@media (max-width: 480px) {
  .sim-tool__table thead th,
  .sim-tool__table tbody td {
    min-width: 60px;
    font-size: 0.6875rem;
    padding: 4px 6px;
  }
}
</style>
