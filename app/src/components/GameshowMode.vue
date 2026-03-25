<template>
  <div class="gameshow-mode">

    <!-- ===== JOIN PHASE ===== -->
    <div v-if="phase === 'join'" class="gs-join">
      <h3>{{ t('learning', 'Gameshow') }}</h3>

      <NcNoteCard v-if="error" type="error" class="gs-error">{{ error }}</NcNoteCard>

      <div class="pool-picker">
        <label class="pool-picker-label">{{ t('learning', 'Pool') }}</label>
        <select v-model="selectedPoolId" class="pool-select">
          <option :value="0" disabled>{{ t('learning', '-- Pool auswaehlen --') }}</option>
          <option v-for="p in pools" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </div>

      <div class="pool-picker">
        <label class="pool-picker-label">{{ t('learning', 'Max. Spieler') }}</label>
        <select v-model="maxPlayers" class="pool-select">
          <option :value="2">2</option>
          <option :value="3">3</option>
          <option :value="4">4</option>
          <option :value="5">5</option>
        </select>
      </div>

      <div class="join-section">
        <input
          v-model="joinCode"
          class="join-input"
          type="text"
          :placeholder="t('learning', 'Gameshow-Code eingeben')"
          maxlength="12"
          @keyup.enter="joinGame"
        />
        <NcButton type="secondary" :disabled="loading || !joinCode.trim()" @click="joinGame">
          {{ loading ? t('learning', 'Beitreten...') : t('learning', 'Beitreten') }}
        </NcButton>
      </div>

      <div class="join-divider"><span>{{ t('learning', 'oder') }}</span></div>

      <div class="start-actions">
        <NcButton type="primary" :disabled="loading || selectedPoolId === 0" @click="createGame">
          {{ loading ? t('learning', 'Erstelle...') : t('learning', 'Neue Gameshow erstellen') }}
        </NcButton>
        <NcButton type="secondary" :disabled="loading || selectedPoolId === 0" @click="startBotGame">
          🤓 {{ t('learning', 'Gegen Klaus spielen') }}
        </NcButton>
        <NcButton type="tertiary" :disabled="loading" @click="$emit('back')">
          {{ t('learning', 'Zurueck') }}
        </NcButton>
      </div>

      <div v-if="history.length > 0" class="gs-history">
        <h4>{{ t('learning', 'Letzte Gameshows') }}</h4>
        <div v-for="h in history" :key="h.id" class="gs-history-item">
          <div class="history-meta">
            <span class="history-mode">{{ h.mode === 'sprint' ? 'Sprint' : 'Elimination' }}</span>
            <span class="history-date">{{ formatDate(h.created_at) }}</span>
          </div>
          <div class="history-result">
            <span class="history-score">{{ t('learning', 'Score') }}: {{ h.my_score }}</span>
            <span class="history-winner">{{ t('learning', 'Gewinner') }}: {{ h.winner }}</span>
          </div>
          <div class="history-players">
            <span v-for="p in h.players.filter(p => !p.is_removed)" :key="p.user_id" class="history-player-chip">
              {{ p.display_name }} ({{ p.score }})
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== LOBBY PHASE ===== -->
    <div v-else-if="phase === 'lobby'" class="gs-lobby">
      <h3>{{ t('learning', 'Lobby') }}</h3>

      <NcNoteCard v-if="error" type="error" class="gs-error">{{ error }}</NcNoteCard>

      <div class="gs-code-box">
        <span class="gs-code-label">{{ t('learning', 'Gameshow-Code') }}</span>
        <span class="gs-code">{{ gameshowCode }}</span>
        <NcButton type="secondary" @click="copyCode">{{ t('learning', 'Code kopieren') }}</NcButton>
      </div>

      <div class="lobby-player-list">
        <div
          v-for="player in activePlayers"
          :key="player.user_id"
          class="lobby-player"
        >
          <span class="player-name">{{ player.display_name || player.user_id }}</span>
          <span class="player-ready" :class="{ ready: player.is_ready }">
            {{ player.is_ready ? '✓' : '...' }}
          </span>
        </div>
        <div v-for="n in emptySlots" :key="'empty-' + n" class="lobby-player lobby-player-empty">
          <span class="player-name player-name-empty">{{ t('learning', 'Warte auf Spieler...') }}</span>
        </div>
      </div>

      <p class="lobby-status">{{ lobbyStatusText }}</p>

      <div class="start-actions">
        <NcButton type="primary" :disabled="loading || readyClicked" @click="setReady">
          {{ readyClicked ? t('learning', 'Bereit!') : t('learning', 'Bereit!') }}
        </NcButton>
        <NcButton type="tertiary" @click="cancelGame">{{ t('learning', 'Abbrechen') }}</NcButton>
      </div>
    </div>

    <!-- ===== QUESTION PHASE ===== -->
    <div v-else-if="phase === 'question'" class="gs-question" :class="questionClasses">
      <div class="progress-area">
        <div class="progress-text">
          {{ t('learning', 'Frage') }} {{ questionDisplay }} / {{ totalQuestions }}
        </div>
        <NcProgressBar :value="progressPercent" />
      </div>

      <div class="timer-area">
        <div class="timer-display" :class="timerClass">{{ timerValue }}</div>
        <div class="timer-label">{{ t('learning', 'Sekunden') }}</div>
      </div>

      <div v-if="isEliminationMode" class="elimination-players">
        <div
          v-for="player in activePlayers"
          :key="'elim-question-' + player.user_id"
          class="elimination-player"
          :class="playerStatusClasses(player)"
        >
          <div class="elimination-avatar">{{ playerInitials(player) }}</div>
          <div class="elimination-player-main">
            <div class="elimination-player-headline">
              <span class="elimination-name">{{ player.display_name || player.user_id }}</span>
              <span v-if="player.slot === mySlot" class="elimination-me">{{ t('learning', 'Du') }}</span>
            </div>
            <div v-if="!isPlayerEliminated(player)" class="elimination-hearts">
              <span
                v-for="heartIndex in 3"
                :key="player.user_id + '-heart-' + heartIndex"
                class="elimination-heart"
                :class="heartClasses(player, heartIndex)"
              >
                {{ heartIndex <= player.lives ? '♥' : '♡' }}
              </span>
            </div>
            <div v-else class="elimination-skull">☠️</div>
          </div>
        </div>
      </div>

      <div v-if="isEliminationMode && isSuddenDeath" class="sudden-death-banner">
        SUDDEN DEATH
      </div>

      <div v-if="isEliminationMode" class="elimination-round-meta">
        <span>{{ t('learning', 'Noch im Spiel') }}: {{ remainingPlayerCount }}</span>
        <span v-if="amIEliminated">{{ t('learning', 'Du bist eliminiert und schaust jetzt zu.') }}</span>
      </div>

      <div v-else class="gs-score-bar">
        <span class="my-score-label">{{ t('learning', 'Punkte') }}:</span>
        <span class="my-score">{{ myScore }}</span>
        <template v-if="botMode">
          <span class="gs-bot-score-label">🤓 Klaus:</span>
          <span class="gs-bot-score">{{ botLocalState.botScore }}</span>
        </template>
      </div>

      <!-- Klaus-Sprechblase (nur im Bot-Modus) -->
      <div v-if="botMode && botPhrase" class="bot-status-bar">
        <span class="bot-avatar">🤓</span>
        <span class="bot-speech">{{ botPhrase }}</span>
      </div>

      <div class="gs-card" :class="{ 'sudden-death-frame': isEliminationMode && isSuddenDeath }">
        <QuestionLanguageSwitcher v-model="questionLanguage" />
        <img
          v-if="currentQuestion && currentQuestion.image_path"
          :src="questionImageUrl(currentQuestion.id)"
          alt=""
          class="question-image"
        />
        <p class="question-text">{{ currentQuestion && currentQuestion.text }}</p>

        <div v-if="hasAnswered" class="waiting-overlay">
          <span>{{ t('learning', 'Warte auf andere Spieler...') }}</span>
        </div>

        <div v-else-if="isEliminationMode && amIEliminated" class="waiting-overlay elimination-spectator-overlay">
          <span>{{ t('learning', 'Du bist eliminiert und schaust jetzt zu.') }}</span>
        </div>

        <div v-if="timerExpired && !hasAnswered" class="waiting-overlay timeout-overlay">
          <span>{{ t('learning', 'Zeit abgelaufen!') }}</span>
        </div>
      </div>

      <div class="answer-grid">
        <button
          v-for="answer in (currentQuestion && currentQuestion.answers || [])"
          :key="answer.id"
          class="answer-btn"
          :class="answerButtonClassesWithReveal(answer)"
          :disabled="hasAnswered || loading || timerExpired || amIEliminated"
          @click="onAnswer(answer.id)"
        >
          {{ answer.text }}
        </button>
      </div>

      <div v-if="disconnectDetected" class="disconnect-overlay">
        <NcNoteCard type="warning">
          {{ t('learning', 'Verbindung unterbrochen. Das Spiel wird beendet...') }}
        </NcNoteCard>
        <NcButton type="primary" @click="cancelGame">{{ t('learning', 'Zurueck') }}</NcButton>
      </div>

      <div class="question-abort-area">
        <NcButton type="tertiary" @click="cancelGame">
          {{ t('learning', 'Abbrechen') }}
        </NcButton>
      </div>
    </div>

    <!-- ===== FEEDBACK PHASE ===== -->
    <div v-else-if="phase === 'feedback'" class="gs-feedback">
      <div v-if="isEliminationMode" class="elimination-players">
        <div
          v-for="player in activePlayers"
          :key="'elim-feedback-' + player.user_id"
          class="elimination-player"
          :class="playerStatusClasses(player)"
        >
          <div class="elimination-avatar">{{ playerInitials(player) }}</div>
          <div class="elimination-player-main">
            <div class="elimination-player-headline">
              <span class="elimination-name">{{ player.display_name || player.user_id }}</span>
              <span v-if="player.slot === mySlot" class="elimination-me">{{ t('learning', 'Du') }}</span>
            </div>
            <div v-if="!isPlayerEliminated(player)" class="elimination-hearts">
              <span
                v-for="heartIndex in 3"
                :key="player.user_id + '-feedback-heart-' + heartIndex"
                class="elimination-heart"
                :class="heartClasses(player, heartIndex)"
              >
                {{ heartIndex <= player.lives ? '♥' : '♡' }}
              </span>
            </div>
            <div v-else class="elimination-skull">☠️</div>
          </div>
        </div>
      </div>

      <div v-if="isEliminationMode && isSuddenDeath" class="sudden-death-banner">
        SUDDEN DEATH
      </div>

      <QuestionLanguageSwitcher v-model="questionLanguage" />
      <div class="feedback-card" :class="[answeredCorrect ? 'feedback-correct' : 'feedback-incorrect', { 'sudden-death-frame': isEliminationMode && isSuddenDeath }]">
        <span class="feedback-icon">{{ answeredCorrect ? '✓' : '✗' }}</span>
        <span class="feedback-label">{{ answeredCorrect ? t('learning', 'Richtig!') : t('learning', 'Falsch!') }}</span>
        <span class="feedback-points" :class="{ 'points-positive': !isEliminationMode && lastPoints > 0 }">
          {{ isEliminationMode ? feedbackMetricText : (lastPoints > 0 ? '+' + lastPoints : lastPoints) }}
        </span>
      </div>

      <div v-if="isEliminationMode && recentlyEliminatedPlayers.length > 0" class="elimination-overlay">
        <span class="elimination-overlay-title">{{ t('learning', 'VirtuProf-Kommentar') }}</span>
        <span class="elimination-overlay-text">{{ eliminationOverlayText }}</span>
      </div>

      <div v-if="lastQuestionAnswers.length > 0" class="answer-grid feedback-answer-grid">
        <div
          v-for="answer in lastQuestionAnswers"
          :key="'feedback-' + answer.id"
          class="answer-btn answer-btn-static"
          :class="feedbackAnswerClasses(answer)"
        >
          {{ answer.text }}
        </div>
      </div>

      <div v-if="correctAnswerId && lastQuestion" class="correct-answer-box">
        <span class="correct-answer-label">{{ t('learning', 'Richtige Antwort:') }}</span>
        <span class="correct-answer-text">{{ correctAnswerText }}</span>
      </div>

      <div v-if="!isEliminationMode" class="gs-score-bar feedback-scores">
        <span class="my-score-label">{{ t('learning', 'Punkte') }}:</span>
        <span class="my-score">{{ myScore }}</span>
      </div>

      <p class="feedback-wait">{{ t('learning', 'Naechste Frage...') }}</p>
    </div>

    <!-- ===== LEADERBOARD PHASE ===== -->
    <div v-else-if="phase === 'leaderboard'" class="gs-leaderboard">
      <template v-if="isEliminationMode">
        <h3>{{ t('learning', 'Elimination-Stand') }}</h3>
        <div class="elimination-summary-card">
          <span class="elimination-summary-value">{{ remainingPlayerCount }}</span>
          <span class="elimination-summary-label">{{ t('learning', 'Noch im Spiel') }}</span>
        </div>
        <div v-if="isSuddenDeath" class="sudden-death-banner">
          SUDDEN DEATH
        </div>
        <div class="final-leaderboard elimination-final-list">
          <div
            v-for="(player, idx) in sortedPlayers"
            :key="'lb-elim-' + player.user_id"
            class="final-player elimination-final-player"
            :class="playerStatusClasses(player)"
          >
            <span class="final-rank">{{ idx + 1 }}.</span>
            <div class="elimination-avatar">{{ playerInitials(player) }}</div>
            <div class="elimination-final-main">
              <span class="player-name">{{ player.display_name || player.user_id }}</span>
              <span class="elimination-status-text">{{ playerStatusText(player) }}</span>
            </div>
            <div v-if="!isPlayerEliminated(player)" class="elimination-hearts elimination-hearts-inline">
              <span
                v-for="heartIndex in 3"
                :key="player.user_id + '-leaderboard-heart-' + heartIndex"
                class="elimination-heart"
                :class="heartClasses(player, heartIndex)"
              >
                {{ heartIndex <= player.lives ? '♥' : '♡' }}
              </span>
            </div>
            <span v-else class="elimination-skull elimination-skull-inline">☠️</span>
          </div>
        </div>
        <p class="lb-next">{{ t('learning', 'Naechste Frage...') }}</p>
      </template>
      <template v-else>
        <h3>{{ t('learning', 'Rangliste') }}</h3>
        <div class="leaderboard-bars" :style="{ minHeight: (sortedPlayers.length * 64) + 'px' }">
          <div v-for="(player, i) in sortedPlayers" :key="'lb-' + player.user_id"
               class="leaderboard-row"
               :style="{ transform: 'translateY(' + (i * 64) + 'px)' }">
            <span class="lb-rank">{{ i + 1 }}.</span>
            <span class="lb-name">
              {{ player.display_name || player.user_id }}
              <span v-if="player.score === maxScore && player.score > 0" class="crown-icon">&#x1F451;</span>
            </span>
            <div class="lb-bar-track">
              <div class="lb-bar-fill"
                   :style="{ width: barsVisible ? barWidth(player.score) : '0%' }"
                   :class="'bar-slot-' + player.slot">
              </div>
            </div>
            <span class="lb-score">{{ player.score }}</span>
          </div>
        </div>
        <p class="lb-next">{{ t('learning', 'Naechste Frage...') }}</p>
      </template>
    </div>

    <!-- ===== FINISHED PHASE ===== -->
    <div v-else-if="phase === 'finished'" class="gs-finished">
      <template v-if="isEliminationMode">
        <h3>{{ t('learning', 'Gameshow beendet!') }}</h3>

        <div class="elimination-finish-card" :class="{ 'elimination-finish-card-empty': !eliminationWinner }">
          <span v-if="eliminationWinner" class="crown-icon crown-winner">&#x1F451;</span>
          <span class="elimination-finish-label">
            {{ eliminationWinner ? t('learning', 'Last One Standing') : t('learning', 'Elimination beendet') }}
          </span>
          <span class="elimination-finish-name">
            {{ eliminationWinner ? (eliminationWinner.display_name || eliminationWinner.user_id) : t('learning', 'Kein Sieger') }}
          </span>
          <span class="elimination-finish-meta">
            {{ eliminationWinner ? t('learning', 'Ueberlebt mit {lives} Leben', { lives: eliminationWinner.lives }) : t('learning', 'Alle Spieler wurden gleichzeitig eliminiert.') }}
          </span>
        </div>

        <div class="final-leaderboard elimination-final-list">
          <div
            v-for="(player, idx) in sortedPlayers"
            :key="'final-elim-' + player.user_id"
            class="final-player elimination-final-player"
            :class="playerStatusClasses(player)"
          >
            <span class="final-rank">{{ idx + 1 }}.</span>
            <div class="elimination-avatar">{{ playerInitials(player) }}</div>
            <div class="elimination-final-main">
              <span class="player-name">{{ player.display_name || player.user_id }}</span>
              <span class="elimination-status-text">{{ playerStatusText(player) }}</span>
            </div>
            <div v-if="!isPlayerEliminated(player)" class="elimination-hearts elimination-hearts-inline">
              <span
                v-for="heartIndex in 3"
                :key="player.user_id + '-finished-heart-' + heartIndex"
                class="elimination-heart"
                :class="heartClasses(player, heartIndex)"
              >
                {{ heartIndex <= player.lives ? '♥' : '♡' }}
              </span>
            </div>
            <span v-else class="elimination-skull elimination-skull-inline">☠️</span>
          </div>
        </div>
      </template>
      <template v-else>
        <h3>{{ t('learning', 'Gameshow beendet!') }}</h3>

        <div class="podium-area">
          <div v-if="sortedPlayers.length >= 2" class="podium-slot podium-2nd">
            <span class="podium-rank">2</span>
            <span class="podium-name">{{ sortedPlayers[1].display_name || sortedPlayers[1].user_id }}</span>
            <span class="podium-score">{{ sortedPlayers[1].score }}</span>
            <div class="podium-block podium-block-2nd"></div>
          </div>
          <div v-if="sortedPlayers.length >= 1" class="podium-slot podium-1st">
            <span class="crown-icon crown-winner">&#x1F451;</span>
            <span class="podium-rank">1</span>
            <span class="podium-name">{{ sortedPlayers[0].display_name || sortedPlayers[0].user_id }}</span>
            <span class="podium-score">{{ sortedPlayers[0].score }}</span>
            <div class="podium-block podium-block-1st"></div>
          </div>
          <div v-if="sortedPlayers.length >= 3" class="podium-slot podium-3rd">
            <span class="podium-rank">3</span>
            <span class="podium-name">{{ sortedPlayers[2].display_name || sortedPlayers[2].user_id }}</span>
            <span class="podium-score">{{ sortedPlayers[2].score }}</span>
            <div class="podium-block podium-block-3rd"></div>
          </div>
        </div>

        <div v-if="sortedPlayers.length > 3" class="final-leaderboard">
          <div
            v-for="(player, idx) in sortedPlayers.slice(3)"
            :key="'final-' + player.user_id"
            class="final-player"
            :class="{ 'final-player-me': player.slot === mySlot }"
          >
            <span class="final-rank">{{ idx + 4 }}.</span>
            <span class="player-name">{{ player.display_name || player.user_id }}</span>
            <span class="final-score">{{ player.score }}</span>
          </div>
        </div>
      </template>

      <!-- Bot-Modus Ergebnis -->
      <div v-if="botMode" class="bot-final-result">
        <div class="bot-final-scores">
          <div class="bot-final-player">
            <span class="bot-final-name">{{ t('learning', 'Du') }}</span>
            <span class="bot-final-score">{{ botLocalState.myScore }}</span>
          </div>
          <span class="bot-final-vs">vs</span>
          <div class="bot-final-player">
            <span class="bot-final-name">🤓 Klaus</span>
            <span class="bot-final-score">{{ botLocalState.botScore }}</span>
          </div>
        </div>
        <p class="bot-winner-text">{{ botWinnerText }}</p>
        <div v-if="botKlausEndPhrase" class="bot-status-bar">
          <span class="bot-avatar">🤓</span>
          <span class="bot-speech">{{ botKlausEndPhrase }}</span>
        </div>
      </div>

      <div class="start-actions">
        <NcButton v-if="botMode" type="primary" @click="startBotGame">{{ t('learning', 'Rematch gegen Klaus') }}</NcButton>
        <NcButton v-else type="primary" @click="newRound">{{ t('learning', 'Neue Runde') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Zurueck') }}</NcButton>
      </div>
    </div>

    <!-- ===== EXPIRED PHASE ===== -->
    <div v-else-if="phase === 'expired'" class="gs-expired">
      <h3>{{ t('learning', 'Gameshow abgebrochen') }}</h3>
      <p>{{ t('learning', 'Ein Spieler hat die Verbindung verloren.') }}</p>
      <div class="start-actions">
        <NcButton type="primary" @click="$emit('back')">{{ t('learning', 'Zurueck') }}</NcButton>
      </div>
    </div>

  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import NcProgressBar from '@nextcloud/vue/dist/Components/NcProgressBar.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import QuestionLanguageSwitcher from './QuestionLanguageSwitcher.vue';
import { botChooseAnswer, botResponseDelay, botPhrase as getBotPhrase } from '../utils/botPlayer.js';

export default {
  name: 'GameshowMode',
  components: { NcButton, NcNoteCard, NcProgressBar, QuestionLanguageSwitcher },

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
    mode: {
      type: String,
      default: 'sprint',
    },
    initialPoolId: {
      type: Number,
      default: null,
    },
  },

  data() {
    return {
      phase: 'join',
      gameshowCode: '',
      joinCode: '',
      state: null,
      pools: [],
      selectedPoolId: 0,
      maxPlayers: 5,
      loading: false,
      error: null,
      pollingInterval: null,
      hasAnswered: false,
      answeredCorrect: false,
      correctAnswerId: null,
      selectedAnswerId: null,
      lastQuestion: null,
      lastPoints: 0,
      lastLivesLost: 0,
      scoreBeforeAnswer: 0,
      livesBeforeAnswer: 0,
      readyClicked: false,
      lastQuestionIndex: -1,
      timerValue: 15,
      timerInterval: null,
      questionLanguage: this.contentLanguage || '',
      barsVisible: false,
      spotlightActive: false,
      screenShake: false,
      pulseBorder: false,
      revealPhase: 'none',
      recentlyDamagedPlayerIds: [],
      recentlyEliminatedPlayerIds: [],
      recentlyEliminatedPlayers: [],
      finishedCelebrated: false,
      history: [],
      historyLoading: false,
      consecutivePollErrors: 0,
      disconnectDetected: false,

      // ---- Bot-Modus ----
      botMode: false,
      botLocalState: {
        questions: [],
        currentIndex: 0,
        totalQuestions: 15,
        myScore: 0,
        botScore: 0,
        botAnswerTimeout: null,
        difficulty: 'medium',
      },
      botPhrase: '',
      botKlausEndPhrase: '',
      botHasAnswered: false,
    };
  },

  computed: {
    isEliminationMode() {
      return this.mode === 'elimination' || this.state?.mode === 'elimination';
    },
    questionClasses() {
      return {
        'spotlight-active': this.spotlightActive,
        'screen-shake': this.screenShake,
        'pulse-border': this.pulseBorder,
      };
    },
    activePlayers() {
      if (!this.state || !this.state.players) return [];
      return this.state.players.filter(p => !p.is_removed);
    },
    emptySlots() {
      if (!this.state) return 0;
      return Math.max(0, this.state.max_players - this.activePlayers.length);
    },
    sortedPlayers() {
      return [...this.activePlayers].sort((a, b) => {
        if (this.isEliminationMode) {
          const aAlive = this.isPlayerEliminated(a) ? 0 : 1;
          const bAlive = this.isPlayerEliminated(b) ? 0 : 1;
          if (aAlive !== bAlive) {
            return bAlive - aAlive;
          }
          if ((b.lives || 0) !== (a.lives || 0)) {
            return (b.lives || 0) - (a.lives || 0);
          }
          return a.slot - b.slot;
        }
        return (b.score || 0) - (a.score || 0);
      });
    },
    maxScore() {
      if (this.sortedPlayers.length === 0) return 1;
      return Math.max(...this.sortedPlayers.map(p => p.score)) || 1;
    },
    mySlot() {
      return this.state ? this.state.my_slot : null;
    },
    myScore() {
      if (this.botMode) return this.botLocalState.myScore;
      if (!this.state || !this.state.players) return 0;
      const me = this.state.players.find(p => p.slot === this.state.my_slot);
      return me ? me.score : 0;
    },
    myPlayer() {
      return this.findMyPlayer(this.state);
    },
    myLives() {
      return this.myPlayer ? this.myPlayer.lives || 0 : 0;
    },
    remainingPlayers() {
      return this.activePlayers.filter(player => !this.isPlayerEliminated(player));
    },
    remainingPlayerCount() {
      return this.state?.active_player_count ?? this.remainingPlayers.length;
    },
    amIEliminated() {
      return this.isPlayerEliminated(this.myPlayer);
    },
    isSuddenDeath() {
      return Boolean(this.isEliminationMode && (this.state?.sudden_death || this.remainingPlayerCount === 2));
    },
    currentQuestion() {
      if (this.botMode) {
        return this.botLocalState.questions[this.botLocalState.currentIndex] || null;
      }
      return this.state ? this.state.current_question : null;
    },
    questionDisplay() {
      if (this.botMode) return this.botLocalState.currentIndex + 1;
      if (!this.state) return 1;
      return this.state.current_question_index + 1;
    },
    totalQuestions() {
      if (this.botMode) return this.botLocalState.totalQuestions;
      return this.state ? this.state.total_questions : 15;
    },
    progressPercent() {
      if (this.botMode) {
        return Math.round((this.botLocalState.currentIndex / this.botLocalState.totalQuestions) * 100);
      }
      if (!this.state) return 0;
      return Math.round((this.state.current_question_index / this.state.total_questions) * 100);
    },
    botWinnerText() {
      const my = this.botLocalState.myScore;
      const bot = this.botLocalState.botScore;
      if (my === bot) return t('learning', 'Unentschieden!');
      return my > bot ? t('learning', 'Du hast gewonnen!') : '🤓 Klaus gewinnt!';
    },
    timerExpired() {
      return this.timerValue <= 0;
    },
    timerClass() {
      if (this.timerValue <= 3) return 'timer-critical';
      if (this.timerValue <= 5) return 'timer-warning';
      return '';
    },
    lastQuestionAnswers() {
      return this.lastQuestion && Array.isArray(this.lastQuestion.answers)
        ? this.lastQuestion.answers
        : [];
    },
    correctAnswerText() {
      const found = this.lastQuestionAnswers.find(a => a.id === this.correctAnswerId);
      return found ? found.text : '';
    },
    feedbackMetricText() {
      if (!this.isEliminationMode) {
        return this.lastPoints > 0 ? '+' + this.lastPoints : this.lastPoints;
      }
      if (this.lastLivesLost > 0) {
        return t('learning', '1 Leben verloren');
      }
      return t('learning', 'Kein Leben verloren');
    },
    eliminationOverlayText() {
      if (this.recentlyEliminatedPlayers.length === 0) {
        return '';
      }
      const player = this.recentlyEliminatedPlayers[0];
      return t('learning', 'Auf Wiedersehen, {playerName}!', {
        playerName: player.display_name || player.user_id,
      });
    },
    eliminationWinner() {
      if (!this.isEliminationMode) {
        return null;
      }
      if (this.state?.winner_user_id) {
        return this.activePlayers.find(player => player.user_id === this.state.winner_user_id) || null;
      }
      return this.remainingPlayers.length === 1 ? this.remainingPlayers[0] : null;
    },
    lobbyStatusText() {
      if (!this.state) return t('learning', 'Warte auf Spieler...');
      const allReady = this.activePlayers.length >= 2 && this.activePlayers.every(p => p.is_ready);
      if (allReady) return t('learning', 'Alle bereit! Spiel startet...');
      if (this.activePlayers.length < 2) return t('learning', 'Warte auf Spieler...');
      return t('learning', 'Warte bis alle bereit sind...');
    },
    effectiveContentLanguage() {
      return this.questionLanguage || '';
    },
  },

  async mounted() {
    if (this.coursePools && this.coursePools.length > 0) {
      this.pools = this.coursePools.map(p => ({
        id: p.pool_id ?? p.id,
        name: p.pool_name ?? p.name,
      }));
      if (this.pools.length === 1) {
        this.selectedPoolId = this.pools[0].id;
      }
    } else {
      await this.fetchPools();
    }
    if (this.initialPoolId && this.pools.some(p => p.id === this.initialPoolId)) {
      this.selectedPoolId = this.initialPoolId;
    }

    const saved = localStorage.getItem('learning_gameshow_session');
    if (saved) {
      try {
        const { code } = JSON.parse(saved);
        if (code) {
          this.recoverSession(code);
          return;
        }
      } catch (e) {
        localStorage.removeItem('learning_gameshow_session');
      }
    }

    this.fetchHistory();
  },

  destroyed() {
    this.stopPolling();
    this.stopTimer();
    this.clearBotTimeout();
  },

  watch: {
    contentLanguage(newLang, oldLang) {
      if (!this.questionLanguage || this.questionLanguage === oldLang) {
        this.questionLanguage = newLang || '';
      }
    },
    questionLanguage() {
      this.refreshDisplayedQuestionLanguage();
    },
    timerValue(val) {
      this.pulseBorder = val <= 5 && val > 0 && this.phase === 'question';
    },
  },

  methods: {
    // -------- Bot-Modus --------

    async startBotGame() {
      if (!this.selectedPoolId) return;
      this.loading = true;
      this.error = null;
      this.botMode = true;
      this.stopPolling();
      this.stopTimer();

      try {
        const r = await axios.get(generateUrl('/apps/learning/api/pools/' + this.selectedPoolId + '/questions'));
        const allQuestions = (r.data || []).filter(q => q.answers && q.answers.length > 0);
        if (allQuestions.length === 0) {
          this.error = t('learning', 'Keine Fragen im Pool gefunden.');
          this.botMode = false;
          this.loading = false;
          return;
        }

        const shuffled = allQuestions.sort(() => Math.random() - 0.5);
        const total = Math.min(15, shuffled.length);

        this.botLocalState = {
          questions: shuffled.slice(0, total),
          currentIndex: 0,
          totalQuestions: total,
          myScore: 0,
          botScore: 0,
          botAnswerTimeout: null,
          difficulty: 'medium',
        };
        this.resetRoundState();
        this.botHasAnswered = false;
        this.botPhrase = getBotPhrase('join');
        this.botKlausEndPhrase = '';
        this.phase = 'question';
        this.scheduleBotAnswer();
      } catch (e) {
        this.error = t('learning', 'Fragen konnten nicht geladen werden');
        this.botMode = false;
      } finally {
        this.loading = false;
      }
    },

    clearBotTimeout() {
      if (this.botLocalState.botAnswerTimeout) {
        clearTimeout(this.botLocalState.botAnswerTimeout);
        this.botLocalState.botAnswerTimeout = null;
      }
    },

    scheduleBotAnswer() {
      this.clearBotTimeout();
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

      if (this.hasAnswered) {
        this.botAdvanceQuestion();
      }
    },

    botAdvanceQuestion() {
      const next = this.botLocalState.currentIndex + 1;
      if (next >= this.botLocalState.totalQuestions) {
        setTimeout(() => {
          const my = this.botLocalState.myScore;
          const bot = this.botLocalState.botScore;
          this.botKlausEndPhrase = my > bot
            ? getBotPhrase('lose')
            : (bot > my ? getBotPhrase('win') : getBotPhrase('taunt'));
          this.phase = 'finished';
        }, 1000);
        return;
      }

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
      }, 1800);
    },

    emitVirtuProf(triggerId, context = {}) {
      this.$root.$emit('virtuprof:trigger', triggerId, context);
    },

    findMyPlayer(state) {
      if (!state || !Array.isArray(state.players)) {
        return null;
      }
      return state.players.find(player => player.slot === state.my_slot) || null;
    },

    isPlayerEliminated(player) {
      if (!this.isEliminationMode || !player) {
        return false;
      }
      return Boolean(player.is_eliminated || Number(player.lives || 0) <= 0);
    },

    playerInitials(player) {
      const label = (player?.display_name || player?.user_id || '?').trim();
      if (!label) {
        return '?';
      }
      const parts = label.split(/\s+/).filter(Boolean);
      return parts.slice(0, 2).map(part => part.charAt(0).toUpperCase()).join('');
    },

    playerStatusText(player) {
      return this.isPlayerEliminated(player)
        ? t('learning', 'Eliminiert')
        : t('learning', 'Noch im Spiel');
    },

    playerStatusClasses(player) {
      return {
        'player-card-me': player.slot === this.mySlot,
        'player-card-hit': this.recentlyDamagedPlayerIds.includes(player.user_id),
        'player-card-eliminated': this.isPlayerEliminated(player),
        'player-card-eliminating': this.recentlyEliminatedPlayerIds.includes(player.user_id),
      };
    },

    heartClasses(player, heartIndex) {
      const lives = Number(player?.lives || 0);
      return {
        'heart-full': heartIndex <= lives,
        'heart-empty': heartIndex > lives,
        'heart-break': this.recentlyDamagedPlayerIds.includes(player.user_id) && heartIndex === Math.min(3, lives + 1),
      };
    },

    syncEliminationState(prevState, nextState) {
      if (!this.isEliminationMode) {
        return;
      }

      const previousPlayers = Array.isArray(prevState?.players) ? prevState.players : [];
      const nextPlayers = Array.isArray(nextState?.players) ? nextState.players : [];
      const damagedIds = [];
      const eliminatedPlayers = [];

      nextPlayers.forEach(player => {
        const previous = previousPlayers.find(candidate => candidate.user_id === player.user_id);
        if (!previous) {
          return;
        }
        if (Number(player.lives || 0) < Number(previous.lives || 0)) {
          damagedIds.push(player.user_id);
        }
        if (!this.isPlayerEliminated(previous) && this.isPlayerEliminated(player)) {
          eliminatedPlayers.push(player);
        }
      });

      this.recentlyDamagedPlayerIds = damagedIds;
      this.recentlyEliminatedPlayerIds = eliminatedPlayers.map(player => player.user_id);
      this.recentlyEliminatedPlayers = eliminatedPlayers;

      if (eliminatedPlayers.length > 0) {
        const player = eliminatedPlayers[0];
        this.emitVirtuProf('gameshow-elimination', {
          playerName: player.display_name || player.user_id,
        });
      }
    },

    syncRoundOutcome(prevState, nextState) {
      if (this.isEliminationMode) {
        const previousLives = this.findMyPlayer(prevState)?.lives ?? this.livesBeforeAnswer;
        const currentLives = this.findMyPlayer(nextState)?.lives ?? 0;
        this.lastLivesLost = Math.max(0, Number(previousLives || 0) - Number(currentLives || 0));
        this.syncEliminationState(prevState, nextState);
        return;
      }

      const nextScore = this.findMyPlayer(nextState)?.score || 0;
      this.lastPoints = nextScore - this.scoreBeforeAnswer;
    },

    enterFinishedPhase() {
      this.phase = 'finished';
      if (this.finishedCelebrated) {
        return;
      }

      this.finishedCelebrated = true;
      if (this.isEliminationMode && this.eliminationWinner) {
        this.emitVirtuProf('gameshow-elimination', {
          playerName: this.eliminationWinner.display_name || this.eliminationWinner.user_id,
          winner: true,
        });
      }
      this.$nextTick(() => { this.fireConfetti(); });
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

    resetRoundState() {
      this.hasAnswered = false;
      this.lastPoints = 0;
      this.lastLivesLost = 0;
      this.answeredCorrect = false;
      this.scoreBeforeAnswer = 0;
      this.livesBeforeAnswer = 0;
      this.correctAnswerId = null;
      this.selectedAnswerId = null;
      this.lastQuestion = null;
      this.revealPhase = 'none';
      this.screenShake = false;
      this.pulseBorder = false;
      this.recentlyDamagedPlayerIds = [];
      this.recentlyEliminatedPlayerIds = [];
      this.recentlyEliminatedPlayers = [];
      this.consecutivePollErrors = 0;
      this.disconnectDetected = false;
    },

    async recoverSession(code) {
      this.loading = true;
      try {
        const r = await axios.get(
          generateUrl('/apps/learning/api/gameshow/' + code + '/state'),
          { params: this.buildStateParams() }
        );
        if (['waiting', 'active'].includes(r.data.status)) {
          this.gameshowCode = code;
          this.state = r.data;
          this.lastQuestionIndex = (r.data.current_question_index || 0) - 1;
          this.finishedCelebrated = false;
          this.readyClicked = false;
          this.resetRoundState();
          this.phase = r.data.status === 'active' ? 'question' : 'lobby';
          if (r.data.status === 'active') {
            this.spotlightActive = true;
            this.startTimer();
          }
          this.emitVirtuProf('arena-session-restored', {
            mode: r.data.mode || this.mode,
          });
          this.startPolling();
        } else {
          localStorage.removeItem('learning_gameshow_session');
          this.fetchHistory();
        }
      } catch (e) {
        localStorage.removeItem('learning_gameshow_session');
        this.fetchHistory();
      } finally {
        this.loading = false;
      }
    },

    answerButtonClasses(answer) {
      const isSelected = this.selectedAnswerId === answer.id;
      return {
        'btn-selected': isSelected,
        'btn-selected-correct': this.hasAnswered && isSelected && this.answeredCorrect,
        'btn-selected-incorrect': this.hasAnswered && isSelected && !this.answeredCorrect,
      };
    },

    answerButtonClassesWithReveal(answer) {
      const base = this.answerButtonClasses(answer);
      if (this.revealPhase === 'pending') {
        base['reveal-pending'] = true;
      } else if (this.revealPhase === 'correct' && this.selectedAnswerId === answer.id) {
        base['reveal-correct'] = true;
      } else if (this.revealPhase === 'wrong' && this.selectedAnswerId === answer.id) {
        base['reveal-wrong'] = true;
      }
      if ((this.revealPhase === 'correct' || this.revealPhase === 'wrong') && this.correctAnswerId === answer.id) {
        base['reveal-correct'] = true;
      }
      return base;
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

    questionImageUrl(id) {
      return generateUrl('/apps/learning/api/questions/' + id + '/image');
    },

    // ---------- Timer ----------

    startTimer() {
      this.stopTimer();
      if (this.state && this.state.question_started_at) {
        const elapsed = Date.now() - this.state.question_started_at;
        this.timerValue = Math.max(0, Math.ceil((15000 - elapsed) / 1000));
      } else {
        this.timerValue = 15;
      }
      this.timerInterval = setInterval(() => {
        if (this.timerValue > 0) {
          this.timerValue--;
        } else {
          this.stopTimer();
        }
      }, 1000);
    },

    stopTimer() {
      if (this.timerInterval) {
        clearInterval(this.timerInterval);
        this.timerInterval = null;
      }
    },

    syncTimer() {
      if (this.state && this.state.question_started_at) {
        const elapsed = Date.now() - this.state.question_started_at;
        const remaining = Math.max(0, Math.ceil((15000 - elapsed) / 1000));
        // Only correct if drift exceeds 2 seconds
        if (Math.abs(this.timerValue - remaining) > 2) {
          this.timerValue = remaining;
        }
      }
    },

    // ---------- API helpers ----------

    async fetchPools() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/pools'));
        const own = r.data.own || [];
        const shared = r.data.shared || [];
        this.pools = [...own, ...shared];
        if (this.pools.length === 1) {
          this.selectedPoolId = this.pools[0].id;
        }
      } catch (e) {
        this.error = t('learning', 'Pools konnten nicht geladen werden');
      }
    },

    async fetchHistory() {
      this.historyLoading = true;
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/gameshow/history'));
        this.history = r.data || [];
      } catch (e) {
        this.history = [];
      } finally {
        this.historyLoading = false;
      }
    },

    formatDate(ts) {
      if (!ts) return '';
      const d = new Date(ts * 1000);
      return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    },

    async refreshDisplayedQuestionLanguage() {
      if (this.gameshowCode) {
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
        // Best-effort only
      }
    },

    // ---------- Join phase ----------

    async createGame() {
      if (!this.selectedPoolId) return;
      this.loading = true;
      this.error = null;
      try {
        const payload = {
          poolId: this.selectedPoolId,
          mode: this.mode,
          maxPlayers: this.maxPlayers,
          ...(this.courseId ? { courseId: this.courseId } : {}),
        };
        const r = await axios.post(generateUrl('/apps/learning/api/gameshow'), payload);
        this.gameshowCode = r.data.code;
        this.state = r.data;
        this.lastQuestionIndex = -1;
        this.resetRoundState();
        this.finishedCelebrated = false;
        this.readyClicked = false;
        this.phase = 'lobby';
        localStorage.setItem('learning_gameshow_session', JSON.stringify({ code: this.gameshowCode }));
        this.startPolling();
      } catch (e) {
        this.error = e.response?.data?.error || t('learning', 'Gameshow konnte nicht erstellt werden');
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
        this.gameshowCode = r.data.code;
        this.state = r.data;
        this.joinCode = '';
        this.resetRoundState();
        this.finishedCelebrated = false;
        this.readyClicked = false;
        if (r.data.status === 'active') {
          this.lastQuestionIndex = (r.data.current_question_index || 0) - 1;
          this.phase = 'question';
          this.spotlightActive = true;
          this.startTimer();
        } else {
          this.lastQuestionIndex = -1;
          this.phase = 'lobby';
        }
        localStorage.setItem('learning_gameshow_session', JSON.stringify({ code: this.gameshowCode }));
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
        await navigator.clipboard.writeText(this.gameshowCode);
      } catch (e) {
        // Fallback: ignore
      }
    },

    async setReady() {
      if (this.readyClicked) return;
      this.readyClicked = true;
      this.error = null;
      try {
        const r = await axios.post(
          generateUrl('/apps/learning/api/gameshow/' + this.gameshowCode + '/ready'),
          this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}
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
      localStorage.removeItem('learning_gameshow_session');
      this.stopPolling();
      this.stopTimer();
      this.phase = 'join';
      this.gameshowCode = '';
      this.state = null;
      this.error = null;
      this.readyClicked = false;
      this.finishedCelebrated = false;
      this.resetRoundState();
      this.$emit('back');
    },

    // ---------- Question phase ----------

    async onAnswer(answerId) {
      if (this.hasAnswered || this.timerExpired || this.amIEliminated) return;

      // ---- Bot-Modus: lokale Verarbeitung ----
      if (this.botMode) {
        this.hasAnswered = true;
        this.selectedAnswerId = answerId;
        const question = this.currentQuestion;
        const correct = question && question.answers
          ? question.answers.find(a => a.is_correct)
          : null;
        this.correctAnswerId = correct ? correct.id : null;
        this.answeredCorrect = correct ? answerId === correct.id : false;
        this.lastQuestion = question;
        if (this.answeredCorrect) {
          this.botLocalState.myScore += 10;
        }
        this.lastPoints = this.answeredCorrect ? 10 : 0;
        if (!this.botHasAnswered) {
          this.botPhrase = getBotPhrase('taunt');
        }
        if (this.botHasAnswered) {
          this.botAdvanceQuestion();
        }
        return;
      }

      // ---- Normaler Modus ----
      const prevState = this.state;
      this.hasAnswered = true;
      this.selectedAnswerId = answerId;
      this.lastQuestion = this.currentQuestion;
      this.scoreBeforeAnswer = this.myScore;
      this.livesBeforeAnswer = this.myLives;
      this.loading = true;
      try {
        const r = await axios.post(
          generateUrl('/apps/learning/api/gameshow/' + this.gameshowCode + '/answer'),
          this.answerPayload(answerId)
        );
        this.correctAnswerId = r.data.correct_answer_id ?? null;
        this.answeredCorrect = r.data.my_answer_correct ?? false;
        this.state = r.data;

        // VIRT-03: React to correct/wrong answer
        if (this.answeredCorrect) {
          this.emitVirtuProf('gameshow-answer-correct', {
            message: this.buildAnswerMessage(true),
          });
        } else {
          this.emitVirtuProf('gameshow-answer-wrong', {
            message: this.buildAnswerMessage(false),
          });
        }

        if (r.data.status === 'finished') {
          this.syncRoundOutcome(prevState, r.data);
          this.stopPolling();
          this.stopTimer();
          this.showFeedbackThenTransition('finished');
        } else if (r.data.status === 'expired') {
          this.stopPolling();
          this.stopTimer();
          this.phase = 'expired';
        } else if (r.data.current_question_index > this.lastQuestionIndex + 1) {
          this.syncRoundOutcome(prevState, r.data);
          // All answered -- question advanced -- show feedback then next
          this.showFeedbackThenNext(r.data.current_question_index);
        } else {
          // Waiting for other players
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

    barWidth(score) {
      return Math.max(5, (score / this.maxScore) * 100) + '%';
    },

    enterLeaderboard() {
      this.barsVisible = false;
      this.phase = 'leaderboard';
      this.$nextTick(() => {
        this.barsVisible = true;
      });
    },

    showFeedbackThenNext(newIndex) {
      this.stopTimer();
      this.spotlightActive = false;
      this.revealPhase = 'pending';
      setTimeout(() => {
        this.revealPhase = this.answeredCorrect ? 'correct' : 'wrong';
        if (!this.answeredCorrect) {
          this.screenShake = true;
          setTimeout(() => { this.screenShake = false; }, 500);
        }
      }, 1800);
      setTimeout(() => {
        this.revealPhase = 'none';
        this.phase = 'feedback';
        setTimeout(() => {
          this.enterLeaderboard();
          // VIRT-02: Standings commentary after leaderboard
          this.emitVirtuProf('gameshow-standings-update', {
            commentary: this.buildStandingsCommentary(),
          });
          setTimeout(() => {
            this.lastQuestionIndex = newIndex - 1;
            this.resetRoundState();
            this.phase = 'question';
            this.spotlightActive = true;
            this.startTimer();
            // VIRT-01: Round announcement for next question
            this.emitVirtuProf('gameshow-round-announce', {
              round: this.questionDisplay,
              total: this.totalQuestions,
            });
          }, 3000);
        }, 50);
      }, 2500);
    },

    showFeedbackThenTransition(targetPhase) {
      this.spotlightActive = false;
      this.revealPhase = 'pending';
      setTimeout(() => {
        this.revealPhase = this.answeredCorrect ? 'correct' : 'wrong';
        if (!this.answeredCorrect) {
          this.screenShake = true;
          setTimeout(() => { this.screenShake = false; }, 500);
        }
      }, 1800);
      setTimeout(() => {
        this.revealPhase = 'none';
        this.phase = 'feedback';
        setTimeout(() => {
          if (targetPhase === 'finished') {
            this.enterLeaderboard();
            // VIRT-02: Final standings commentary
            this.emitVirtuProf('gameshow-standings-update', {
              commentary: this.buildStandingsCommentary(),
            });
            setTimeout(() => {
              // Winner announcement
              if (this.sortedPlayers.length > 0) {
                this.emitVirtuProf('gameshow-winner', {
                  winnerName: this.sortedPlayers[0].display_name || this.sortedPlayers[0].user_id,
                });
              }
              this.enterFinishedPhase();
            }, 3000);
          } else {
            this.phase = targetPhase;
          }
        }, 50);
      }, 2500);
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

    // ---------- VirtuProf helpers ----------

    buildStandingsCommentary() {
      if (!this.sortedPlayers || this.sortedPlayers.length === 0) return '';
      if (this.isEliminationMode) {
        const leader = this.sortedPlayers[0];
        const name1 = leader.display_name || leader.user_id;
        if (this.remainingPlayerCount <= 1) {
          return name1 + ' ist der letzte Ueberlebende!';
        }
        if (this.remainingPlayerCount === 2 && this.sortedPlayers.length >= 2) {
          const rival = this.sortedPlayers[1];
          const name2 = rival.display_name || rival.user_id;
          return 'Sudden Death zwischen ' + name1 + ' und ' + name2 + '!';
        }
        if (this.recentlyEliminatedPlayers.length > 0) {
          const eliminated = this.recentlyEliminatedPlayers[0];
          const eliminatedName = eliminated.display_name || eliminated.user_id;
          return eliminatedName + ' ist raus. Noch ' + this.remainingPlayerCount + ' Spieler im Spiel.';
        }
        return name1 + ' fuehrt mit ' + (leader.lives || 0) + ' Leben.';
      }
      const leader = this.sortedPlayers[0];
      const name1 = leader.display_name || leader.user_id;
      if (this.sortedPlayers.length >= 2) {
        const runner = this.sortedPlayers[1];
        const name2 = runner.display_name || runner.user_id;
        const gap = leader.score - runner.score;
        if (gap <= 50) return name2 + ' ist dicht dran an ' + name1 + '!';
        if (gap > 200) return name1 + ' dominiert!';
        return name1 + ' fuehrt mit ' + gap + ' Punkten Vorsprung!';
      }
      return name1 + ' fuehrt!';
    },

    buildAnswerMessage(correct) {
      const positives = ['Richtig! Weiter so!', 'Perfekt!', 'Genau richtig!', 'Stark!'];
      const negatives = ['Knapp daneben!', 'Leider falsch!', 'Das war nichts!', 'Nicht ganz!'];
      const arr = correct ? positives : negatives;
      return arr[Math.floor(Math.random() * arr.length)];
    },

    newRound() {
      this.stopPolling();
      this.stopTimer();
      this.gameshowCode = '';
      this.state = null;
      this.resetRoundState();
      this.readyClicked = false;
      this.finishedCelebrated = false;
      this.lastQuestionIndex = -1;
      this.barsVisible = false;
      this.phase = 'join';
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
      if (!this.gameshowCode) return;
      try {
        const r = await axios.get(
          generateUrl('/apps/learning/api/gameshow/' + this.gameshowCode + '/state'),
          { params: this.buildStateParams() }
        );
        this.consecutivePollErrors = 0;
        this.applyStateTransitions(r.data);
      } catch (e) {
        // Network errors during polling are non-fatal
        this.consecutivePollErrors++;
        if (this.consecutivePollErrors >= 60 && !this.disconnectDetected) {
          // 60 ticks * 500ms = 30 seconds
          this.disconnectDetected = true;
        }
      }
    },

    applyStateTransitions(newState) {
      const prevState = this.state;
      const prevIndex = prevState ? prevState.current_question_index : -1;
      this.state = newState;

      if (newState.status === 'finished') {
        localStorage.removeItem('learning_gameshow_session');
        if (this.phase === 'question' && this.hasAnswered) {
          this.syncRoundOutcome(prevState, newState);
          this.showFeedbackThenTransition('finished');
        } else {
          this.enterFinishedPhase();
        }
        this.stopPolling();
        this.stopTimer();
        return;
      }

      if (newState.status === 'expired') {
        localStorage.removeItem('learning_gameshow_session');
        this.stopPolling();
        this.stopTimer();
        this.phase = 'expired';
        return;
      }

      if (newState.status === 'active') {
        const indexAdvanced = newState.current_question_index > prevIndex && prevIndex >= 0;

        if (this.phase === 'question' && this.hasAnswered && indexAdvanced) {
          this.syncRoundOutcome(prevState, newState);
          this.showFeedbackThenNext(newState.current_question_index);
          return;
        }

        if (this.phase === 'lobby' || this.phase === 'join') {
          this.lastQuestionIndex = newState.current_question_index - 1;
          this.hasAnswered = false;
          this.phase = 'question';
          this.spotlightActive = true;
          this.startTimer();
          // VIRT-01: Announce first round from lobby
          this.emitVirtuProf('gameshow-round-announce', {
            round: this.questionDisplay,
            total: this.totalQuestions,
          });
        }

        // Sync timer on every poll during question phase
        if (this.phase === 'question') {
          this.syncTimer();
        }
        return;
      }

      // status === 'waiting' -- stay in lobby
      if (this.phase === 'join') {
        this.phase = 'lobby';
      }
    },
  },
};
</script>

<style scoped>
.gameshow-mode {
  max-width: 600px;
  margin: 0 auto;
}

/* ===== JOIN ===== */
.gs-join {
  text-align: center;
  padding: 40px 20px;
}

.gs-join h3 {
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

.gs-error {
  margin: 0 auto 16px;
  max-width: 420px;
  text-align: start;
}

/* ===== LOBBY ===== */
.gs-lobby {
  text-align: center;
  padding: 40px 20px;
}

.gs-lobby h3 {
  font-size: 28px;
  font-weight: 700;
  margin-bottom: 24px;
  color: var(--color-main-text);
}

.gs-code-box {
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

.gs-code-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
}

.gs-code {
  font-size: 24px;
  font-weight: 700;
  font-family: monospace;
  letter-spacing: 4px;
  color: var(--color-primary-element);
}

.lobby-player-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-width: 360px;
  margin: 0 auto 20px;
}

.lobby-player {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 16px;
  background: var(--color-background-hover);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large);
}

.lobby-player-empty {
  opacity: 0.5;
  border-style: dashed;
}

.player-name {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-main-text);
  word-break: break-all;
}

.player-name-empty {
  font-weight: 400;
  color: var(--color-text-maxcontrast);
}

.player-ready {
  font-size: 20px;
  color: var(--color-text-maxcontrast);
  transition: color 0.3s;
}

.player-ready.ready {
  color: var(--color-success);
}

.lobby-status {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 20px;
}

/* ===== QUESTION ===== */
.gs-question {
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

.timer-area {
  text-align: center;
  margin-bottom: 16px;
}

.timer-display {
  font-size: 48px;
  font-weight: 700;
  color: var(--color-main-text);
  line-height: 1;
  transition: color 0.3s;
}

.timer-warning {
  color: var(--color-warning);
}

.timer-critical {
  color: var(--color-error);
  animation: timer-pulse 0.5s ease-in-out infinite;
}

.timer-label {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  text-transform: uppercase;
  letter-spacing: 1px;
}

.gs-score-bar {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-bottom: 16px;
}

.my-score-label {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
}

.my-score {
  font-size: 24px;
  font-weight: 700;
  color: var(--color-primary-element);
}

.elimination-players {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.elimination-player {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 14px;
  transition: transform 0.2s ease, opacity 0.3s ease, border-color 0.2s ease, background 0.2s ease;
}

.player-card-me {
  border-color: var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 10%, var(--color-main-background));
}

.player-card-hit {
  border-color: var(--color-error);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-error) 25%, transparent);
}

