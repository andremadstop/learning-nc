<template>
  <section class="sim-tool dns-tool" :class="{ 'sim-tool--embedded': isEmbedded }">
    <header v-if="!isEmbedded" class="sim-tool__header dns-tool__header">
      <div>
        <p class="sim-tool__eyebrow dns-tool__eyebrow">{{ t('learning', 'CompTIA Network+ N10-009') }}</p>
        <h2 class="sim-tool__title dns-tool__title">{{ t('learning', 'DNS-Resolver') }}</h2>
        <p class="sim-tool__subtitle dns-tool__subtitle">
          {{ t('learning', 'Verfolge Root, TLD und autoritative Nameserver Schritt für Schritt und übe typische DNS-Fehlerbilder.') }}
        </p>
      </div>
    </header>

    <nav
      v-if="!isEmbedded"
      class="sim-tool__tabs dns-tool__tabs"
      role="tablist"
      :aria-label="t('learning', 'DNS-Resolver Tabs')"
    >
      <button
        class="sim-tool__tab dns-tool__tab"
        :class="{ 'sim-tool__tab--active': currentView === 'simulator' }"
        role="tab"
        :aria-selected="currentView === 'simulator' ? 'true' : 'false'"
        @click="view = 'simulator'"
      >
        {{ t('learning', 'Simulator') }}
      </button>
      <button
        class="sim-tool__tab dns-tool__tab"
        :class="{ 'sim-tool__tab--active': currentView === 'exercise' }"
        role="tab"
        :aria-selected="currentView === 'exercise' ? 'true' : 'false'"
        @click="view = 'exercise'"
      >
        {{ t('learning', 'Übung') }}
      </button>
    </nav>

    <section v-if="currentView === 'simulator'" class="sim-tool__panel dns-tool__panel">
      <div class="dns-controls">
        <label class="sim-tool__field dns-field">
          <span class="dns-field__label">{{ t('learning', 'Domain') }}</span>
          <input
            v-model.trim="form.domain"
            class="sim-tool__input dns-input"
            type="text"
            :placeholder="t('learning', 'Beispiel: example.com oder 25.113.0.203.in-addr.arpa')"
          >
        </label>

        <label class="sim-tool__field dns-field">
          <span class="dns-field__label">{{ t('learning', 'Record-Typ') }}</span>
          <select v-model="form.recordType" class="sim-tool__input dns-input">
            <option v-for="type in recordTypes" :key="type.id" :value="type.id">
              {{ type.id }}
            </option>
          </select>
        </label>

        <div class="dns-controls__actions">
          <button class="sim-tool__btn" type="button" @click="runLookup('manual')">
            {{ t('learning', 'Lookup starten') }}
          </button>
          <button class="sim-tool__btn sim-tool__btn--secondary" type="button" @click="advanceStep" :disabled="!lookupResult || isLastStep">
            {{ t('learning', 'Nächster Schritt') }}
          </button>
          <button class="sim-tool__btn sim-tool__btn--secondary" type="button" @click="restartAnimation" :disabled="!lookupResult">
            {{ t('learning', 'Animation neu starten') }}
          </button>
        </div>
      </div>

      <p class="dns-tool__hint">
        {{ t('learning', 'Klickbare Beispiele: example.com, mail.example.com, www.example.org, branch.example.de') }}
      </p>

      <div class="dns-records">
        <button
          v-for="type in recordTypes"
          :key="type.id"
          class="sim-tool__scenario dns-record-card"
          type="button"
          :aria-label="t('learning', 'Beispiel-Lookup für {type}', { type: type.id })"
          @click="runRecordTypeExample(type.id)"
        >
          <strong>{{ type.id }}</strong>
          <span>{{ type.description }}</span>
          <small>{{ type.example }}</small>
        </button>
      </div>
    </section>

    <section v-else class="sim-tool__panel dns-tool__panel dns-tool__panel--exercise">
      <div v-if="!isEmbedded" class="dns-scenarios">
        <button
          v-for="scenarioEntry in scenarios"
          :key="scenarioEntry.id"
          class="sim-tool__scenario dns-scenario-card"
          :class="{ 'sim-tool__scenario--active': activeScenario && activeScenario.id === scenarioEntry.id }"
          type="button"
          @click="loadScenario(scenarioEntry)"
        >
          <strong>{{ scenarioEntry.title }}</strong>
          <span>{{ scenarioEntry.question }}</span>
        </button>
      </div>

      <NcEmptyContent
        v-if="!activeScenario"
        :name="t('learning', 'Noch kein Szenario geladen')"
        :description="t('learning', 'Wähle ein DNS-Szenario aus, um die Lookup-Kette zu analysieren.')"
      />

      <div v-else class="dns-exercise">
        <header class="dns-exercise__header">
          <div>
            <p class="dns-exercise__eyebrow">{{ t('learning', 'Szenario') }}</p>
            <h3>{{ activeScenario.title }}</h3>
          </div>
          <button class="sim-tool__btn sim-tool__btn--secondary" type="button" @click="runLookup('scenario')">
            {{ t('learning', 'Lookup erneut laden') }}
          </button>
        </header>

        <p class="dns-exercise__context">{{ activeScenario.context }}</p>
        <p class="dns-exercise__question">{{ activeScenario.question }}</p>

        <div class="dns-options">
          <button
            v-for="option in activeScenario.options || []"
            :key="option.id"
            class="sim-tool__option dns-option"
            :class="optionClass(option.id)"
            type="button"
            @click="submitScenarioAnswer(option.id)"
          >
            {{ option.label }}
          </button>
        </div>

        <NcNoteCard v-if="scenarioFeedback" :type="scenarioFeedback.correct ? 'success' : 'error'">
          {{ scenarioFeedback.message }}
        </NcNoteCard>
      </div>
    </section>

    <section
      v-if="lookupResult"
      class="dns-visuals"
      :class="{ 'dns-visuals--summary-only': !lookupResult.steps.length }"
    >
      <div v-if="lookupResult.steps.length" class="dns-stage">
        <div class="dns-stage__actors" role="img" :aria-label="t('learning', 'DNS-Auflösung mit Client, Resolver, Root, TLD und autoritativem Server')">
          <div
            v-for="actor in actors"
            :key="actor.id"
            class="dns-stage__node"
            :class="actorClass(actor.id)"
          >
            <span class="dns-stage__node-label">{{ actor.label }}</span>
            <small>{{ actor.caption }}</small>
          </div>
        </div>

        <div class="dns-stage__progress">
          <div class="dns-stage__bar">
            <span class="dns-stage__bar-fill" :style="{ width: progressWidth }"></span>
          </div>
          <span>{{ currentStepIndex + 1 }} / {{ lookupResult.steps.length }}</span>
        </div>

        <ol class="dns-steps">
          <li
            v-for="(step, index) in lookupResult.steps"
            :key="step.id"
            class="dns-step"
            :class="{
              'dns-step--active': index === currentStepIndex,
              'dns-step--done': index < currentStepIndex,
            }"
          >
            <div class="dns-step__route">{{ actorLabel(step.source) }} -> {{ actorLabel(step.target) }}</div>
            <strong>{{ step.title }}</strong>
            <p>{{ step.detail }}</p>
          </li>
        </ol>
      </div>

      <aside class="dns-summary">
        <div class="dns-summary__header">
          <span class="dns-summary__badge sim-tool__status" :class="statusClass">
            {{ statusLabel }}
          </span>
          <strong>{{ lookupResult.recordType }} {{ lookupResult.domain }}</strong>
        </div>

        <p>{{ lookupResult.message }}</p>

        <div v-if="lookupResult.answers.length" class="dns-summary__block">
          <h4>{{ t('learning', 'Antwort') }}</h4>
          <ul>
            <li v-for="answer in lookupResult.answers" :key="answer">{{ answer }}</li>
          </ul>
        </div>

        <div v-if="lookupResult.aliasChain.length" class="dns-summary__block">
          <h4>{{ t('learning', 'Alias-Kette') }}</h4>
          <ul>
            <li v-for="alias in lookupResult.aliasChain" :key="alias">{{ alias }}</li>
          </ul>
        </div>

        <div class="dns-summary__meta">
          <div>
            <span>{{ t('learning', 'TLD-Server') }}</span>
            <strong>{{ lookupResult.tldServer }}</strong>
          </div>
          <div>
            <span>{{ t('learning', 'Authoritativ') }}</span>
            <strong>{{ lookupResult.authoritativeServer }}</strong>
          </div>
          <div>
            <span>{{ t('learning', 'Kanonischer Name') }}</span>
            <strong>{{ lookupResult.canonicalName || lookupResult.domain }}</strong>
          </div>
        </div>
      </aside>
    </section>

    <NcEmptyContent
      v-else
      :name="t('learning', 'Noch kein Lookup')"
      :description="t('learning', 'Starte einen Lookup oder lade ein DNS-Szenario, um die Resolver-Kette sichtbar zu machen.')"
    />
  </section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import dnsScenarios from '../../data/dns_scenarios.json';
