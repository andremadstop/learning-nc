<template>
  <div class="wissensturm-mode">

    <!-- ===== JOIN PHASE ===== -->
    <div v-if="phase === 'join'" class="wt-join">
      <h3>{{ t('learning', 'Wissensturm') }}</h3>
      <p class="wt-tagline">{{ t('learning', 'Sammel alle 5 Kategorien und baue den h\u00f6chsten Turm!') }}</p>

      <NcNoteCard v-if="error" type="error" class="wt-error">{{ error }}</NcNoteCard>

      <div class="wt-category-preview">
        <div
          v-for="(cat, i) in categoriesPreview"
          :key="i"
          class="wt-cat-chip"
          :style="{ background: COLORS[i] }"
        >
          {{ cat }}
        </div>
      </div>

      <div class="wt-pool-picker">
        <label class="wt-picker-label">{{ t('learning', 'Max. Spieler') }}</label>
        <select v-model="maxPlayers" class="wt-select">
          <option :value="2">2</option>
          <option :value="3">3</option>
          <option :value="4">4</option>
          <option :value="5">5</option>
        </select>
      </div>

      <div class="wt-join-section">
        <input
          v-model="joinCode"
          class="wt-join-input"
          type="text"
          :placeholder="t('learning', 'Wissensturm-Code eingeben')"
          maxlength="12"
          @keyup.enter="joinGame"
        />
        <NcButton type="secondary" :disabled="loading || !joinCode.trim()" @click="joinGame">
          {{ loading ? t('learning', 'Beitreten...') : t('learning', 'Beitreten') }}
        </NcButton>
      </div>

      <div class="wt-divider"><span>{{ t('learning', 'oder') }}</span></div>

      <div class="wt-start-actions">
        <NcButton type="primary" :disabled="loading" @click="createGame">
          {{ loading ? t('learning', 'Erstelle...') : t('learning', 'Neues Spiel starten') }}
        </NcButton>
        <NcButton type="secondary" :disabled="loading || !coursePools || coursePools.length === 0" @click="startBotGame">
          🤓 {{ t('learning', 'Gegen Klaus spielen') }}
        </NcButton>
        <NcButton type="tertiary" :disabled="loading" @click="$emit('back')">
          {{ t('learning', 'Zur\u00fcck') }}
        </NcButton>
      </div>
    </div>

    <!-- ===== LOBBY PHASE ===== -->
    <div v-else-if="phase === 'lobby'" class="wt-lobby">
      <h3>{{ t('learning', 'Wissensturm Lobby') }}</h3>

      <NcNoteCard v-if="error" type="error" class="wt-error">{{ error }}</NcNoteCard>

      <div class="wt-code-box">
        <span class="wt-code-label">{{ t('learning', 'Spielcode') }}</span>
        <span class="wt-code">{{ gameshowCode }}</span>
        <NcButton type="secondary" @click="copyCode">{{ t('learning', 'Code kopieren') }}</NcButton>
      </div>

      <div class="wt-lobby-players">
        <div
          v-for="player in activePlayers"
          :key="player.user_id"
          class="wt-lobby-player"
        >
          <span class="wt-player-name">{{ player.display_name || player.user_id }}</span>
          <span class="wt-player-ready" :class="{ ready: player.is_ready }">
            {{ player.is_ready ? '\u2713' : '...' }}
          </span>
        </div>
        <div v-for="n in emptySlots" :key="'empty-' + n" class="wt-lobby-player wt-lobby-empty">
          <span class="wt-player-name-empty">{{ t('learning', 'Warte auf Spieler...') }}</span>
        </div>
      </div>

      <p class="wt-lobby-status">{{ lobbyStatusText }}</p>

      <div class="wt-start-actions">
        <NcButton type="primary" :disabled="loading || readyClicked" @click="setReady">
          {{ t('learning', 'Bereit!') }}
        </NcButton>
        <NcButton type="tertiary" @click="cancelGame">{{ t('learning', 'Abbrechen') }}</NcButton>
      </div>
    </div>

    <!-- ===== GAME PHASE ===== -->
    <div v-else-if="phase === 'game'" class="wt-game">
      <NcNoteCard v-if="error" type="error" class="wt-error">{{ error }}</NcNoteCard>

      <!-- Towers display -->
      <div class="wt-towers-area">
        <div
          v-for="player in activePlayers"
          :key="'tower-' + player.user_id"
          class="wt-tower-col"
          :class="{ 'wt-tower-active': isActivePlayer(player) }"
        >
          <div class="wt-player-label">
            <span>{{ player.display_name || player.user_id }}</span>
            <span v-if="player.slot === mySlot" class="wt-you-badge">{{ t('learning', 'Du') }}</span>
            <span v-if="isActivePlayer(player)" class="wt-turn-badge">{{ t('learning', 'Dran') }}</span>
          </div>

          <!-- Tower blocks (bottom to top) -->
          <div class="wt-tower">
            <div
              v-for="(blockCat, bi) in towerBlocks(player)"
              :key="'block-' + player.user_id + '-' + bi"
              class="wt-block"
              :class="{ 'wt-block-new': bi === towerBlocks(player).length - 1 && recentGainSlot === player.slot }"
              :style="{ background: colorForCategory(blockCat) }"
            >
              <span class="wt-block-label">{{ categoryLabel(blockCat) }}</span>
            </div>
            <!-- Empty slots to show goal height -->
            <div
              v-for="n in Math.max(0, WIN_BLOCKS - towerBlocks(player).length)"
              :key="'empty-block-' + player.user_id + '-' + n"
              class="wt-block wt-block-empty"
            >
            </div>
          </div>

          <!-- Steal indicator -->
          <div v-if="stealPendingFromSlot === player.slot" class="wt-steal-indicator">
            {{ t('learning', '\u2605 Steal m\u00f6glich!') }}
          </div>
        </div>
      </div>

      <!-- Active turn info -->
      <div class="wt-turn-info">
        <span v-if="isMyTurn && boardPhase === 'roll'">
          {{ t('learning', 'Du bist dran! W\u00e4hle eine Kategorie.') }}
        </span>
        <span v-else-if="isMyTurn && boardPhase === 'question'">
          {{ t('learning', 'Beantworte die Frage!') }}
        </span>
        <span v-else>
          {{ t('learning', '{name} ist am Zug...', { name: activePlayerName }) }}
        </span>
      </div>

      <!-- Klaus-Sprechblase (nur im Bot-Modus) -->
      <div v-if="botMode && botPhrase" class="bot-status-bar">
        <span class="bot-avatar">🤓</span>
        <span class="bot-speech">{{ botPhrase }}</span>
      </div>

      <!-- Category selection (only for active player in roll phase) -->
      <div v-if="isMyTurn && boardPhase === 'roll'" class="wt-category-grid">
        <button
          v-for="(cat, i) in categories"
          :key="'cat-' + i"
          class="wt-cat-btn"
          :class="{ 'wt-cat-btn-owned': myTowerHasCategory(cat.poolId), 'wt-cat-btn-disabled': loading }"
          :style="{ '--cat-color': COLORS[i] }"
          :disabled="loading"
          @click="onSelectCategory(cat.poolId, i)"
        >
          <span class="wt-cat-color-dot" :style="{ background: COLORS[i] }"></span>
          <span class="wt-cat-name">{{ cat.name }}</span>
          <span v-if="myTowerHasCategory(cat.poolId)" class="wt-cat-check">\u2713</span>
        </button>
      </div>

      <!-- Question phase (only active player answers) -->
      <div v-if="isMyTurn && boardPhase === 'question' && currentQuestion" class="wt-question-area">
        <div class="wt-selected-cat-badge" :style="{ background: selectedCategoryColor }">
          {{ selectedCategoryName }}
        </div>

        <img
          v-if="currentQuestion.image_path"
          :src="questionImageUrl(currentQuestion.id)"
          :alt="currentQuestion.text || ''"
          class="wt-question-image"
        />
        <p class="wt-question-text">{{ currentQuestion.text }}</p>

        <div class="wt-answer-grid">
          <button
            v-for="answer in (currentQuestion.answers || [])"
            :key="answer.id"
            class="wt-answer-btn"
            :class="answerBtnClasses(answer)"
            :disabled="hasAnswered || loading"
            @click="onAnswer(answer.id)"
          >
            {{ answer.text }}
          </button>
        </div>
      </div>

      <!-- Waiting for other player -->
      <div v-if="!isMyTurn && boardPhase === 'question'" class="wt-waiting">
        <NcLoadingIcon :size="32" />
        <p>{{ t('learning', 'Warte auf {name}...', { name: activePlayerName }) }}</p>
      </div>

      <!-- Steal notification -->
      <div v-if="stealNotification" class="wt-steal-notification" role="alert" aria-live="polite">
        {{ stealNotification }}
      </div>

      <NcButton type="tertiary" class="wt-abort-btn" @click="cancelGame">
        {{ t('learning', 'Spiel verlassen') }}
      </NcButton>
    </div>

    <!-- ===== FEEDBACK PHASE ===== -->
    <div v-else-if="phase === 'feedback'" class="wt-feedback">
      <div class="wt-feedback-card" :class="answeredCorrect ? 'wt-feedback-correct' : 'wt-feedback-wrong'">
        <span class="wt-feedback-icon">{{ answeredCorrect ? '\u2713' : '\u2717' }}</span>
        <span class="wt-feedback-label">
          {{ answeredCorrect ? t('learning', 'Richtig!') : t('learning', 'Falsch!') }}
        </span>
        <span v-if="answeredCorrect && stealOccurred" class="wt-feedback-steal">
          {{ t('learning', 'Block gestohlen!') }}
        </span>
        <span v-else-if="!answeredCorrect" class="wt-feedback-meta">
          {{ t('learning', 'Oberster Block f\u00e4llt ab!') }}
        </span>
      </div>

      <p class="wt-feedback-wait">{{ t('learning', 'N\u00e4chster Zug...') }}</p>
    </div>

    <!-- ===== FINISHED PHASE ===== -->
    <div v-else-if="phase === 'finished'" class="wt-finished">
      <h3>{{ t('learning', 'Wissensturm gew\u00f6nnen!') }}</h3>

      <div v-if="winner" class="wt-winner-card">
        <span class="wt-winner-crown">&#x1F3C6;</span>
        <span class="wt-winner-name">{{ winner.display_name || winner.user_id }}</span>
        <span class="wt-winner-label">{{ t('learning', 'Hat alle 5 Farben gesammelt!') }}</span>
      </div>

      <!-- Final towers -->
      <div class="wt-towers-area wt-towers-final">
        <div
          v-for="player in activePlayers"
          :key="'final-tower-' + player.user_id"
          class="wt-tower-col"
        >
          <div class="wt-player-label">
            <span>{{ player.display_name || player.user_id }}</span>
            <span v-if="winner && player.user_id === winner.user_id" class="wt-you-badge">&#x1F3C6;</span>
          </div>
          <div class="wt-tower">
            <div
              v-for="(blockCat, bi) in towerBlocks(player)"
              :key="'final-block-' + player.user_id + '-' + bi"
              class="wt-block"
              :style="{ background: colorForCategory(blockCat) }"
            >
              <span class="wt-block-label">{{ categoryLabel(blockCat) }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="wt-start-actions">
        <NcButton type="primary" @click="newRound">{{ t('learning', 'Neue Runde') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Zur\u00fcck') }}</NcButton>
      </div>
    </div>

    <!-- ===== EXPIRED PHASE ===== -->
    <div v-else-if="phase === 'expired'" class="wt-expired">
      <h3>{{ t('learning', 'Spiel abgebrochen') }}</h3>
      <p>{{ t('learning', 'Ein Spieler hat die Verbindung verloren.') }}</p>
      <div class="wt-start-actions">
        <NcButton type="primary" @click="$emit('back')">{{ t('learning', 'Zur\u00fcck') }}</NcButton>
      </div>
    </div>

  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { botChooseAnswer, botResponseDelay, botChooseCategory, botPhrase as getBotPhrase } from '../utils/botPlayer.js';
import { useOptionalVirtuProfStore } from '../stores/virtuProfStore.js';

const COLORS = ['#2196f3', '#e53935', '#4caf50', '#ffc107', '#9c27b0'];
const WIN_BLOCKS = 5;
const POLL_INTERVAL_MS = 1000;

export default {
  name: 'WissensturmMode',
  components: { NcButton, NcNoteCard, NcLoadingIcon },

  props: {
    courseId: {
      type: Number,
      default: null,
    },
    coursePools: {
      type: Array,
      default: null,
    },
    contentLanguage: {
      type: String,
      default: '',
    },
  },

  data() {
    return {
      COLORS,
      WIN_BLOCKS,

      // UI state machine
      phase: 'join', // join | lobby | game | feedback | finished | expired

      // Session
      gameshowCode: '',
      joinCode: '',
      maxPlayers: 2,
      loading: false,
      error: null,
      state: null,  // Full server state from last poll

      // Polling
      pollingInterval: null,
      consecutivePollErrors: 0,

      // Game state derived
      readyClicked: false,
      hasAnswered: false,
      answeredCorrect: false,
      correctAnswerId: null,
      selectedAnswerId: null,
      selectedCategoryIndex: -1,
      stealOccurred: false,
      stealNotification: null,
      recentGainSlot: null,
      finishedCelebrated: false,

      // ---- Bot-Modus ----
      botMode: false,
      botLocalTowers: { 0: [], 1: [] },  // slot -> array of poolIds
      botActiveTurn: 0,                   // 0=User, 1=Klaus
      botBoardPhase: 'roll',              // 'roll' | 'question'
      botCurrentQuestion: null,
      botAnswerTimeout: null,
      botPhrase: '',
      botDifficulty: 'medium',
    };
  },

  computed: {
    // Categories: first 5 pools in the course, cycling if fewer
    categories() {
      if (!this.coursePools || this.coursePools.length === 0) return [];
      const pools = this.coursePools.slice(0, WIN_BLOCKS);
      // If fewer than 5, cycle
      const result = [];
      for (let i = 0; i < WIN_BLOCKS; i++) {
        const pool = pools[i % pools.length];
        result.push({
          poolId: pool.pool_id ?? pool.id,
          name: pool.pool_name ?? pool.name ?? ('Pool ' + (i + 1)),
        });
      }
      return result;
    },

    categoriesPreview() {
      return this.categories.map(c => c.name);
    },

    activePlayers() {
      if (this.botMode) {
        return [
          { user_id: 'me', display_name: t('learning', 'Du'), slot: 0, is_ready: true },
          { user_id: 'bot-klaus', display_name: 'Klaus', slot: 1, is_ready: true, is_bot: true },
        ];
      }
      if (!this.state || !this.state.players) return [];
      return this.state.players.filter(p => !p.is_removed);
    },

    emptySlots() {
      if (!this.state) return 0;
      return Math.max(0, (this.state.max_players || 2) - this.activePlayers.length);
    },

    mySlot() {
      if (this.botMode) return 0;
      return this.state ? this.state.my_slot : null;
    },

    boardState() {
      return (this.state && this.state.board_state) ? this.state.board_state : null;
    },

    boardPhase() {
      if (this.botMode) return this.botBoardPhase;
      return this.boardState ? (this.boardState.phase || 'roll') : 'roll';
    },

    activePlayerIndex() {
      if (this.botMode) return this.botActiveTurn;
      return this.boardState ? (this.boardState.active_player_index ?? 0) : 0;
    },

    isMyTurn() {
      if (this.botMode) return this.botActiveTurn === 0;
      return this.mySlot === this.activePlayerIndex;
    },

    activePlayerName() {
      if (this.botMode) return this.botActiveTurn === 0 ? t('learning', 'Du') : 'Klaus';
      const player = this.activePlayers.find(p => p.slot === this.activePlayerIndex);
      return player ? (player.display_name || player.user_id) : '';
    },

    currentQuestion() {
      if (this.botMode) return this.botCurrentQuestion;
      if (!this.state) return null;
      // After selectCategory, override question comes from board_state
      if (this.boardState && this.boardState.current_question_override) {
        // The current question from the server state
        return this.state.current_question || null;
      }
      return this.state.current_question || null;
    },

    selectedCategoryColor() {
      if (this.selectedCategoryIndex < 0) return '#888';
      return COLORS[this.selectedCategoryIndex] || '#888';
    },

    selectedCategoryName() {
      if (this.selectedCategoryIndex < 0) return '';
      return this.categories[this.selectedCategoryIndex]
        ? this.categories[this.selectedCategoryIndex].name
        : '';
    },

    stealPendingFromSlot() {
      if (!this.boardState) return null;
      return this.boardState.steal_pending ? this.boardState.steal_from_slot : null;
    },

    winner() {
      if (this.botMode) {
        if ((this.botLocalTowers[0] || []).length >= WIN_BLOCKS) {
          return this.activePlayers.find(p => p.slot === 0) || null;
        }
        if ((this.botLocalTowers[1] || []).length >= WIN_BLOCKS) {
          return this.activePlayers.find(p => p.slot === 1) || null;
        }
        return null;
      }
      if (!this.state) return null;
      if (this.state.winner_user_id) {
        return this.activePlayers.find(p => p.user_id === this.state.winner_user_id) || null;
      }
      return null;
    },

    lobbyStatusText() {
      if (!this.state) return t('learning', 'Warte auf Spieler...');
      const allReady = this.activePlayers.length >= 2 && this.activePlayers.every(p => p.is_ready);
      if (allReady) return t('learning', 'Alle bereit! Spiel startet...');
      if (this.activePlayers.length < 2) return t('learning', 'Warte auf Spieler...');
      return t('learning', 'Warte bis alle bereit sind...');
    },
  },

  mounted() {
    // Try to recover session from localStorage
    const saved = localStorage.getItem('learning_wissensturm_session');
    if (saved) {
      try {
        const { code } = JSON.parse(saved);
        if (code) {
          this.recoverSession(code);
          return;
        }
      } catch (e) {
        localStorage.removeItem('learning_wissensturm_session');
      }
    }
  },

  destroyed() {
    this.stopPolling();
    this.clearBotTimeout();
  },

  methods: {
    // ---------- Bot-Modus ----------

    startBotGame() {
      if (!this.coursePools || this.coursePools.length === 0) {
        this.error = t('learning', 'Kein Pool verf\u00fcgbar.');
        return;
      }
      this.botMode = true;
      this.stopPolling();
      this.botLocalTowers = { 0: [], 1: [] };
      this.botActiveTurn = 0;
      this.botBoardPhase = 'roll';
      this.botCurrentQuestion = null;
      this.botPhrase = getBotPhrase('join');
      this.resetRoundState();
      this.phase = 'game';
    },

    clearBotTimeout() {
      if (this.botAnswerTimeout) {
        clearTimeout(this.botAnswerTimeout);
        this.botAnswerTimeout = null;
      }
    },

    // Holt eine Frage aus dem angegebenen Pool via API (Bot-Modus)
    async botFetchQuestion(poolId) {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/pools/' + poolId + '/questions'));
        const qs = (r.data || []).filter(q => q.answers && q.answers.length > 0);
        if (qs.length === 0) return null;
        return qs[Math.floor(Math.random() * qs.length)];
      } catch (e) {
        return null;
      }
    },

    // Klaus nimmt seinen Zug
    async botTakeTurn() {
      this.botBoardPhase = 'roll';
      this.botPhrase = getBotPhrase('thinking');

      // Klaus wählt eine Kategorie nach Delay
      const rollDelay = 1500 + Math.random() * 1000;
      this.botAnswerTimeout = setTimeout(async () => {
        // Wähle Kategorie — bevorzuge fehlende
        const missingCats = this.categories.filter(
          cat => !(this.botLocalTowers[1] || []).map(id => parseInt(id, 10)).includes(cat.poolId)
        );
        const availableCats = missingCats.length > 0 ? missingCats : this.categories;
        const chosen = botChooseCategory(availableCats);
        if (!chosen) {
          this.botEndTurn();
          return;
        }

        // Lade Frage
        this.botBoardPhase = 'question';
        const question = await this.botFetchQuestion(chosen.poolId);
        if (!question) {
          this.botEndTurn();
          return;
        }

        this.botCurrentQuestion = question;

        // Klaus antwortet nach Delay
        const answerDelay = botResponseDelay(this.botDifficulty);
        this.botAnswerTimeout = setTimeout(() => {
          const answered = botChooseAnswer(question.answers, this.botDifficulty);
          const isCorrect = answered && answered.is_correct;
          if (isCorrect) {
            // Block auf Klaus' Turm
            const newTower = [...(this.botLocalTowers[1] || []), String(chosen.poolId)];
            this.botLocalTowers = { ...this.botLocalTowers, 1: newTower };
            this.botPhrase = getBotPhrase('correct');

            // Prüfe Gewinn-Bedingung
            const uniqueBlocks = new Set(newTower.map(id => parseInt(id, 10)));
            if (uniqueBlocks.size >= WIN_BLOCKS) {
              this.botPhrase = getBotPhrase('win');
              this.phase = 'finished';
              return;
            }
          } else {
            this.botPhrase = getBotPhrase('wrong');
          }
          this.botCurrentQuestion = null;
          this.botEndTurn();
        }, answerDelay);
      }, rollDelay);
    },

    botEndTurn() {
      this.botBoardPhase = 'roll';
      this.botCurrentQuestion = null;
      setTimeout(() => {
        this.botActiveTurn = 0;
      }, 600);
    },

    // ---------- Helpers ----------

    emitVirtuProf(triggerId, context = {}) {
      useOptionalVirtuProfStore()?.trigger(triggerId, context);
    },

    towerBlocks(player) {
      if (this.botMode) {
        return this.botLocalTowers[player.slot] || [];
      }
      if (!this.boardState || !this.boardState.towers) return [];
      const key = String(player.slot);
      return this.boardState.towers[key] || [];
    },

    colorForCategory(poolIdStr) {
      const poolId = parseInt(poolIdStr, 10);
      const idx = this.categories.findIndex(c => c.poolId === poolId);
      if (idx >= 0) return COLORS[idx % COLORS.length];
      // Fallback: hash poolId to a color index
      return COLORS[poolId % COLORS.length];
    },

    categoryLabel(poolIdStr) {
      const poolId = parseInt(poolIdStr, 10);
      const cat = this.categories.find(c => c.poolId === poolId);
      return cat ? (cat.name.length > 8 ? cat.name.slice(0, 7) + '\u2026' : cat.name) : String(poolId);
    },

    isActivePlayer(player) {
      return player.slot === this.activePlayerIndex && this.phase === 'game';
    },

    myTowerHasCategory(poolId) {
      if (this.botMode) {
        return (this.botLocalTowers[0] || []).map(id => parseInt(id, 10)).includes(poolId);
      }
      if (!this.boardState || this.mySlot === null) return false;
      const key = String(this.mySlot);
      const tower = this.boardState.towers ? (this.boardState.towers[key] || []) : [];
      return tower.map(id => parseInt(id, 10)).includes(poolId);
    },

    isMyPlayer(player) {
      return player.slot === this.mySlot;
    },

    answerBtnClasses(answer) {
      return {
        'wt-answer-selected': this.selectedAnswerId === answer.id,
        'wt-answer-correct': this.hasAnswered && answer.id === this.correctAnswerId,
        'wt-answer-wrong': this.hasAnswered && this.selectedAnswerId === answer.id && !this.answeredCorrect,
      };
    },

    questionImageUrl(id) {
      return generateUrl('/apps/learning/api/questions/' + id + '/image');
    },

    buildStateParams() {
      return this.contentLanguage ? { lang: this.contentLanguage } : {};
    },

    // ---------- Session management ----------

    async recoverSession(code) {
      this.loading = true;
      try {
        const r = await axios.get(
          generateUrl('/apps/learning/api/gameshow/' + code + '/state'),
          { params: this.buildStateParams() }
        );
        if (['waiting', 'active'].includes(r.data.status) && r.data.mode === 'wissensturm') {
          this.gameshowCode = code;
          this.state = r.data;
          this.readyClicked = false;
          this.finishedCelebrated = false;
          this.resetRoundState();
          this.phase = r.data.status === 'active' ? 'game' : 'lobby';
          this.startPolling();
        } else {
          localStorage.removeItem('learning_wissensturm_session');
        }
      } catch (e) {
        localStorage.removeItem('learning_wissensturm_session');
      } finally {
        this.loading = false;
      }
    },

    async createGame() {
      if (!this.coursePools || this.coursePools.length === 0) {
        this.error = t('learning', 'Kein Pool verf\u00fcgbar. Bitte f\u00fcge dem Kurs zuerst Pools hinzu.');
        return;
      }
      const firstPool = this.coursePools[0];
      const poolId = firstPool.pool_id ?? firstPool.id;

      this.loading = true;
      this.error = null;
      try {
        const payload = {
          poolId,
          mode: 'wissensturm',
          maxPlayers: this.maxPlayers,
          ...(this.courseId ? { courseId: this.courseId } : {}),
        };
        const r = await axios.post(generateUrl('/apps/learning/api/gameshow'), payload);
        this.gameshowCode = r.data.code;
        this.state = r.data;
        this.readyClicked = false;
        this.finishedCelebrated = false;
        this.resetRoundState();
        this.phase = 'lobby';
        localStorage.setItem('learning_wissensturm_session', JSON.stringify({ code: this.gameshowCode }));
        this.startPolling();
      } catch (e) {
        this.error = e.response?.data?.error || t('learning', 'Spiel konnte nicht erstellt werden');
      } finally {
        this.loading = false;
      }
    },

    async joinGame() {
      const code = this.joinCode.trim();
      if (!code) return;
      this.loading = true;
      this.error = null;
      try {
        const r = await axios.post(generateUrl('/apps/learning/api/gameshow/' + code + '/join'), {});
        if (r.data.mode !== 'wissensturm') {
          this.error = t('learning', 'Dieser Code ist kein Wissensturm-Spiel');
          return;
        }
        this.gameshowCode = r.data.code;
        this.state = r.data;
        this.joinCode = '';
        this.readyClicked = false;
        this.finishedCelebrated = false;
        this.resetRoundState();
        this.phase = r.data.status === 'active' ? 'game' : 'lobby';
        localStorage.setItem('learning_wissensturm_session', JSON.stringify({ code: this.gameshowCode }));
        this.startPolling();
      } catch (e) {
        this.error = e.response?.data?.error || t('learning', 'Beitreten fehlgeschlagen');
      } finally {
        this.loading = false;
      }
    },

    async copyCode() {
      try {
        await navigator.clipboard.writeText(this.gameshowCode);
      } catch (e) {
        // Best effort
      }
    },

    async setReady() {
      if (this.readyClicked) return;
      this.readyClicked = true;
      this.error = null;
      try {
        const r = await axios.post(
          generateUrl('/apps/learning/api/gameshow/' + this.gameshowCode + '/ready'),
          this.contentLanguage ? { lang: this.contentLanguage } : {}
        );
        this.state = r.data;
        this.applyStateTransitions(r.data);
      } catch (e) {
        this.readyClicked = false;
        this.error = e.response?.data?.error || t('learning', 'Bereit-Status konnte nicht gesetzt werden');
      }
    },

    cancelGame() {
      this.clearBotTimeout();
      this.botMode = false;
      localStorage.removeItem('learning_wissensturm_session');
      this.stopPolling();
      this.phase = 'join';
      this.gameshowCode = '';
      this.state = null;
      this.error = null;
      this.readyClicked = false;
      this.finishedCelebrated = false;
      this.resetRoundState();
      this.$emit('back');
    },

    resetRoundState() {
      this.hasAnswered = false;
      this.answeredCorrect = false;
      this.correctAnswerId = null;
      this.selectedAnswerId = null;
      this.selectedCategoryIndex = -1;
      this.stealOccurred = false;
      this.stealNotification = null;
      this.recentGainSlot = null;
      this.consecutivePollErrors = 0;
    },

    newRound() {
      this.stopPolling();
      this.gameshowCode = '';
      this.state = null;
      this.readyClicked = false;
      this.finishedCelebrated = false;
      this.resetRoundState();
      this.phase = 'join';
    },

    // ---------- Turn actions ----------

    async onSelectCategory(poolId, catIndex) {
      if (this.loading || !this.isMyTurn) return;

      // ---- Bot-Modus: Frage lokal laden ----
      if (this.botMode) {
        this.loading = true;
        this.selectedCategoryIndex = catIndex;
        this.botBoardPhase = 'question';
        const question = await this.botFetchQuestion(poolId);
        if (!question) {
          this.error = t('learning', 'Keine Fragen in dieser Kategorie.');
          this.botBoardPhase = 'roll';
          this.loading = false;
          return;
        }
        this.botCurrentQuestion = question;
        this.loading = false;
        return;
      }

      this.loading = true;
      this.error = null;

      // First: roll dice (backend requirement — advances phase to 'question')
      try {
        await axios.post(
          generateUrl('/apps/learning/api/gameshow/' + this.gameshowCode + '/roll'),
          {}
        );
      } catch (e) {
        // If roll fails with "Dice already rolled", proceed to selectCategory
        const msg = e.response?.data?.error || '';
        if (!msg.includes('already rolled') && !msg.includes('question phase')) {
          this.error = e.response?.data?.error || t('learning', 'Aktion fehlgeschlagen');
          this.loading = false;
          return;
        }
      }

      // Then: select category
      try {
        const r = await axios.post(
          generateUrl('/apps/learning/api/gameshow/' + this.gameshowCode + '/category'),
          { categoryPoolId: poolId, ...(this.contentLanguage ? { lang: this.contentLanguage } : {}) }
        );
        this.selectedCategoryIndex = catIndex;
        this.state = r.data;
        // Stay in game phase so question becomes visible
      } catch (e) {
        this.error = e.response?.data?.error || t('learning', 'Kategorie konnte nicht gew\u00e4hlt werden');
      } finally {
        this.loading = false;
      }
    },

    async onAnswer(answerId) {
      if (this.hasAnswered || this.loading) return;

      // ---- Bot-Modus: lokale Antwort ----
      if (this.botMode) {
        this.hasAnswered = true;
        this.selectedAnswerId = answerId;
        const question = this.botCurrentQuestion;
        const correct = question && question.answers
          ? question.answers.find(a => a.is_correct)
          : null;
        this.correctAnswerId = correct ? correct.id : null;
        this.answeredCorrect = correct ? answerId === correct.id : false;

        if (this.answeredCorrect) {
          // Block auf User-Turm
          const poolId = this.categories[this.selectedCategoryIndex]
            ? String(this.categories[this.selectedCategoryIndex].poolId)
            : null;
          if (poolId) {
            const newTower = [...(this.botLocalTowers[0] || []), poolId];
            this.botLocalTowers = { ...this.botLocalTowers, 0: newTower };
            this.recentGainSlot = 0;
            setTimeout(() => { this.recentGainSlot = null; }, 1200);

            const uniqueBlocks = new Set(newTower.map(id => parseInt(id, 10)));
            if (uniqueBlocks.size >= WIN_BLOCKS) {
              this.phase = 'finished';
              return;
            }
          }
        }

        this.showFeedbackThenBotTurn();
        return;
      }

      // ---- Normaler Modus ----
      this.hasAnswered = true;
      this.selectedAnswerId = answerId;
      this.loading = true;
      this.error = null;

      const prevBoardState = this.boardState ? JSON.parse(JSON.stringify(this.boardState)) : null;

      try {
        const r = await axios.post(
          generateUrl('/apps/learning/api/gameshow/' + this.gameshowCode + '/answer'),
          {
            answerId,
            answeredAt: Date.now(),
            ...(this.contentLanguage ? { lang: this.contentLanguage } : {}),
          }
        );

        this.correctAnswerId = r.data.correct_answer_id ?? null;
        this.answeredCorrect = r.data.my_answer_correct ?? false;

        // Check if steal occurred
        const newBoardState = r.data.board_state;
        this.stealOccurred = false;
        if (this.answeredCorrect && prevBoardState && prevBoardState.steal_pending) {
          this.stealOccurred = true;
          this.stealNotification = t('learning', 'Du hast einen Block gestohlen!');
          setTimeout(() => { this.stealNotification = null; }, 3000);
        }

        // Animate the new block
        if (this.answeredCorrect && newBoardState) {
          this.recentGainSlot = this.mySlot;
          setTimeout(() => { this.recentGainSlot = null; }, 1200);
        }

        // VirtuProf feedback
        if (this.answeredCorrect) {
          this.emitVirtuProf('gameshow-answer-correct', { message: 'Richtig! Block auf dem Turm!' });
        } else {
          this.emitVirtuProf('gameshow-answer-wrong', { message: 'Leider falsch! Oberster Block f\u00e4llt!' });
        }

        this.state = r.data;

        if (r.data.status === 'finished') {
          this.stopPolling();
          this.showFeedbackThenFinish();
        } else if (r.data.status === 'expired') {
          this.stopPolling();
          this.phase = 'expired';
        } else {
          this.showFeedbackThenResume();
        }
      } catch (e) {
        this.hasAnswered = false;
        this.selectedAnswerId = null;
        this.error = e.response?.data?.error || t('learning', 'Antwort konnte nicht gesendet werden');
      } finally {
        this.loading = false;
      }
    },

    showFeedbackThenBotTurn() {
      this.phase = 'feedback';
      setTimeout(() => {
        this.resetRoundState();
        this.botBoardPhase = 'roll';
        this.botCurrentQuestion = null;
        this.phase = 'game';
        // Zug zu Klaus
        this.botActiveTurn = 1;
        this.botTakeTurn();
      }, 1800);
    },

    showFeedbackThenResume() {
      this.phase = 'feedback';
      // Block fall animation plays via CSS; after 1.8s resume game
      setTimeout(() => {
        this.resetRoundState();
        this.phase = 'game';
        // Re-enable polling if needed
        if (!this.pollingInterval) {
          this.startPolling();
        }
      }, 1800);
    },

    showFeedbackThenFinish() {
      this.phase = 'feedback';
      setTimeout(() => {
        localStorage.removeItem('learning_wissensturm_session');
        this.enterFinished();
      }, 1800);
    },

    enterFinished() {
      this.phase = 'finished';
      if (this.finishedCelebrated) return;
      this.finishedCelebrated = true;

      const w = this.winner;
      if (w) {
        this.emitVirtuProf('gameshow-winner', {
          winnerName: w.display_name || w.user_id,
        });
      }

      this.$nextTick(() => { this.fireConfetti(); });
    },

    fireConfetti() {
      try {
        const confetti = require('canvas-confetti');
        const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReduced) return;
        confetti({ particleCount: 120, spread: 80, origin: { y: 0.6 } });
        setTimeout(() => {
          confetti({ particleCount: 60, spread: 100, origin: { y: 0.5 }, startVelocity: 25 });
        }, 300);
      } catch (e) {
        // canvas-confetti not available, skip
      }
    },

    // ---------- Polling ----------

    startPolling() {
      if (this.pollingInterval) return;
      this.pollingInterval = setInterval(this.pollState, POLL_INTERVAL_MS);
    },

    stopPolling() {
      if (this.pollingInterval) {
        clearInterval(this.pollingInterval);
        this.pollingInterval = null;
      }
    },

    async pollState() {
      if (!this.gameshowCode) return;
      try {
        const r = await axios.get(
          generateUrl('/apps/learning/api/gameshow/' + this.gameshowCode + '/state'),
          { params: this.buildStateParams() }
        );
        this.consecutivePollErrors = 0;
        this.applyStateTransitions(r.data);
      } catch (e) {
        this.consecutivePollErrors++;
      }
    },

    applyStateTransitions(newState) {
      if (newState.mode !== 'wissensturm') return;

      this.state = newState;

      if (newState.status === 'finished') {
        localStorage.removeItem('learning_wissensturm_session');
        this.stopPolling();
        if (this.phase !== 'finished' && this.phase !== 'feedback') {
          this.enterFinished();
        }
        return;
      }

      if (newState.status === 'expired') {
        localStorage.removeItem('learning_wissensturm_session');
        this.stopPolling();
        this.phase = 'expired';
        return;
      }

      if (newState.status === 'active') {
        if (this.phase === 'lobby' || this.phase === 'join') {
          this.resetRoundState();
          this.phase = 'game';
          this.emitVirtuProf('gameshow-round-announce', { round: 1, total: null });
        }

        // If it's not our turn and we're in game phase, just update state
        // (don't override if we're currently in question sub-phase to avoid flickering)
        if (this.phase === 'game' && !this.isMyTurn) {
          // Check for steal opportunity
          const bs = newState.board_state;
          if (bs && bs.steal_pending && bs.steal_from_slot !== null && bs.steal_from_slot !== this.mySlot) {
            // We may steal if the next active player is us
            // The backend handles this: if steal_pending and we answer correctly, we steal
          }
        }
        return;
      }

      // status === 'waiting'
      if (this.phase === 'join') {
        this.phase = 'lobby';
      }
    },
  },
};
</script>

<style scoped>
.wissensturm-mode {
  max-width: 700px;
  margin: 0 auto;
  padding: 16px;
}

/* ===== JOIN ===== */
.wt-join {
  text-align: center;
  padding: 40px 20px;
}

.wt-tagline {
  color: var(--color-text-maxcontrast);
  margin-bottom: 24px;
}

.wt-category-preview {
  display: flex;
  gap: 8px;
  justify-content: center;
  flex-wrap: wrap;
  margin-bottom: 24px;
}

.wt-cat-chip {
  color: #fff;
  border-radius: 16px;
  padding: 4px 14px;
  font-size: 0.85em;
  font-weight: 600;
  text-shadow: 0 1px 2px rgba(0,0,0,.4);
}

.wt-pool-picker {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  margin-bottom: 16px;
}

.wt-picker-label {
  font-weight: 600;
  color: var(--color-text-maxcontrast);
}

.wt-select {
  border: 1px solid var(--color-border);
  border-radius: 6px;
  padding: 6px 12px;
  background: var(--color-main-background);
  color: var(--color-main-text);
}

.wt-join-section {
  display: flex;
  gap: 8px;
  justify-content: center;
  align-items: center;
  margin-bottom: 16px;
}

.wt-join-input {
  border: 1px solid var(--color-border);
  border-radius: 6px;
  padding: 8px 14px;
  font-size: 1em;
  background: var(--color-main-background);
  color: var(--color-main-text);
  width: 200px;
}

.wt-divider {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 16px 0;
  color: var(--color-text-maxcontrast);
  font-size: 0.9em;
}

.wt-divider::before,
.wt-divider::after {
  content: '';
  flex: 1;
  border-top: 1px solid var(--color-border);
}

.wt-start-actions {
  display: flex;
  gap: 12px;
  justify-content: center;
  margin-top: 16px;
  flex-wrap: wrap;
}

.wt-error {
  margin-bottom: 12px;
}

/* ===== LOBBY ===== */
.wt-lobby {
  padding: 24px;
  text-align: center;
}

.wt-code-box {
  display: flex;
  align-items: center;
  gap: 12px;
  justify-content: center;
  background: var(--color-background-dark);
  border-radius: 8px;
  padding: 12px 20px;
  margin: 16px 0;
  flex-wrap: wrap;
}

.wt-code-label {
  color: var(--color-text-maxcontrast);
  font-size: 0.9em;
}

.wt-code {
  font-family: monospace;
  font-size: 1.5em;
  font-weight: 700;
  letter-spacing: 2px;
  color: var(--color-main-text);
}

.wt-lobby-players {
  margin: 16px 0;
}

.wt-lobby-player {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 16px;
  border-radius: 8px;
  background: var(--color-background-hover);
  margin-bottom: 6px;
}

.wt-lobby-empty {
  opacity: 0.5;
}

.wt-player-name,
.wt-player-name-empty {
  font-weight: 500;
}

.wt-player-name-empty {
  font-style: italic;
  color: var(--color-text-maxcontrast);
}

.wt-player-ready {
  font-size: 1.1em;
  color: var(--color-text-maxcontrast);
}

.wt-player-ready.ready {
  color: var(--color-success);
  font-weight: 700;
}

.wt-lobby-status {
  color: var(--color-text-maxcontrast);
  margin-bottom: 16px;
}

/* ===== GAME ===== */
.wt-game {
  padding: 16px;
}

/* --- Towers --- */
.wt-towers-area {
  display: flex;
  gap: 16px;
  justify-content: center;
  align-items: flex-end;
  margin-bottom: 24px;
  flex-wrap: wrap;
}

.wt-tower-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 90px;
  transition: transform 0.2s ease;
}

