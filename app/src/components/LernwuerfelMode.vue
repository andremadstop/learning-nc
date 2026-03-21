<template>
  <div class="lw-mode">

    <!-- ===== JOIN PHASE ===== -->
    <div v-if="phase === 'join'" class="lw-join">
      <h3>{{ t('learning', 'Lernwürfel') }}</h3>
      <p class="lw-subtitle">{{ t('learning', 'Mensch ärgere dich nicht — mit Lernfragen') }}</p>

      <NcNoteCard v-if="error" type="error" class="lw-error">{{ error }}</NcNoteCard>

      <div class="lw-form-row">
        <label class="lw-label">{{ t('learning', 'Pool') }}</label>
        <select v-model="selectedPoolId" class="lw-select">
          <option :value="0" disabled>{{ t('learning', '-- Pool auswählen --') }}</option>
          <option v-for="p in pools" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </div>

      <div class="lw-form-row">
        <label class="lw-label">{{ t('learning', 'Max. Spieler') }}</label>
        <select v-model="maxPlayers" class="lw-select lw-select-small">
          <option :value="2">2</option>
          <option :value="3">3</option>
          <option :value="4">4</option>
          <option :value="5">5</option>
        </select>
      </div>

      <div class="lw-join-section">
        <input
          v-model="joinCode"
          class="lw-code-input"
          type="text"
          :placeholder="t('learning', 'Code eingeben')"
          maxlength="12"
          @keyup.enter="joinGame"
        />
        <NcButton type="secondary" :disabled="loading || !joinCode.trim()" @click="joinGame">
          {{ loading ? t('learning', 'Beitreten...') : t('learning', 'Beitreten') }}
        </NcButton>
      </div>

      <div class="lw-divider"><span>{{ t('learning', 'oder') }}</span></div>

      <div class="lw-actions">
        <NcButton type="primary" :disabled="loading || selectedPoolId === 0" @click="createGame">
          {{ loading ? t('learning', 'Erstelle...') : t('learning', 'Neues Spiel starten') }}
        </NcButton>
        <NcButton type="tertiary" :disabled="loading" @click="$emit('back')">
          {{ t('learning', 'Zurück') }}
        </NcButton>
      </div>
    </div>

    <!-- ===== LOBBY PHASE ===== -->
    <div v-else-if="phase === 'lobby'" class="lw-lobby">
      <h3>{{ t('learning', 'Lobby — Lernwürfel') }}</h3>

      <NcNoteCard v-if="error" type="error" class="lw-error">{{ error }}</NcNoteCard>

      <div class="lw-code-box">
        <span class="lw-code-label">{{ t('learning', 'Spielcode') }}</span>
        <span class="lw-code">{{ gameshowCode }}</span>
        <NcButton type="secondary" @click="copyCode">{{ t('learning', 'Kopieren') }}</NcButton>
      </div>

      <div class="lw-lobby-players">
        <div
          v-for="player in activePlayers"
          :key="player.user_id"
          class="lw-lobby-player"
        >
          <span class="lw-player-token" :class="'token-slot-' + player.slot">
            {{ playerInitials(player) }}
          </span>
          <span class="lw-player-name">{{ player.display_name || player.user_id }}</span>
          <span class="lw-player-ready" :class="{ ready: player.is_ready }">
            {{ player.is_ready ? '✓' : '...' }}
          </span>
        </div>
        <div v-for="n in emptySlots" :key="'empty-' + n" class="lw-lobby-player lw-lobby-empty">
          <span class="lw-player-name-empty">{{ t('learning', 'Warte auf Spieler...') }}</span>
        </div>
      </div>

      <p class="lw-lobby-status">{{ lobbyStatusText }}</p>

      <div class="lw-actions">
        <NcButton type="primary" :disabled="loading || readyClicked" @click="setReady">
          {{ readyClicked ? t('learning', 'Bereit!') : t('learning', 'Bereit!') }}
        </NcButton>
        <NcButton type="tertiary" @click="cancelGame">{{ t('learning', 'Abbrechen') }}</NcButton>
      </div>
    </div>

    <!-- ===== BOARD PHASE ===== -->
    <div v-else-if="phase === 'board'" class="lw-board-phase">
      <NcNoteCard v-if="error" type="error" class="lw-error">{{ error }}</NcNoteCard>

      <!-- Board SVG -->
      <div class="lw-board-wrapper">
        <svg
          class="lw-board-svg"
          viewBox="0 0 420 140"
          xmlns="http://www.w3.org/2000/svg"
          role="img"
          :aria-label="t('learning', 'Spielbrett mit 30 Feldern')"
        >
          <!-- Path fields 1-30 in a winding 3-row layout -->
          <g v-for="field in boardFields" :key="'field-' + field.num">
            <rect
              :x="field.x"
              :y="field.y"
              width="13"
              height="13"
              rx="2"
              class="lw-field"
              :class="fieldClasses(field.num)"
            />
            <!-- Star for special fields -->
            <text
              v-if="SPECIAL_FIELDS[field.num]"
              :x="field.x + 6.5"
              :y="field.y + 9.5"
              text-anchor="middle"
              class="lw-field-star"
            >★</text>
            <!-- Field number (small) -->
            <text
              v-else
              :x="field.x + 6.5"
              :y="field.y + 9.5"
              text-anchor="middle"
              class="lw-field-num"
            >{{ field.num }}</text>
          </g>

          <!-- START label -->
          <text x="7" y="135" class="lw-label-text">START</text>
          <!-- ZIEL label -->
          <text x="392" y="135" class="lw-label-text" text-anchor="middle">ZIEL</text>

          <!-- Player tokens -->
          <g v-for="player in activePlayers" :key="'token-' + player.user_id">
            <circle
              v-if="tokenPosition(player) !== null"
              :cx="tokenPosition(player).cx"
              :cy="tokenPosition(player).cy"
              r="5"
              class="lw-token"
              :class="['token-fill-slot-' + player.slot, { 'token-bounce': kickedSlots.includes(player.slot) }]"
            />
            <text
              v-if="tokenPosition(player) !== null"
              :x="tokenPosition(player).cx"
              :y="tokenPosition(player).cy + 4"
              text-anchor="middle"
              class="lw-token-label"
            >{{ playerInitials(player) }}</text>
          </g>
        </svg>
      </div>

      <!-- Turn indicator + player status -->
      <div class="lw-turn-bar">
        <div
          v-for="player in activePlayers"
          :key="'turn-' + player.user_id"
          class="lw-turn-player"
          :class="{ 'lw-turn-active': isActivePlayer(player), 'lw-turn-me': player.slot === mySlot }"
        >
          <span class="lw-turn-token" :class="'token-slot-' + player.slot">
            {{ playerInitials(player) }}
          </span>
          <span class="lw-turn-name">{{ player.display_name || player.user_id }}</span>
          <span class="lw-turn-pos">{{ t('learning', 'Feld {n}', { n: playerPosition(player) }) }}</span>
          <span v-if="isShielded(player)" class="lw-shield-icon" :title="t('learning', 'Schutzfeld')">🛡</span>
        </div>
      </div>

      <!-- Active player controls (my turn) -->
      <div v-if="isMyTurn && boardPhase === 'roll'" class="lw-my-turn">
        <p class="lw-my-turn-label">{{ t('learning', 'Du bist dran!') }}</p>
        <div v-if="turnSkippedMsg" class="lw-skip-notice">
          <NcNoteCard type="info">{{ turnSkippedMsg }}</NcNoteCard>
        </div>
        <div class="lw-dice-area">
          <div class="lw-dice" :class="{ 'lw-dice-rolling': diceRolling }" @click="!loading && rollDice()">
            <span class="lw-dice-face">{{ diceDisplayFace }}</span>
          </div>
          <NcButton type="primary" :disabled="loading || diceRolling" @click="rollDice">
            {{ loading ? t('learning', 'Würfle...') : t('learning', 'Würfeln') }}
          </NcButton>
        </div>
      </div>

      <!-- Waiting for other player's roll -->
      <div v-else-if="!isMyTurn && boardPhase === 'roll'" class="lw-wait-roll">
        <p>{{ t('learning', '{name} ist dran...', { name: activePlayerName }) }}</p>
        <NcLoadingIcon :size="24" />
      </div>

      <!-- Special effect notification -->
      <div v-if="specialEffectMsg" class="lw-special-effect" role="alert" aria-live="polite">
        {{ specialEffectMsg }}
      </div>

      <!-- Question card (my turn, question phase) -->
      <div v-if="isMyTurn && boardPhase === 'question' && currentQuestion" class="lw-question-card">
        <p class="lw-dice-rolled">{{ t('learning', 'Gewürfelt: {n}', { n: diceResult }) }}</p>
        <QuestionLanguageSwitcher v-model="questionLanguage" />
        <img
          v-if="currentQuestion.image_path"
          :src="questionImageUrl(currentQuestion.id)"
          alt=""
          class="lw-question-image"
        />
        <p class="lw-question-text">{{ currentQuestion.text }}</p>
        <div class="lw-answer-grid">
          <button
            v-for="answer in (currentQuestion.answers || [])"
            :key="answer.id"
            class="lw-answer-btn"
            :class="answerBtnClasses(answer)"
            :disabled="hasAnswered || loading"
            @click="onAnswer(answer.id)"
          >
            {{ answer.text }}
          </button>
        </div>
      </div>

      <!-- Waiting for other player's answer -->
      <div v-else-if="!isMyTurn && boardPhase === 'question'" class="lw-wait-answer">
        <p>{{ t('learning', '{name} beantwortet eine Frage...', { name: activePlayerName }) }}</p>
        <NcLoadingIcon :size="24" />
      </div>

      <!-- Answer feedback overlay -->
      <div v-if="showAnswerFeedback" class="lw-feedback-overlay" :class="lastAnswerCorrect ? 'lw-feedback-correct' : 'lw-feedback-wrong'">
        <span class="lw-feedback-icon">{{ lastAnswerCorrect ? '✓' : '✗' }}</span>
        <span class="lw-feedback-label">{{ lastAnswerCorrect ? t('learning', 'Richtig! Figur rückt vor.') : t('learning', 'Falsch! Figur bleibt stehen.') }}</span>
      </div>

      <div class="lw-abort-row">
        <NcButton type="tertiary" @click="cancelGame">{{ t('learning', 'Abbrechen') }}</NcButton>
      </div>
    </div>

    <!-- ===== FINISHED PHASE ===== -->
    <div v-else-if="phase === 'finished'" class="lw-finished">
      <h3>{{ t('learning', 'Spiel beendet!') }}</h3>

      <div v-if="winnerPlayer" class="lw-winner-card">
        <span class="lw-winner-trophy">🏆</span>
        <span class="lw-winner-name">{{ winnerPlayer.display_name || winnerPlayer.user_id }}</span>
        <span class="lw-winner-label">{{ t('learning', 'hat gewonnen!') }}</span>
      </div>

      <div class="lw-final-list">
        <div
          v-for="(player, idx) in sortedByPosition"
          :key="'fin-' + player.user_id"
          class="lw-final-player"
          :class="{ 'lw-final-me': player.slot === mySlot }"
        >
          <span class="lw-final-rank">{{ idx + 1 }}.</span>
          <span class="lw-player-token" :class="'token-slot-' + player.slot">
            {{ playerInitials(player) }}
          </span>
          <span class="lw-final-name">{{ player.display_name || player.user_id }}</span>
          <span class="lw-final-pos">{{ t('learning', 'Feld {n}', { n: playerPosition(player) }) }}</span>
          <span class="lw-final-score">{{ t('learning', '{n} richtig', { n: player.score }) }}</span>
        </div>
      </div>

      <div class="lw-actions">
        <NcButton type="primary" @click="newRound">{{ t('learning', 'Neue Runde') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Zurück') }}</NcButton>
      </div>
    </div>

    <!-- ===== EXPIRED PHASE ===== -->
    <div v-else-if="phase === 'expired'" class="lw-expired">
      <h3>{{ t('learning', 'Spiel abgebrochen') }}</h3>
      <p>{{ t('learning', 'Ein Spieler hat die Verbindung verloren.') }}</p>
      <div class="lw-actions">
        <NcButton type="primary" @click="$emit('back')">{{ t('learning', 'Zurück') }}</NcButton>
      </div>
    </div>

  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import QuestionLanguageSwitcher from './QuestionLanguageSwitcher.vue'

/** Special field definitions matching the backend constants */
const SPECIAL_FIELDS = {
  5: 'bonus_roll',
  10: 'shield',
  15: 'trap',
  20: 'bonus_roll',
  25: 'shield',
}

/** Number of board fields */
const BOARD_SIZE = 30

/**
 * Pre-computed SVG coordinates for each field 1-30.
 * Layout: 3 rows of 10 fields in a winding path (snake).
 *   Row 0 (bottom, y=110): fields 1-10, left to right
 *   Row 1 (middle, y=65):  fields 11-20, right to left
 *   Row 2 (top,    y=20):  fields 21-30, left to right
 */
function buildBoardFields() {
  const fields = []
  const rowY = [110, 65, 20]
  const cellW = 14
  const marginX = 5

  for (let i = 1; i <= BOARD_SIZE; i++) {
    const row = Math.floor((i - 1) / 10) // 0, 1, 2
    const col = (i - 1) % 10            // 0-9 within row
    const y = rowY[row]
    let x
    if (row === 1) {
      // Right to left
      x = marginX + (9 - col) * cellW
    } else {
      // Left to right
      x = marginX + col * cellW
    }
    fields.push({ num: i, x, y })
  }
  return fields
}

const BOARD_FIELDS = buildBoardFields()

/** Dice face characters (Unicode) */
const DICE_FACES = ['', '⚀', '⚁', '⚂', '⚃', '⚄', '⚅']

/** Player slot colours */
const SLOT_COLORS = ['#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6']

export default {
  name: 'LernwuerfelMode',

  components: { NcButton, NcNoteCard, NcLoadingIcon, QuestionLanguageSwitcher },

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
    initialPoolId: {
      type: Number,
      default: null,
    },
  },

  data() {
    return {
      // Phase management
      phase: 'join',

      // Session
      gameshowCode: '',
      joinCode: '',
      state: null,
      loading: false,
      error: null,
      readyClicked: false,
      pollingInterval: null,
      consecutivePollErrors: 0,

      // Pool selection
      pools: [],
      selectedPoolId: 0,
      maxPlayers: 2,

      // Board
      boardFields: BOARD_FIELDS,
      SPECIAL_FIELDS,
      diceResult: null,
      diceRolling: false,
      diceDisplayValue: null,
      turnSkippedMsg: null,

      // Answer handling
      hasAnswered: false,
      lastAnswerCorrect: false,
      selectedAnswerId: null,
      showAnswerFeedback: false,
      kickedSlots: [],

      // VFX
      specialEffectMsg: null,

      // Language
      questionLanguage: this.contentLanguage || '',

      // Celebration
      finishedCelebrated: false,
    }
  },

  computed: {
    activePlayers() {
      if (!this.state || !this.state.players) return []
      return this.state.players.filter(p => !p.is_removed)
    },

    emptySlots() {
      if (!this.state) return 0
      return Math.max(0, this.state.max_players - this.activePlayers.length)
    },

    mySlot() {
      return this.state ? this.state.my_slot : null
    },

    boardState() {
      return this.state ? this.state.board_state : null
    },

    boardPhase() {
      return this.boardState ? this.boardState.phase : 'roll'
    },

    activePlayerIndex() {
      return this.boardState ? (this.boardState.active_player_index ?? 0) : 0
    },

    isMyTurn() {
      return this.mySlot === this.activePlayerIndex
    },

    activePlayerName() {
      const active = this.activePlayers.find(p => p.slot === this.activePlayerIndex)
      return active ? (active.display_name || active.user_id) : ''
    },

    currentQuestion() {
      return this.state ? this.state.current_question : null
    },

    lobbyStatusText() {
      if (!this.state) return t('learning', 'Warte auf Spieler...')
      const all = this.activePlayers.length >= 2 && this.activePlayers.every(p => p.is_ready)
      if (all) return t('learning', 'Alle bereit! Spiel startet...')
      if (this.activePlayers.length < 2) return t('learning', 'Warte auf Spieler...')
      return t('learning', 'Warte bis alle bereit sind...')
    },

    winnerPlayer() {
      if (!this.state) return null
      if (this.state.winner_user_id) {
        return this.activePlayers.find(p => p.user_id === this.state.winner_user_id) || null
      }
      // Fall back to player at position 30
      if (this.boardState && this.boardState.positions) {
        for (const [slot, pos] of Object.entries(this.boardState.positions)) {
          if (pos >= BOARD_SIZE) {
            return this.activePlayers.find(p => p.slot === parseInt(slot)) || null
          }
        }
      }
      return null
    },

    sortedByPosition() {
      return [...this.activePlayers].sort((a, b) => {
        const posA = this.playerPosition(a)
        const posB = this.playerPosition(b)
        if (posB !== posA) return posB - posA
        return b.score - a.score
      })
    },

    diceDisplayFace() {
      if (this.diceDisplayValue && this.diceDisplayValue >= 1 && this.diceDisplayValue <= 6) {
        return DICE_FACES[this.diceDisplayValue]
      }
      return '🎲'
    },

    effectiveContentLanguage() {
      return this.questionLanguage || ''
    },
  },

  watch: {
    contentLanguage(newLang, oldLang) {
      if (!this.questionLanguage || this.questionLanguage === oldLang) {
        this.questionLanguage = newLang || ''
      }
    },
  },

  async mounted() {
    if (this.coursePools && this.coursePools.length > 0) {
      this.pools = this.coursePools.map(p => ({
        id: p.pool_id ?? p.id,
        name: p.pool_name ?? p.name,
      }))
      if (this.pools.length === 1) {
        this.selectedPoolId = this.pools[0].id
      }
    } else {
      await this.fetchPools()
    }

    if (this.initialPoolId && this.pools.some(p => p.id === this.initialPoolId)) {
      this.selectedPoolId = this.initialPoolId
    }

    // Session recovery
    const saved = localStorage.getItem('learning_lernwuerfel_session')
    if (saved) {
      try {
        const { code } = JSON.parse(saved)
        if (code) {
          this.recoverSession(code)
          return
        }
      } catch (e) {
        localStorage.removeItem('learning_lernwuerfel_session')
      }
    }
  },

  destroyed() {
    this.stopPolling()
  },

  methods: {
    // -------- Board helpers --------

    playerPosition(player) {
      if (!this.boardState || !this.boardState.positions) return 0
      return this.boardState.positions[String(player.slot)] ?? 0
    },

    isActivePlayer(player) {
      return player.slot === this.activePlayerIndex
    },

    isShielded(player) {
      if (!this.boardState || !this.boardState.shielded_slots) return false
      return this.boardState.shielded_slots.includes(player.slot)
    },

    fieldClasses(fieldNum) {
      const type = SPECIAL_FIELDS[fieldNum]
      return {
        'lw-field-bonus': type === 'bonus_roll',
        'lw-field-shield': type === 'shield',
        'lw-field-trap': type === 'trap',
        'lw-field-goal': fieldNum === BOARD_SIZE,
        'lw-field-start': fieldNum === 1,
      }
    },

    tokenPosition(player) {
      const pos = this.playerPosition(player)
      const field = BOARD_FIELDS[pos - 1] // field 1 = index 0
      if (!field && pos === 0) {
        // Start area: cluster near bottom-left outside the board
        return { cx: 0, cy: 0 }
      }
      if (!field) return null
      return { cx: field.x + 6.5, cy: field.y + 6.5 }
    },

    playerInitials(player) {
      const label = (player?.display_name || player?.user_id || '?').trim()
      if (!label) return '?'
      const parts = label.split(/\s+/).filter(Boolean)
      return parts.slice(0, 2).map(p => p.charAt(0).toUpperCase()).join('')
    },

    questionImageUrl(id) {
      return generateUrl('/apps/learning/api/questions/' + id + '/image')
    },

    answerBtnClasses(answer) {
      if (!this.hasAnswered) return {}
      const isSelected = this.selectedAnswerId === answer.id
      return {
        'btn-selected-correct': isSelected && this.lastAnswerCorrect,
        'btn-selected-incorrect': isSelected && !this.lastAnswerCorrect,
      }
    },

    buildStateParams() {
      return this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}
    },

    // -------- Polling --------

    startPolling() {
      this.stopPolling()
      this.pollingInterval = setInterval(() => {
        if (this.phase === 'board' || this.phase === 'lobby') {
          this.pollState()
        }
      }, 1500)
    },

    stopPolling() {
      if (this.pollingInterval) {
        clearInterval(this.pollingInterval)
        this.pollingInterval = null
      }
    },

    async pollState() {
      if (!this.gameshowCode || this.loading) return
      try {
        const r = await axios.get(
          generateUrl('/apps/learning/api/gameshow/' + this.gameshowCode + '/state'),
          { params: this.buildStateParams() }
        )
        this.consecutivePollErrors = 0
        this.applyStateTransitions(r.data)
      } catch (e) {
        this.consecutivePollErrors++
        if (this.consecutivePollErrors >= 5) {
          this.stopPolling()
          this.phase = 'expired'
        }
      }
    },

    applyStateTransitions(newState) {
      const prevBoardState = this.state ? this.state.board_state : null
      this.state = newState

      if (newState.status === 'finished') {
        this.stopPolling()
        this.phase = 'finished'
        if (!this.finishedCelebrated) {
          this.finishedCelebrated = true
          this.$nextTick(() => { this.fireConfetti() })
          this.emitVirtuProf('lernwuerfel-win', {
            winner: this.winnerPlayer?.display_name || '',
          })
        }
        return
      }

      if (newState.status === 'expired') {
        this.stopPolling()
        this.phase = 'expired'
        return
      }

      if (newState.status === 'active') {
        if (this.phase === 'lobby') {
          this.phase = 'board'
          this.hasAnswered = false
          this.showAnswerFeedback = false
        }

        // Detect kick events: opponent sent to position 0
        if (prevBoardState && newState.board_state) {
          const prevPos = prevBoardState.positions || {}
          const nextPos = newState.board_state.positions || {}
          const kicked = []
          for (const [slot, pos] of Object.entries(nextPos)) {
            if (pos === 0 && (prevPos[slot] ?? 0) > 0) {
              kicked.push(parseInt(slot))
            }
          }
          if (kicked.length > 0) {
            this.kickedSlots = kicked
            setTimeout(() => { this.kickedSlots = [] }, 1200)
          }
        }

        // Detect special effects
        const effect = newState.board_state?.special_effect
        if (effect && effect !== (prevBoardState?.special_effect)) {
          this.showSpecialEffect(effect)
        }
      }

      if (newState.status === 'waiting') {
        this.phase = 'lobby'
      }
    },

    showSpecialEffect(effect) {
      const msgs = {
        bonus_roll: t('learning', '★ Bonuswurf! Du darfst nochmal würfeln.'),
        shield: t('learning', '★ Schutzfeld! Du bist eine Runde geschützt.'),
        trap: t('learning', '★ Falle! Du musst eine Runde aussetzen.'),
      }
      this.specialEffectMsg = msgs[effect] || null
      if (this.specialEffectMsg) {
        setTimeout(() => { this.specialEffectMsg = null }, 3500)
      }
    },

    // -------- Session management --------

    async recoverSession(code) {
      this.loading = true
      try {
        const r = await axios.get(
          generateUrl('/apps/learning/api/gameshow/' + code + '/state'),
          { params: this.buildStateParams() }
        )
        if (['waiting', 'active'].includes(r.data.status)) {
          this.gameshowCode = code
          this.state = r.data
          this.finishedCelebrated = false
          this.readyClicked = false
          this.hasAnswered = false
          this.phase = r.data.status === 'active' ? 'board' : 'lobby'
          this.startPolling()
        } else {
          localStorage.removeItem('learning_lernwuerfel_session')
        }
      } catch (e) {
        localStorage.removeItem('learning_lernwuerfel_session')
      } finally {
        this.loading = false
      }
    },

    async fetchPools() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/pools'))
        const own = r.data.own || []
        const shared = r.data.shared || []
        this.pools = [...own, ...shared]
        if (this.pools.length === 1) {
          this.selectedPoolId = this.pools[0].id
        }
      } catch (e) {
        this.error = t('learning', 'Pools konnten nicht geladen werden')
      }
    },

    // -------- Join phase --------

    async createGame() {
      if (!this.selectedPoolId) return
      this.loading = true
      this.error = null
      try {
        const payload = {
          poolId: this.selectedPoolId,
          mode: 'lernwuerfel',
          maxPlayers: this.maxPlayers,
          ...(this.courseId ? { courseId: this.courseId } : {}),
        }
        const r = await axios.post(generateUrl('/apps/learning/api/gameshow'), payload)
        this.gameshowCode = r.data.code
        this.state = r.data
        this.readyClicked = false
        this.finishedCelebrated = false
        this.phase = 'lobby'
        localStorage.setItem('learning_lernwuerfel_session', JSON.stringify({ code: this.gameshowCode }))
        this.startPolling()
      } catch (e) {
        this.error = e.response?.data?.error || t('learning', 'Spiel konnte nicht erstellt werden')
      } finally {
        this.loading = false
      }
    },

    async joinGame() {
      const code = this.joinCode.trim()
      if (!code) return
      this.loading = true
      this.error = null
      try {
        const r = await axios.post(generateUrl('/apps/learning/api/gameshow/' + code + '/join'), {})
        this.gameshowCode = r.data.code
        this.state = r.data
        this.joinCode = ''
        this.readyClicked = false
        this.finishedCelebrated = false
        this.hasAnswered = false
        this.phase = r.data.status === 'active' ? 'board' : 'lobby'
        localStorage.setItem('learning_lernwuerfel_session', JSON.stringify({ code: this.gameshowCode }))
        this.startPolling()
      } catch (e) {
        this.error = e.response?.data?.error || t('learning', 'Beitreten fehlgeschlagen')
      } finally {
        this.loading = false
      }
    },

    async copyCode() {
      try {
        await navigator.clipboard.writeText(this.gameshowCode)
      } catch (e) {
        // Fallback: silently ignore
      }
    },

    // -------- Lobby phase --------

    async setReady() {
      if (this.readyClicked) return
      this.readyClicked = true
      this.error = null
      try {
        const r = await axios.post(
          generateUrl('/apps/learning/api/gameshow/' + this.gameshowCode + '/ready'),
          this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}
        )
        this.state = r.data
        this.applyStateTransitions(r.data)
      } catch (e) {
        this.readyClicked = false
        this.error = e.response?.data?.error || t('learning', 'Bereit-Status konnte nicht gesetzt werden')
      }
    },

    cancelGame() {
      localStorage.removeItem('learning_lernwuerfel_session')
      this.stopPolling()
      this.phase = 'join'
      this.gameshowCode = ''
      this.state = null
      this.error = null
      this.readyClicked = false
      this.finishedCelebrated = false
      this.hasAnswered = false
      this.showAnswerFeedback = false
      this.$emit('back')
    },

    // -------- Board phase: roll --------

    async rollDice() {
      if (this.loading || this.diceRolling) return
      this.loading = true
      this.diceRolling = true
      this.error = null
      this.turnSkippedMsg = null
      this.hasAnswered = false
      this.showAnswerFeedback = false

      // Animate dice spin
      let animFrames = 0
      const animInterval = setInterval(() => {
        this.diceDisplayValue = Math.floor(Math.random() * 6) + 1
        animFrames++
        if (animFrames >= 8) {
          clearInterval(animInterval)
        }
      }, 80)

      try {
        const r = await axios.post(
          generateUrl('/apps/learning/api/gameshow/' + this.gameshowCode + '/roll'),
          this.buildStateParams()
        )

        clearInterval(animInterval)
        this.diceRolling = false

        if (r.data.turn_skipped) {
          this.turnSkippedMsg = t('learning', 'Falle! Du musstest eine Runde aussetzen.')
          this.diceDisplayValue = null
          this.state = r.data
          // Turn advanced to next player
          return
        }

        this.diceResult = r.data.board_state?.dice_result ?? null
        this.diceDisplayValue = this.diceResult
        this.state = r.data
        this.applyStateTransitions(r.data)
      } catch (e) {
        clearInterval(animInterval)
        this.diceRolling = false
        this.error = e.response?.data?.error || t('learning', 'Würfeln fehlgeschlagen')
      } finally {
        this.loading = false
      }
    },

    // -------- Board phase: answer --------

    async onAnswer(answerId) {
      if (this.hasAnswered || this.loading) return
      this.hasAnswered = true
      this.selectedAnswerId = answerId
      this.loading = true
      this.error = null

      try {
        const r = await axios.post(
          generateUrl('/apps/learning/api/gameshow/' + this.gameshowCode + '/answer'),
          {
            answerId,
            answeredAt: Date.now(),
            ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
          }
        )

        this.lastAnswerCorrect = r.data.my_answer_correct ?? false
        this.showAnswerFeedback = true

        this.emitVirtuProf(
          this.lastAnswerCorrect ? 'lernwuerfel-correct' : 'lernwuerfel-wrong',
          {}
        )

        // If rolled a 6 and answered correctly, backend returns phase='roll' for same player
        const newBoardPhase = r.data.board_state?.phase
        const wasMyTurnStill = r.data.board_state?.active_player_index === this.mySlot

        setTimeout(() => {
          this.showAnswerFeedback = false
          this.state = r.data
          this.applyStateTransitions(r.data)

          // Bonus roll: same player, back to roll phase
          if (newBoardPhase === 'roll' && wasMyTurnStill && r.data.status === 'active') {
            this.hasAnswered = false
            this.diceResult = null
            this.diceDisplayValue = null
            const effect = r.data.board_state?.special_effect
            if (effect === 'bonus_roll') {
              this.showSpecialEffect('bonus_roll')
            }
          }
        }, 1400)
      } catch (e) {
        this.hasAnswered = false
        this.selectedAnswerId = null
        this.error = e.response?.data?.error || t('learning', 'Antwort konnte nicht gesendet werden')
        this.showAnswerFeedback = false
      } finally {
        this.loading = false
      }
    },

    // -------- Celebration --------

    fireConfetti() {
      try {
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
        if (prefersReducedMotion) return
        import('canvas-confetti').then(({ default: confetti }) => {
          confetti({
            particleCount: 120,
            spread: 80,
            origin: { y: 0.6 },
          })
        }).catch(() => {})
      } catch (e) {
        // Non-critical
      }
    },

    emitVirtuProf(triggerId, context = {}) {
      this.$root.$emit('virtuprof:trigger', triggerId, context)
    },

    newRound() {
      localStorage.removeItem('learning_lernwuerfel_session')
      this.stopPolling()
      this.phase = 'join'
      this.gameshowCode = ''
      this.state = null
      this.error = null
      this.readyClicked = false
      this.finishedCelebrated = false
      this.hasAnswered = false
      this.showAnswerFeedback = false
      this.diceResult = null
      this.diceDisplayValue = null
    },
  },
}
</script>