import {
  getRecordTypeCatalog,
  resolveDnsLookup,
  resolveRecordTypeExample,
} from '../utils/dnsResolver.js';

const ACTORS = Object.freeze([
  { id: 'client', label: 'Client', caption: 'Stub Resolver' },
  { id: 'resolver', label: 'Resolver', caption: 'rekursiv' },
  { id: 'root', label: 'Root', caption: '.' },
  { id: 'tld', label: 'TLD', caption: '.com /.org /.de' },
  { id: 'authoritative', label: 'Auth', caption: 'Zone' },
]);

const STATUS_LABELS = Object.freeze({
  ok: 'OK',
  missing_record: 'NODATA',
  missing_domain: 'NXDOMAIN',
  dnssec_failure: 'SERVFAIL',
  cname_loop: 'SERVFAIL',
  invalid_request: 'Ungültig',
});

export default {
  name: 'DnsResolver',
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
      view: 'simulator',
      form: {
        domain: 'example.com',
        recordType: 'A',
      },
      lookupResult: null,
      currentStepIndex: 0,
      activeScenario: null,
      selectedOptionId: '',
      scenarioFeedback: null,
      stepTimer: null,
      prefersReducedMotion: false,
      actors: ACTORS,
      scenarios: dnsScenarios,
    };
  },
  computed: {
    currentView() {
      if (!this.isEmbedded) {
        return this.view;
      }

      if (this.scenario && Array.isArray(this.scenario.options)) {
        return 'exercise';
      }

      return 'simulator';
    },
    isEmbedded() {
      return this.mode === 'embedded';
    },
    recordTypes() {
      return getRecordTypeCatalog();
    },
    progressWidth() {
      if (!this.lookupResult || !this.lookupResult.steps.length) {
        return '0%';
      }

      const percentage = ((this.currentStepIndex + 1) / this.lookupResult.steps.length) * 100;
      return `${percentage}%`;
    },
    isLastStep() {
      if (!this.lookupResult) return true;
      return this.currentStepIndex >= this.lookupResult.steps.length - 1;
    },
    statusLabel() {
      if (!this.lookupResult) return '';
      return STATUS_LABELS[this.lookupResult.status] || this.lookupResult.status;
    },
    statusClass() {
      if (!this.lookupResult) return '';
      if (this.lookupResult.status === 'ok') return 'sim-tool__status--pass';
      if (['missing_record', 'missing_domain'].includes(this.lookupResult.status)) return 'sim-tool__status--warn';
      return 'sim-tool__status--fail';
    },
  },
  watch: {
    scenario: {
      immediate: true,
      handler(newScenario) {
        if (!this.isEmbedded || !newScenario) {
          return;
        }

        this.loadScenario(newScenario, false);
      },
    },
  },
  created() {
    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
      this.prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }
  },
  beforeDestroy() {
    this.clearTimer();
  },
  methods: {
    actorClass(actorId) {
      const step = this.lookupResult?.steps?.[this.currentStepIndex];
      if (!step) return '';
      if (step.source === actorId || step.target === actorId) {
        return 'dns-stage__node--active';
      }
      return this.currentStepIndex > 0 ? 'dns-stage__node--visited' : '';
    },
    actorLabel(actorId) {
      return this.actors.find(actor => actor.id === actorId)?.label || actorId;
    },
    optionClass(optionId) {
      if (!this.selectedOptionId) return '';
      if (!this.activeScenario) return '';

      if (optionId === this.activeScenario.correctOptionId) {
        return 'dns-option--correct';
      }

      if (optionId === this.selectedOptionId) {
        return 'dns-option--wrong';
      }

      return '';
    },
    clearTimer() {
      if (this.stepTimer) {
        window.clearInterval(this.stepTimer);
        this.stepTimer = null;
      }
    },
    startAnimation() {
      this.clearTimer();

      if (!this.lookupResult || this.lookupResult.steps.length === 0) {
        return;
      }

      this.currentStepIndex = this.prefersReducedMotion ? this.lookupResult.steps.length - 1 : 0;
      if (this.prefersReducedMotion || this.lookupResult.steps.length === 1) {
        return;
      }

      this.stepTimer = window.setInterval(() => {
        if (this.currentStepIndex >= this.lookupResult.steps.length - 1) {
          this.clearTimer();
          return;
        }

        this.currentStepIndex += 1;
      }, 950);
    },
    setLookupResult(result, source) {
      this.lookupResult = result;
      this.startAnimation();
      this.$emit('result', {
        kind: 'lookup',
        source,
        domain: result.domain,
        recordType: result.recordType,
        status: result.status,
        answers: result.answers,
        aliasChain: result.aliasChain,
        scenarioId: this.activeScenario?.id || null,
      });
    },
    runLookup(source = 'manual') {
      const result = resolveDnsLookup(this.form.domain, this.form.recordType);
      this.setLookupResult(result, source);
    },
    runRecordTypeExample(recordType) {
      const result = resolveRecordTypeExample(recordType);
      this.form.domain = result.domain;
      this.form.recordType = result.recordType;
      this.setLookupResult(result, 'record-example');
    },
    restartAnimation() {
      if (!this.lookupResult) return;
      this.startAnimation();
    },
    advanceStep() {
      if (!this.lookupResult || this.isLastStep) return;
      this.clearTimer();
      this.currentStepIndex += 1;
    },
    loadScenario(scenarioEntry, resetFeedback = true) {
      this.activeScenario = { ...scenarioEntry };
      this.selectedOptionId = '';
      if (resetFeedback) {
        this.scenarioFeedback = null;
      }

      this.form.domain = this.activeScenario.domain;
      this.form.recordType = this.activeScenario.recordType;
      this.runLookup('scenario');
    },
    submitScenarioAnswer(optionId) {
      if (!this.activeScenario) {
        return;
      }

      this.selectedOptionId = optionId;
      const correct = optionId === this.activeScenario.correctOptionId;
      this.scenarioFeedback = {
        correct,
        message: correct
          ? this.activeScenario.explanation
          : `${t('learning', 'Nicht ganz.')} ${this.activeScenario.explanation}`,
      };

      this.$emit('result', {
        kind: 'exercise',
        scenarioId: this.activeScenario.id,
        correct,
        selectedOptionId: optionId,
      });
    },
  },
};
</script>