.wt-tower-col.wt-tower-active {
  transform: scale(1.05);
}

.wt-player-label {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  margin-bottom: 8px;
  font-weight: 600;
  font-size: 0.9em;
  text-align: center;
}

.wt-you-badge {
  background: var(--color-primary);
  color: #fff;
  border-radius: 4px;
  padding: 1px 6px;
  font-size: 0.75em;
}

.wt-turn-badge {
  background: var(--color-warning);
  color: #fff;
  border-radius: 4px;
  padding: 1px 6px;
  font-size: 0.75em;
}

.wt-tower {
  display: flex;
  flex-direction: column-reverse;
  gap: 3px;
  min-height: 220px;
  align-items: center;
}

.wt-block {
  width: 80px;
  height: 40px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7em;
  font-weight: 700;
  color: #fff;
  text-shadow: 0 1px 2px rgba(0,0,0,.4);
  border: 2px solid rgba(255,255,255,.25);
  box-shadow: 0 2px 4px rgba(0,0,0,.15);
}

.wt-block-empty {
  background: var(--color-background-dark) !important;
  border: 2px dashed var(--color-border);
  opacity: 0.5;
}

.wt-block-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 70px;
  display: block;
}

/* Block slide-in animation */
@keyframes block-slide-in {
  from {
    transform: translateY(-60px);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}

@media (prefers-reduced-motion: no-preference) {
  .wt-block-new {
    animation: block-slide-in 0.4s ease;
  }
}

/* Block fall animation (applied in feedback phase for wrong answers) */
@keyframes block-fall {
  0% { transform: translateY(0) rotate(0deg); opacity: 1; }
  40% { transform: translateY(4px) rotate(-8deg); opacity: 0.9; }
  100% { transform: translateY(80px) rotate(20deg); opacity: 0; }
}

@media (prefers-reduced-motion: no-preference) {
  .wt-feedback.wt-feedback-wrong .wt-block:last-child {
    animation: block-fall 0.6s ease forwards;
  }
}

/* Steal indicator */
.wt-steal-indicator {
  margin-top: 6px;
  font-size: 0.8em;
  font-weight: 700;
  color: var(--color-warning);
  text-align: center;
}

/* --- Turn info --- */
.wt-turn-info {
  text-align: center;
  font-size: 1em;
  font-weight: 600;
  margin-bottom: 16px;
  color: var(--color-main-text);
}

/* --- Category grid --- */
.wt-category-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 10px;
  margin-bottom: 20px;
}

