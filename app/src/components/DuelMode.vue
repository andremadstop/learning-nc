<template>
  <div class="duel-mode">

    <!-- ===== JOIN PHASE ===== -->
    <div v-if="phase === 'join' && !hideJoinScreen" class="duel-join">
      <h3>{{ t('learning', 'Duell') }}</h3>

      <NcNoteCard v-if="error" type="error" class="duel-error">{{ error }}</NcNoteCard>

      <div class="pool-picker">
        <label class="pool-picker-label">{{ t('learning', 'Pool') }}</label>
        <select v-model="selectedPoolId" class="pool-select">
          <option :value="0" disabled>{{ t('learning', '— Pool auswählen —') }}</option>
          <option v-for="p in pools" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </div>

      <div class="pool-picker">
        <label class="pool-picker-label">{{ t('learning', 'Fragen') }}</label>
        <select v-model="numQuestions" class="pool-select">
          <option :value="5">5</option>
          <option :value="10">10</option>
          <option :value="15">15</option>
          <option :value="20">20</option>
        </select>
      </div>

      <div v-if="courseId" class="pool-picker">
        <label class="pool-picker-label">{{ t('learning', 'Directly challenge an opponent') }}</label>
        <select v-model="selectedOpponentUid" class="pool-select">
          <option value="">{{ t('learning', 'Create duel code only') }}</option>
          <option v-for="opponent in opponents" :key="opponent.user_id" :value="opponent.user_id">
            {{ opponent.display_name || opponent.user_id }}
          </option>
        </select>
        <p v-if="courseId && opponents.length === 0" class="invite-help">
          {{ t('learning', 'No direct opponents are available in this course.') }}
        </p>
      </div>

      <div class="join-section">
        <input
          v-model="joinCode"
          class="join-input"
          type="text"
          :placeholder="t('learning', 'Duell-Code eingeben')"
          maxlength="12"
          @keyup.enter="joinDuel"
        />
        <NcButton type="secondary" :disabled="loading || !joinCode.trim()" @click="joinDuel">
          {{ loading ? t('learning', 'Beitreten...') : t('learning', 'Beitreten') }}
        </NcButton>
      </div>

      <div class="join-divider"><span>{{ t('learning', 'oder') }}</span></div>

      <div class="start-actions">
        <NcButton type="primary" :disabled="loading || selectedPoolId === 0" @click="createDuel">
          {{ createButtonLabel }}
        </NcButton>
        <NcButton type="secondary" :disabled="loading || selectedPoolId === 0" @click="startBotDuel">
          🤓 {{ t('learning', 'Gegen Klaus spielen') }}
        </NcButton>
        <NcButton type="tertiary" :disabled="loading" @click="$emit('back')">
          {{ t('learning', 'Zurück') }}
        </NcButton>
      </div>
    </div>

    <div v-else-if="phase === 'join' && hideJoinScreen" class="duel-join">
      <NcNoteCard v-if="error" type="error" class="duel-error">{{ error }}</NcNoteCard>
      <NcLoadingIcon v-else :size="44" />
    </div>

    <!-- ===== LOBBY PHASE ===== -->
    <div v-else-if="phase === 'lobby'" class="duel-lobby">
      <h3>{{ t('learning', 'Lobby') }}</h3>

      <NcNoteCard v-if="error" type="error" class="duel-error">{{ error }}</NcNoteCard>

      <div class="duel-code-box">
        <span class="duel-code-label">{{ t('learning', 'Duell-Code') }}</span>
        <span class="duel-code">{{ duelCode }}</span>
        <NcButton type="secondary" @click="copyCode">{{ t('learning', 'Code kopieren') }}</NcButton>
      </div>

      <div class="lobby-players">
        <div class="lobby-player">
          <span class="player-name">{{ duelState && duelState.creator_uid }}</span>
          <span class="player-ready" :class="{ ready: duelState && duelState.creator_ready }">
            {{ duelState && duelState.creator_ready ? '✓' : '...' }}
          </span>
        </div>
        <span class="lobby-vs">{{ t('learning', 'vs') }}</span>
        <div class="lobby-player">
          <span class="player-name">
            {{ opponentUid ? opponentUid : t('learning', 'Warte auf Gegner...') }}
          </span>
          <span class="player-ready" :class="{ ready: duelState && duelState.opponent_ready }">
            {{ duelState && duelState.opponent_ready ? '✓' : (opponentUid ? '...' : '') }}
          </span>
        </div>
      </div>

      <p class="lobby-status">{{ lobbyStatusText }}</p>

      <div class="start-actions">
        <NcButton type="primary" :disabled="loading || readyClicked || !canSetReady" @click="setReady">
          {{ readyClicked ? t('learning', 'Bereit!') : t('learning', 'Bereit!') }}
        </NcButton>
        <NcButton type="tertiary" @click="cancelDuel">{{ t('learning', 'Abbrechen') }}</NcButton>
      </div>
    </div>

    <!-- ===== QUESTION PHASE ===== -->
    <div v-else-if="phase === 'question'" class="duel-question">
      <div class="progress-area">
        <div class="progress-text">
          <template v-if="botMode">
            {{ botLocalState.currentIndex + 1 }} / {{ botLocalState.totalQuestions }}
          </template>
          <template v-else>
            {{ (duelState && duelState.current_question_index + 1) || 1 }} / {{ duelState && duelState.total_questions || 10 }}
          </template>
        </div>
        <NcProgressBar :value="progressPercent" />
      </div>

      <div class="duel-score-bar">
        <span class="my-score">{{ myScore }}</span>
        <span class="score-divider">:</span>
        <span class="opp-score">{{ opponentScore }}</span>
      </div>

      <!-- Klaus-Status (nur im Bot-Modus) -->
      <div v-if="botMode && botPhrase" class="bot-status-bar">
        <span class="bot-avatar">🤓</span>
        <span class="bot-speech">{{ botPhrase }}</span>
      </div>

      <div class="duel-card">
        <QuestionLanguageSwitcher v-model="questionLanguage" :question="currentQuestion" />
        <img
          v-if="currentQuestion && currentQuestion.image_path"
          :src="questionImageUrl(currentQuestion.id)"
          alt=""
          class="question-image"
        />
        <p class="question-text">{{ currentQuestion && currentQuestion.text }}</p>

        <div v-if="hasAnswered" class="waiting-overlay">
          <span>{{ t('learning', 'Warte auf Gegner...') }}</span>
        </div>
      </div>

      <div class="answer-grid">
        <button
          v-for="answer in (currentQuestion && currentQuestion.answers || [])"
          :key="answer.id"
          class="answer-btn"
          :class="answerButtonClasses(answer)"
          :disabled="hasAnswered || loading"
          @click="onAnswer(answer.id)"
        >
          {{ answer.text }}
        </button>
      </div>

      <div v-if="disconnectDetected" class="disconnect-overlay">
        <NcNoteCard type="warning">
          {{ t('learning', 'Verbindung zum Gegner unterbrochen. Das Spiel wird beendet...') }}
        </NcNoteCard>
        <NcButton type="primary" @click="$emit('back')">{{ t('learning', 'Zurück') }}</NcButton>
      </div>

      <div class="question-abort-area">
        <NcButton type="tertiary" @click="abortGame">
          {{ t('learning', 'Abbrechen') }}
        </NcButton>
      </div>
    </div>

    <!-- ===== FEEDBACK PHASE ===== -->
    <div v-else-if="phase === 'feedback'" class="duel-feedback">
      <QuestionLanguageSwitcher v-model="questionLanguage" :question="lastQuestion || currentQuestion" />
      <div class="feedback-card" :class="answeredCorrect ? 'feedback-correct' : 'feedback-incorrect'">
        <span class="feedback-icon">{{ answeredCorrect ? '✓' : '✗' }}</span>
        <span class="feedback-label">{{ answeredCorrect ? t('learning', 'Richtig!') : t('learning', 'Falsch!') }}</span>
        <span class="feedback-points" :class="lastPoints > 0 ? 'points-positive' : (lastPoints < 0 ? 'points-negative' : '')">
          {{ lastPoints > 0 ? '+' + lastPoints : lastPoints }}
        </span>
      </div>

      <div v-if="lastQuestionAnswers.length > 0" class="answer-grid feedback-answer-grid">
        <div
          v-for="answer in lastQuestionAnswers"
          :key="'feedback-' + answer.id"
          class="answer-btn answer-btn-static"
          :class="feedbackAnswerClasses(answer)">
          {{ answer.text }}
        </div>
      </div>

      <div v-if="correctAnswerId && lastQuestion" class="correct-answer-box">
        <span class="correct-answer-label">{{ t('learning', 'Richtige Antwort:') }}</span>
        <span class="correct-answer-text">
          {{ correctAnswerText }}
        </span>
      </div>

      <div class="duel-score-bar feedback-scores">
        <span class="my-score">{{ myScore }}</span>
        <span class="score-divider">:</span>
        <span class="opp-score">{{ opponentScore }}</span>
      </div>

      <p class="feedback-wait">{{ t('learning', 'Nächste Frage...') }}</p>
    </div>

    <!-- ===== FINISHED PHASE ===== -->
    <div v-else-if="phase === 'finished'" class="duel-finished">
      <h3>{{ t('learning', 'Duell beendet!') }}</h3>

      <!-- Bot-Modus Ergebnis -->
      <template v-if="botMode">
        <div class="final-scores">
          <div class="final-player">
            <span class="player-name">{{ t('learning', 'Du') }}</span>
            <span class="final-score">{{ botLocalState.myScore }}</span>
          </div>
          <span class="score-divider">:</span>
          <div class="final-player">
            <span class="player-name">🤓 Klaus</span>
            <span class="final-score">{{ botLocalState.botScore }}</span>
          </div>
        </div>
        <p class="winner-announce">{{ botWinnerText }}</p>
        <div v-if="botKlausEndPhrase" class="bot-status-bar bot-end-phrase">
          <span class="bot-avatar">🤓</span>
          <span class="bot-speech">{{ botKlausEndPhrase }}</span>
        </div>
        <div class="start-actions">
          <NcButton type="primary" :disabled="loading" @click="startBotDuel">
            {{ t('learning', 'Rematch gegen Klaus') }}
          </NcButton>
          <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Zurück') }}</NcButton>
        </div>
      </template>

      <!-- Normales Duell-Ergebnis -->
      <template v-else>
        <div class="final-scores">
          <div class="final-player">
            <span class="player-name">{{ duelState && duelState.creator_uid }}</span>
            <span class="final-score">{{ duelState && duelState.creator_score }}</span>
          </div>
          <span class="score-divider">:</span>
          <div class="final-player">
            <span class="player-name">{{ duelState && duelState.opponent_uid }}</span>
            <span class="final-score">{{ duelState && duelState.opponent_score }}</span>
          </div>
        </div>
        <p class="winner-announce">{{ winnerText }}</p>
        <div class="start-actions">
          <NcButton type="primary" :disabled="loading" @click="doRematch">
            {{ loading ? t('learning', 'Starte...') : t('learning', 'Rematch') }}
          </NcButton>
          <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Zurück') }}</NcButton>
        </div>
      </template>
    </div>

    <!-- ===== EXPIRED PHASE ===== -->
    <div v-else-if="phase === 'expired'" class="duel-expired">
      <h3>{{ t('learning', 'Duell abgebrochen') }}</h3>
      <p>{{ t('learning', 'Ein Spieler hat die Verbindung verloren.') }}</p>
      <div class="start-actions">
        <NcButton type="primary" @click="$emit('back')">{{ t('learning', 'Zurück') }}</NcButton>
      </div>
    </div>

  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import NcProgressBar from '@nextcloud/vue/dist/Components/NcProgressBar.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import QuestionLanguageSwitcher from './QuestionLanguageSwitcher.vue';