.player-card-eliminated {
  opacity: 0.55;
  filter: grayscale(0.8);
}

.player-card-eliminating {
  animation: elimination-fade 0.55s ease-out both;
}

.elimination-avatar {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: color-mix(in srgb, var(--color-primary-element) 14%, var(--color-main-background));
  color: var(--color-primary-element);
  font-size: 14px;
  font-weight: 700;
  flex: 0 0 auto;
}

.elimination-player-main,
.elimination-final-main {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  flex: 1;
}

.elimination-player-headline {
  display: flex;
  align-items: center;
  gap: 6px;
}

.elimination-name {
  font-size: 14px;
  font-weight: 700;
  color: var(--color-main-text);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.elimination-me {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 2px 6px;
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-primary-element) 16%, transparent);
  color: var(--color-primary-element);
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}

.elimination-hearts {
  display: flex;
  align-items: center;
  gap: 4px;
  color: var(--color-error);
  font-size: 18px;
  line-height: 1;
}

.elimination-hearts-inline {
  justify-content: flex-end;
}

.elimination-heart {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 14px;
}

.heart-full {
  color: var(--color-error);
}

.heart-empty {
  color: color-mix(in srgb, var(--color-error) 28%, var(--color-main-text));
}

.heart-break {
  animation: heart-break 0.55s ease-out both;
  transform-origin: center;
}

