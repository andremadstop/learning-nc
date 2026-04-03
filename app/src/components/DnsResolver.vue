<template>
  <section class="sim-tool" :class="{ 'sim-tool--embedded': isEmbedded }">
    <header v-if="!isEmbedded" class="sim-tool__header">
      <div>
        <p class="sim-tool__eyebrow">{{ t('learning', 'CompTIA Network+ N10-009') }}</p>
        <h2 class="sim-tool__title">{{ t('learning', 'DNS-Resolver') }}</h2>
        <p class="sim-tool__subtitle">
          {{ t('learning', 'Verfolge Root, TLD und autoritative Nameserver Schritt für Schritt und übe typische DNS-Fehlerbilder.') }}
        </p>
      </div>
    </header>

    <nav
      v-if="!isEmbedded"
      class="sim-tool__tabs"
      role="tablist"
      :aria-label="t('learning', 'DNS-Resolver Tabs')"
    >
      <button
        class="sim-tool__tab"
        :class="{ 'sim-tool__tab--active': currentView === 'simulator' }"
        role="tab"
        :aria-selected="currentView === 'simulator' ? 'true' : 'false'"
        @click="view = 'simulator'"
      >
        {{ t('learning', 'Simulator') }}
      </button>
      <button
        class="sim-tool__tab"
        :class="{ 'sim-tool__tab--active': currentView === 'exercise' }"
        role="tab"
        :aria-selected="currentView === 'exercise' ? 'true' : 'false'"
        @click="view = 'exercise'"
      >
        {{ t('learning', 'Übung') }}
      </button>
      <button
        class="sim-tool__tab"
        :class="{ 'sim-tool__tab--active': currentView === 'practicum' }"
        role="tab"
        :aria-selected="currentView === 'practicum' ? 'true' : 'false'"
        @click="view = 'practicum'"
      >
        {{ t('learning', 'Praxis') }}
      </button>
    </nav>

    <section v-if="currentView === 'simulator'" class="sim-tool__panel">
      <div class="sim-tool__controls">
        <label class="sim-tool__field">
          <span class="sim-tool__field-label">{{ t('learning', 'Domain') }}</span>
          <input
            v-model.trim="form.domain"
            class="sim-tool__input"
            type="text"
            :placeholder="t('learning', 'Beispiel: example.com oder 25.113.0.203.in-addr.arpa')"
          >
        </label>

        <label class="sim-tool__field">
          <span class="sim-tool__field-label">{{ t('learning', 'Record-Typ') }}</span>
          <select v-model="form.recordType" class="sim-tool__input">
            <option v-for="type in recordTypes" :key="type.id" :value="type.id">
              {{ type.id }}
            </option>
          </select>
        </label>

        <div class="sim-tool__controls-actions">
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

      <p class="sim-tool__hint">
        {{ t('learning', 'Klickbare Beispiele: example.com, mail.example.com, www.example.org, branch.example.de') }}
      </p>

      <div class="sim-tool__records">
        <button
          v-for="type in recordTypes"
          :key="type.id"
          class="sim-tool__scenario sim-tool__record-card"
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

    <section v-else-if="currentView === 'exercise'" class="sim-tool__panel sim-tool__panel--exercise">
      <div v-if="!isEmbedded" class="sim-tool__scenarios">
        <button
          v-for="scenarioEntry in scenarios"
          :key="scenarioEntry.id"
          class="sim-tool__scenario"
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

      <div v-else class="sim-tool__exercise">
        <header class="sim-tool__exercise-header">
          <div>
            <p class="sim-tool__exercise-eyebrow">{{ t('learning', 'Szenario') }}</p>
            <h3>{{ activeScenario.title }}</h3>
          </div>
          <button class="sim-tool__btn sim-tool__btn--secondary" type="button" @click="runLookup('scenario')">
            {{ t('learning', 'Lookup erneut laden') }}
          </button>
        </header>

        <p class="sim-tool__exercise-context">{{ activeScenario.context }}</p>
        <p class="sim-tool__exercise-question">{{ activeScenario.question }}</p>

        <div class="sim-tool__options">
          <button
            v-for="option in activeScenario.options || []"
            :key="option.id"
            class="sim-tool__option"
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

    <section v-else-if="currentView === 'practicum'" class="sim-tool__panel">
      <PracticumRunner simulator-type="dns" />
    </section>

    <section
      v-if="lookupResult"
      class="sim-tool__visuals"
      :class="{ 'sim-tool__visuals--summary-only': !lookupResult.steps.length }"
    >
      <div v-if="lookupResult.steps.length" class="sim-tool__stage">
        <div class="sim-tool__stage-actors" role="img" :aria-label="t('learning', 'DNS-Auflösung mit Client, Resolver, Root, TLD und autoritativem Server')">
          <div
            v-for="actor in actors"
            :key="actor.id"
            class="sim-tool__stage-node"
            :class="actorClass(actor.id)"
          >
            <span class="sim-tool__stage-node-label">{{ actor.label }}</span>
            <small>{{ actor.caption }}</small>
          </div>
        </div>

        <div class="sim-tool__stage-progress">
          <div class="sim-tool__stage-bar">
            <span class="sim-tool__stage-bar-fill" :style="{ width: progressWidth }"></span>
          </div>
          <span>{{ currentStepIndex + 1 }} / {{ lookupResult.steps.length }}</span>
        </div>

        <ol class="sim-tool__steps">
          <li
            v-for="(step, index) in lookupResult.steps"
            :key="step.id"
            class="sim-tool__step"
            :class="{
              'sim-tool__step--active': index === currentStepIndex,
              'sim-tool__step--done': index < currentStepIndex,
            }"
          >
            <div class="sim-tool__step-route">{{ actorLabel(step.source) }} -> {{ actorLabel(step.target) }}</div>
            <strong>{{ step.title }}</strong>
            <p>{{ step.detail }}</p>
          </li>
        </ol>
      </div>

      <aside class="sim-tool__summary">
        <div class="sim-tool__summary-header">
          <span class="sim-tool__summary-badge sim-tool__status" :class="statusClass">
            {{ statusLabel }}
          </span>
          <strong>{{ lookupResult.recordType }} {{ lookupResult.domain }}</strong>
        </div>

        <p>{{ lookupResult.message }}</p>

        <div v-if="lookupResult.answers.length" class="sim-tool__summary-block">
          <h4>{{ t('learning', 'Antwort') }}</h4>
          <ul>
            <li v-for="answer in lookupResult.answers" :key="answer">{{ answer }}</li>
          </ul>
        </div>

        <div v-if="lookupResult.aliasChain.length" class="sim-tool__summary-block">
          <h4>{{ t('learning', 'Alias-Kette') }}</h4>
          <ul>
            <li v-for="alias in lookupResult.aliasChain" :key="alias">{{ alias }}</li>
          </ul>
        </div>

        <div class="sim-tool__summary-meta">
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
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import PracticumRunner from './PracticumRunner.vue';
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
  beforeUnmount() {
    this.clearTimer();
  },
  methods: {
    actorClass(actorId) {
      const step = this.lookupResult?.steps?.[this.currentStepIndex];
      if (!step) return '';
      if (step.source === actorId || step.target === actorId) {
        return 'sim-tool__stage-node--active';
      }
      return this.currentStepIndex > 0 ? 'sim-tool__stage-node--visited' : '';
    },
    actorLabel(actorId) {
      return this.actors.find(actor => actor.id === actorId)?.label || actorId;
    },
    optionClass(optionId) {
      if (!this.selectedOptionId) return '';
      if (!this.activeScenario) return '';

      if (optionId === this.activeScenario.correctOptionId) {
        return 'sim-tool__option--correct';
      }

      if (optionId === this.selectedOptionId) {
        return 'sim-tool__option--wrong';
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
.sim-tool__controls,
.sim-tool__exercise-header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
}

.sim-tool__controls {
  align-items: end;
  flex-wrap: wrap;
}

.sim-tool__exercise-eyebrow {
  margin: 0 0 8px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-size: 0.78rem;
  color: var(--sim-accent);
  font-family: var(--sim-text-mono);
}

.sim-tool__hint {
  margin: 8px 0 0;
  color: var(--sim-text-muted);
  line-height: 1.55;
}

.sim-tool__exercise-context {
  margin: 8px 0 0;
  color: var(--sim-text-muted);
  line-height: 1.55;
}

.sim-tool__field-label,
.sim-tool__summary-meta span,
.sim-tool__summary-block h4,
.sim-tool__step-route {
  font-size: 0.82rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--sim-text-muted);
  font-family: var(--sim-text-mono);
}

.sim-tool__controls-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.sim-tool__records,
.sim-tool__scenarios {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.sim-tool__record-card small {
  color: var(--sim-text-muted);
}

.sim-tool__option--correct {
  border-color: var(--sim-success);
  background: rgba(0, 230, 118, 0.1);
}

.sim-tool__option--wrong {
  border-color: var(--sim-danger);
  background: rgba(248, 81, 73, 0.1);
}

.sim-tool__exercise {
  display: grid;
  gap: 16px;
}

.sim-tool__exercise-question {
  margin: 0;
  font-size: 1.1rem;
}

.sim-tool__visuals {
  display: grid;
  grid-template-columns: minmax(0, 1.65fr) minmax(280px, 1fr);
  gap: 20px;
  align-items: start;
}

.sim-tool__visuals--summary-only {
  grid-template-columns: 1fr;
}

.sim-tool__stage,
.sim-tool__summary {
  border-radius: 18px;
  padding: 18px;
  background: var(--sim-panel-elevated);
  border: 1px solid var(--sim-border);
  color: var(--sim-text);
}

.sim-tool__stage-actors {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(5, minmax(0, 1fr));
}

.sim-tool__stage-node {
  min-height: 88px;
  padding: 14px 10px;
  border-radius: 16px;
  text-align: center;
  background: rgba(28, 35, 51, 0.82);
  border: 1px solid var(--sim-border);
  display: grid;
  align-content: center;
  gap: 6px;
}

.sim-tool__stage-node--active {
  border-color: var(--sim-accent);
  box-shadow: var(--sim-glow-accent);
}

.sim-tool__stage-node--visited {
  background: rgba(24, 42, 68, 0.74);
}

.sim-tool__stage-node-label {
  font-weight: 700;
}

.sim-tool__stage-progress {
  display: flex;
  gap: 12px;
  align-items: center;
  margin: 18px 0;
}

.sim-tool__stage-bar {
  flex: 1 1 auto;
  height: 10px;
  border-radius: 999px;
  background: var(--sim-border);
  overflow: hidden;
}

.sim-tool__stage-bar-fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, var(--sim-accent), var(--sim-success));
  transition: width 240ms ease;
}

