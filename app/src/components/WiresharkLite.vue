<template>
  <section class="sim-tool" :class="{ 'sim-tool--embedded': isEmbedded }">
    <header v-if="!isEmbedded" class="sim-tool__header">
      <div>
        <p class="sim-tool__eyebrow">{{ t('learning', 'CompTIA Network+ N10-009') }}</p>
        <h2 class="sim-tool__title">{{ t('learning', 'Wireshark-Lite') }}</h2>
        <p class="sim-tool__subtitle">{{ t('learning', 'Untersuche Paketfolgen, Layer-Aufbau und typische Anomalien wie Retransmissions oder TTL exceeded.') }}</p>
      </div>
    </header>

    <nav v-if="!isEmbedded" class="sim-tool__tabs" role="tablist">
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'simulator' }" @click="view = 'simulator'">{{ t('learning', 'Simulator') }}</button>
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'exercise' }" @click="view = 'exercise'">{{ t('learning', 'Übung') }}</button>
      <button class="sim-tool__tab" :class="{ 'sim-tool__tab--active': currentView === 'practicum' }" @click="view = 'practicum'">{{ t('learning', 'Praxis') }}</button>
    </nav>

    <section v-if="currentView === 'simulator'" class="sim-tool__panel">
      <label class="sim-tool__field">
        <span>{{ t('learning', 'Capture') }}</span>
        <select v-model="activeCaptureId" class="sim-tool__input">
          <option v-for="capture in captures" :key="capture.id" :value="capture.id">{{ capture.title }}</option>
        </select>
      </label>

      <div class="sim-tool__timeline">
        <button v-for="packet in timeline" :key="packet.id" class="sim-tool__scenario sim-tool__packet" :class="{ 'sim-tool__scenario--active': selectedPacket && selectedPacket.id === packet.id }" @click="selectedPacketId = packet.id">
          <strong>{{ packet.protocol }}</strong>
          <span>{{ packet.summary }}</span>
          <small>{{ packet.timestamp }}</small>
        </button>
      </div>

      <div v-if="selectedPacket" class="sim-tool__layers">
        <article v-for="layer in selectedPacket.layers" :key="layer.id" class="sim-tool__layer" :class="`sim-tool__layer--${layer.id}`">
          <h3>{{ layer.label }}</h3>
          <ul>
            <li v-for="field in layer.fields" :key="field.label"><strong>{{ field.label }}:</strong> {{ field.value }}</li>
          </ul>
        </article>
      </div>

      <div class="sim-tool__issues">
        <NcNoteCard type="info">
          {{ t('learning', 'Retransmissions: {count}', { count: issues.retransmissions.length }) }}
        </NcNoteCard>
        <NcNoteCard type="warning">
          {{ t('learning', 'TTL exceeded Pakete: {count}', { count: issues.ttlExceededPackets.length }) }}
        </NcNoteCard>
        <NcNoteCard :type="issues.dnsSpoofing ? 'error' : 'success'">
          {{ issues.dnsSpoofing ? t('learning', 'DNS-Spoofing-Anzeichen erkannt') : t('learning', 'Keine DNS-Spoofing-Anzeichen im Capture') }}
        </NcNoteCard>
      </div>
    </section>

    <section v-else-if="currentView === 'exercise'" class="sim-tool__panel">
      <div v-if="!isEmbedded" class="sim-tool__scenario-grid">
        <button v-for="entry in scenarios" :key="entry.id" class="sim-tool__scenario" :class="{ 'sim-tool__scenario--active': activeScenario && activeScenario.id === entry.id }" @click="loadScenario(entry)">
          <strong>{{ entry.id }}</strong>
          <span>{{ entry.question }}</span>
        </button>
      </div>

      <NcEmptyContent
        v-if="!activeScenario"
        :name="t('learning', 'Noch keine Paket-Übung')"
        :description="t('learning', 'Wähle ein Wireshark-Szenario aus.')"
      />

      <div v-else class="sim-tool__exercise">
        <h3>{{ activeScenario.question }}</h3>
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
      <PracticumRunner simulator-type="wireshark" />
    </section>
  </section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import PracticumRunner from './PracticumRunner.vue'
import packetCaptures from '../../data/packet_captures.json'
import { buildCaptureTimeline, detectCaptureIssues } from '../utils/packetParser.js'

export default {
  name: 'WiresharkLite',
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
      captures: packetCaptures.captures,
      scenarios: packetCaptures.scenarios,
      activeCaptureId: packetCaptures.captures[0].id,
      selectedPacketId: packetCaptures.captures[0].packets[0].id,
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
    activeCapture() {
      return this.captures.find((capture) => capture.id === this.activeCaptureId)
    },
    timeline() {
      return this.activeCapture ? buildCaptureTimeline(this.activeCapture) : []
    },
    selectedPacket() {
      return this.timeline.find((packet) => packet.id === this.selectedPacketId)
    },
    issues() {
      return this.activeCapture ? detectCaptureIssues(this.activeCapture.packets) : {
        retransmissions: [],
        ttlExceededPackets: [],
        dnsSpoofing: false,
      }
    },
  },
  watch: {
    activeCaptureId() {
      this.selectedPacketId = this.activeCapture.packets[0].id
    },
  },
  created() {
    if (this.scenario) {
      this.loadScenario(this.scenario)
    }
  },
  beforeDestroy() {
    // Keine aktiven Timer oder globalen Event-Listener registriert.
    // Hook vorhanden fuer sauberes Embedded-Rendering in SimulatorShell.
  },
  methods: {
    loadScenario(entry) {
      this.activeScenario = entry
      this.feedback = null
    },
    answerScenario(optionId) {
      const correct = optionId === this.activeScenario.answer
      this.feedback = {
        correct,
        message: correct
          ? t('learning', 'Richtig. Dieses Paketmuster passt zu genau diesem Befund.')
          : t('learning', 'Noch nicht. Achte auf Flags, doppelte Antworten oder TTL exceeded.'),
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
.sim-tool__timeline {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 0.75rem;
}
.sim-tool__layers {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 0.75rem;
}
.sim-tool__layer {
  padding: 1rem;
  border: 1px solid var(--sim-border);
  border-radius: 16px;
  color: var(--sim-text);
}
.sim-tool__layer h3,
.sim-tool__layer ul {
  margin: 0;
}
.sim-tool__layer ul {
  padding-left: 1rem;
}
.sim-tool__layer--ethernet { background: rgba(88, 166, 255, 0.12); }
.sim-tool__layer--ip { background: rgba(0, 230, 118, 0.1); }
.sim-tool__layer--tcp,
.sim-tool__layer--udp,
.sim-tool__layer--icmp { background: rgba(210, 153, 34, 0.14); }
.sim-tool__layer--dns,
.sim-tool__layer--http,
.sim-tool__layer--arp { background: rgba(248, 81, 73, 0.12); }
.sim-tool__issues {
  display: grid;
  gap: 0.75rem;
}
</style>