<style scoped>
.dns-tool {
  display: grid;
  gap: 20px;
  padding: 24px;
  border-radius: 20px;
  background:
    radial-gradient(circle at top left, rgba(88, 166, 255, 0.18), transparent 42%),
    linear-gradient(180deg, rgba(15, 23, 42, 0.98), rgba(18, 31, 52, 0.95));
  color: #eef4ff;
  box-shadow: var(--lnc-shadow-card);
  overflow: hidden;
}

.dns-tool--embedded {
  padding: 20px;
}

.dns-tool__header,
.dns-exercise__header,
.dns-controls {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
}

.dns-tool__eyebrow,
.dns-exercise__eyebrow {
  margin: 0 0 8px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-size: 0.78rem;
  color: rgba(198, 223, 255, 0.76);
}

.dns-tool__title {
  margin: 0;
  font-size: 2rem;
}

.dns-tool__subtitle,
.dns-tool__hint,
.dns-exercise__context {
  margin: 8px 0 0;
  color: rgba(222, 233, 250, 0.82);
  line-height: 1.55;
}

.dns-tool__badge {
  border-radius: 999px;
  padding: 10px 14px;
  background: rgba(95, 170, 255, 0.16);
  border: 1px solid rgba(95, 170, 255, 0.35);
  white-space: nowrap;
}