.sim-tool__steps {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 12px;
}

.sim-tool__step {
  padding: 14px;
  border-radius: 16px;
  border: 1px solid var(--sim-border);
  background: var(--sim-panel-elevated);
}

.sim-tool__step--done {
  opacity: 0.72;
}

.sim-tool__step--active {
  border-color: var(--sim-accent);
  box-shadow: var(--sim-glow-accent);
}

.sim-tool__step-route {
  margin-bottom: 6px;
}

.sim-tool__step p {
  margin: 8px 0 0;
  color: var(--sim-text-muted);
  line-height: 1.5;
}

.sim-tool__summary {
  display: grid;
  gap: 16px;
}

.sim-tool__summary-header {
  display: grid;
  gap: 8px;
}

.sim-tool__summary-badge {
  width: fit-content;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.sim-tool__summary-block ul,
.sim-tool__summary-meta {
  margin: 0;
  padding: 0;
  display: grid;
  gap: 12px;
}

.sim-tool__summary-block ul {
  list-style: none;
}

.sim-tool__summary-meta > div {
  display: grid;
  gap: 4px;
}

@media (max-width: 960px) {
  .sim-tool__visuals {
    grid-template-columns: 1fr;
  }

  .sim-tool__stage-actors {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .sim-tool__stage-actors {
    grid-template-columns: 1fr;
  }

  .sim-tool__controls,
  .sim-tool__exercise-header {
    flex-direction: column;
  }

  .sim-tool__stage-node {
    min-height: auto;
    padding: 10px 8px;
    font-size: 0.8rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .sim-tool__record-card,
  .sim-tool__stage-bar-fill {
    transition: none;
  }
}
</style>