import { botChooseAnswer, botResponseDelay, botPhrase as getBotPhrase } from '../utils/botPlayer.js';

export default {
  name: 'DuelMode',
  components: { NcButton, NcLoadingIcon, NcNoteCard, NcProgressBar, QuestionLanguageSwitcher },

  props: {
    courseId: {
      type: Number,
      default: null,
    },
    coursePools: {
      type: Array,
      default: null,
    },
    initialPoolId: {
      type: Number,
      default: null,
    },
    presetDuelCode: {
      type: String,
      default: '',
    },
    hideJoinScreen: {
      type: Boolean,
      default: false,
    },
    contentLanguage: {
      type: String,
      default: '',
    },
  },

  data() {
    return {
      phase: 'join',
      duelCode: '',
      joinCode: '',
      duelState: null,
      pools: [],
      selectedPoolId: 0,
      numQuestions: 10,
      loading: false,
      error: null,
      opponents: [],
      selectedOpponentUid: '',
      pollingInterval: null,
      hasAnswered: false,
      lastPoints: 0,
      answeredCorrect: false,
      scoreBeforeAnswer: 0,
      readyClicked: false,
      lastQuestionIndex: -1,
      correctAnswerId: null,
      selectedAnswerId: null,
      lastQuestion: null,
      questionLanguage: this.contentLanguage || '',
      consecutivePollErrors: 0,
      disconnectDetected: false,
      restoringFromStorage: false,

      // ---- Bot-Modus ----
      botMode: false,
      botLocalState: {
        questions: [],          // Aus API geladene Fragen
        currentIndex: 0,
        totalQuestions: 10,
        myScore: 0,
        botScore: 0,
        botAnswerTimeout: null,
        difficulty: 'medium',
      },
      botPhrase: '',            // Aktuell angezeigte Klaus-Sprechblase
      botKlausEndPhrase: '',    // Endsatz am Spielende
      botHasAnswered: false,    // Klaus hat diese Frage schon beantwortet
    };
  },

  computed: {
    myRole() {
      return this.duelState ? this.duelState.my_role : null;
    },
    myScore() {
      if (this.botMode) return this.botLocalState.myScore;
      if (!this.duelState) return 0;
      return this.duelState.my_role === 'creator'
        ? this.duelState.creator_score
        : this.duelState.opponent_score;
    },
    opponentScore() {
      if (this.botMode) return this.botLocalState.botScore;
      if (!this.duelState) return 0;
      return this.duelState.my_role === 'creator'
        ? this.duelState.opponent_score
        : this.duelState.creator_score;
    },
    opponentUid() {
      return this.duelState ? this.duelState.opponent_uid : null;
    },
    currentQuestion() {
      if (this.botMode) {
        const idx = this.botLocalState.currentIndex;
        return this.botLocalState.questions[idx] || null;
      }
      return this.duelState ? this.duelState.current_question : null;
    },
    progressPercent() {
      if (this.botMode) {
        return Math.round((this.botLocalState.currentIndex / this.botLocalState.totalQuestions) * 100);
      }
      if (!this.duelState) return 0;
      return Math.round((this.duelState.current_question_index / this.duelState.total_questions) * 100);
    },
    lastQuestionAnswers() {
      return this.lastQuestion && Array.isArray(this.lastQuestion.answers)
        ? this.lastQuestion.answers
        : [];
    },
    correctAnswerText() {
      const correctAnswer = this.lastQuestionAnswers.find(answer => answer.id === this.correctAnswerId);
      return correctAnswer ? correctAnswer.text : '';
    },
    winnerText() {
      if (!this.duelState) return '';
      const cs = this.duelState.creator_score;
      const os = this.duelState.opponent_score;
      const isCreator = this.duelState.my_role === 'creator';
      if (cs === os) return t('learning', 'Unentschieden!');
      const iWon = (isCreator && cs > os) || (!isCreator && os > cs);
      return iWon ? t('learning', 'Du hast gewonnen!') : t('learning', 'Gegner gewinnt');
    },
    botWinnerText() {
      const my = this.botLocalState.myScore;
      const bot = this.botLocalState.botScore;
      if (my === bot) return t('learning', 'Unentschieden!');
      return my > bot ? t('learning', 'Du hast gewonnen!') : '🤓 Klaus gewinnt!';
    },
    createButtonLabel() {
      if (this.loading) {
        return this.selectedOpponentUid
          ? t('learning', 'Challenging...')
          : t('learning', 'Erstelle...');
      }
      return this.selectedOpponentUid
        ? t('learning', 'Challenge opponent')
        : t('learning', 'Neues Duell erstellen');
    },
    canSetReady() {
      return !!this.duelState && ['ready', 'active'].includes(this.duelState.status);
    },
    lobbyStatusText() {
      if (!this.duelState) {
        return t('learning', 'Warte bis beide bereit sind...');
      }
      if (this.duelState.status === 'invited') {
        return t('learning', 'Invite sent. Waiting for the opponent to accept...')
      }
      if (this.duelState.status === 'ready' && !this.readyClicked) {
        return t('learning', 'Invite accepted. Both players can mark themselves ready now.')
      }
      return t('learning', 'Warte bis beide bereit sind...');
    },
    effectiveContentLanguage() {
      return this.questionLanguage || '';
    },
  },

  mounted() {
    if (this.coursePools && this.coursePools.length > 0) {
      // Course pools use pool_id/pool_name — normalize to id/name
      this.pools = this.coursePools.map(p => ({
        id: p.pool_id ?? p.id,
        name: p.pool_name ?? p.name,
      }));
      this.applyInitialPoolSelection();
    } else {
      this.fetchPools();
    }

    if (this.courseId) {
      this.fetchOpponents();
    }

    if (this.presetDuelCode) {
      this.loadExistingDuel(this.presetDuelCode);
    } else {
      const saved = localStorage.getItem('learning_duel_session');
      if (saved) {
        try {
          const { code } = JSON.parse(saved);
          if (code) {
            this.restoringFromStorage = true;
            this.loadExistingDuel(code);
          }
        } catch (e) {
          localStorage.removeItem('learning_duel_session');
        }
      }
    }
  },

  destroyed() {
    this.stopPolling();
    this.clearBotTimeout();
  },

  watch: {
    presetDuelCode(code) {
      if (code) {
        this.loadExistingDuel(code);
      } else if (!this.duelState) {
        this.stopPolling();
        this.phase = 'join';
        this.duelCode = '';
        this.resetRoundState();
      }
    },
    contentLanguage(newLang, oldLang) {
      if (!this.questionLanguage || this.questionLanguage === oldLang) {
        this.questionLanguage = newLang || '';
      }
    },
    questionLanguage() {
      this.refreshDisplayedQuestionLanguage();
    },
  },

  methods: {
    emitVirtuProf(triggerId, context = {}) {
      this.$root.$emit('virtuprof:trigger', triggerId, context);
    },
    applyInitialPoolSelection() {
      const desiredPoolId = this.initialPoolId;
      if (desiredPoolId && this.pools.some(pool => Number(pool.id) === Number(desiredPoolId))) {
        this.selectedPoolId = Number(desiredPoolId);
      } else if (this.pools.length === 1) {
        this.selectedPoolId = this.pools[0].id;
      }
    },
    buildStateParams() {
      return this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {};
    },
    answerPayload(answerId) {
      return {
        answerId,
        answeredAt: Date.now(),
        ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
      };
    },
    async refreshDisplayedQuestionLanguage() {
      if (this.duelCode) {
        await this.pollState();
      }
      if (!this.lastQuestion || !this.lastQuestion.id) {
        return;
      }
      try {
        const r = await axios.get(
          generateUrl('/apps/learning/api/questions/' + this.lastQuestion.id + '/translated'),
          { params: this.buildStateParams() }
        );
        this.lastQuestion = r.data;
      } catch (e) {
        // Best-effort only.
      }
    },
    resetRoundState() {
      this.hasAnswered = false;
      this.lastPoints = 0;
      this.answeredCorrect = false;
      this.scoreBeforeAnswer = 0;
      this.correctAnswerId = null;
      this.selectedAnswerId = null;
      this.lastQuestion = null;
      this.consecutivePollErrors = 0;
      this.disconnectDetected = false;
    },

    abortGame() {
      this.cancelDuel();
    },

    answerButtonClasses(answer) {
      const isSelected = this.selectedAnswerId === answer.id;
      return {
        'btn-selected': isSelected,
        'btn-selected-correct': this.hasAnswered && isSelected && this.answeredCorrect,
        'btn-selected-incorrect': this.hasAnswered && isSelected && !this.answeredCorrect,
      };
    },

    feedbackAnswerClasses(answer) {
      const isSelected = this.selectedAnswerId === answer.id;
      return {
        'btn-selected': isSelected,
        'btn-selected-correct': isSelected && this.answeredCorrect,
        'btn-selected-incorrect': isSelected && !this.answeredCorrect,
        'btn-correct-answer': answer.id === this.correctAnswerId,
      };
    },

    async loadExistingDuel(code) {
      this.loading = true;
      this.error = null;
      this.duelCode = code;
      this.resetRoundState();
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/duels/' + code + '/state'), { params: this.buildStateParams() });
        this.duelState = r.data;
        this.readyClicked = false;
        this.lastQuestionIndex = (r.data.current_question_index || 0) - 1;
        if (r.data.status === 'active') {
          this.phase = 'question';
        } else if (r.data.status === 'finished') {
          this.phase = 'finished';
        } else if (r.data.status === 'expired') {
          this.phase = 'expired';
        } else {
          this.phase = 'lobby';
        }
        this.$emit('preset-consumed');
        this.emitVirtuProf('duel-first-start');
        if (this.restoringFromStorage) {
          this.emitVirtuProf('arena-session-restored', { mode: 'duel' });
          this.restoringFromStorage = false;
        }
        if (r.data.status !== 'finished' && r.data.status !== 'expired') {
          localStorage.setItem('learning_duel_session', JSON.stringify({ code: this.duelCode }));
        }
        this.startPolling();
      } catch (e) {
        this.restoringFromStorage = false;
        this.error = e.response?.data?.error || t('learning', 'Duell konnte nicht geladen werden');
        if (!this.hideJoinScreen) {
          this.phase = 'join';
        }
      } finally {
        this.loading = false;
      }
    },

    // ---------- API helpers ----------

    async fetchPools() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/pools'));
        const own = r.data.own || [];
        const shared = r.data.shared || [];
        this.pools = [...own, ...shared];
        this.applyInitialPoolSelection();
      } catch (e) {
        this.error = t('learning', 'Pools konnten nicht geladen werden');
      }
    },

    async fetchOpponents() {
      if (!this.courseId) {
        this.opponents = [];
        return;
      }
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/courses/' + this.courseId + '/duel-opponents'));
        this.opponents = Array.isArray(r.data) ? r.data : [];
      } catch (e) {
        this.opponents = [];
      }
    },

    questionImageUrl(id) {
      return generateUrl('/apps/learning/api/questions/' + id + '/image');
    },

    // ---------- Join phase ----------

    async createDuel() {
      if (!this.selectedPoolId) return;
      this.loading = true;
      this.error = null;
      try {
        const isInvite = !!this.selectedOpponentUid && !!this.courseId;
        const endpoint = isInvite
          ? generateUrl('/apps/learning/api/duel-invites')
          : generateUrl('/apps/learning/api/duels');
        const payload = {
          poolId: this.selectedPoolId,
          numQuestions: this.numQuestions,
          ...(this.courseId ? { courseId: this.courseId } : {}),
          ...(isInvite ? { inviteeUid: this.selectedOpponentUid } : {}),
        };
        const r = await axios.post(endpoint, payload);
        this.duelCode = r.data.code || r.data.state?.code || '';
        this.duelState = r.data.state || r.data;
        this.lastQuestionIndex = -1;
        this.resetRoundState();
        this.readyClicked = false;
        this.phase = 'lobby';
        this.$root.$emit('virtuprof:refresh-duel-invites');
        this.emitVirtuProf('duel-first-start');
        localStorage.setItem('learning_duel_session', JSON.stringify({ code: this.duelCode }));
        this.startPolling();
      } catch (e) {
        this.error = e.response?.data?.error || t('learning', 'Duell konnte nicht erstellt werden');
      } finally {
        this.loading = false;
      }
    },

    async joinDuel() {
      const code = this.joinCode.trim();
      if (!code) return;
      this.loading = true;
      this.error = null;
      try {
        const r = await axios.post(generateUrl('/apps/learning/api/duels/' + code + '/join'), {});
        this.duelCode = r.data.code;
        this.duelState = r.data;
        this.joinCode = '';
        this.resetRoundState();
        this.readyClicked = false;
        this.emitVirtuProf('duel-first-start');
        if (r.data.status === 'active') {
          this.lastQuestionIndex = (r.data.current_question_index || 0) - 1;
          this.phase = 'question';
        } else {
          this.lastQuestionIndex = -1;
          this.phase = 'lobby';
        }
        localStorage.setItem('learning_duel_session', JSON.stringify({ code: this.duelCode }));
        this.startPolling();
      } catch (e) {
        this.error = e.response?.data?.error || t('learning', 'Beitreten fehlgeschlagen');
      } finally {
        this.loading = false;
      }
    },

    // ---------- Lobby phase ----------

    async copyCode() {
      try {
        await navigator.clipboard.writeText(this.duelCode);
      } catch (e) {
        // Fallback: select text
      }
    },

    async setReady() {
      if (this.readyClicked) return;
      this.readyClicked = true;
      this.error = null;
      try {
        const r = await axios.post(
          generateUrl('/apps/learning/api/duels/' + this.duelCode + '/ready'),
          this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}
        );
        this.duelState = r.data;
        this.applyStateTransitions(r.data);
      } catch (e) {
        this.readyClicked = false;
        this.error = e.response?.data?.error || t('learning', 'Bereit-Status konnte nicht gesetzt werden');
      }
    },

    clearBotTimeout() {
      if (this.botLocalState.botAnswerTimeout) {
        clearTimeout(this.botLocalState.botAnswerTimeout);
        this.botLocalState.botAnswerTimeout = null;
      }
    },

    cancelDuel() {
      this.clearBotTimeout();
      this.botMode = false;
      localStorage.removeItem('learning_duel_session');
      this.stopPolling();
      this.phase = 'join';
      this.duelCode = '';
      this.duelState = null;
      this.error = null;
      this.readyClicked = false;
      this.selectedOpponentUid = '';
      this.resetRoundState();
      this.$emit('back');
    },

    // ---------- Question phase ----------

    async onAnswer(answerId) {
      if (this.hasAnswered) return;
      this.hasAnswered = true;
      this.selectedAnswerId = answerId;

      // ---- Bot-Modus: lokale Antwortverarbeitung ----
      if (this.botMode) {
        const question = this.currentQuestion;
        const correct = question && question.answers
          ? question.answers.find(a => a.is_correct)
          : null;
        this.correctAnswerId = correct ? correct.id : null;
        this.answeredCorrect = correct ? answerId === correct.id : false;
        if (this.answeredCorrect) {
          this.botLocalState.myScore += 10;
        }
        this.lastPoints = this.answeredCorrect ? 10 : 0;
        this.lastQuestion = question;

        // Klaus eventuell aufziehen
        if (!this.botHasAnswered) {
          this.botPhrase = getBotPhrase('taunt');
        }

        if (this.botHasAnswered) {
          // Klaus hat schon geantwortet — sofort weiter
          this.botAdvanceQuestion();
        }
        // Sonst wartet onAnswer bis Klaus fertig ist (scheduleBotAnswer ruft botAdvanceQuestion auf)
        return;
      }

      // ---- Normaler Duell-Modus ----
      this.lastQuestion = this.currentQuestion;
      this.scoreBeforeAnswer = this.myScore;
      this.loading = true;
      try {
        const r = await axios.post(
          generateUrl('/apps/learning/api/duels/' + this.duelCode + '/answer'),
          this.answerPayload(answerId)
        );
        this.correctAnswerId = r.data.correct_answer_id ?? null;
        this.answeredCorrect = r.data.my_answer_correct ?? false;
        this.duelState = r.data;
        this.lastPoints = this.myScore - this.scoreBeforeAnswer;

        if (r.data.status === 'finished') {
          this.stopPolling();
          this.phase = 'finished';
        } else if (r.data.status === 'expired') {
          this.stopPolling();
          this.phase = 'expired';
        } else if (r.data.current_question_index > this.lastQuestionIndex + 1) {
          // Both answered — question advanced → show feedback then next question
          this.phase = 'feedback';
          setTimeout(() => {
            this.lastQuestionIndex = r.data.current_question_index - 1;
            this.resetRoundState();
            this.phase = 'question';
          }, 1500);
        } else {
          // We answered but opponent hasn't yet → stay in question phase with hasAnswered=true (waiting overlay)
          this.phase = 'question';
        }
      } catch (e) {
        this.hasAnswered = false;
        this.selectedAnswerId = null;
        this.correctAnswerId = null;
        this.lastQuestion = null;
        this.error = e.response?.data?.error || t('learning', 'Antwort konnte nicht gesendet werden');
      } finally {
        this.loading = false;
      }
    },

    // ---------- Finished phase ----------

    async doRematch() {
      this.loading = true;
      this.error = null;
      try {
        const r = await axios.post(
          generateUrl('/apps/learning/api/duels/' + this.duelCode + '/rematch'),
          {}
        );
        // Rematch returns { new_code, code, status, ... }
        const newCode = r.data.new_code || r.data.code;
        this.duelCode = newCode;
        this.duelState = r.data;
        this.resetRoundState();
        this.lastQuestionIndex = (r.data.current_question_index || 0) - 1;
        this.readyClicked = false;
        this.phase = 'question';  // active — skip lobby
        this.stopPolling();
        this.startPolling();
      } catch (e) {
        this.error = e.response?.data?.error || t('learning', 'Rematch fehlgeschlagen');
      } finally {
        this.loading = false;
      }
    },

    // ---------- Polling ----------

    startPolling() {
      if (this.pollingInterval) return;
      this.pollingInterval = setInterval(this.pollState, 500);
    },

    stopPolling() {
      if (this.pollingInterval) {
        clearInterval(this.pollingInterval);
        this.pollingInterval = null;
      }
    },

    async pollState() {
      if (!this.duelCode) return;
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/duels/' + this.duelCode + '/state'), { params: this.buildStateParams() });
        this.consecutivePollErrors = 0;
        this.applyStateTransitions(r.data);
      } catch (e) {
        // Network errors during polling are non-fatal — just skip this tick
        this.consecutivePollErrors++;
        if (this.consecutivePollErrors >= 60 && !this.disconnectDetected) {
          // 60 ticks * 500ms = 30 seconds
          this.disconnectDetected = true;
        }
      }
    },

    // -------- Bot-Modus --------

    async startBotDuel() {
      if (!this.selectedPoolId) return;
      this.loading = true;
      this.error = null;
      this.botMode = true;
      this.stopPolling();

      try {
        const r = await axios.get(generateUrl('/apps/learning/api/pools/' + this.selectedPoolId + '/questions'));
        const allQuestions = (r.data || []).filter(q => q.answers && q.answers.length > 0);
        if (allQuestions.length === 0) {
          this.error = t('learning', 'Keine Fragen im Pool gefunden.');
          this.botMode = false;
          this.loading = false;
          return;
        }

        // Mische Fragen und nehme bis zu numQuestions
        const shuffled = allQuestions.sort(() => Math.random() - 0.5);
        const total = Math.min(this.numQuestions, shuffled.length);

        this.botLocalState = {
          questions: shuffled.slice(0, total),
          currentIndex: 0,
          totalQuestions: total,
          myScore: 0,
          botScore: 0,
          botAnswerTimeout: null,
          difficulty: 'medium',
        };
        this.hasAnswered = false;
        this.botHasAnswered = false;
        this.botPhrase = getBotPhrase('join');
        this.botKlausEndPhrase = '';
        this.phase = 'question';

        // Klaus tippt seine Antwort nach Delay
        this.scheduleBotAnswer();
      } catch (e) {
        this.error = t('learning', 'Fragen konnten nicht geladen werden');
        this.botMode = false;
      } finally {
        this.loading = false;
      }
    },

    scheduleBotAnswer() {
      if (this.botLocalState.botAnswerTimeout) {
        clearTimeout(this.botLocalState.botAnswerTimeout);
      }
      const delay = botResponseDelay(this.botLocalState.difficulty);
      this.botPhrase = getBotPhrase('thinking');
      this.botLocalState.botAnswerTimeout = setTimeout(() => {
        this.botExecuteAnswer();
      }, delay);
    },

    botExecuteAnswer() {
      if (this.botHasAnswered) return;
      const question = this.currentQuestion;
      if (!question || !question.answers) return;

      this.botHasAnswered = true;
      const chosen = botChooseAnswer(question.answers, this.botLocalState.difficulty);
      const isCorrect = chosen && chosen.is_correct;

      if (isCorrect) {
        this.botLocalState.botScore += 10;
        this.botPhrase = getBotPhrase('correct');
      } else {
        this.botPhrase = getBotPhrase('wrong');
      }

      // Wenn auch der User schon geantwortet hat, Frage wechseln
      if (this.hasAnswered) {
        this.botAdvanceQuestion();
      }
      // Sonst wartet Klaus bis der User antwortet — onAnswer() merkt das
    },

    botAdvanceQuestion() {
      const next = this.botLocalState.currentIndex + 1;
      if (next >= this.botLocalState.totalQuestions) {
        // Spiel vorbei
        setTimeout(() => {
          const my = this.botLocalState.myScore;
          const bot = this.botLocalState.botScore;
          if (my > bot) {
            this.botKlausEndPhrase = getBotPhrase('lose');
          } else if (bot > my) {
            this.botKlausEndPhrase = getBotPhrase('win');
          } else {
            this.botKlausEndPhrase = getBotPhrase('taunt');
          }
          this.phase = 'finished';
        }, 1000);
        return;
      }

      // Kurze Feedback-Pause dann nächste Frage
      this.phase = 'feedback';
      setTimeout(() => {
        this.botLocalState.currentIndex = next;
        this.hasAnswered = false;
        this.botHasAnswered = false;
        this.selectedAnswerId = null;
        this.correctAnswerId = null;
        this.answeredCorrect = false;
        this.botPhrase = '';
        this.phase = 'question';
        this.scheduleBotAnswer();
      }, 1500);
    },

    applyStateTransitions(state) {
      // Opponent detects that the other player already started a rematch
      if (state.rematch_code && (state.status === 'finished' || state.status === 'expired')) {
        this.loadExistingDuel(state.rematch_code);
        return;
      }

      const prevIndex = this.duelState ? this.duelState.current_question_index : -1;
      this.duelState = state;

      if (state.status === 'finished') {
        localStorage.removeItem('learning_duel_session');
        this.stopPolling();
        this.phase = 'finished';
        this.$root.$emit('virtuprof:refresh-duel-invites');
        return;
      }

      if (state.status === 'expired') {
        localStorage.removeItem('learning_duel_session');
        this.stopPolling();
        this.phase = 'expired';
        this.$root.$emit('virtuprof:refresh-duel-invites');
        return;
      }

      if (state.status === 'declined') {
        this.stopPolling();
        this.phase = 'join';
        this.error = t('learning', 'The duel invite was declined.');
        this.duelCode = '';
        this.duelState = null;
        this.readyClicked = false;
        this.resetRoundState();
        this.$root.$emit('virtuprof:refresh-duel-invites');
        return;
      }

      if (state.status === 'canceled') {
        this.stopPolling();
        this.phase = 'join';
        this.error = t('learning', 'The duel invite was canceled.');
        this.duelCode = '';
        this.duelState = null;
        this.readyClicked = false;
        this.resetRoundState();
        this.$root.$emit('virtuprof:refresh-duel-invites');
        return;
      }

      if (state.status === 'active') {
        // Check if question index advanced since we last saw it
        const indexAdvanced = state.current_question_index > prevIndex && prevIndex >= 0;

        if (this.phase === 'question' && this.hasAnswered && indexAdvanced) {
          // Opponent answered too — question advanced → show feedback
          this.lastPoints = this.myScore - this.scoreBeforeAnswer;
          this.phase = 'feedback';
          const nextIndex = state.current_question_index;
          setTimeout(() => {
            this.lastQuestionIndex = nextIndex - 1;
            this.resetRoundState();
            this.phase = 'question';
          }, 1500);
          return;
        }

        if (this.phase === 'lobby' || this.phase === 'join') {
          // Duel just became active — transition to question phase
          this.lastQuestionIndex = state.current_question_index - 1;
          this.hasAnswered = false;
          this.phase = 'question';
        }
        return;
      }

      // status === 'ready', 'waiting' or 'invited' — stay in lobby
      if (this.phase === 'join') {
        this.phase = 'lobby';
      }
    },
  },
};
</script>