.dns-tool__tabs {
  display: inline-flex;
  gap: 8px;
  padding: 6px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.06);
  width: fit-content;
}

.dns-tool__tab {
  border: 0;
  border-radius: 999px;
  padding: 10px 16px;
  background: transparent;
  color: inherit;
  cursor: pointer;
}

.dns-tool__tab--active {
  background: rgba(88, 166, 255, 0.24);
}

.dns-tool__panel {
  display: grid;
  gap: 16px;
}

.dns-controls {
  align-items: end;
  flex-wrap: wrap;
}

.dns-field {
  display: grid;
  gap: 8px;
  min-width: 220px;
  flex: 1 1 220px;
}

.dns-field__label,
.dns-summary__meta span,
.dns-summary__block h4 {
  font-size: 0.82rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(198, 223, 255, 0.72);
}

.dns-input {
  width: 100%;
  min-height: 44px;
  border-radius: 14px;
  border: 1px solid rgba(143, 188, 255, 0.26);
  background: rgba(6, 14, 26, 0.55);
  color: inherit;
  padding: 12px 14px;
}

.dns-controls__actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.dns-records,
.dns-scenarios,
.dns-options {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.dns-record-card,
.dns-scenario-card,
.dns-option {
  border: 1px solid rgba(143, 188, 255, 0.2);
  background: rgba(10, 20, 38, 0.7);
  color: inherit;
  border-radius: 16px;
  padding: 16px;
  text-align: left;
  cursor: pointer;
  display: grid;
  gap: 8px;
  transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
}

.dns-record-card:hover,
.dns-scenario-card:hover,
.dns-option:hover {
  transform: translateY(-2px);
  border-color: rgba(88, 166, 255, 0.55);
  box-shadow: 0 14px 30px rgba(0, 0, 0, 0.22);
}

.dns-record-card small {
  color: rgba(198, 223, 255, 0.68);
}

.dns-scenario-card--active,
.dns-option--correct {
  border-color: rgba(36, 201, 124, 0.75);
  background: rgba(8, 45, 34, 0.72);
}

.dns-option--wrong {
  border-color: rgba(248, 81, 73, 0.85);
  background: rgba(65, 21, 20, 0.72);
}

.dns-exercise {
  display: grid;
  gap: 16px;
}

.dns-exercise__question {
  margin: 0;
  font-size: 1.1rem;
}

.dns-visuals {
  display: grid;
  grid-template-columns: minmax(0, 1.65fr) minmax(280px, 1fr);
  gap: 20px;
  align-items: start;
}

.dns-visuals--summary-only {
  grid-template-columns: 1fr;
}

.dns-stage,
.dns-summary {
  border-radius: 18px;
  padding: 18px;
  background: rgba(9, 17, 29, 0.72);
  border: 1px solid rgba(143, 188, 255, 0.16);
}

.dns-stage__actors {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(5, minmax(0, 1fr));
}

.dns-stage__node {
  min-height: 88px;
  padding: 14px 10px;
  border-radius: 16px;
  text-align: center;
  background: rgba(17, 27, 45, 0.74);
  border: 1px solid rgba(143, 188, 255, 0.14);
  display: grid;
  align-content: center;
  gap: 6px;
}

.dns-stage__node--active {
  border-color: rgba(88, 166, 255, 0.82);
  box-shadow: 0 0 0 1px rgba(88, 166, 255, 0.35), 0 0 24px rgba(88, 166, 255, 0.18);
}

.dns-stage__node--visited {
  background: rgba(15, 34, 60, 0.74);
}

.dns-stage__node-label {
  font-weight: 700;
}

.dns-stage__progress {
  display: flex;
  gap: 12px;
  align-items: center;
  margin: 18px 0;
}

.dns-stage__bar {
  flex: 1 1 auto;
  height: 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  overflow: hidden;
}

.dns-stage__bar-fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, #58a6ff, #75e6da);
  transition: width 240ms ease;
}