<style scoped>
/* ===== Layout ===== */
.lw-mode {
  max-width: 680px;
  margin: 0 auto;
  padding: 16px;
  font-family: var(--font-face, sans-serif);
}

.lw-subtitle {
  color: var(--color-text-lighter, #666);
  margin-top: -8px;
  margin-bottom: 16px;
}

/* ===== Form rows ===== */
.lw-form-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}
.lw-label {
  min-width: 120px;
  font-weight: 500;
}
.lw-select {
  flex: 1;
  padding: 6px 10px;
  border: 1px solid var(--color-border, #ddd);
  border-radius: 6px;
  background: var(--color-main-background, #fff);
}
.lw-select-small {
  flex: 0 0 80px;
}

/* ===== Join ===== */
.lw-join-section {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}
.lw-code-input {
  flex: 1;
  padding: 8px 12px;
  border: 1px solid var(--color-border, #ddd);
  border-radius: 6px;
  font-size: 14px;
}
.lw-divider {
  text-align: center;
  color: var(--color-text-lighter, #888);
  margin: 12px 0;
  position: relative;
}
.lw-divider span {
  background: var(--color-main-background, #fff);
  padding: 0 8px;
  position: relative;
  z-index: 1;
}
.lw-divider::before {
  content: '';
  display: block;
  position: absolute;
  top: 50%;
  left: 0;
  right: 0;
  height: 1px;
  background: var(--color-border, #ddd);
}
.lw-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

/* ===== Lobby ===== */
.lw-code-box {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  background: var(--color-background-darker, #f5f5f5);
  border-radius: 8px;
  margin-bottom: 16px;
}
.lw-code-label {
  font-weight: 500;
}
.lw-code {
  font-size: 22px;
  font-weight: 700;
  letter-spacing: 3px;
  color: var(--color-primary, #0082c9);
}
.lw-lobby-players {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 16px;
}
.lw-lobby-player {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  background: var(--color-background-dark, #fafafa);
  border-radius: 8px;
}
.lw-lobby-empty {
  opacity: 0.5;
  font-style: italic;
}
.lw-player-name-empty {
  color: var(--color-text-lighter, #888);
}
.lw-player-ready {
  margin-left: auto;
  font-size: 18px;
  color: var(--color-text-lighter, #888);
}
.lw-player-ready.ready {
  color: #2ecc71;
  font-weight: 700;
}
.lw-lobby-status {
  color: var(--color-text-lighter, #888);
  font-style: italic;
  margin-bottom: 16px;
}

/* ===== Player tokens ===== */
.lw-player-token,
.lw-turn-token {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  flex-shrink: 0;
}
.token-slot-0 { background-color: #e74c3c; }
.token-slot-1 { background-color: #3498db; }
.token-slot-2 { background-color: #2ecc71; }
.token-slot-3 { background-color: #f39c12; }
.token-slot-4 { background-color: #9b59b6; }

/* ===== Board SVG ===== */
.lw-board-wrapper {
  border: 1px solid var(--color-border, #ddd);
  border-radius: 10px;
  padding: 8px;
  margin-bottom: 16px;
  background: var(--color-background-dark, #f8f8f8);
  overflow-x: auto;
}
.lw-board-svg {
  width: 100%;
  min-width: 320px;
  display: block;
}
.lw-field {
  fill: var(--color-background-hover, #e8e8e8);
  stroke: var(--color-border, #ccc);
  stroke-width: 0.5;
}
.lw-field-bonus {
  fill: #f1c40f;
  stroke: #e67e22;
}
.lw-field-shield {
  fill: #3498db;
  stroke: #2980b9;
}
.lw-field-trap {
  fill: #e74c3c;
  stroke: #c0392b;
}
.lw-field-goal {
  fill: #2ecc71;
  stroke: #27ae60;
  stroke-width: 1;
}
.lw-field-start {
  fill: #95a5a6;
  stroke: #7f8c8d;
}
.lw-field-star {
  font-size: 8px;
  fill: #fff;
  pointer-events: none;
}
.lw-field-num {
  font-size: 6px;
  fill: var(--color-text-lighter, #888);
  pointer-events: none;
}
.lw-label-text {
  font-size: 7px;
  fill: var(--color-text-lighter, #888);
}

/* ===== Tokens on board ===== */
.lw-token {
  transition: cx 0.4s ease, cy 0.4s ease;
  stroke: rgba(0,0,0,0.3);
  stroke-width: 0.5;
}
.token-fill-slot-0 { fill: #e74c3c; }
.token-fill-slot-1 { fill: #3498db; }
.token-fill-slot-2 { fill: #2ecc71; }
.token-fill-slot-3 { fill: #f39c12; }
.token-fill-slot-4 { fill: #9b59b6; }
.lw-token-label {
  font-size: 5px;
  fill: #fff;
  pointer-events: none;
  font-weight: 700;
}

@media (prefers-reduced-motion: no-preference) {
  .token-bounce {
    animation: lw-bounce 0.6s ease;
  }
  @keyframes lw-bounce {
    0% { r: 5; }
    40% { r: 7; fill: #e74c3c; }
    100% { r: 5; }
  }
}

/* ===== Turn bar ===== */
.lw-turn-bar {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.lw-turn-player {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 8px;
  border: 2px solid transparent;
  background: var(--color-background-dark, #f0f0f0);
  transition: border-color 0.2s;
}
.lw-turn-active {
  border-color: var(--color-primary, #0082c9);
  background: var(--color-background-hover, #e8f4fc);
}
.lw-turn-me {
  font-weight: 600;
}
.lw-turn-name {
  font-size: 13px;
}
.lw-turn-pos {
  font-size: 11px;
  color: var(--color-text-lighter, #888);
}
.lw-shield-icon {
  font-size: 14px;
}

/* ===== Dice ===== */
.lw-my-turn {
  text-align: center;
  margin-bottom: 16px;
}
.lw-my-turn-label {
  font-size: 15px;
  font-weight: 600;
  margin-bottom: 8px;
  color: var(--color-primary, #0082c9);
}
.lw-dice-area {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
}
.lw-dice {
  width: 60px;
  height: 60px;
  border-radius: 12px;
  border: 2px solid var(--color-primary, #0082c9);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 36px;
  cursor: pointer;
  user-select: none;
  background: var(--color-main-background, #fff);
  transition: transform 0.15s;
}
.lw-dice:hover {
  transform: scale(1.05);
}

@media (prefers-reduced-motion: no-preference) {
  .lw-dice-rolling {
    animation: lw-dice-spin 0.8s ease;
  }
  @keyframes lw-dice-spin {
    0%   { transform: rotateY(0deg) rotateX(0deg); }
    25%  { transform: rotateY(90deg) rotateX(20deg); }
    50%  { transform: rotateY(180deg) rotateX(-20deg); }
    75%  { transform: rotateY(270deg) rotateX(10deg); }
    100% { transform: rotateY(360deg) rotateX(0deg); }
  }
}

.lw-dice-rolled {
  font-size: 13px;
  color: var(--color-text-lighter, #888);
  margin-bottom: 8px;
}

.lw-wait-roll,
.lw-wait-answer {
  text-align: center;
  padding: 16px;
  color: var(--color-text-lighter, #888);
}
.lw-wait-roll p,
.lw-wait-answer p {
  margin-bottom: 8px;
}

/* ===== Question card ===== */
.lw-question-card {
  padding: 16px;
  border: 1px solid var(--color-border, #ddd);
  border-radius: 10px;
  background: var(--color-main-background, #fff);
  margin-bottom: 16px;
}
.lw-question-image {
  max-width: 100%;
  border-radius: 6px;
  margin-bottom: 10px;
}
.lw-question-text {
  font-size: 16px;
  font-weight: 500;
  margin-bottom: 16px;
  line-height: 1.5;
}
.lw-answer-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.lw-answer-btn {
  padding: 10px 12px;
  border: 1px solid var(--color-border, #ddd);
  border-radius: 8px;
  background: var(--color-background-dark, #f5f5f5);
  cursor: pointer;
  text-align: left;
  font-size: 14px;
  transition: background 0.15s, border-color 0.15s;
}
.lw-answer-btn:hover:not(:disabled) {
  background: var(--color-background-hover, #e8e8e8);
}
.lw-answer-btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}
.btn-selected-correct {
  background: #d5f5e3;
  border-color: #27ae60;
  color: #1e8449;
}
.btn-selected-incorrect {
  background: #fde8e8;
  border-color: #e74c3c;
  color: #c0392b;
}

/* ===== Feedback overlay ===== */
.lw-feedback-overlay {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 12px;
  font-weight: 600;
  font-size: 16px;
}
.lw-feedback-correct {
  background: #d5f5e3;
  border: 1px solid #27ae60;
  color: #1e8449;
}
.lw-feedback-wrong {
  background: #fde8e8;
  border: 1px solid #e74c3c;
  color: #c0392b;
}
.lw-feedback-icon {
  font-size: 22px;
  flex-shrink: 0;
}

/* ===== Special effect ===== */
.lw-special-effect {
  padding: 10px 14px;
  border-radius: 8px;
  background: #fef9e7;
  border: 1px solid #f1c40f;
  color: #7d6608;
  font-weight: 500;
  margin-bottom: 12px;
  text-align: center;
}

/* ===== Skip notice ===== */
.lw-skip-notice {
  margin-bottom: 12px;
}

/* ===== Abort ===== */
.lw-abort-row {
  display: flex;
  justify-content: flex-end;
  margin-top: 8px;
}

/* ===== Finished ===== */
.lw-winner-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 24px;
  background: var(--color-primary-element-light, #e8f4fc);
  border-radius: 12px;
  margin-bottom: 20px;
}
.lw-winner-trophy {
  font-size: 48px;
  line-height: 1;
  margin-bottom: 8px;
}
.lw-winner-name {
  font-size: 22px;
  font-weight: 700;
}
.lw-winner-label {
  color: var(--color-text-lighter, #888);
}
.lw-final-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 20px;
}
.lw-final-player {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  border-radius: 8px;
  background: var(--color-background-dark, #f5f5f5);
}
.lw-final-me {
  background: var(--color-primary-element-light, #e8f4fc);
}
.lw-final-rank {
  font-size: 16px;
  font-weight: 700;
  min-width: 24px;
}
.lw-final-name {
  flex: 1;
}
.lw-final-pos {
  font-size: 12px;
  color: var(--color-text-lighter, #888);
}
.lw-final-score {
  font-weight: 600;
}

/* ===== Error ===== */
.lw-error {
  margin-bottom: 12px;
}

/* ===== Expired ===== */
.lw-expired {
  text-align: center;
  padding: 24px;
}
</style>