<style scoped>
.duel-mode {
  max-width: 600px;
  margin: 0 auto;
}

/* ===== JOIN ===== */
.duel-join {
  text-align: center;
  padding: 40px 20px;
}

.duel-join h3 {
  font-size: 28px;
  font-weight: 700;
  margin-bottom: 24px;
  color: var(--color-main-text);
}

.pool-picker {
  margin: 0 auto 24px;
  max-width: 360px;
  text-align: start;
}

.pool-picker-label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
  margin-bottom: 6px;
}

.invite-help {
  margin: 8px 0 0;
  font-size: 12px;
  color: var(--color-text-maxcontrast);
}

.pool-select {
  width: 100%;
  padding: 10px 36px 10px 12px;
  border: 2px solid var(--color-border);
  border-radius: var(--border-radius-large);
  background-color: var(--color-main-background);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23888' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  color: var(--color-main-text) !important;
  font-size: 15px;
  line-height: 1.4;
  cursor: pointer;
  appearance: none;
  -webkit-appearance: none;
  -moz-appearance: none;
}

.pool-select option {
  background-color: var(--color-main-background);
  color: var(--color-main-text);
}

.pool-select:focus {
  border-color: var(--color-primary-element);
  outline: none;
}

.join-section {
  display: flex;
  gap: 8px;
  justify-content: center;
  max-width: 420px;
  margin: 0 auto 16px;
}