.dns-steps {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 12px;
}

.dns-step {
  padding: 14px;
  border-radius: 16px;
  border: 1px solid rgba(143, 188, 255, 0.14);
  background: rgba(10, 18, 31, 0.68);
}

.dns-step--done {
  opacity: 0.72;
}

.dns-step--active {
  border-color: rgba(88, 166, 255, 0.72);
  background: rgba(11, 29, 52, 0.88);
}

.dns-step__route {
  margin-bottom: 6px;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(198, 223, 255, 0.72);
}

.dns-step p {
  margin: 8px 0 0;
  color: rgba(222, 233, 250, 0.84);
  line-height: 1.5;
}

.dns-summary {
  display: grid;
  gap: 16px;
}

.dns-summary__header {
  display: grid;
  gap: 8px;
}

.dns-summary__badge {
  width: fit-content;
  padding: 6px 10px;
  border-radius: 999px;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.dns-summary__badge--ok {
  background: rgba(36, 201, 124, 0.18);
  color: #9ff1c5;
}

.dns-summary__badge--missing_record,
.dns-summary__badge--missing_domain {
  background: rgba(255, 200, 87, 0.18);
  color: #ffd977;
}

.dns-summary__badge--dnssec_failure,
.dns-summary__badge--cname_loop,
.dns-summary__badge--invalid_request {
  background: rgba(248, 81, 73, 0.18);
  color: #ff9d96;
}

.dns-summary__block ul,
.dns-summary__meta {
  margin: 0;
  padding: 0;
  display: grid;
  gap: 12px;
}

.dns-summary__block ul {
  list-style: none;
}

.dns-summary__meta > div {
  display: grid;
  gap: 4px;
}

.sim-tool.dns-tool {
  background:
    radial-gradient(circle at top left, rgba(88, 166, 255, 0.12), transparent 42%),
    linear-gradient(180deg, rgba(13, 17, 23, 0.98), rgba(22, 27, 34, 0.98));
  color: var(--sim-text);
}

.sim-tool__header.dns-tool__header,
.dns-exercise__header,
.dns-controls {
  align-items: flex-start;
}

.sim-tool__eyebrow.dns-tool__eyebrow,
.dns-exercise__eyebrow {
  color: var(--sim-accent);
  font-family: var(--sim-text-mono);
}

.sim-tool__title.dns-tool__title {
  font-size: 1.5rem;
}

.sim-tool__subtitle.dns-tool__subtitle,
.dns-tool__hint,
.dns-exercise__context {
  color: var(--sim-text-muted);
}

.dns-field__label,
.dns-summary__meta span,
.dns-summary__block h4,
.dns-step__route {
  color: var(--sim-text-muted);
  font-family: var(--sim-text-mono);
}

.sim-tool__input.dns-input {
  border-color: var(--sim-border);
  background: var(--sim-bg);
}

.dns-record-card,
.dns-scenario-card,
.dns-option,
.dns-stage,
.dns-summary,
.dns-stage__node,
.dns-step {
  border-color: var(--sim-border);
}

.dns-record-card,
.dns-scenario-card,
.dns-option,
.dns-stage,
.dns-summary {
  background: var(--sim-panel-elevated);
  color: var(--sim-text);
}

.dns-stage__node {
  background: rgba(28, 35, 51, 0.82);
}

.dns-stage__node--active,
.dns-step--active {
  border-color: var(--sim-accent);
  box-shadow: var(--sim-glow-accent);
}

.dns-stage__node--visited {
  background: rgba(24, 42, 68, 0.74);
}

.dns-stage__bar {
  background: var(--sim-border);
}

.dns-stage__bar-fill {
  background: linear-gradient(90deg, var(--sim-accent), var(--sim-success));
}

.dns-step p,
.dns-record-card small {
  color: var(--sim-text-muted);
}

.dns-summary__badge {
  padding: 4px 10px;
}

@media (max-width: 960px) {
  .dns-visuals {
    grid-template-columns: 1fr;
  }

  .dns-stage__actors {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .dns-tool {
    padding: 18px;
  }

  .dns-stage__actors {
    grid-template-columns: 1fr;
  }

  .dns-tool__header,
  .dns-controls,
  .dns-exercise__header {
    flex-direction: column;
  }
}

@media (prefers-reduced-motion: reduce) {
  .dns-record-card,
  .dns-scenario-card,
  .dns-option,
  .dns-stage__bar-fill {
    transition: none;
  }
}
</style>