.wt-cat-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 16px;
  border-radius: 10px;
  border: 3px solid var(--cat-color, #888);
  background: var(--color-main-background);
  color: var(--color-main-text);
  cursor: pointer;
  font-size: 0.95em;
  font-weight: 600;
  transition: background 0.15s, transform 0.1s, box-shadow 0.15s;
  position: relative;
}

.wt-cat-btn:hover:not(:disabled) {
  background: color-mix(in srgb, var(--cat-color, #888) 12%, var(--color-main-background));
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,.1);
}

.wt-cat-btn:disabled,
.wt-cat-btn-disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.wt-cat-btn-owned {
  opacity: 0.7;
}

.wt-cat-color-dot {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  flex-shrink: 0;
  border: 2px solid rgba(0,0,0,.15);
}

.wt-cat-name {
  flex: 1;
  text-align: left;
}

.wt-cat-check {
  color: var(--color-success);
  font-size: 1.1em;
}

/* --- Question area --- */
.wt-question-area {
  background: var(--color-background-hover);
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 16px;
}

.wt-selected-cat-badge {
  display: inline-block;
  color: #fff;
  border-radius: 16px;
  padding: 4px 16px;
  font-size: 0.85em;
  font-weight: 600;
  text-shadow: 0 1px 2px rgba(0,0,0,.4);
  margin-bottom: 12px;
}

.wt-question-image {
  max-width: 100%;
  border-radius: 8px;
  margin-bottom: 12px;
}

.wt-question-text {
  font-size: 1.05em;
  font-weight: 600;
  margin-bottom: 16px;
  line-height: 1.5;
}

.wt-answer-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.wt-answer-btn {
  padding: 12px 16px;
  border-radius: 8px;
  border: 2px solid var(--color-border);
  background: var(--color-main-background);
  color: var(--color-main-text);
  font-size: 0.95em;
  cursor: pointer;
  text-align: left;
  transition: background 0.15s, border-color 0.15s;
}

.wt-answer-btn:hover:not(:disabled) {
  background: var(--color-background-hover);
  border-color: var(--color-primary);
}

.wt-answer-btn:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}