.join-input {
  flex: 1;
  padding: 10px 14px;
  border: 2px solid var(--color-border);
  border-radius: var(--border-radius-large);
  background: var(--color-main-background);
  color: var(--color-main-text);
  font-size: 15px;
  font-family: monospace;
  letter-spacing: 2px;
}

.join-input:focus {
  border-color: var(--color-primary-element);
  outline: none;
}

.join-divider {
  position: relative;
  text-align: center;
  margin: 16px 0;
  color: var(--color-text-maxcontrast);
}

.join-divider::before,
.join-divider::after {
  content: '';
  position: absolute;
  top: 50%;
  width: 40%;
  height: 1px;
  background: var(--color-border);
}

.join-divider::before { left: 0; }
.join-divider::after { right: 0; }

.start-actions {
  display: flex;
  justify-content: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 16px;
}

.duel-error {
  margin: 0 auto 16px;
  max-width: 420px;
  text-align: start;
}

/* ===== LOBBY ===== */
.duel-lobby {
  text-align: center;
  padding: 40px 20px;
}

.duel-lobby h3 {
  font-size: 28px;
  font-weight: 700;
  margin-bottom: 24px;
  color: var(--color-main-text);
}

.duel-code-box {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  background: var(--color-background-hover);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 16px 24px;
  margin: 0 auto 24px;
  max-width: 400px;
  flex-wrap: wrap;
}

