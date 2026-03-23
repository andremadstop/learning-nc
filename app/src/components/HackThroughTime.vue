<template>
  <div class="htt-root epoch-transition"
    :data-epoch="currentEpochTheme"
    data-lnc-theme="dark"
    data-lnc-skin="paper-circuits">

    <!-- ===== OVERVIEW PHASE ===== -->
    <div v-if="phase === 'overview'" class="htt-overview">
      <div class="htt-header">
        <button class="htt-back-btn" @click="$emit('back')">
          {{ t('learning', 'Zurueck') }}
        </button>
        <h2 class="htt-title">{{ t('learning', 'Hack Through Time') }}</h2>
      </div>

      <!-- CHRONOS guide -->
      <div class="htt-guide">
        <CharacterAvatar characterId="chronos" :size="64" state="idle" />
        <div class="htt-guide-speech">
          <span class="htt-guide-name">CHRONOS</span>
          <p class="htt-guide-text">
            {{ selectedClass
              ? t('learning', 'Gut, {className}. Waehle eine Epoche und beweise dein Wissen.', { className: selectedClassName })
              : t('learning', 'Willkommen, Zeitreisender. Waehle zuerst deine Spezialisierung, dann reisen wir durch 7 Epochen der IT-Security-Geschichte.')
            }}
          </p>
        </div>
      </div>

      <!-- Class selection bar -->
      <div class="htt-class-bar">
        <button
          v-for="cls in classList"
          :key="cls.id"
          class="htt-class-btn"
          :class="{ 'htt-class-active': selectedClass === cls.id }"
          @click="selectClass(cls.id)"
        >
          <span class="htt-class-icon">{{ classIcon(cls.icon) }}</span>
          <span class="htt-class-name">{{ cls.name }}</span>
        </button>
        <button v-if="!selectedClass" class="htt-class-btn htt-class-choose"
          @click="phase = 'class_select'">
          {{ t('learning', 'Klasse waehlen') }}
        </button>
      </div>

      <!-- Epoch grid -->
      <div v-if="loading" class="htt-loading">
        <div class="htt-spinner"></div>
        <p>{{ t('learning', 'Lade Epochen...') }}</p>
      </div>

      <div v-else class="htt-epoch-grid">
        <div
          v-for="epoch in sortedEpochs"
          :key="epoch.id"
          class="htt-epoch-card"
          :class="{
            'htt-epoch-locked': epochStatus(epoch.id) === 'locked',
            'htt-epoch-completed': epochStatus(epoch.id) === 'completed',
            'htt-epoch-active': epochStatus(epoch.id) === 'in_progress',
          }"
          tabindex="0"
          role="button"
          :aria-label="epoch.name + ' (' + epoch.era + ')'"
          @click="handleEpochClick(epoch)"
          @keydown.enter="handleEpochClick(epoch)"
        >
          <div class="htt-epoch-era">{{ epoch.era }}</div>
          <h3 class="htt-epoch-name">{{ epoch.name }}</h3>
          <p class="htt-epoch-desc">{{ epoch.description }}</p>
          <div class="htt-epoch-footer">
            <span class="htt-epoch-status">
              <template v-if="epochStatus(epoch.id) === 'locked'">{{ t('learning', 'Gesperrt') }}</template>
              <template v-else-if="epochStatus(epoch.id) === 'completed'">{{ t('learning', 'Abgeschlossen') }}</template>
              <template v-else-if="epochStatus(epoch.id) === 'in_progress'">{{ t('learning', 'Gestartet') }}</template>
              <template v-else>{{ t('learning', 'Verfuegbar') }}</template>
            </span>
            <span v-if="selectedClass" class="htt-affinity-badge"
              :class="'htt-affinity-' + getAffinity(epoch.id)">
              <template v-if="getAffinity(epoch.id) === 'bonus'">{{ t('learning', 'Bonus') }}</template>
              <template v-else-if="getAffinity(epoch.id) === 'penalty'">{{ t('learning', 'Schwach') }}</template>
            </span>
          </div>
          <div v-if="getEpochScore(epoch.id) !== null" class="htt-epoch-score">
            {{ getEpochScore(epoch.id) }} {{ t('learning', 'Punkte') }}
          </div>
        </div>
      </div>

      <!-- Overall score -->
      <div v-if="overallScore > 0" class="htt-overall-score">
        <strong>{{ t('learning', 'Gesamt-Score:') }}</strong> {{ overallScore }} {{ t('learning', 'Punkte') }}
      </div>
    </div>

    <!-- ===== CLASS SELECT PHASE ===== -->
    <div v-else-if="phase === 'class_select'" class="htt-class-select">
      <div class="htt-header">
        <button class="htt-back-btn" @click="phase = 'overview'">
          {{ t('learning', 'Zurueck') }}
        </button>
        <h2 class="htt-title">{{ t('learning', 'Spezialisierung waehlen') }}</h2>
      </div>

      <div class="htt-guide">
        <CharacterAvatar characterId="chronos" :size="64" state="thinking" />
        <div class="htt-guide-speech">
          <span class="htt-guide-name">CHRONOS</span>
          <p class="htt-guide-text">
            {{ t('learning', 'Waehle deine Spezialisierung, Zeitreisender. Jede Klasse hat Staerken und Schwaechen in bestimmten Epochen.') }}
          </p>
        </div>
      </div>

      <div class="htt-class-grid">
        <div
          v-for="cls in classList"
          :key="cls.id"
          class="htt-class-card"
          :class="{ 'htt-class-card-selected': pendingClass === cls.id }"
          tabindex="0"
          role="button"
          @click="pendingClass = cls.id"
          @keydown.enter="pendingClass = cls.id"
        >
          <div class="htt-class-card-icon">{{ classIcon(cls.icon) }}</div>
          <h3 class="htt-class-card-name">{{ cls.name }}</h3>
          <p class="htt-class-card-desc">{{ cls.description }}</p>
          <div class="htt-class-card-affinity">
            <span class="htt-affinity-strong">
              {{ t('learning', 'Stark in:') }}
              {{ cls.affinityEpochs.map(e => epochName(e)).join(', ') }}
            </span>
            <span class="htt-affinity-weak">
              {{ t('learning', 'Schwach in:') }}
              {{ cls.weakEpochs.map(e => epochName(e)).join(', ') }}
            </span>
          </div>
        </div>
      </div>

      <div class="htt-class-confirm">
        <button
          class="htt-btn-primary"
          :disabled="!pendingClass"
          @click="confirmClass"
        >
          {{ t('learning', 'Bestaetigen') }}
        </button>
      </div>
    </div>

    <!-- ===== MUSEUM PHASE ===== -->
    <div v-else-if="phase === 'museum'" class="htt-museum">
      <div class="htt-header">
        <button class="htt-back-btn" @click="goToOverview()">
          {{ t('learning', 'Zurueck zur Uebersicht') }}
        </button>
        <h2 class="htt-title">{{ t('learning', 'Museum') }} — {{ currentEpochName }}</h2>
      </div>

      <div class="htt-guide htt-guide-mini">
        <CharacterAvatar characterId="chronos" :size="48" state="explain" />
        <div class="htt-guide-speech">
          <span class="htt-guide-name">CHRONOS</span>
          <p class="htt-guide-text">
            {{ t('learning', 'Schauen wir uns an, was in dieser Epoche passiert ist...') }}
          </p>
        </div>
      </div>

      <div v-if="museumFacts.length > 0" class="htt-museum-card">
        <div class="htt-museum-year">{{ museumFacts[museumIndex].year }}</div>
        <h3 class="htt-museum-title">{{ museumFacts[museumIndex].title }}</h3>
        <div class="htt-museum-people">
          <span v-for="person in museumFacts[museumIndex].people" :key="person" class="htt-museum-person">
            {{ person }}
          </span>
        </div>
        <p class="htt-museum-impact">{{ museumFacts[museumIndex].impact }}</p>
        <div v-if="museumFacts[museumIndex].funFact" class="htt-museum-funfact">
          {{ museumFacts[museumIndex].funFact }}
        </div>
        <div class="htt-museum-nav">
          <button :disabled="museumIndex <= 0" @click="museumPrev" class="htt-btn-secondary">
            {{ t('learning', 'Zurueck') }}
          </button>
          <span class="htt-museum-counter">{{ museumIndex + 1 }} / {{ museumFacts.length }}</span>
          <button v-if="museumIndex < museumFacts.length - 1" @click="museumNext" class="htt-btn-secondary">
            {{ t('learning', 'Weiter') }}
          </button>
          <button v-else @click="startSkillCheck(currentEpochId)" class="htt-btn-primary">
            {{ t('learning', 'Weiter zur Epoche') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ===== SKILL CHECK PHASE ===== -->
    <div v-else-if="phase === 'skill_check'" class="htt-skill-check">
      <div class="htt-header">
        <button class="htt-back-btn" @click="goToOverview()">
          {{ t('learning', 'Abbrechen') }}
        </button>
        <h2 class="htt-title">{{ t('learning', 'Skill-Check') }} — {{ currentEpochName }}</h2>
      </div>

      <!-- Affinity banner -->
      <div v-if="selectedClass" class="htt-affinity-banner"
        :class="'htt-affinity-banner-' + getAffinity(currentEpochId)">
        <template v-if="getAffinity(currentEpochId) === 'bonus'">
          {{ t('learning', '{className}-Bonus: Diese Epoche ist dein Spezialgebiet!', { className: selectedClassName }) }}
        </template>
        <template v-else-if="getAffinity(currentEpochId) === 'penalty'">
          {{ t('learning', '{className}-Schwaeche: Diese Epoche ist nicht deine Staerke.', { className: selectedClassName }) }}
        </template>
        <template v-else>
          {{ t('learning', 'Neutrale Epoche fuer {className}.', { className: selectedClassName }) }}
        </template>
      </div>

      <div v-if="skillLoading" class="htt-loading">
        <div class="htt-spinner"></div>
        <p>{{ t('learning', 'Lade Fragen...') }}</p>
      </div>

      <div v-else-if="skillQuestions.length > 0 && !skillResult" class="htt-question-area">
        <div class="htt-question-progress">
          {{ t('learning', 'Frage {current} von {total}', { current: skillQuestionIndex + 1, total: skillQuestions.length }) }}
        </div>
        <div class="htt-question-card">
          <p class="htt-question-text">{{ skillQuestions[skillQuestionIndex].text }}</p>
          <div class="htt-answers">
            <button
              v-for="answer in skillQuestions[skillQuestionIndex].answers"
              :key="answer.id"
              class="htt-answer-btn"
              :class="{ 'htt-answer-selected': skillAnswers[skillQuestions[skillQuestionIndex].id] === answer.id }"
              @click="submitAnswer(skillQuestions[skillQuestionIndex].id, answer.id)"
            >
              {{ answer.text }}
            </button>
          </div>
        </div>
        <div class="htt-question-nav">
          <button :disabled="skillQuestionIndex <= 0" @click="skillQuestionIndex--" class="htt-btn-secondary">
            {{ t('learning', 'Zurueck') }}
          </button>
          <button v-if="skillQuestionIndex < skillQuestions.length - 1" @click="skillQuestionIndex++" class="htt-btn-secondary">
            {{ t('learning', 'Weiter') }}
          </button>
          <button v-else
            :disabled="Object.keys(skillAnswers).length < skillQuestions.length"
            @click="submitSkillCheck"
            class="htt-btn-primary">
            {{ t('learning', 'Abgeben') }}
          </button>
        </div>
      </div>

      <!-- Skill check result inline -->
      <div v-else-if="skillResult" class="htt-result">
        <div class="htt-guide">
          <CharacterAvatar characterId="chronos" :size="64"
            :state="skillResult.passed ? 'celebrate' : 'thinking'" />
          <div class="htt-guide-speech">
            <span class="htt-guide-name">CHRONOS</span>
            <p class="htt-guide-text">
              {{ skillResult.passed
                ? t('learning', 'Ausgezeichnet! Du hast die Epoche gemeistert.')
                : t('learning', 'Nicht schlecht, aber du solltest diese Epoche wiederholen.')
              }}
            </p>
          </div>
        </div>
        <div class="htt-result-score">
          <span class="htt-result-number">{{ skillResult.score }} / {{ skillResult.total }}</span>
          <span class="htt-result-label">
            {{ skillResult.passed ? t('learning', 'Bestanden') : t('learning', 'Nicht bestanden') }}
          </span>
        </div>
        <div v-if="selectedClass" class="htt-result-affinity">
          {{ t('learning', 'Klassen-Effekt:') }}
          <template v-if="getAffinity(currentEpochId) === 'bonus'">
            {{ t('learning', 'Bonus-Epoche (leichterer Pass-Threshold)') }}
          </template>
          <template v-else-if="getAffinity(currentEpochId) === 'penalty'">
            {{ t('learning', 'Schwaeche-Epoche (haerterer Pass-Threshold)') }}
          </template>
          <template v-else>
            {{ t('learning', 'Neutral (Standard-Threshold)') }}
          </template>
        </div>
        <div class="htt-result-actions">
          <button v-if="skillResult.passed" @click="goToOverview" class="htt-btn-primary">
            {{ t('learning', 'Naechste Epoche') }}
          </button>
          <button v-else @click="retryEpoch" class="htt-btn-primary">
            {{ t('learning', 'Nochmal versuchen') }}
          </button>
          <button @click="goToOverview" class="htt-btn-secondary">
            {{ t('learning', 'Zurueck zur Uebersicht') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ===== RESULT PHASE ===== -->
    <div v-else-if="phase === 'result'" class="htt-result-phase">
      <div class="htt-guide">
        <CharacterAvatar characterId="chronos" :size="64" state="celebrate" />
        <div class="htt-guide-speech">
          <span class="htt-guide-name">CHRONOS</span>
          <p class="htt-guide-text">
            {{ t('learning', 'Gut gemacht, Zeitreisender! Dein Wissen waechst mit jeder Epoche.') }}
          </p>
        </div>
      </div>
      <div class="htt-result-score">
        <span class="htt-result-number">{{ overallScore }}</span>
        <span class="htt-result-label">{{ t('learning', 'Gesamt-Punkte') }}</span>
      </div>
      <button @click="goToOverview" class="htt-btn-primary">
        {{ t('learning', 'Zurueck zur Uebersicht') }}
      </button>
    </div>
  </div>
</template>

<script>
import CharacterAvatar from './CharacterAvatar.vue'
import { EPOCHS, getEpoch } from '../../data/epochs/epochs.js'
import { MUSEUM_FACTS } from '../../data/epochs/museum.js'
import { CHARACTER_CLASSES, getClassAffinity } from '../data/characterClasses.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
  name: 'HackThroughTime',
  components: {
    CharacterAvatar,
  },
  props: {
    contentLanguage: {
      type: String,
      default: '',
    },
  },
  data() {
    return {
      phase: 'overview',
      selectedClass: null,
      pendingClass: null,
      loading: true,
      epochs: [],
      progress: {},
      currentEpochId: null,
      museumFacts: [],
      museumIndex: 0,
      museumViewed: {},
      skillQuestions: [],
      skillAnswers: {},
      skillQuestionIndex: 0,
      skillResult: null,
      skillLoading: false,
      error: null,
    }
  },
  computed: {
    currentEpochTheme() {
      if (!this.currentEpochId) {
        return ''
      }
      const epoch = getEpoch(this.currentEpochId)
      return epoch ? epoch.themeKey : ''
    },
    sortedEpochs() {
      return Object.values(EPOCHS).sort((a, b) => a.order - b.order)
    },
    classList() {
      return Object.values(CHARACTER_CLASSES)
    },
    selectedClassName() {
      if (!this.selectedClass) {
        return ''
      }
      const cls = CHARACTER_CLASSES[this.selectedClass]
      return cls ? cls.name : ''
    },
    currentEpochName() {
      if (!this.currentEpochId) {
        return ''
      }
      const epoch = getEpoch(this.currentEpochId)
      return epoch ? epoch.name : ''
    },
    overallScore() {
      let total = 0
      for (const key of Object.keys(this.progress)) {
        if (this.progress[key] && this.progress[key].score) {
          total += this.progress[key].score
        }
      }
      return total
    },
  },
  async mounted() {
    await Promise.all([this.loadEpochs(), this.loadProgress()])
    this.loading = false
  },
  methods: {
    async loadEpochs() {
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/zeitreise/epochs'))
        this.epochs = response.data || []
      } catch (err) {
        this.epochs = Object.values(EPOCHS)
      }
    },
    async loadProgress() {
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/zeitreise/progress'))
        const progressData = response.data || []
        const map = {}
        for (const p of progressData) {
          map[p.epoch_id] = p
          if (p.character_class && !this.selectedClass) {
            this.selectedClass = p.character_class
            this.pendingClass = p.character_class
          }
        }
        this.progress = map
      } catch (err) {
        this.progress = {}
      }
    },
    epochStatus(epochId) {
      const p = this.progress[epochId]
      if (p && p.status === 'completed') {
        return 'completed'
      }
      if (p && p.status === 'in_progress') {
        return 'in_progress'
      }
      // Check if this epoch is unlocked
      const epoch = getEpoch(epochId)
      if (!epoch) {
        return 'locked'
      }
      if (epoch.order === 1) {
        return 'available'
      }
      // Previous epoch must be completed
      const prevEpoch = this.sortedEpochs.find(e => e.order === epoch.order - 1)
      if (prevEpoch && this.progress[prevEpoch.id] && this.progress[prevEpoch.id].status === 'completed') {
        return 'available'
      }
      return 'locked'
    },
    getEpochScore(epochId) {
      const p = this.progress[epochId]
      if (p && typeof p.score === 'number') {
        return p.score
      }
      return null
    },
    getAffinity(epochId) {
      if (!this.selectedClass) {
        return 'neutral'
      }
      return getClassAffinity(this.selectedClass, epochId)
    },
    epochName(epochId) {
      const epoch = getEpoch(epochId)
      return epoch ? epoch.era : epochId
    },
    classIcon(icon) {
      const icons = {
        phone: '\u{1F4DE}',
        terminal: '\u{1F4BB}',
        target: '\u{1F3AF}',
        shield: '\u{1F6E1}',
      }
      return icons[icon] || '\u{2753}'
    },
    selectClass(classId) {
      this.selectedClass = classId
      this.pendingClass = classId
    },
    confirmClass() {
      if (this.pendingClass) {
        this.selectedClass = this.pendingClass
        this.phase = 'overview'
      }
    },
    handleEpochClick(epoch) {
      const status = this.epochStatus(epoch.id)
      if (status === 'locked') {
        return
      }
      if (!this.selectedClass) {
        this.phase = 'class_select'
        return
      }
      this.startEpoch(epoch.id)
    },
    async startEpoch(epochId) {
      this.currentEpochId = epochId
      this.museumIndex = 0
      this.museumViewed = {}
      this.skillResult = null
      this.skillAnswers = {}
      this.skillQuestionIndex = 0

      try {
        await axios.post(
          generateUrl('/apps/learning/api/zeitreise/epochs/' + epochId + '/start'),
          { characterClass: this.selectedClass }
        )
      } catch (err) {
        // Ignore — might already be started
      }

      this.viewMuseum(epochId)
    },
    async viewMuseum(epochId) {
      this.phase = 'museum'
      // Use local museum facts
      this.museumFacts = MUSEUM_FACTS[epochId] || []
      this.museumIndex = 0
      // Also try to fetch from API
      try {
        const response = await axios.get(
          generateUrl('/apps/learning/api/zeitreise/epochs/' + epochId + '/museum')
        )
        if (response.data && response.data.length > 0) {
          this.museumFacts = response.data
        }
      } catch (err) {
        // Use local facts as fallback
      }
      if (this.museumFacts.length > 0) {
        this.markMuseumViewed(this.museumFacts[0].id)
      }
    },
    async markMuseumViewed(factId) {
      if (this.museumViewed[factId]) {
        return
      }
      this.museumViewed[factId] = true
      try {
        await axios.post(
          generateUrl('/apps/learning/api/zeitreise/epochs/' + this.currentEpochId + '/museum/viewed'),
          { factId }
        )
      } catch (err) {
        // Non-critical
      }
    },
    museumPrev() {
      if (this.museumIndex > 0) {
        this.museumIndex--
      }
    },
    museumNext() {
      if (this.museumIndex < this.museumFacts.length - 1) {
        this.museumIndex++
        this.markMuseumViewed(this.museumFacts[this.museumIndex].id)
      }
    },
    async startSkillCheck(epochId) {
      this.phase = 'skill_check'
      this.skillLoading = true
      this.skillResult = null
      this.skillAnswers = {}
      this.skillQuestionIndex = 0
      try {
        const response = await axios.get(
          generateUrl('/apps/learning/api/zeitreise/epochs/' + epochId + '/skill-check')
        )
        this.skillQuestions = response.data || []
      } catch (err) {
        this.skillQuestions = []
        this.error = this.t('learning', 'Fehler beim Laden der Fragen.')
      }
      this.skillLoading = false
    },
    submitAnswer(questionId, answerId) {
      this.$set(this.skillAnswers, questionId, answerId)
    },
    async submitSkillCheck() {
      const answers = Object.keys(this.skillAnswers).map(qId => ({
        questionId: Number(qId),
        answerId: this.skillAnswers[qId],
      }))
      try {
        const response = await axios.post(
          generateUrl('/apps/learning/api/zeitreise/epochs/' + this.currentEpochId + '/skill-check'),
          { answers }
        )
        this.skillResult = response.data || { score: 0, total: answers.length, passed: false }
        // Refresh progress
        await this.loadProgress()
      } catch (err) {
        this.skillResult = { score: 0, total: answers.length, passed: false }
      }
    },
    retryEpoch() {
      if (this.currentEpochId) {
        this.startSkillCheck(this.currentEpochId)
      }
    },
    goToOverview() {
      this.phase = 'overview'
      this.currentEpochId = null
      this.skillResult = null
      this.skillQuestions = []
      this.skillAnswers = {}
      this.museumFacts = []
      this.loadProgress()
    },
  },
}
</script>

<style scoped>
.htt-root {
  padding: 16px;
  min-height: 400px;
  background-color: var(--epoch-bg, var(--color-main-background));
  color: var(--epoch-text, var(--color-main-text));
  font-family: var(--epoch-font-family, system-ui, sans-serif);
  border-radius: 12px;
}

/* Header */
.htt-header {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 20px;
}

.htt-back-btn {
  background: transparent;
  border: 1px solid var(--epoch-border, var(--color-border));
  color: var(--epoch-text, var(--color-main-text));
  padding: 6px 14px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 14px;
}

.htt-back-btn:hover {
  background: var(--epoch-accent, var(--color-primary-element));
  color: var(--epoch-bg, #fff);
}

.htt-title {
  font-size: 24px;
  font-weight: 700;
  margin: 0;
}

/* Guide */
.htt-guide {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 24px;
  padding: 16px;
  background: rgba(0, 0, 0, 0.15);
  border-radius: 10px;
  border: 1px solid var(--epoch-border, var(--color-border));
}

.htt-guide-mini {
  padding: 10px;
  margin-bottom: 16px;
}

.htt-guide-speech {
  flex: 1;
}

.htt-guide-name {
  font-weight: 700;
  font-size: 13px;
  text-transform: uppercase;
  letter-spacing: 1px;
  opacity: 0.8;
}

.htt-guide-text {
  margin: 4px 0 0;
  font-size: 15px;
  line-height: 1.5;
}

/* Class bar */
.htt-class-bar {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.htt-class-btn {
  padding: 8px 16px;
  border: 1px solid var(--epoch-border, var(--color-border));
  background: transparent;
  color: var(--epoch-text, var(--color-main-text));
  border-radius: 8px;
  cursor: pointer;
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 6px;
  transition: background 0.2s;
}

.htt-class-btn:hover {
  background: rgba(255, 255, 255, 0.1);
}

.htt-class-active {
  background: var(--epoch-accent, var(--color-primary-element));
  color: var(--epoch-bg, #fff);
  border-color: var(--epoch-accent, var(--color-primary-element));
}

.htt-class-choose {
  border-style: dashed;
  opacity: 0.8;
}

.htt-class-icon {
  font-size: 18px;
}

/* Loading */
.htt-loading {
  text-align: center;
  padding: 40px;
}

.htt-spinner {
  width: 32px;
  height: 32px;
  border: 3px solid var(--epoch-border, var(--color-border));
  border-top-color: var(--epoch-accent, var(--color-primary-element));
  border-radius: 50%;
  animation: htt-spin 0.8s linear infinite;
  margin: 0 auto 12px;
}

@keyframes htt-spin {
  to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
  .htt-spinner { animation: none; opacity: 0.6; }
}

/* Epoch grid */
.htt-epoch-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 24px;
}

.htt-epoch-card {
  padding: 20px;
  border: 1px solid var(--epoch-border, var(--color-border));
  border-radius: 10px;
  cursor: pointer;
  transition: transform 0.2s, box-shadow 0.2s;
  background: rgba(255, 255, 255, 0.05);
}

.htt-epoch-card:hover:not(.htt-epoch-locked) {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px var(--epoch-glow, rgba(0,0,0,0.2));
}

.htt-epoch-locked {
  opacity: 0.45;
  cursor: not-allowed;
}

.htt-epoch-completed {
  border-color: var(--lnc-green, #00e676);
}

.htt-epoch-active {
  border-color: var(--epoch-accent, var(--color-primary-element));
}

.htt-epoch-era {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
  opacity: 0.7;
  margin-bottom: 4px;
}

.htt-epoch-name {
  font-size: 18px;
  font-weight: 600;
  margin: 0 0 8px;
}

.htt-epoch-desc {
  font-size: 13px;
  line-height: 1.4;
  opacity: 0.8;
  margin: 0 0 12px;
}

.htt-epoch-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.htt-epoch-status {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
}

.htt-epoch-score {
  font-size: 13px;
  margin-top: 8px;
  font-weight: 600;
  color: var(--epoch-accent, var(--color-primary-element));
}

/* Affinity */
.htt-affinity-badge {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 10px;
  font-weight: 600;
}

.htt-affinity-bonus {
  background: rgba(0, 230, 118, 0.2);
  color: #00e676;
}

.htt-affinity-penalty {
  background: rgba(248, 81, 73, 0.2);
  color: #f85149;
}

/* Overall score */
.htt-overall-score {
  text-align: center;
  padding: 16px;
  font-size: 18px;
  border-top: 1px solid var(--epoch-border, var(--color-border));
}

/* Class select */
.htt-class-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
  margin-bottom: 24px;
}

.htt-class-card {
  padding: 20px;
  border: 2px solid var(--epoch-border, var(--color-border));
  border-radius: 10px;
  cursor: pointer;
  transition: border-color 0.2s;
}

.htt-class-card:hover {
  border-color: var(--epoch-accent, var(--color-primary-element));
}

.htt-class-card-selected {
  border-color: var(--epoch-accent, var(--color-primary-element));
  background: rgba(255, 255, 255, 0.05);
}

.htt-class-card-icon {
  font-size: 32px;
  margin-bottom: 8px;
}

.htt-class-card-name {
  font-size: 18px;
  font-weight: 600;
  margin: 0 0 8px;
}

.htt-class-card-desc {
  font-size: 14px;
  opacity: 0.8;
  margin: 0 0 12px;
}

.htt-class-card-affinity {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
}

.htt-affinity-strong {
  color: #00e676;
}

.htt-affinity-weak {
  color: #f85149;
}

.htt-class-confirm {
  text-align: center;
}

/* Museum */
.htt-museum-card {
  max-width: 600px;
  margin: 0 auto;
  padding: 32px;
  border: 1px solid var(--epoch-border, var(--color-border));
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.05);
}

.htt-museum-year {
  font-size: 48px;
  font-weight: 800;
  color: var(--epoch-accent, var(--color-primary-element));
  margin-bottom: 8px;
}

.htt-museum-title {
  font-size: 22px;
  font-weight: 600;
  margin: 0 0 12px;
}

.htt-museum-people {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 16px;
}

.htt-museum-person {
  padding: 3px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  background: var(--epoch-accent, var(--color-primary-element));
  color: var(--epoch-bg, #fff);
}

.htt-museum-impact {
  font-size: 15px;
  line-height: 1.6;
  margin: 0 0 16px;
}

.htt-museum-funfact {
  padding: 12px 16px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.08);
  border-left: 3px solid var(--epoch-accent, var(--color-primary-element));
  font-size: 14px;
  font-style: italic;
  margin-bottom: 20px;
}

.htt-museum-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.htt-museum-counter {
  font-size: 13px;
  opacity: 0.7;
}

/* Skill check */
.htt-affinity-banner {
  padding: 10px 16px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 20px;
}

.htt-affinity-banner-bonus {
  background: rgba(0, 230, 118, 0.15);
  color: #00e676;
  border: 1px solid rgba(0, 230, 118, 0.3);
}

.htt-affinity-banner-penalty {
  background: rgba(248, 81, 73, 0.15);
  color: #f85149;
  border: 1px solid rgba(248, 81, 73, 0.3);
}

.htt-affinity-banner-neutral {
  background: rgba(88, 166, 255, 0.15);
  color: #58a6ff;
  border: 1px solid rgba(88, 166, 255, 0.3);
}

.htt-question-progress {
  font-size: 13px;
  margin-bottom: 16px;
  opacity: 0.7;
}

.htt-question-card {
  padding: 24px;
  border: 1px solid var(--epoch-border, var(--color-border));
  border-radius: 10px;
  margin-bottom: 20px;
  background: rgba(255, 255, 255, 0.05);
}

.htt-question-text {
  font-size: 17px;
  line-height: 1.5;
  margin: 0 0 20px;
  font-weight: 500;
}

.htt-answers {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.htt-answer-btn {
  padding: 12px 16px;
  border: 1px solid var(--epoch-border, var(--color-border));
  border-radius: 8px;
  background: transparent;
  color: var(--epoch-text, var(--color-main-text));
  cursor: pointer;
  text-align: left;
  font-size: 15px;
  transition: border-color 0.2s;
}

.htt-answer-btn:hover {
  border-color: var(--epoch-accent, var(--color-primary-element));
}

.htt-answer-selected {
  border-color: var(--epoch-accent, var(--color-primary-element));
  background: rgba(88, 166, 255, 0.1);
}

.htt-question-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

/* Result */
.htt-result,
.htt-result-phase {
  text-align: center;
  padding: 24px;
}

.htt-result .htt-guide {
  justify-content: center;
  text-align: left;
}

.htt-result-score {
  margin: 24px 0;
}

.htt-result-number {
  display: block;
  font-size: 48px;
  font-weight: 800;
  color: var(--epoch-accent, var(--color-primary-element));
}

.htt-result-label {
  display: block;
  font-size: 16px;
  margin-top: 4px;
  font-weight: 600;
}

.htt-result-affinity {
  font-size: 14px;
  opacity: 0.8;
  margin-bottom: 20px;
}

.htt-result-actions {
  display: flex;
  gap: 12px;
  justify-content: center;
  flex-wrap: wrap;
}

/* Buttons */
.htt-btn-primary {
  padding: 10px 24px;
  border: none;
  border-radius: 8px;
  background: var(--epoch-accent, var(--color-primary-element));
  color: var(--epoch-bg, #fff);
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.2s;
}

.htt-btn-primary:hover {
  opacity: 0.85;
}

.htt-btn-primary:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.htt-btn-secondary {
  padding: 10px 24px;
  border: 1px solid var(--epoch-border, var(--color-border));
  border-radius: 8px;
  background: transparent;
  color: var(--epoch-text, var(--color-main-text));
  font-size: 15px;
  font-weight: 500;
  cursor: pointer;
}

.htt-btn-secondary:hover {
  background: rgba(255, 255, 255, 0.08);
}

.htt-btn-secondary:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

/* Responsive */
@media (max-width: 768px) {
  .htt-epoch-grid {
    grid-template-columns: 1fr;
  }
  .htt-class-grid {
    grid-template-columns: 1fr;
  }
  .htt-class-bar {
    flex-direction: column;
  }
  .htt-question-nav,
  .htt-museum-nav,
  .htt-result-actions {
    flex-direction: column;
  }
}

@media (prefers-reduced-motion: reduce) {
  .htt-epoch-card {
    transition: none;
  }
  .htt-class-btn,
  .htt-class-card,
  .htt-answer-btn,
  .htt-btn-primary,
  .htt-btn-secondary {
    transition: none;
  }
}
</style>