.wt-answer-selected {
  border-color: var(--color-primary) !important;
  background: color-mix(in srgb, var(--color-primary) 10%, var(--color-main-background)) !important;
}

.wt-answer-correct {
  border-color: var(--color-success) !important;
  background: color-mix(in srgb, var(--color-success) 15%, var(--color-main-background)) !important;
}

.wt-answer-wrong {
  border-color: var(--color-error) !important;
  background: color-mix(in srgb, var(--color-error) 15%, var(--color-main-background)) !important;
}

/* --- Waiting --- */
.wt-waiting {
  text-align: center;
  padding: 32px;
  color: var(--color-text-maxcontrast);
}

.wt-waiting p {
  margin-top: 12px;
}

/* --- Steal notification --- */
.wt-steal-notification {
  background: var(--color-warning);
  color: #fff;
  border-radius: 8px;
  padding: 10px 16px;
  text-align: center;
  font-weight: 700;
  margin-bottom: 12px;
  animation: pulse-in 0.3s ease;
}

@keyframes pulse-in {
  from { transform: scale(0.9); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

.wt-abort-btn {
  display: block;
  margin: 16px auto 0;
}

/* ===== FEEDBACK ===== */
.wt-feedback {
  padding: 40px 20px;
  text-align: center;
}

.wt-feedback-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 32px;
  border-radius: 16px;
  margin: 0 auto 24px;
  max-width: 320px;
}

.wt-feedback-correct {
  background: color-mix(in srgb, var(--color-success) 15%, var(--color-main-background));
  border: 3px solid var(--color-success);
}

.wt-feedback-wrong {
  background: color-mix(in srgb, var(--color-error) 15%, var(--color-main-background));
  border: 3px solid var(--color-error);
}

.wt-feedback-icon {
  font-size: 3em;
  font-weight: 900;
}

.wt-feedback-correct .wt-feedback-icon { color: var(--color-success); }
.wt-feedback-wrong .wt-feedback-icon { color: var(--color-error); }

.wt-feedback-label {
  font-size: 1.4em;
  font-weight: 700;
}

.wt-feedback-steal {
  font-weight: 600;
  color: var(--color-warning);
}

.wt-feedback-meta {
  font-size: 0.9em;
  color: var(--color-text-maxcontrast);
}

.wt-feedback-wait {
  color: var(--color-text-maxcontrast);
}

/* ===== FINISHED ===== */
.wt-finished {
  padding: 32px 20px;
  text-align: center;
}

.wt-winner-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  background: color-mix(in srgb, var(--color-primary) 10%, var(--color-main-background));
  border: 3px solid var(--color-primary);
  border-radius: 16px;
  padding: 24px 32px;
  max-width: 320px;
  margin: 0 auto 32px;
}

.wt-winner-crown {
  font-size: 3em;
}

.wt-winner-name {
  font-size: 1.5em;
  font-weight: 700;
}

.wt-winner-label {
  color: var(--color-text-maxcontrast);
  font-size: 0.9em;
}

.wt-towers-final {
  margin-bottom: 32px;
}

/* ===== EXPIRED ===== */
.wt-expired {
  padding: 40px 20px;
  text-align: center;
}

/* ===== Bot-Modus ===== */
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
}
.bot-avatar { font-size: 22px; flex-shrink: 0; }
.bot-speech { font-style: italic; }
</style>