.duel-code-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
}

.duel-code {
  font-size: 24px;
  font-weight: 700;
  font-family: monospace;
  letter-spacing: 4px;
  color: var(--color-primary-element);
}

.lobby-players {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 20px;
  margin-bottom: 16px;
}

.lobby-player {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  min-width: 120px;
}

.player-name {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-main-text);
  word-break: break-all;
}

.player-ready {
  font-size: 22px;
  color: var(--color-text-maxcontrast);
  transition: color 0.3s;
}

.player-ready.ready {
  color: var(--color-success);
}

.lobby-vs {
  font-size: 20px;
  font-weight: 700;
  color: var(--color-text-maxcontrast);
}

.lobby-status {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 20px;
}

/* ===== QUESTION ===== */
.duel-question {
  padding: 20px;
}

.progress-area {
  margin-bottom: 16px;
}

.progress-text {
  text-align: center;
  font-size: 15px;
  font-weight: 600;
  margin-bottom: 8px;
  color: var(--color-primary-element);
}

.duel-score-bar {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  margin-bottom: 20px;
}

.my-score,
.opp-score {
  font-size: 32px;
  font-weight: 700;
  color: var(--color-main-text);
}

.score-divider {
  font-size: 24px;
  font-weight: 700;
  color: var(--color-text-maxcontrast);
}

