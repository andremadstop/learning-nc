<template>
  <section class="sim-tool scan-tool" :class="{ 'sim-tool--embedded': isEmbedded }">
    <header v-if="!isEmbedded" class="sim-tool__header">
      <div>
        <p class="sim-tool__eyebrow">{{ t('learning', 'CompTIA Network+ N10-009') }}</p>
        <h2 class="sim-tool__title">{{ t('learning', 'Port-Scanner') }}</h2>
        <p class="sim-tool__subtitle">{{ t('learning', 'Simuliere Host-Scans, identifiziere Serverrollen und erkenne unsichere Dienste.') }}</p>
      </div>
    </header>

    <nav v-if="!isEmbedded" class="sim-tool__tabs" role="tablist">
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'simulator' }" @click="view = 'simulator'">{{ t('learning', 'Simulator') }}</button>
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'exercise' }" @click="view = 'exercise'">{{ t('learning', 'Uebung') }}</button>
    </nav>

    <section v-if="currentView === 'simulator'" class="sim-tool__panel">
      <div class="sim-tool__grid">
        <label class="sim-tool__field">
          <span>{{ t('learning', 'IP-Adresse') }}</span>
          <input v-model.trim="ipAddress" class="sim-tool__input" type="text">
        </label>
        <label class="sim-tool__field">
          <span>{{ t('learning', 'Host-Profil') }}</span>
          <select v-model="profileId" class="sim-tool__input">
            <option v-for="profile in profiles" :key="profile.id" :value="profile.id">{{ profile.label }}</option>
          </select>
        </label>
        <label class="sim-tool__field">
          <span>{{ t('learning', 'Scan-Typ') }}</span>
          <select v-model="scanType" class="sim-tool__input">
            <option value="syn">SYN</option>
            <option value="connect">Connect</option>
            <option value="udp">UDP</option>
            <option value="fin">FIN</option>
          </select>
        </label>
      </div>

      <div class="scan-tool__actions">
        <button class="sim-tool__btn" type="button" @click="startScan">{{ t('learning', 'Scan starten') }}</button>
        <span>{{ scanExplanation }}</span>
      </div>

      <div v-if="isScanning || scanResult" class="scan-tool__progress-wrap">
        <div class="sim-tool__progress">
          <span class="sim-tool__progress-bar" :style="{ width: `${progress}%` }"></span>
        </div>
        <strong>{{ progress }}%</strong>
      </div>

      <div v-if="scanResult" class="sim-tool__table-wrap">
        <table class="sim-tool__table">
          <thead>
            <tr>
              <th>{{ t('learning', 'Port') }}</th>
              <th>{{ t('learning', 'State') }}</th>
              <th>{{ t('learning', 'Service') }}</th>
              <th>{{ t('learning', 'Version') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in visiblePorts" :key="entry.port" :class="`scan-tool__state--${entry.state}`">
              <td>{{ entry.port }}</td>
              <td>
                <span
                  class="sim-tool__status"
                  :class="entry.state === 'open' ? 'sim-tool__status--pass' : entry.state === 'filtered' ? 'sim-tool__status--warn' : 'sim-tool__status--fail'"
                >
                  <span
                    class="sim-tool__dot"
                    :class="entry.state === 'open' ? 'sim-tool__dot--open' : entry.state === 'filtered' ? 'sim-tool__dot--filtered' : 'sim-tool__dot--closed'"
                  ></span>
                  {{ entry.state }}
                </span>
              </td>
              <td>{{ entry.service }}</td>
              <td>{{ entry.version }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="scanResult" class="scan-tool__facts">
        <NcNoteCard type="info">{{ t('learning', 'Erkannter Host-Typ: {type}', { type: detectedTypeLabel }) }}</NcNoteCard>
        <NcNoteCard v-if="compromiseIndicators.length" type="error">
          {{ t('learning', 'Warnung: Verdaechtige Ports entdeckt ({ports})', { ports: compromiseIndicators.map((entry) => entry.port).join(', ') }) }}
        </NcNoteCard>
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
        :name="t('learning', 'Noch kein Scan-Szenario')"
        :description="t('learning', 'Waehle eine Port-Scanner-Uebung aus.')"
      />

      <div v-else class="scan-tool__exercise">
        <h3>{{ activeScenario.title }}</h3>
        <p>{{ activeScenario.question }}</p>
        <ul class="scan-tool__mini-list">
          <li v-for="entry in scenarioPorts" :key="entry.port">{{ entry.port }}/{{ entry.state }} - {{ entry.service }}</li>
        </ul>
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
import portscanScenarios from '../../data/portscan_scenarios.json'
import {
  buildScanResults,
  buildScanTimeline,
  detectHostType,
  explainScanType,
  findCompromiseIndicators,
  getHostProfiles,
} from '../utils/portScanner.js'

export default {
  name: 'PortScanner',
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
    const profiles = getHostProfiles()
    return {
      view: this.mode === 'embedded' ? 'exercise' : 'simulator',
      profiles,
      profileId: profiles[0].id,
      ipAddress: '192.168.1.50',
      scanType: 'syn',
      progress: 0,
      isScanning: false,
      scanResult: null,
      visiblePortCount: 0,
      intervalId: null,
      scenarios: portscanScenarios,
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
    visiblePorts() {
      return this.scanResult ? this.scanResult.ports.slice(0, this.visiblePortCount) : []
    },
    compromiseIndicators() {
      return this.scanResult ? findCompromiseIndicators(this.scanResult) : []
    },
    detectedTypeLabel() {
      return this.scanResult ? detectHostType(this.scanResult) : '-'
    },
    scanExplanation() {
      return explainScanType(this.scanType)
    },
    scenarioPorts() {
      if (!this.activeScenario) {
        return []
      }
      return buildScanResults(this.activeScenario.profileId, this.activeScenario.overrides || {}).ports
    },
  },
  created() {
    if (this.scenario) {
      this.loadScenario(this.scenario)
    }
  },
  beforeDestroy() {
    window.clearInterval(this.intervalId)
  },
  methods: {
    startScan() {
      const result = buildScanResults(this.profileId, { scanType: this.scanType })
      const timeline = buildScanTimeline(result)
      window.clearInterval(this.intervalId)
      this.scanResult = result
      this.isScanning = true
      this.progress = 0
      this.visiblePortCount = 0

      this.intervalId = window.setInterval(() => {
        this.progress = Math.min(100, this.progress + 10)
        this.visiblePortCount = timeline.filter((entry) => entry.revealAtMs <= (result.durationMs * (this.progress / 100))).length
        if (this.progress >= 100) {
          this.isScanning = false
          window.clearInterval(this.intervalId)
          this.visiblePortCount = result.ports.length
          this.$emit('result', {
            ipAddress: this.ipAddress,
            detectedType: detectHostType(result),
            compromiseIndicators: findCompromiseIndicators(result),
          })
        }
      }, Math.max(120, Math.round(result.durationMs / 10)))
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
          ? t('learning', 'Richtig. Das offene Portmuster passt genau zu diesem Befund.')
          : t('learning', 'Noch nicht. Achte auf offene Mail-, Backdoor- oder Management-Ports.'),
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
.scan-tool__actions {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  color: var(--sim-text-muted);
  font-size: 0.875rem;
}
.scan-tool__progress-wrap {
  display: flex;
  align-items: center;
  gap: 1rem;
  color: var(--sim-text);
}
.scan-tool__state--open {
  background: rgba(0, 230, 118, 0.06);
}
.scan-tool__state--filtered {
  background: rgba(210, 153, 34, 0.08);
}
.scan-tool__state--closed {
  background: rgba(248, 81, 73, 0.06);
}
.scan-tool__facts {
  display: grid;
  gap: 0.75rem;
}
.scan-tool__mini-list {
  margin: 0;
  padding-left: 1.25rem;
  color: var(--sim-text-muted);
}

@media (max-width: 768px) {
  .scan-tool__progress-wrap {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