.elimination-skull {
  color: var(--color-text-maxcontrast);
  font-size: 20px;
}

.elimination-skull-inline {
  min-width: 44px;
  text-align: right;
}

.elimination-round-meta {
  display: flex;
  justify-content: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
}

.sudden-death-banner {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  margin-bottom: 16px;
  padding: 10px 14px;
  border-radius: 999px;
  border: 2px solid color-mix(in srgb, var(--color-error) 55%, transparent);
  background: color-mix(in srgb, var(--color-error) 12%, var(--color-main-background));
  color: var(--color-error);
  font-size: 14px;
  font-weight: 800;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  animation: sudden-death-pulse 0.9s ease-in-out infinite;
}

.sudden-death-frame {
  border-color: var(--color-error);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-error) 18%, transparent),
              0 6px 20px rgba(220, 38, 38, 0.16);
}

.gs-card {
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

.timeout-overlay {
  background: rgba(220, 38, 38, 0.15);
}

.elimination-spectator-overlay {
  background: rgba(15, 23, 42, 0.28);
  color: var(--color-main-text);
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

.answer-btn-static {
  cursor: default;
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
.gs-feedback {
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

.elimination-overlay {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0 auto 16px;
  padding: 14px 18px;
  max-width: 440px;
  border-radius: 14px;
  background: color-mix(in srgb, var(--color-warning) 14%, var(--color-main-background));
  border: 1px solid color-mix(in srgb, var(--color-warning) 45%, transparent);
}

.elimination-overlay-title {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-text-maxcontrast);
}

.elimination-overlay-text {
  font-size: 18px;
  font-weight: 700;
  color: var(--color-main-text);
}

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

.feedback-scores {
  margin-bottom: 16px;
}

.feedback-wait {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
  animation: pulse 1.2s ease-in-out infinite;
}

/* ===== LEADERBOARD ===== */
.gs-leaderboard {
  text-align: center;
  padding: 40px 20px;
}

.gs-leaderboard h3 {
  font-size: 28px;
  font-weight: 700;
  margin-bottom: 24px;
  color: var(--color-main-text);
}

.leaderboard-bars {
  position: relative;
  max-width: 480px;
  margin: 0 auto 24px;
}

.leaderboard-row {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  display: flex;
  align-items: center;
  gap: 10px;
  height: 52px;
  transition: transform 0.4s ease;
}

.lb-rank {
  font-size: 16px;
  font-weight: 700;
  color: var(--color-text-maxcontrast);
  min-width: 28px;
  text-align: right;
}

.lb-name {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-main-text);
  min-width: 100px;
  text-align: left;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.crown-icon {
  font-size: 18px;
  margin-left: 4px;
  filter: drop-shadow(0 1px 2px rgba(0,0,0,0.3));
}

.lb-bar-track {
  flex: 1;
  height: 32px;
  background: var(--color-background-hover);
  border-radius: 8px;
  overflow: hidden;
}

.lb-bar-fill {
  height: 100%;
  border-radius: 8px;
  transition: width 0.6s ease-out;
}

.bar-slot-0 { background: var(--color-primary-element); }
.bar-slot-1 { background: var(--color-warning); }
.bar-slot-2 { background: var(--color-success); }
.bar-slot-3 { background: #9b59b6; }
.bar-slot-4 { background: #e67e22; }

.lb-score {
  font-size: 18px;
  font-weight: 700;
  color: var(--color-primary-element);
  min-width: 50px;
  text-align: right;
}

.lb-next {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
  animation: pulse 1.2s ease-in-out infinite;
}

.elimination-summary-card {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  margin-bottom: 20px;
  padding: 18px 24px;
  border-radius: 18px;
  background: color-mix(in srgb, var(--color-primary-element) 10%, var(--color-main-background));
  border: 1px solid color-mix(in srgb, var(--color-primary-element) 25%, transparent);
}

.elimination-summary-value {
  font-size: 36px;
  font-weight: 800;
  color: var(--color-primary-element);
  line-height: 1;
}

.elimination-summary-label {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-text-maxcontrast);
}

/* ===== FINISHED ===== */
.gs-finished {
  text-align: center;
  padding: 40px 20px;
}

.gs-finished h3 {
  font-size: 28px;
  font-weight: 700;
  margin-bottom: 32px;
  color: var(--color-main-text);
}

/* Podium */
.podium-area {
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 12px;
  max-width: 420px;
  margin: 0 auto 32px;
}

.podium-slot {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  min-width: 90px;
}

.podium-rank {
  font-size: 22px;
  font-weight: 700;
  color: var(--color-text-maxcontrast);
}

.podium-name {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-main-text);
  max-width: 110px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.podium-score {
  font-size: 18px;
  font-weight: 700;
  color: var(--color-primary-element);
  margin-bottom: 4px;
}

.podium-block {
  width: 100%;
  border-radius: 8px 8px 0 0;
}

.podium-block-1st {
  height: 100px;
  background: linear-gradient(180deg, #ffd700 0%, #f0c000 100%);
}

.podium-block-2nd {
  height: 70px;
  background: linear-gradient(180deg, #c0c0c0 0%, #a8a8a8 100%);
}

.podium-block-3rd {
  height: 50px;
  background: linear-gradient(180deg, #cd7f32 0%, #b87333 100%);
}

.crown-winner {
  font-size: 32px;
  display: block;
  margin-bottom: 4px;
  animation: crown-bounce 0.6s ease-out;
}

@keyframes crown-bounce {
  0% { transform: translateY(-20px); opacity: 0; }
  60% { transform: translateY(4px); opacity: 1; }
  100% { transform: translateY(0); opacity: 1; }
}

.final-leaderboard {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-width: 400px;
  margin: 0 auto 32px;
}

.final-player {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  background: var(--color-background-hover);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large);
}

.final-player-me {
  border-color: var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 8%, var(--color-background-hover));
}

.final-rank {
  font-size: 18px;
  font-weight: 700;
  color: var(--color-text-maxcontrast);
  min-width: 30px;
}

.final-player .player-name {
  flex: 1;
}

.elimination-final-player {
  align-items: center;
}

.elimination-status-text {
  font-size: 12px;
  font-weight: 700;
  color: var(--color-text-maxcontrast);
}

.elimination-finish-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  max-width: 440px;
  margin: 0 auto 28px;
  padding: 28px 24px;
  border-radius: 20px;
  border: 2px solid color-mix(in srgb, var(--color-success) 40%, transparent);
  background: linear-gradient(180deg,
    color-mix(in srgb, var(--color-success) 18%, var(--color-main-background)) 0%,
    var(--color-main-background) 100%);
}

.elimination-finish-card-empty {
  border-color: color-mix(in srgb, var(--color-warning) 45%, transparent);
  background: linear-gradient(180deg,
    color-mix(in srgb, var(--color-warning) 16%, var(--color-main-background)) 0%,
    var(--color-main-background) 100%);
}

.elimination-finish-label {
  font-size: 14px;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--color-text-maxcontrast);
}

.elimination-finish-name {
  font-size: 32px;
  font-weight: 800;
  color: var(--color-main-text);
  text-align: center;
}

.elimination-finish-meta {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
  text-align: center;
}

.final-score {
  font-size: 22px;
  font-weight: 700;
  color: var(--color-primary-element);
}

/* ===== EXPIRED ===== */
.gs-expired {
  text-align: center;
  padding: 60px 20px;
}

.gs-expired h3 {
  font-size: 28px;
  font-weight: 700;
  margin-bottom: 16px;
  color: var(--color-main-text);
}

.gs-expired p {
  font-size: 16px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 32px;
}

/* ===== ANIMATIONS ===== */
@keyframes pulse {
  0%, 100% { opacity: 0.5; }
  50% { opacity: 1; }
}

@keyframes heart-break {
  0% {
    opacity: 1;
    transform: scale(1) rotate(0deg);
  }
  45% {
    opacity: 1;
    transform: scale(1.25) rotate(-12deg);
  }
  100% {
    opacity: 0.25;
    transform: scale(0.2) rotate(18deg);
  }
}

@keyframes timer-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}

@keyframes elimination-fade {
  from {
    opacity: 1;
    transform: translateY(0);
  }
  to {
    opacity: 0.55;
    transform: translateY(8px);
  }
}

@keyframes sudden-death-pulse {
  0%, 100% {
    box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.18);
  }
  50% {
    box-shadow: 0 0 0 8px rgba(220, 38, 38, 0.04);
  }
}

/* ===== RESPONSIVE ===== */
@media (max-width: 480px) {
  .gs-join,
  .gs-lobby,
  .gs-leaderboard,
  .gs-finished,
  .gs-expired { padding: 24px 12px; }

  .gs-card { padding: 56px 16px 20px; min-height: 130px; }
  .question-text { font-size: 17px; }
  .answer-grid { grid-template-columns: 1fr; }
  .answer-btn { min-height: 58px; }
  .timer-display { font-size: 36px; }
  .elimination-players { grid-template-columns: 1fr; }
  .elimination-finish-name { font-size: 26px; }
  .sudden-death-banner { letter-spacing: 0.1em; }
}

/* ===== SPECTACLE ANIMATIONS ===== */

/* ANIM-01: Spotlight — dark vignette with illuminated question */
.gs-question.spotlight-active .gs-card {
  box-shadow: 0 0 40px 8px rgba(var(--color-primary-element-rgb, 0, 130, 201), 0.3),
              0 4px 16px rgba(0, 0, 0, 0.08);
  border-color: rgba(var(--color-primary-element-rgb, 0, 130, 201), 0.4);
}

.gs-question.spotlight-active::before {
  content: '';
  position: fixed;
  inset: 0;
  background: radial-gradient(ellipse 600px 400px at 50% 40%, transparent 0%, rgba(0, 0, 0, 0.5) 100%);
  pointer-events: none;
  z-index: 0;
  animation: spotlight-fade-in 0.4s ease-out;
}

.gs-question.spotlight-active .gs-card {
  position: relative;
  z-index: 1;
}

.gs-question.spotlight-active .answer-grid {
  position: relative;
  z-index: 1;
}

@keyframes spotlight-fade-in {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* ANIM-02: Dramatic 2s blink before reveal */
.answer-btn.reveal-pending {
  animation: reveal-blink 0.4s ease-in-out 5;
  border-color: var(--color-primary-element);
}

@keyframes reveal-blink {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.3; }
}

.answer-btn.reveal-correct {
  border-color: var(--color-success);
  background: color-mix(in srgb, var(--color-success) 20%, var(--color-main-background));
  color: var(--color-success);
  transform: scale(1.03);
  transition: all 0.3s ease;
}

.answer-btn.reveal-wrong {
  border-color: var(--color-error);
  background: color-mix(in srgb, var(--color-error) 15%, var(--color-main-background));
  color: var(--color-error);
  transition: all 0.3s ease;
}

/* ANIM-03: Screen shake on wrong answer */
.gs-question.screen-shake {
  animation: screen-shake 0.5s ease-in-out;
}

@keyframes screen-shake {
  0%, 100% { transform: translateX(0); }
  10% { transform: translateX(-6px); }
  20% { transform: translateX(6px); }
  30% { transform: translateX(-4px); }
  40% { transform: translateX(4px); }
  50% { transform: translateX(-2px); }
  60% { transform: translateX(2px); }
}

/* ANIM-04: Pulsing borders on tension (timer critical) */
.gs-question.pulse-border .gs-card {
  animation: pulse-border 0.8s ease-in-out infinite;
}

@keyframes pulse-border {
  0%, 100% {
    border-color: var(--color-border);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
  }
  50% {
    border-color: var(--color-error);
    box-shadow: 0 0 20px 4px rgba(220, 38, 38, 0.25);
  }
}

/* ANIM-06: Podium slides up from bottom */
.gs-finished .podium-slot {
  animation: podium-enter 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}

.gs-finished .podium-1st { animation-delay: 0.4s; }
.gs-finished .podium-2nd { animation-delay: 0.2s; }
.gs-finished .podium-3rd { animation-delay: 0s; }

@keyframes podium-enter {
  from {
    opacity: 0;
    transform: translateY(40px) scale(0.8);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

/* Crown winner enhanced bounce on finished */
.gs-finished .crown-winner {
  animation: crown-bounce 0.6s ease-out 0.8s both;
}

@media (prefers-reduced-motion: reduce) {
  .feedback-wait { animation: none; }
  .answer-btn { transition: none; }
  .timer-display { transition: none; }
  .timer-critical { animation: none; }
  .player-ready { transition: none; }
  .leaderboard-row { transition: none; }
  .lb-bar-fill { transition: none; }
  .crown-winner { animation: none; }
  .lb-next { animation: none; }
  /* Spectacle animations */
  .gs-question.spotlight-active::before { display: none; }
  .gs-question.spotlight-active .gs-card { box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); }
  .answer-btn.reveal-pending { animation: none; opacity: 1; }
  .answer-btn.reveal-correct,
  .answer-btn.reveal-wrong { transition: none; transform: none; }
  .gs-question.screen-shake { animation: none; }
  .gs-question.pulse-border .gs-card { animation: none; }
  .gs-finished .podium-slot { animation: none; opacity: 1; transform: none; }
  .gs-finished .crown-winner { animation: none; }
  .heart-break { animation: none; opacity: 1; transform: none; }
  .player-card-eliminating { animation: none; }
  .sudden-death-banner { animation: none; }
}

/* History section */
.gs-history {
  margin-top: 32px;
  text-align: left;
  max-width: 420px;
  margin-left: auto;
  margin-right: auto;
}
.gs-history h4 {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 12px;
  color: var(--color-main-text);
}
.gs-history-item {
  background: var(--color-background-hover);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large);
  padding: 12px 16px;
  margin-bottom: 8px;
}
.history-meta {
  display: flex;
  justify-content: space-between;
  margin-bottom: 6px;
}
.history-mode {
  font-weight: 600;
  color: var(--color-primary-element);
  font-size: 14px;
}
.history-date {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
}
.history-result {
  display: flex;
  gap: 16px;
  font-size: 14px;
  margin-bottom: 6px;
}
.history-score {
  font-weight: 600;
}
.history-winner {
  color: var(--color-success);
  font-weight: 500;
}
.history-players {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.history-player-chip {
  font-size: 12px;
  background: var(--color-background-dark);
  padding: 2px 8px;
  border-radius: 12px;
  color: var(--color-text-maxcontrast);
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
.gs-bot-score-label { margin-left: 16px; font-size: 13px; }
.gs-bot-score { font-weight: 700; margin-left: 4px; }
.bot-final-result {
  text-align: center;
  padding: 16px;
  background: var(--color-background-dark, #f4f4f4);
  border-radius: var(--border-radius-large, 8px);
  margin-bottom: 16px;
}
.bot-final-scores {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 20px;
  margin-bottom: 8px;
}
.bot-final-player { display: flex; flex-direction: column; align-items: center; gap: 4px; }
.bot-final-name { font-size: 14px; color: var(--color-text-maxcontrast); }
.bot-final-score { font-size: 36px; font-weight: 700; }
.bot-final-vs { font-size: 18px; color: var(--color-text-maxcontrast); }
.bot-winner-text { font-size: 18px; font-weight: 600; margin: 8px 0; }
</style>