.duel-card {
  position: relative;
  background: var(--color-main-background);
  border: 2px solid var(--color-border);
  border-radius: 16px;
  padding: 60px 24px 28px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
  text-align: center;
  margin-bottom: 24px;
  min-height: 160px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.question-image {
  max-width: 100%;
  max-height: 180px;
  border-radius: 10px;
  border: 1px solid var(--color-border);
  margin-bottom: 12px;
  object-fit: contain;
}

.question-text {
  font-size: 20px;
  font-weight: 500;
  line-height: 1.6;
  color: var(--color-main-text);
  margin: 0;
}

.waiting-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(15, 23, 42, 0.18);
  backdrop-filter: blur(1px);
  border-radius: 14px;
  font-size: 16px;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
}

.answer-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.answer-btn {
  padding: 20px 16px;
  border: 2px solid var(--color-border);
  border-radius: var(--border-radius-large);
  background: var(--color-main-background);
  cursor: pointer;
  font-size: 18px;
  font-weight: 700;
  transition: all 0.15s;
  min-height: 70px;
  color: var(--color-main-text);
}

.answer-btn:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
  border-color: var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 8%, var(--color-main-background));
}

.btn-selected {
  border-color: var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background));
}

.btn-selected-correct {
  border-color: var(--color-success);
  background: color-mix(in srgb, var(--color-success) 16%, var(--color-main-background));
  color: var(--color-success);
}

.btn-selected-incorrect {
  border-color: var(--color-error);
  background: color-mix(in srgb, var(--color-error) 12%, var(--color-main-background));
  color: var(--color-error);
}

.btn-correct-answer {
  border-color: var(--color-success);
  background: color-mix(in srgb, var(--color-success) 12%, var(--color-main-background));
}

.answer-btn:disabled {
  opacity: 0.9;
  cursor: not-allowed;
  transform: none;
}

.question-abort-area {
  margin-top: 20px;
  text-align: center;
}

.disconnect-overlay {
  margin-top: 20px;
  text-align: center;
}

/* ===== FEEDBACK ===== */
.duel-feedback {
  position: relative;
  padding: 64px 20px 40px;
  text-align: center;
}

.feedback-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 32px 24px;
  border-radius: 16px;
  margin-bottom: 24px;
  border: 2px solid transparent;
}

.feedback-correct {
  background: color-mix(in srgb, var(--color-success) 15%, var(--color-main-background));
  border-color: var(--color-success);
}

.feedback-incorrect {
  background: color-mix(in srgb, var(--color-error) 15%, var(--color-main-background));
  border-color: var(--color-error);
}

.feedback-icon {
  font-size: 52px;
  font-weight: 700;
  line-height: 1;
}

.feedback-correct .feedback-icon { color: var(--color-success); }
.feedback-incorrect .feedback-icon { color: var(--color-error); }

.feedback-label {
  font-size: 22px;
  font-weight: 700;
}

.feedback-correct .feedback-label { color: var(--color-success); }
.feedback-incorrect .feedback-label { color: var(--color-error); }

.feedback-points {
  font-size: 28px;
  font-weight: 700;
  margin-top: 4px;
}

.points-positive { color: var(--color-success); }
.points-negative { color: var(--color-error); }

.correct-answer-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  background: color-mix(in srgb, var(--color-success) 10%, var(--color-main-background));
  border: 1px solid var(--color-success);
  border-radius: 10px;
  padding: 12px 20px;
  margin-bottom: 16px;
}

.correct-answer-label {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--color-success);
}

.correct-answer-text {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-main-text);
  text-align: center;
}

.feedback-answer-grid {
  margin-bottom: 16px;
}

.answer-btn-static {
  cursor: default;
}

.feedback-scores {
  margin-bottom: 16px;
}

.feedback-wait {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
  animation: pulse 1.2s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 0.5; }
  50% { opacity: 1; }
}

/* ===== FINISHED ===== */
.duel-finished {
  text-align: center;
  padding: 40px 20px;
}

.duel-finished h3 {
  font-size: 28px;
  font-weight: 700;
  margin-bottom: 32px;
  color: var(--color-main-text);
}

.final-scores {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 20px;
  margin-bottom: 24px;
}

.final-player {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}

.final-score {
  font-size: 52px;
  font-weight: 700;
  color: var(--color-primary-element);
}

.winner-announce {
  font-size: 22px;
  font-weight: 700;
  color: var(--color-main-text);
  margin-bottom: 32px;
}

/* ===== EXPIRED ===== */
.duel-expired {
  text-align: center;
  padding: 60px 20px;
}

.duel-expired h3 {
  font-size: 28px;
  font-weight: 700;
  margin-bottom: 16px;
  color: var(--color-main-text);
}

.duel-expired p {
  font-size: 16px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 32px;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 480px) {
  .duel-join,
  .duel-lobby,
  .duel-finished,
  .duel-expired { padding: 24px 12px; }

  .duel-card { padding: 56px 16px 20px; min-height: 130px; }
  .question-text { font-size: 17px; }
  .answer-grid { grid-template-columns: 1fr; }
  .answer-btn { min-height: 58px; }
  .final-scores { gap: 12px; }
  .final-score { font-size: 40px; }
  .lobby-players { gap: 12px; }
}

@media (prefers-reduced-motion: reduce) {
  .feedback-wait { animation: none; }
  .answer-btn { transition: none; }
}

/* ===== Klaus Bot-Status ===== */
.bot-status-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--color-background-dark, #f4f4f4);
  border: 1px solid var(--color-border, #ddd);
  border-radius: var(--border-radius-large, 8px);
  padding: 8px 14px;
  margin: 8px 0;
  font-size: 14px;
  color: var(--color-main-text);
}
.bot-avatar {
  font-size: 22px;
  flex-shrink: 0;
}
.bot-speech {
  font-style: italic;
}
.bot-end-phrase {
  margin-top: 12px;
  background: var(--color-background-hover, #eef);
}
</style>
