<template>
  <div class="abenteuer-mode" :class="{ 'ghostline-mode': stateBag && stateBag.flags && stateBag.flags.ghostline_active }" data-lnc-theme="dark" data-lnc-skin="paper-circuits">

    <!-- ===== CAMPAIGN SELECT PHASE ===== -->
    <div v-if="phase === 'campaign-select'" class="ab-campaign-select">
      <div class="ab-header">
        <button class="ab-back-btn" @click="$emit('back')">← {{ t('learning', 'Zurück') }}</button>
        <h2 class="ab-title">🗺 {{ t('learning', 'Abenteuer') }}</h2>
        <p class="ab-subtitle">{{ t('learning', 'Wähle eine Kampagne und löse IT-Probleme in einer spannenden Geschichte.') }}</p>
      </div>

      <div v-if="loadingCampaigns" class="ab-loading">
        <div class="ab-spinner"></div>
        <p>{{ t('learning', 'Lade Kampagnen...') }}</p>
      </div>

      <div v-else class="ab-campaign-sections">
        <section class="ab-campaign-section">
          <div class="ab-section-header">
            <h3>{{ t('learning', 'Empfohlene Kampagnen') }}</h3>
            <p>{{ t('learning', 'Kurzer Kernpfad mit den produktiven Story-Kampagnen fuer Bootcamps und Intensivkurse.') }}</p>
          </div>
          <div class="ab-campaign-grid">
            <div
              v-for="campaign in featuredCampaigns"
              :key="campaign.id"
              class="ab-campaign-card"
              :class="{ 'ab-campaign-completed': campaign.progress === 'completed' }"
              tabindex="0"
              role="button"
              @click="selectCampaign(campaign)"
              @keydown.enter="selectCampaign(campaign)"
              @keydown.space.prevent="selectCampaign(campaign)"
            >
              <div class="ab-campaign-icon">{{ campaign.icon }}</div>
              <div class="ab-campaign-info">
                <h3 class="ab-campaign-title">{{ campaign.title }}</h3>
                <p class="ab-campaign-desc">{{ campaign.description }}</p>
                <div class="ab-campaign-meta">
                  <span class="ab-difficulty" :class="'ab-diff-' + campaign.difficulty">
                    {{ difficultyLabel(campaign.difficulty) }}
                  </span>
                  <span v-for="area in campaign.focus_areas" :key="area" class="ab-focus-tag">{{ area }}</span>
                </div>
              </div>
              <div class="ab-campaign-progress">
                <div v-if="campaign.progress === 'not_started'" class="ab-prog-badge ab-prog-new">
                  {{ t('learning', 'Neu') }}
                </div>
                <div v-else-if="campaign.progress === 'completed'" class="ab-prog-badge ab-prog-done">
                  ✓ {{ t('learning', 'Abgeschlossen') }}
                </div>
                <div v-else class="ab-prog-badge ab-prog-active">
                  {{ t('learning', 'Szene {n}', { n: campaign.current_scene || 1 }) }}
                </div>
              </div>
            </div>
          </div>
        </section>

        <section v-if="bonusCampaigns.length > 0" class="ab-campaign-section">
          <div class="ab-section-header">
            <h3>{{ t('learning', 'Bonus-Kampagnen') }}</h3>
            <p>{{ t('learning', 'Weitere Storys fuer Zusatzrunden oder Vertiefung außerhalb des Kernpfads.') }}</p>
          </div>
          <div class="ab-campaign-grid ab-campaign-grid--bonus">
            <div
              v-for="campaign in bonusCampaigns"
              :key="campaign.id"
              class="ab-campaign-card ab-campaign-card--bonus"
              :class="{ 'ab-campaign-completed': campaign.progress === 'completed' }"
              tabindex="0"
              role="button"
              @click="selectCampaign(campaign)"
              @keydown.enter="selectCampaign(campaign)"
              @keydown.space.prevent="selectCampaign(campaign)"
            >
              <span class="ab-bonus-pill">{{ t('learning', 'Bonus') }}</span>
              <div class="ab-campaign-icon">{{ campaign.icon }}</div>
              <div class="ab-campaign-info">
                <h3 class="ab-campaign-title">{{ campaign.title }}</h3>
                <p class="ab-campaign-desc">{{ campaign.description }}</p>
                <div class="ab-campaign-meta">
                  <span class="ab-difficulty" :class="'ab-diff-' + campaign.difficulty">
                    {{ difficultyLabel(campaign.difficulty) }}
                  </span>
                  <span v-for="area in campaign.focus_areas" :key="area" class="ab-focus-tag">{{ area }}</span>
                </div>
              </div>
              <div class="ab-campaign-progress">
                <div v-if="campaign.progress === 'not_started'" class="ab-prog-badge ab-prog-new">
                  {{ t('learning', 'Neu') }}
                </div>
                <div v-else-if="campaign.progress === 'completed'" class="ab-prog-badge ab-prog-done">
                  ✓ {{ t('learning', 'Abgeschlossen') }}
                </div>
                <div v-else class="ab-prog-badge ab-prog-active">
                  {{ t('learning', 'Szene {n}', { n: campaign.current_scene || 1 }) }}
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>

    <!-- ===== CAMPAIGN INTRO PHASE ===== -->
    <div v-else-if="phase === 'intro'" class="ab-intro">
      <CampaignIntro
        :campaignId="selectedCampaign.id"
        :title="selectedCampaign.title"
        :difficulty="selectedCampaign.difficulty || 'normal'"
        @done="phase = 'character-select'"
      />
    </div>

    <!-- ===== CHARACTER SELECT PHASE ===== -->
    <div v-else-if="phase === 'character-select'" class="ab-character-select">
      <div class="ab-header">
        <button class="ab-back-btn" @click="phase = 'campaign-select'">← {{ t('learning', 'Kampagnen') }}</button>
        <h2 class="ab-title">{{ selectedCampaign ? selectedCampaign.title : '' }}</h2>
        <p class="ab-subtitle">{{ t('learning', 'Wähle deinen Charakter') }}</p>
      </div>

      <div class="ab-character-grid">
        <div
          v-for="char in characters"
          :key="char.id"
          class="ab-character-card"
          :class="{ 'ab-char-selected': selectedCharacter && selectedCharacter.id === char.id }"
          tabindex="0"
          role="button"
          @click="selectCharacter(char)"
          @keydown.enter="selectCharacter(char)"
          @keydown.space.prevent="selectCharacter(char)"
        >
          <div class="ab-char-portrait">{{ char.portrait }}</div>
          <div class="ab-char-info">
            <h3 class="ab-char-name">{{ char.name }}</h3>
            <p class="ab-char-role">{{ char.role }}</p>
            <p class="ab-char-desc">{{ char.description }}</p>
            <div class="ab-char-stats">
              <div class="ab-stat">
                <span class="ab-stat-label">{{ t('learning', 'Stärke') }}</span>
                <span class="ab-stat-value">{{ char.strength }}</span>
              </div>
              <div class="ab-stat">
                <span class="ab-stat-label">{{ t('learning', 'Schwäche') }}</span>
                <span class="ab-stat-value">{{ char.weakness }}</span>
              </div>
            </div>
          </div>
          <div v-if="selectedCharacter && selectedCharacter.id === char.id" class="ab-char-check">✓</div>
        </div>
      </div>

      <div class="ab-char-actions">
        <button
          class="ab-btn ab-btn-primary"
          :disabled="!selectedCharacter"
          @click="startCampaign"
        >
          {{ t('learning', 'Kampagne starten') }}
        </button>
        <button
          class="ab-btn ab-btn-secondary"
          :disabled="!selectedCharacter || !selectedCampaign || !selectedCampaign.is_graph"
          @click="openCoopLobby"
        >
          {{ t('learning', 'Coop starten') }}
        </button>
      </div>
    </div>

    <!-- ===== SCENE PHASE ===== -->
    <div v-else-if="phase === 'scene'" class="ab-scene">
      <transition name="rpg-fade" mode="out-in">
        <div :key="currentScene ? currentScene.id : 'loading'" class="ab-scene-inner">
          <div v-if="loadingScene" class="ab-loading">
            <div class="ab-spinner"></div>
          </div>

          <template v-else-if="currentScene">
            <!-- Scene header -->
            <div class="ab-scene-header">
              <button class="ab-back-btn" @click="confirmAbort">←</button>
              <span class="ab-scene-title">{{ currentScene.title }}</span>
              <span v-if="currentScene.gemini_role === 'attacker'" class="ab-role-badge ab-role-attacker">
                {{ t('learning', 'Angreifer aktiv') }}
              </span>
              <span v-if="currentScene.gemini_role === 'dau'" class="ab-role-badge ab-role-dau">
                {{ t('learning', 'Endanwender am Telefon') }}
              </span>
              <span class="ab-scene-progress">{{ sceneProgressLabel }}</span>
              <button
                v-if="isGraphMode && fullGraph"
                class="ab-map-btn"
                :title="t('learning', 'Quest-Map')"
                @click="$refs.questMap && $refs.questMap.open()"
              >
                &#x1F5FA;
              </button>
            </div>

            <GameTimer
              v-if="currentTimer"
              :deadline-at="currentTimer.deadlineAt"
              :total-seconds="currentTimer.totalSeconds"
              :reduced-motion="reducedMotion"
              @expired="onTimerExpired"
            />

            <GameHud
              v-if="isGraphMode"
              :state-bag="stateBag"
              :game-state="currentGameState"
              :full-graph="fullGraph"
              :score-delta="scoreDelta"
              :reputation-deltas="reputationDeltas"
            />

            <DauBotDialog
              v-if="currentBotCorrection && !showCoopWaiting"
              :scenario="currentBotCorrection"
              :reduced-motion="reducedMotion"
              :result="botResult"
              @submit="onBotSubmit"
              @result-applied="onBotResultApplied"
            />

            <CoopWaiting
              v-if="showCoopWaiting"
              :active-player="coopWaitingPlayer"
              :simulator-type="coopWaitingType"
              :progress="coopWaitingProgress"
            />

            <!-- Narrative box -->
            <div
              v-if="!currentBotCorrection"
              class="ab-narrative-box"
              :class="{ 'ab-typewriter': !reducedMotion }">
              <p class="ab-narrative-text" :class="{ 'ab-typing': narrativeTyping }">
                {{ displayedNarrative }}
              </p>
              <button v-if="narrativeTyping" class="ab-skip-btn" @click="skipTypewriter">
                {{ t('learning', 'Überspringen') }}
              </button>
            </div>

            <!-- NPC Dialog (portrait-based via DialogueStage) -->
            <DialogueStage
              v-if="currentScene.npc_dialog && !narrativeTyping && !currentBotCorrection"
              :speakerId="resolveNpcCharacterId(currentScene.npc_dialog.speaker)"
              :speakerName="npcName(currentScene.npc_dialog.speaker)"
              :emotion="currentScene.npc_dialog.emotion || npcDefaultEmotion(currentScene.npc_dialog.speaker)"
              :text="currentScene.npc_dialog.text"
              :isNarrator="false"
            />

            <!-- Decision cards (linear mode) -->
            <div v-if="currentScene.choices && !narrativeTyping && !choiceMade && !isGraphMode && !currentBotCorrection" class="ab-choices">
              <h4 class="ab-choices-label">{{ t('learning', 'Was tust du?') }}</h4>
              <div class="ab-choice-grid">
                <button
                  v-for="choice in currentScene.choices"
                  :key="choice.id"
                  class="ab-choice-card"
                  :disabled="makingChoice"
                  @click="makeChoice(choice)"
                >
                  <span v-if="choice.dynamic" class="ab-choice-dynamic-badge">KI</span>
                  <span class="ab-choice-icon">{{ choice.icon || '🎯' }}</span>
                  <span class="ab-choice-text">{{ choice.text }}</span>
                </button>
              </div>
            </div>

            <!-- Edge choices (graph mode) -->
            <div v-if="isGraphMode && graphAvailableEdges.length && !narrativeTyping && !choiceMade && !currentBotCorrection" class="ab-choices">
              <h4 class="ab-choices-label">{{ t('learning', 'Was tust du?') }}</h4>
              <div class="ab-choice-grid">
                <button
                  v-for="edge in graphAvailableEdges"
                  :key="edge.id"
                  class="ab-choice-card"
                  :disabled="makingChoice || timerExpired"
                  @click="makeGraphChoice(edge.id)"
                >
                  <span class="ab-choice-icon">{{ edge.icon || '🎯' }}</span>
                  <span class="ab-choice-text">{{ edge.label || edge.id }}</span>
                </button>
              </div>
            </div>

            <!-- Freetext input -->
            <div v-if="currentScene.freetext_enabled && !narrativeTyping && !choiceMade && !currentBotCorrection" class="ab-freetext">
              <h4 class="ab-freetext-label">{{ t('learning', '...oder beschreibe deine eigene Aktion:') }}</h4>
              <div class="ab-freetext-input-row">
                <input
                  v-model="freetextInput"
                  type="text"
                  class="ab-freetext-field"
                  :placeholder="t('learning', 'Was tust du? (z.B. Ich trenne den Server vom Netz)')"
                  :disabled="freetextLoading"
                  maxlength="500"
                  @keydown.enter="submitFreetext"
                />
                <button
                  class="ab-freetext-submit"
                  :disabled="!freetextInput.trim() || freetextLoading"
                  @click="submitFreetext"
                >
                  {{ freetextLoading ? '...' : '>' }}
                </button>
              </div>
              <p v-if="freetextError" class="ab-freetext-error">{{ freetextError }}</p>
            </div>

            <CoopVoteOverlay
              v-if="showCoopVoteOverlay"
              :choices="graphAvailableEdges"
              :votes="coopVotes"
              :players="coopPlayers"
              :selected-choice-id="currentUserVoteId"
              :poll-interval-ms="coopPollIntervalMs"
              :disabled="makingChoice || coopBusy || coopAdvancing || timerExpired"
              @vote="makeGraphChoice"
            />
          </template>
        </div>
      </transition>
    </div>

    <!-- ===== SKILL CHECK PHASE ===== -->
    <div v-else-if="phase === 'skill-check'" class="ab-skill-check">
      <div class="ab-skill-header">
        <span class="ab-skill-badge">⚡ {{ t('learning', 'Skill Check') }}</span>
        <span v-if="currentSkillCheck" class="ab-skill-context">
          {{ t('learning', 'Frage {n} von {total}', { n: skillCheckIndex + 1, total: skillCheckTotal }) }}
        </span>
      </div>

      <div v-if="currentSkillQuestion" class="ab-skill-question">
        <p class="ab-question-text">{{ currentSkillQuestion.text }}</p>
        <div class="ab-answers">
          <button
            v-for="answer in currentSkillQuestion.answers"
            :key="answer.id"
            class="ab-answer-btn"
            :class="{
              'ab-answer-selected': selectedAnswer === answer.id,
              'ab-answer-correct': skillAnswered && answer.is_correct,
              'ab-answer-wrong': skillAnswered && selectedAnswer === answer.id && !answer.is_correct,
            }"
            :disabled="skillAnswered"
            @click="submitSkillAnswer(answer)"
          >
            {{ answer.text }}
          </button>
        </div>
      </div>

      <!-- Result overlay -->
      <transition name="rpg-result">
        <div v-if="skillAnswered" class="ab-result-overlay">
          <div class="ab-result-box" :class="lastAnswerCorrect ? 'ab-result-success' : 'ab-result-fail'">
            <div class="ab-result-icon">{{ lastAnswerCorrect ? '✅' : '❌' }}</div>
            <p class="ab-result-label">
              {{ lastAnswerCorrect
                ? t('learning', 'SKILL CHECK BESTANDEN')
                : t('learning', 'SKILL CHECK FEHLGESCHLAGEN — Aber du lernst daraus!') }}
            </p>
            <p v-if="!lastAnswerCorrect && currentSkillQuestion.explanation" class="ab-result-explanation">
              {{ currentSkillQuestion.explanation }}
            </p>
          </div>
        </div>
      </transition>

      <!-- NPC reaction to skill-check result -->
      <div v-if="skillCheckNpcId" class="ab-skill-npc-reaction">
        <CharacterAvatar
          :characterId="skillCheckNpcId"
          :size="80"
          :state="skillCheckNpcState"
        />
      </div>
    </div>

    <!-- ===== SIMULATION PHASE (Graph-Mode: SimulatorShell) ===== -->
    <div v-else-if="phase === 'simulation' && isGraphMode && currentGraphSimulator" class="ab-simulation campaign-simulator">
      <div class="ab-sim-header">
        <span class="ab-sim-badge">💻 {{ t('learning', 'Simulation') }}</span>
        <span class="ab-sim-context">{{ currentGraphSimulator.description || '' }}</span>
      </div>
      <SimulatorShell
        v-if="!showCoopWaiting"
        :type="currentGraphSimulator.type"
        :scenario-id="currentGraphSimulator.scenario || ''"
        :scenario-override="currentGraphSimulator.scenario_override || null"
        :node-id="currentGraphNode && currentGraphNode.id || ''"
        @complete="onSimulatorComplete"
      />
      <CoopWaiting
        v-else
        :active-player="coopWaitingPlayer"
        :simulator-type="coopWaitingType"
        :progress="coopWaitingProgress"
      />
    </div>

    <!-- ===== SIMULATION PHASE (Linear-Mode: PbqRenderer) ===== -->
    <div v-else-if="phase === 'simulation'" class="ab-simulation">
      <div class="ab-sim-header">
        <span class="ab-sim-badge">💻 {{ t('learning', 'Simulation') }}</span>
        <span class="ab-sim-context">{{ currentSimulation ? currentSimulation.description : '' }}</span>
      </div>

      <div v-if="currentSimulation" class="ab-sim-body">
        <p class="ab-sim-intro">
          {{ t('learning', 'Weise deine Fähigkeiten nach: Löse die folgende Netzwerk-Simulation, bevor die Geschichte endet.') }}
        </p>

        <PbqRenderer
          v-if="simQuestion"
          :question="simQuestion"
          :initial-value="simAnswer"
          :disabled="simSubmitted"
          @change="onSimChange"
          @submit="onSimSubmit"
          @skip="onSimSkip"
        />

        <div v-if="simSubmitted" class="ab-sim-result" :class="simPassed ? 'ab-sim-pass' : 'ab-sim-partial'">
          <div class="ab-sim-result-icon">{{ simPassed ? '✅' : '📊' }}</div>
          <p class="ab-sim-result-label">
            {{ simPassed
              ? t('learning', 'Simulation abgeschlossen! Ausgezeichnete Arbeit.')
              : t('learning', 'Simulation beendet. Teilweise gelöst — Lernfortschritt gespeichert.') }}
          </p>
          <button class="ab-btn ab-btn-primary" @click="finishSimulation">
            {{ t('learning', 'Epilog lesen') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ===== EPILOG PHASE ===== -->
    <div v-else-if="phase === 'epilog'" class="ab-epilog">
      <div class="ab-epilog-inner">
        <div class="ab-epilog-icon">{{ epilogIcon }}</div>
        <h2 class="ab-epilog-title">
          {{ epilogData && epilogData.outcome === 'success'
            ? t('learning', 'Mission abgeschlossen!')
            : t('learning', 'Mission beendet') }}
        </h2>
        <p class="ab-epilog-narrative">
          {{ epilogData ? epilogData.narrative : '' }}
        </p>
        <div class="ab-epilog-score">
          <div class="ab-score-row">
            <span>{{ t('learning', 'Szenen abgeschlossen') }}</span>
            <span class="ab-score-val">{{ sessionStats.scenesCompleted }}</span>
          </div>
          <div class="ab-score-row">
            <span>{{ t('learning', 'Skill Checks bestanden') }}</span>
            <span class="ab-score-val">{{ sessionStats.skillChecksPassed }} / {{ sessionStats.skillChecksTotal }}</span>
          </div>
          <div v-if="sessionStats.xpEarned > 0" class="ab-score-row">
            <span>{{ t('learning', 'XP verdient') }}</span>
            <span class="ab-score-val ab-xp">+{{ sessionStats.xpEarned }} XP</span>
          </div>
        </div>
        <div class="ab-epilog-actions">
          <button class="ab-btn ab-btn-primary" @click="restartCampaign">
            {{ t('learning', 'Nochmal spielen') }}
          </button>
          <button class="ab-btn ab-btn-secondary" @click="phase = 'campaign-select'">
            {{ t('learning', 'Andere Kampagne') }}
          </button>
          <button class="ab-btn ab-btn-ghost" @click="$emit('back')">
            {{ t('learning', 'Zurück') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ===== COOP LOBBY PHASE ===== -->
    <div v-else-if="phase === 'coop-lobby'" class="ab-coop-lobby">
      <div class="ab-header">
        <button class="ab-back-btn" @click="handleCoopLeave">←</button>
        <h2 class="ab-title">{{ t('learning', 'Koop-Lobby') }}</h2>
      </div>
      <CoopLobby
        :session-code="coopSessionCode"
        :players="coopPlayers"
        :is-host="isCurrentCoopHost"
        :is-ready="isCurrentCoopReady"
        :can-start="coopCanStart"
        :join-code="coopJoinCode"
        :busy="coopBusy"
        :error="coopError"
        :status="coopSessionStatus"
        @ready="handleCoopReady"
        @start="handleCoopStart"
        @leave="handleCoopLeave"
        @join="handleCoopJoin"
        @copy="copyCoopCode"
        @update:joinCode="coopJoinCode = $event"
      />
    </div>

    <!-- ===== ABORT CONFIRMATION ===== -->
    <div v-if="showAbortConfirm" class="ab-abort-overlay" role="dialog" aria-modal="true" aria-label="Abbrechen bestätigen">
      <div class="ab-abort-dialog">
        <h3>{{ t('learning', 'Kampagne abbrechen?') }}</h3>
        <p>{{ t('learning', 'Dein Fortschritt wird gespeichert. Du kannst später weitermachen.') }}</p>
        <div class="ab-abort-actions">
          <button class="ab-btn ab-btn-ghost" @click="showAbortConfirm = false">
            {{ t('learning', 'Weiterspielen') }}
          </button>
          <button class="ab-btn ab-btn-secondary" @click="abortCampaign">
            {{ t('learning', 'Beenden') }}
          </button>
        </div>
      </div>
    </div>

    <QuestMap
      v-if="isGraphMode && fullGraph"
      ref="questMap"
      :graph="fullGraph"
      :state-bag="stateBag"
      :current-node-id="currentGraphNode && currentGraphNode.id || ''"
      :available-edges="graphAvailableEdges"
      @navigate="handleQuestMapNavigate"
      @close="() => {}"
    />

  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import PbqRenderer from './PbqRenderer.vue'
import CampaignIntro from './CampaignIntro.vue'
import DialogueStage from './DialogueStage.vue'
import CharacterAvatar from './CharacterAvatar.vue'
import SimulatorShell from './SimulatorShell.vue'
import QuestMap from './QuestMap.vue'
import GameHud from './GameHud.vue'
import GameTimer from './GameTimer.vue'
import DauBotDialog from './DauBotDialog.vue'
import CoopLobby from './CoopLobby.vue'
import CoopVoteOverlay from './CoopVoteOverlay.vue'
import CoopWaiting from './CoopWaiting.vue'
import { getCharacter } from '../data/characters.js'
import { computeScoreDelta, computeReputationDeltas } from '../utils/hudEngine.js'
import {
	createCoopSession,
	joinCoopSession,
	setCoopReady,
	pollCoopLobby,
	pollCoopState,
	submitVote,
} from '../utils/coopEngine.js'

const STATIC_CAMPAIGNS = [
	{
		id: 'grosser_ausfall',
		icon: '📡',
		title: 'Der große Ausfall',
		description: 'Montag morgen: nichts geht. Alle Systeme von NovaTech sind down. Du bist das Notfall-Team.',
		difficulty: 'intermediate',
		focus_areas: ['Network+'],
		duration_minutes: 60,
		progress: 'not_started',
		current_scene: null,
		is_graph: true,
	},
	{
		id: 'einbruch_im_netz',
		icon: '🔐',
		title: 'Einbruch im Netz',
		description: 'Dienstag, 03:47 Uhr. 847 fehlgeschlagene Logins in 12 Minuten. Das ist kein normaler Dienstag.',
		difficulty: 'advanced',
		focus_areas: ['Security+'],
		duration_minutes: 60,
		progress: 'not_started',
		current_scene: null,
	},
	{
		id: 'der_neue_standort',
		icon: '🏢',
		title: 'Der neue Standort',
		description: 'NovaTech expandiert. Neues Büro, 40 Arbeitsplätze, 4 Wochen Deadline. Kein Fehler erlaubt.',
		difficulty: 'intermediate',
		focus_areas: ['Network+', 'Security+'],
		duration_minutes: 75,
		progress: 'not_started',
		current_scene: null,
	},
	{
		id: 'ransomware',
		icon: '💀',
		title: 'Ransomware',
		description: 'Freitag 16:30 Uhr: "YOUR FILES HAVE BEEN ENCRYPTED." 48 Stunden bis zur Deadline.',
		difficulty: 'advanced',
		focus_areas: ['Security+'],
		duration_minutes: 60,
		progress: 'not_started',
		current_scene: null,
	},
	{
		id: 'das_erbe',
		icon: '💾',
		title: 'Das Erbe',
		description: 'NovaTech übernimmt Oldstyle GmbH. Windows Server 2012, ein Hub (kein Switch!) und admin123.',
		difficulty: 'beginner',
		focus_areas: ['A+', 'Network+', 'Linux+'],
		duration_minutes: 75,
		progress: 'not_started',
		current_scene: null,
	},
	{
		id: 'test_graph_campaign',
		icon: '🧪',
		title: 'Test Graph Kampagne',
		description: 'Interne Test-Kampagne fuer den Graph-Modus mit Simulator-Challenges.',
		difficulty: 'intermediate',
		focus_areas: ['Network+'],
		duration_minutes: 30,
		progress: 'not_started',
		current_scene: null,
		is_graph: true,
	},
]

const CHARACTERS = [
	{
		id: 'architect',
		portrait: '👨‍💻',
		name: 'Alex "The Architect" Neumann',
		role: 'Netzwerk-Architekt',
		description: 'Methodisch, analytisch. Denkt in OSI-Schichten. Stark bei Routing und Topologie-Design.',
		strength: 'Routing, VLANs, Subnetting',
		weakness: 'Security-Fragen dauern länger',
	},
	{
		id: 'security',
		portrait: '👩‍💻',
		name: 'Sarah "Firewall" Okonkwo',
		role: 'Security-Analystin',
		description: 'Paranoid im besten Sinne. Sieht überall Bedrohungen. Hat für jedes Szenario einen IR-Plan.',
		strength: 'Firewalls, Incident Response, Threats',
		weakness: 'Routing-Fragen brauchen mehr Zeit',
	},
	{
		id: 'sysadmin',
		portrait: '🧔',
		name: 'Kai "Root" Lindström',
		role: 'Sysadmin',
		description: 'Unix-Philosophie pur. Löst alles per Terminal. Hat für jedes Problem ein Bash-Skript.',
		strength: 'Linux, Scripting, DNS, Server',
		weakness: 'Wireless ist "Teufelszeug"',
	},
	{
		id: 'helpdesk',
		portrait: '👩‍🦺',
		name: 'Mia "Helpdesk" Torres',
		role: 'First-Level-Support',
		description: 'Troubleshooting-Talent. Kann Fachjargon in Alltagssprache übersetzen. Kennt das Drucker-Passwort.',
		strength: 'Troubleshooting, Hardware, A+',
		weakness: 'Tiefes Netzwerk-Wissen fehlt manchmal',
	},
]

const FEATURED_CAMPAIGN_IDS = [
	'der_grosse_ausfall',
	'phishing_friday',
	'zero_day_rechenzentrum',
	'ghostline_quest',
]

const NPC_PORTRAITS = {
	dr_weber: { portrait: '👩‍💼', name: 'Dr. Weber' },
	jens_bug: { portrait: '👨‍💻', name: 'Jens "Bug" Hoffmann' },
	nova: { portrait: '🤖', name: 'NOVA' },
}

export default {
	name: 'AbenteuerMode',

	components: {
		PbqRenderer,
		CampaignIntro,
		DialogueStage,
		CharacterAvatar,
		SimulatorShell,
		QuestMap,
		GameHud,
		GameTimer,
		DauBotDialog,
		CoopLobby,
		CoopVoteOverlay,
		CoopWaiting,
	},

	props: {
		courseId: {
			type: Number,
			default: 0,
		},
		coursePools: {
			type: Array,
			default: () => [],
		},
		contentLanguage: {
			type: String,
			default: '',
		},
		initialCoopMode: {
			type: Boolean,
			default: false,
		},
		initialCoopCode: {
			type: String,
			default: '',
		},
	},

	emits: ['back'],

	data() {
		return {
			phase: 'campaign-select',

			// Campaign selection
			campaigns: [],
			loadingCampaigns: false,

			// Character selection
			characters: CHARACTERS,
			selectedCampaign: null,
			selectedCharacter: null,
			forceNewCampaign: false,

			// Scene
			currentScene: null,
			loadingScene: false,
			narrativeTyping: false,
			displayedNarrative: '',
			typewriterTimer: null,
			choiceMade: false,
			makingChoice: false,

			// Skill check
			currentSkillCheck: null,
			currentSkillQuestion: null,
			skillCheckQuestions: [],
			skillChecksPassedThisRound: 0,
			skillAnswersBatch: [],
			skillCheckIndex: 0,
			skillCheckTotal: 0,
			skillAnswered: false,
			selectedAnswer: null,
			lastAnswerCorrect: false,
			advanceTimer: null,

			// Epilog
			epilogData: null,
			sessionStats: {
				scenesCompleted: 0,
				skillChecksPassed: 0,
				skillChecksTotal: 0,
				xpEarned: 0,
			},

			// Coop
			coopMode: Boolean(this.initialCoopMode),
			coopSessionId: null,
			coopSessionCode: '',
			coopPlayers: [],
			coopSessionStatus: 'setup',
			coopVoting: false,
			coopVotes: {},
			coopJoinCode: String(this.initialCoopCode || '').trim().toUpperCase(),
			coopPollTimer: null,
			coopPollMode: 'lobby',
			coopPollIntervalMs: 3000,
			coopBusy: false,
			coopError: '',
			coopAdvancing: false,
			coopPollInFlight: false,
			coopLobbyCanStart: false,
			coopLastNodeId: '',
			coopSimulatorProgress: [],
			coopBotProgress: [],
			currentUserId: '',

			// Abort
			showAbortConfirm: false,

			// Simulation
			currentSimulation: null,
			simQuestion: null,
			simAnswer: {},
			simSubmitted: false,
			simPassed: false,

			// Graph-Mode State
			isGraphMode: false,
			stateBag: {},
			currentGraphNode: null,
			graphAvailableEdges: [],
			currentGraphSimulator: null,
			fullGraph: null,
			currentScore: 0,
			currentActNumber: 1,
			currentCharacterClass: '',
			previousScore: 0,
			previousReputation: {},
			scoreDelta: null,
			reputationDeltas: null,
			currentTimer: null,
			timerExpired: false,
			currentBotCorrection: null,
			botResult: null,

			// Freetext
			freetextInput: '',
			freetextLoading: false,
			freetextError: '',

			// Accessibility
			reducedMotion: false,

			// Skill-check NPC reaction
			skillCheckNpcState: 'idle',
		}
	},

	computed: {
		featuredCampaigns() {
			return this.campaigns.filter((campaign) => campaign.is_featured)
		},

		bonusCampaigns() {
			return this.campaigns.filter((campaign) => !campaign.is_featured)
		},

		epilogIcon() {
			if (!this.epilogData) return '📋'
			return this.epilogData.outcome === 'success' ? '🏆' : '📋'
		},
		skillCheckNpcId() {
			if (!this.selectedCampaign) return null
			const wpNpcs = this.selectedCampaign.workplace_npcs
			if (!wpNpcs || !wpNpcs.length) return null
			return wpNpcs[0].character_id || null
		},

		sceneProgressLabel() {
			if (!this.selectedCampaign) return ''
			const total = this.selectedCampaign.total_scenes || 5
			const current = this.sessionStats.scenesCompleted + 1
			return `${current} / ${total}`
		},

		currentGameState() {
			return {
				score: this.currentScore,
				act_number: this.currentActNumber || 1,
				character_class: this.currentCharacterClass || this.selectedCharacter?.id || '',
			}
		},

		currentCoopPlayer() {
			return this.coopPlayers.find(player => player.user_id === this.currentUserId) || null
		},

		isCurrentCoopHost() {
			return Boolean(this.currentCoopPlayer?.is_host)
		},

		isCurrentCoopReady() {
			return Boolean(this.currentCoopPlayer?.is_ready)
		},

		coopCanStart() {
			return this.coopLobbyCanStart
				|| (this.coopPlayers.length >= 2 && this.coopPlayers.every(player => Boolean(player.is_ready)))
		},

		currentUserVoteId() {
			return this.coopVotes?.player_votes?.[this.currentUserId] || ''
		},

		coopActiveProgressPlayer() {
			const source = this.currentGraphSimulator ? this.coopSimulatorProgress : this.coopBotProgress
			if (Array.isArray(source) && source.length) {
				const latest = [...source].sort((left, right) => {
					return Number(right.updated_at || 0) - Number(left.updated_at || 0)
				})[0]
				return this.coopPlayers.find(player => player.user_id === latest.user_id) || latest
			}
			return this.coopPlayers.find(player => player.is_host) || this.currentCoopPlayer || null
		},

		coopWaitingPlayer() {
			return this.coopActiveProgressPlayer
		},

		coopWaitingType() {
			if (this.currentGraphSimulator?.type) {
				return this.currentGraphSimulator.type
			}
			if (this.currentBotCorrection) {
				return t('learning', 'DauBot-Fall')
			}
			return ''
		},

		coopWaitingProgress() {
			if (this.currentGraphSimulator) {
				return this.coopSimulatorProgress
			}
			if (this.currentBotCorrection) {
				return this.coopBotProgress
			}
			return []
		},

		showCoopWaiting() {
			if (!this.coopMode || !this.coopSessionId || !this.coopPlayers.length) {
				return false
			}
			const hasInteraction = Boolean(this.currentGraphSimulator || this.currentBotCorrection)
			return hasInteraction && !this.isCurrentCoopHost
		},

		showCoopVoteOverlay() {
			return Boolean(
				this.coopMode
				&& this.coopVoting
				&& this.phase === 'scene'
				&& !this.narrativeTyping
				&& this.graphAvailableEdges.length
				&& this.coopSessionStatus === 'playing',
			)
		},
	},

	mounted() {
		this._pendingBotResponse = null
		this._hudDeltaTimer = null
		this._timerExpiryTimer = null
		this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
		this.currentUserId = this.resolveCurrentUserId()
		this.fetchCampaigns()
	},

	beforeDestroy() {
		this.clearTimers()
	},

	methods: {
		// ===== CAMPAIGN SELECTION =====

		async fetchCampaigns() {
			this.loadingCampaigns = true
			try {
				const url = generateUrl('/apps/learning/api/story/campaigns')
				const resp = await axios.get(url, {
					params: this.courseId ? { courseId: this.courseId } : {},
				})
				const raw = resp.data.campaigns || resp.data || []
				// Normalize: backend uses campaign_id, frontend expects id
				this.campaigns = this.normalizeCampaignList(raw)
				if (this.campaigns.length === 0) this.campaigns = this.normalizeCampaignList(STATIC_CAMPAIGNS)
			} catch (e) {
				// Backend not yet available — fall back to static graph campaigns only
				this.campaigns = this.normalizeCampaignList(STATIC_CAMPAIGNS)
			} finally {
				this.loadingCampaigns = false
			}
		},

		normalizeCampaignList(rawCampaigns = []) {
			return (Array.isArray(rawCampaigns) ? rawCampaigns : [])
				.filter(campaign => campaign.is_graph || campaign.graph || campaign.graph_mode)
				.map((campaign) => {
					const id = campaign.id || campaign.campaign_id
					return {
						...campaign,
						id,
						is_graph: campaign.is_graph || campaign.graph_mode || false,
						is_featured: FEATURED_CAMPAIGN_IDS.includes(id),
					}
				})
				.sort((left, right) => {
					const featuredDelta = Number(right.is_featured) - Number(left.is_featured)
					if (featuredDelta !== 0) {
						return featuredDelta
					}
					return String(left.title || '').localeCompare(String(right.title || ''))
				})
		},

		selectCampaign(campaign) {
			this.selectedCampaign = campaign
			this.phase = 'intro'
		},

		difficultyLabel(d) {
			const map = {
				beginner: t('learning', 'Einsteiger'),
				intermediate: t('learning', 'Fortgeschritten'),
				advanced: t('learning', 'Experte'),
			}
			return map[d] || d
		},

		// ===== CHARACTER SELECTION =====

		selectCharacter(char) {
			this.selectedCharacter = char
			if ((this.initialCoopMode || this.initialCoopCode) && !this.coopSessionId) {
				this.openCoopLobby()
			}
		},

		resolveCurrentUserId() {
			if (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function') {
				return OC.getCurrentUser()?.uid || ''
			}
			return ''
		},

		extractCoopErrorMessage(error, fallback) {
			return error?.response?.data?.error || fallback
		},

		syncSelectedCampaignById(campaignId) {
			if (!campaignId || this.selectedCampaign?.id === campaignId) {
				return
			}
			const matchingCampaign = this.campaigns.find(campaign => campaign.id === campaignId)
			if (matchingCampaign) {
				this.selectedCampaign = matchingCampaign
			}
		},

		openCoopLobby() {
			if (!this.selectedCharacter || !this.selectedCampaign?.is_graph) {
				return
			}
			this.coopMode = true
			this.coopError = ''
			this.phase = 'coop-lobby'
			this.isGraphMode = true
		},

		resetCoopState(options = {}) {
			const preserveJoinCode = Boolean(options.preserveJoinCode)
			this.stopCoopPolling()
			this.coopMode = false
			this.coopSessionId = null
			this.coopSessionCode = ''
			this.coopSessionStatus = 'setup'
			this.coopPlayers = []
			this.coopVoting = false
			this.coopVotes = {}
			this.coopPollMode = 'lobby'
			this.coopPollIntervalMs = 3000
			this.coopBusy = false
			this.coopAdvancing = false
			this.coopPollInFlight = false
			this.coopLobbyCanStart = false
			this.coopLastNodeId = ''
			this.coopSimulatorProgress = []
			this.coopBotProgress = []
			if (!preserveJoinCode) {
				this.coopJoinCode = ''
			}
		},

		resetGraphUiState() {
			this.stateBag = {}
			this.currentGraphNode = null
			this.graphAvailableEdges = []
			this.currentGraphSimulator = null
			this.fullGraph = null
			this.currentScore = 0
			this.currentActNumber = 1
			this.currentCharacterClass = this.selectedCharacter?.id || ''
			this.previousScore = 0
			this.previousReputation = {}
			this.scoreDelta = null
			this.reputationDeltas = null
			this.currentTimer = null
			this.timerExpired = false
			this.currentBotCorrection = null
			this.botResult = null
			this._pendingBotResponse = null
			if (this._hudDeltaTimer) {
				clearTimeout(this._hudDeltaTimer)
				this._hudDeltaTimer = null
			}
			if (this._timerExpiryTimer) {
				clearTimeout(this._timerExpiryTimer)
				this._timerExpiryTimer = null
			}
		},

		hasReputationChanges(deltas) {
			return Object.values(deltas || {}).some(delta => Number(delta) !== 0)
		},

		scheduleHudDeltaReset() {
			if (this._hudDeltaTimer) {
				clearTimeout(this._hudDeltaTimer)
			}
			const hasScoreDelta = this.scoreDelta && this.scoreDelta.direction !== 'none'
			const hasRepDelta = this.hasReputationChanges(this.reputationDeltas)
			if (!hasScoreDelta && !hasRepDelta) {
				this.scoreDelta = null
				this.reputationDeltas = null
				this._hudDeltaTimer = null
				return
			}
			this._hudDeltaTimer = setTimeout(() => {
				this.scoreDelta = null
				this.reputationDeltas = null
				this._hudDeltaTimer = null
			}, 2000)
		},

		applyGraphStateSnapshot(graphState, computeDeltas = true) {
			const safeState = graphState || {}
			const newScore = Number(safeState.score) || 0
			const newReputation = { ...(safeState.stateBag?.reputation || {}) }

			if (computeDeltas) {
				this.scoreDelta = computeScoreDelta(this.previousScore, newScore)
				this.reputationDeltas = computeReputationDeltas(this.previousReputation, newReputation)
				this.scheduleHudDeltaReset()
			} else {
				this.scoreDelta = null
				this.reputationDeltas = null
			}

			this.previousScore = newScore
			this.previousReputation = { ...newReputation }
			this.currentScore = newScore
			this.currentActNumber = Number(safeState.act_number) || 1
			this.currentCharacterClass = safeState.character_class || this.currentCharacterClass || this.selectedCharacter?.id || ''
			this.stateBag = { ...(safeState.stateBag || {}) }
		},

		applyGraphAuxiliaryPayloads(respData, preserveBotResult = false) {
			if (this._timerExpiryTimer) {
				clearTimeout(this._timerExpiryTimer)
				this._timerExpiryTimer = null
			}
			this.currentTimer = respData.timer && !respData.timer.expired
				? {
					deadlineAt: respData.timer.deadline_at,
					totalSeconds: respData.timer.seconds,
				}
				: null
			this.timerExpired = false
			this.currentBotCorrection = respData.bot_correction && !respData.bot_correction.completed
				? respData.bot_correction
				: null
			if (!preserveBotResult) {
				this.botResult = null
			}
		},

		showGraphSceneNode(node) {
			this.currentGraphSimulator = null
			this.currentScene = node
			this.phase = 'scene'
			this.choiceMade = false
			if (this.typewriterTimer) {
				clearTimeout(this.typewriterTimer)
				this.typewriterTimer = null
			}
			if (!node?.narrative) {
				this.displayedNarrative = ''
				this.narrativeTyping = false
				return
			}
			if (this.currentBotCorrection) {
				this.displayedNarrative = node.narrative
				this.narrativeTyping = false
				return
			}
			this.startTypewriter(node.narrative)
		},

		applyGraphResponse(respData, options = {}) {
			const computeDeltas = options.computeDeltas !== false
			this.applyGraphStateSnapshot(respData.state || {}, computeDeltas)
			// Backend sends "scene", frontend uses "node" — normalize
			const node = respData.node || respData.scene || null
			this.currentGraphNode = node || this.currentGraphNode
			this.graphAvailableEdges = respData.available_edges || []
			this.fullGraph = respData.full_graph || this.fullGraph
			this.applyGraphAuxiliaryPayloads(respData, Boolean(options.preserveBotResult))

			if (respData.simulator) {
				this.currentGraphSimulator = respData.simulator
				this.phase = 'simulation'
				return
			}

			if (node?.type === 'ending') {
				this.currentGraphSimulator = null
				this.phase = 'epilog'
				return
			}

			this.showGraphSceneNode(node)
		},

		async startCampaign() {
			if (!this.selectedCharacter) return
			const cid = this.selectedCampaign.id

			// Graph-mode: use /graph-start instead of /start
			if (this.selectedCampaign.is_graph) {
				this.isGraphMode = true
				this.resetGraphUiState()
				this.currentCharacterClass = this.selectedCharacter.id
				try {
					const resp = await axios.post(
						generateUrl(`/apps/learning/api/story/campaigns/${cid}/graph-start`),
						{
							characterClass: this.selectedCharacter.id,
							courseId: this.courseId || null,
						},
					)
					this.applyGraphResponse(resp.data, { computeDeltas: false })
				} catch (e) {
					// Fallback: disable graph mode and try linear start
					this.isGraphMode = false
					this.resetGraphUiState()
					this.beginScene()
				}
				return
			}

			// Linear mode: Always start fresh — POST /start deletes old progress and creates new session
			try {
				const url = generateUrl(`/apps/learning/api/story/campaigns/${cid}/start`)
				const resp = await axios.post(url, {
					characterClass: this.selectedCharacter.id,
					courseId: this.courseId || null,
				})
				if (resp.data?.scene) {
					this.phase = 'scene'
					this.choiceMade = false
					this.currentScene = resp.data.scene
					this.loadingScene = false
					this.startTypewriter(this.currentScene.narrative)
				}
			} catch (e) {
				// Last resort: local fallback
				this.beginScene()
			}
		},

		// ===== SCENE RENDERING =====

		async beginScene(sceneId) {
			this.phase = 'scene'
			this.choiceMade = false
			this.loadingScene = true
			this.displayedNarrative = ''
			this.clearTimers()

			try {
				const cid = this.selectedCampaign.id
				const url = generateUrl(`/apps/learning/api/story/campaigns/${cid}/scene`)
				const params = {
					characterClass: this.selectedCharacter.id,
				}
				if (sceneId) params.sceneId = sceneId
				if (this.courseId) params.courseId = this.courseId
				const resp = await axios.get(url, { params })
				// Backend returns {progress, scene} wrapper
				this.currentScene = resp.data.scene || resp.data
				this.loadingScene = false
				// Check if this scene has a simulation to show before choices
				if (this.currentScene.simulation) {
					this.startTypewriter(this.currentScene.narrative)
					// Simulation will be triggered after narrative finishes or on choice
				} else {
					this.startTypewriter(this.currentScene.narrative)
				}
			} catch (e) {
				// Backend not yet available — show stub scene
				this.currentScene = this.makeStubScene()
				this.loadingScene = false
				this.startTypewriter(this.currentScene.narrative)
			}
		},

		makeStubScene() {
			return {
				id: 's1_ankunft',
				title: t('learning', 'Ankunft'),
				narrative: t('learning', 'Du betrittst das Gebäude. Die Rezeption ist chaotisch — Telefone klingeln, niemand hat Internet. Dr. Weber steht am Aufzug: "Mein Meeting um 8 ist per Video. Schaffen Sie das?"'),
				npc_dialog: {
					speaker: 'dr_weber',
					text: t('learning', 'Mein Meeting um 8 ist per Video. Schaffen Sie das?'),
				},
				choices: [
					{ id: 'c1_serverraum', icon: '🖥', text: t('learning', 'Ich gehe direkt in den Serverraum') },
					{ id: 'c1_befragung', icon: '🗣', text: t('learning', 'Erst befrage ich die Mitarbeiter') },
					{ id: 'c1_schraenke', icon: '📦', text: t('learning', 'Ich prüfe die Netzwerkschränke auf dieser Etage') },
				],
			}
		},

		startTypewriter(text) {
			if (this.reducedMotion) {
				this.displayedNarrative = text
				this.narrativeTyping = false
				return
			}
			this.narrativeTyping = true
			this.displayedNarrative = ''
			let i = 0
			const speed = 20 // ms per character

			const type = () => {
				if (i < text.length) {
					this.displayedNarrative += text[i]
					i++
					this.typewriterTimer = setTimeout(type, speed)
				} else {
					this.narrativeTyping = false
				}
			}
			this.typewriterTimer = setTimeout(type, 50)
		},

		skipTypewriter() {
			if (this.typewriterTimer) clearTimeout(this.typewriterTimer)
			if (this.currentScene) {
				this.displayedNarrative = this.currentScene.narrative
			}
			this.narrativeTyping = false
		},

		async makeChoice(choice) {
			this.makingChoice = true
			this.choiceMade = true

			if (this.coopMode) {
				this.makingChoice = false
				return
			}

			// Check if choice has skill check
			if (choice.skill_check) {
				this.currentSkillCheck = choice
				this.fetchSkillQuestion(choice)
			} else {
				// POST choice to backend — advances progress + returns next scene
				try {
					const cid = this.selectedCampaign.id
					const resp = await axios.post(
						generateUrl(`/apps/learning/api/story/campaigns/${cid}/choice`),
						{ choiceId: choice.id }
					)
					const scene = resp.data?.scene || resp.data
					if (scene && scene.id) {
						this.sessionStats.scenesCompleted++
						this.currentScene = scene
						this.choiceMade = false
						this.loadingScene = false
						if (scene.is_epilog) {
							this.showEpilog(scene.epilog_type || 'success')
						} else {
							this.startTypewriter(scene.narrative)
							if (scene.simulation) this.scheduleSimulation(scene.simulation)
						}
					} else {
						// Fallback: local advance
						await this.advanceToScene(choice.success_scene || choice.next_scene)
					}
				} catch (e) {
					// Fallback: local advance
					await this.advanceToScene(choice.success_scene || choice.next_scene)
				}
			}
			this.makingChoice = false
		},

		async advanceToScene(sceneId) {
			if (!sceneId) {
				this.showEpilog()
				return
			}
			this.sessionStats.scenesCompleted++
			await this.beginScene(sceneId)

			// If the scene is an epilog scene, trigger epilog phase
			if (this.currentScene && this.currentScene.is_epilog) {
				this.showEpilog(this.currentScene.epilog_type || 'success')
				return
			}

			// If the newly loaded scene has a simulation, enter simulation phase after narrative
			if (this.currentScene && this.currentScene.simulation) {
				// Delay to let typewriter finish — simulation starts when narrative is done
				this.scheduleSimulation(this.currentScene.simulation)
			}
		},

		scheduleSimulation(simulation) {
			// Poll until narrative typing is done, then show simulation
			const checkTyping = () => {
				if (!this.narrativeTyping) {
					this.enterSimulationPhase(simulation)
				} else {
					setTimeout(checkTyping, 300)
				}
			}
			checkTyping()
		},

		// ===== SKILL CHECK =====

		fetchSkillQuestion(choice) {
			this.phase = 'skill-check'
			this.skillAnswered = false
			this.selectedAnswer = null
			this.skillCheckIndex = 0
			this.skillCheckTotal = choice.skill_check?.question_count || 1
			// Reset per-check pass counter (not session-wide)
			this.skillChecksPassedThisRound = 0

			// Questions are pre-loaded in the scene response (buildSceneResponse includes them)
			const preloaded = choice.skill_check?.questions
			if (Array.isArray(preloaded) && preloaded.length > 0) {
				this.skillCheckQuestions = preloaded
				this.currentSkillQuestion = preloaded[0]
			} else {
				// Fallback: single stub question
				this.skillCheckQuestions = [this.makeStubQuestion()]
				this.currentSkillQuestion = this.skillCheckQuestions[0]
			}
		},

		makeStubQuestion() {
			return {
				id: 'stub_q1',
				text: t('learning', 'Was ist der erste Schritt bei der CompTIA 7-Step Troubleshooting-Methodik?'),
				answers: [
					{ id: 'a1', text: t('learning', 'Problem identifizieren'), is_correct: true },
					{ id: 'a2', text: t('learning', 'Theorie aufstellen'), is_correct: false },
					{ id: 'a3', text: t('learning', 'Plan erstellen'), is_correct: false },
					{ id: 'a4', text: t('learning', 'Theorie testen'), is_correct: false },
				],
				explanation: t('learning', 'Der erste Schritt ist immer die Problemidentifikation — Symptome sammeln und das Problem genau beschreiben.'),
			}
		},

		async submitSkillAnswer(answer) {
			if (this.skillAnswered) return
			this.selectedAnswer = answer.id
			this.skillAnswered = true
			this.lastAnswerCorrect = answer.is_correct
			this.sessionStats.skillChecksTotal++

			// Collect answer for batch submission
			if (!this.skillAnswersBatch) this.skillAnswersBatch = []
			this.skillAnswersBatch.push({
				question_id: this.currentSkillQuestion.id,
				answer_id: answer.id,
			})

			if (answer.is_correct) {
				this.sessionStats.skillChecksPassed++
				this.skillChecksPassedThisRound++
				this.skillCheckNpcState = 'celebrate'
				this.$root.$emit('virtuprof:trigger', 'gameshow-answer-correct', { poolId: this.currentPoolId })
			} else {
				this.skillCheckNpcState = 'alert'
				this.$root.$emit('virtuprof:trigger', 'gameshow-answer-wrong', { poolId: this.currentPoolId })
			}
			setTimeout(() => { this.skillCheckNpcState = 'idle' }, 2000)

			// Auto-advance after showing result
			this.advanceTimer = setTimeout(() => {
				this.skillAnswered = false
				this.skillCheckIndex++
				if (this.skillCheckIndex < this.skillCheckTotal) {
					this.fetchNextSkillQuestion()
				} else {
					// All questions answered — submit batch to backend
					this.submitSkillBatch()
				}
			}, 2000)
		},

		fetchNextSkillQuestion() {
			this.selectedAnswer = null
			// Use pre-loaded questions array from initial fetch
			if (this.skillCheckQuestions && this.skillCheckQuestions[this.skillCheckIndex]) {
				this.currentSkillQuestion = this.skillCheckQuestions[this.skillCheckIndex]
			} else {
				this.currentSkillQuestion = this.makeStubQuestion()
			}
		},

		async submitSkillBatch() {
			// Send all collected answers to backend — it decides pass/fail and returns next scene
			try {
				const cid = this.selectedCampaign.id
				const choiceId = this.currentSkillCheck ? this.currentSkillCheck.id : ''
				const resp = await axios.post(
					generateUrl(`/apps/learning/api/story/campaigns/${cid}/batch`),
					{
						choiceId: choiceId,
						answers: JSON.stringify(this.skillAnswersBatch || []),
					}
				)
				const scene = resp.data?.scene
				if (scene && scene.id) {
					this.sessionStats.scenesCompleted++
					this.currentScene = scene
					this.phase = 'scene'
					this.choiceMade = false
					this.loadingScene = false
					this.skillAnswersBatch = []
					if (scene.is_epilog) {
						this.showEpilog(scene.epilog_type || 'success')
					} else {
						this.startTypewriter(scene.narrative)
						if (scene.simulation) this.scheduleSimulation(scene.simulation)
					}
				}
			} catch (e) {
				// Fallback: show epilog (we can't determine next scene locally)
				this.showEpilog('partial')
			}
		},

		// ===== SIMULATION =====

		/**
		 * Enter the PBQ simulation phase for the current scene.
		 * Builds a stub PBQ question config derived from simulation.type.
		 */
		enterSimulationPhase(simulation) {
			this.currentSimulation = simulation
			this.simAnswer = {}
			this.simSubmitted = false
			this.simPassed = false
			this.simQuestion = this.buildSimQuestion(simulation)
			this.phase = 'simulation'
		},

		/**
		 * Build a PBQ question object from the simulation descriptor.
		 * Uses the simulation.type to pick the right pbq_subtype.
		 * Falls back to a diagnostic subtype when type is unknown.
		 */
		buildSimQuestion(simulation) {
			const typeMap = {
				cli: {
					pbq_subtype: 'cli',
					pbq_config: {
						hint: simulation.description || t('learning', 'Nutze die Kommandozeile, um das Problem zu lösen.'),
						domain: simulation.domain || 'cisco_ios',
						terminals: simulation.terminals || [
							{ name: 'SW1' },
						],
						command_outputs: simulation.command_outputs || {},
						evaluation: simulation.evaluation || { required_commands: [] },
					},
				},
				switch_config: {
					pbq_subtype: 'switch_config',
					pbq_config: {
						instructions: [simulation.description || t('learning', 'Konfiguriere die Netzwerkgeräte korrekt.')],
						switches: [
							{
								name: 'SW-CORE',
								ports: [
									{ id: 'Gi0/1', label: 'Gi0/1 (Trunk)', correct: 'trunk' },
									{ id: 'Gi0/2', label: 'Gi0/2 (Access VLAN 10)', correct: 'access_vlan10' },
								],
							},
						],
					},
				},
				network_device_placement: {
					pbq_subtype: 'placement',
					pbq_config: {
						instructions: [simulation.description || t('learning', 'Platziere die Netzwerkgeräte korrekt.')],
						positions: [
							{ id: 'firewall', label: t('learning', 'Firewall'), correct: 'edge' },
							{ id: 'switch',   label: t('learning', 'Core Switch'), correct: 'core' },
						],
						choices: [
							{ id: 'edge', label: 'Edge' },
							{ id: 'core', label: 'Core' },
						],
					},
				},
				diagnostic: {
					pbq_subtype: 'diagnostic',
					pbq_config: {
						instructions: [simulation.description || t('learning', 'Analysiere die Netzwerkprobleme.')],
						findings: [
							{ id: 'f1', description: t('learning', 'Routing Loop erkannt'), correct_action: 'fix_route' },
							{ id: 'f2', description: t('learning', 'Falsche VLAN-Konfiguration'), correct_action: 'fix_vlan' },
						],
						actions: [
							{ id: 'fix_route', label: t('learning', 'Route korrigieren') },
							{ id: 'fix_vlan',  label: t('learning', 'VLAN anpassen') },
							{ id: 'ignore',    label: t('learning', 'Ignorieren') },
						],
					},
				},
			}

			const template = typeMap[simulation.type] || typeMap.diagnostic
			return {
				id: 'sim_' + (simulation.type || 'generic'),
				text: simulation.description || t('learning', 'Netzwerk-Simulation'),
				...template,
			}
		},

		onSimChange(answer) {
			this.simAnswer = answer
		},

		/**
		 * Called when the user submits the simulation.
		 * Score: answered >= 50% of total = pass.
		 */
		onSimSubmit(answer) {
			this.simAnswer = answer
			const answered = Object.keys(answer || {}).length
			// Get total from PbqRenderer's totalCount logic (we mirror it here)
			const total = this.simQuestion
				? this.calcSimTotal(this.simQuestion)
				: 2
			this.simPassed = answered > 0 && answered >= Math.ceil(total / 2)
			this.simSubmitted = true
		},

		onSimSkip() {
			// Skipping counts as partial
			this.simPassed = false
			this.simSubmitted = true
		},

		calcSimTotal(question) {
			const cfg = question.pbq_config || {}
			switch (question.pbq_subtype) {
				case 'switch_config':
					return (cfg.switches || []).reduce(
						(sum, sw) => sum + (sw.ports || []).filter(p => !!p.correct).length,
						0,
					)
				case 'placement':
					return (cfg.positions || []).length
				case 'diagnostic':
					return (cfg.findings || []).length
				default:
					return 1
			}
		},

		finishSimulation() {
			const outcome = this.simPassed ? 'success' : 'partial'
			this.showEpilog(outcome)
		},

		// ===== GRAPH-MODE METHODS =====

		/**
		 * Called when SimulatorShell emits @complete in graph-mode.
		 * Sends graph-traverse with simulator results, updates stateBag as new reference.
		 */
		async onSimulatorComplete(passed, score, result) {
			if (!this.isGraphMode) return
			if (this.coopMode) {
				await this.submitCoopVote('', {
					simulatorCompleted: true,
					simulatorPassed: passed,
					simulatorScore: Math.round(score * 100),
					simulatorResult: JSON.stringify(result),
				})
				return
			}

			// Find target edge based on pass status
			const targetEdge = this.graphAvailableEdges.find(e =>
				passed
					? (!e.requires_flag || this.stateBag.flags?.[e.requires_flag])
					: true,
			) || this.graphAvailableEdges[0]

			if (!targetEdge) {
				// No edge available — show epilog
				this.phase = 'epilog'
				return
			}

			try {
				this.makingChoice = true
				const resp = await axios.post(
					generateUrl(`/apps/learning/api/story/campaigns/${this.selectedCampaign.id}/graph-traverse`),
					{
						edgeId: targetEdge.id,
						simulator_passed: passed,
						simulator_score: Math.round(score * 100),
						simulator_result: JSON.stringify(result),
					},
				)
				this.applyGraphResponse(resp.data)
			} catch (e) {
				console.error('graph-traverse failed', e)
				// Error state: user stays on simulation phase, can retry
			} finally {
				this.makingChoice = false
			}
		},

		/**
		 * Graph-mode choice handler: traverse an edge from scene phase.
		 */
		async makeGraphChoice(edgeId, options = {}) {
			if (!edgeId || this.makingChoice) {
				return
			}
			if (this.timerExpired && !options.fromTimer) {
				return
			}
			if (this.coopMode) {
				await this.submitCoopVote(edgeId)
				return
			}
			try {
				this.makingChoice = true
				const resp = await axios.post(
					generateUrl(`/apps/learning/api/story/campaigns/${this.selectedCampaign.id}/graph-traverse`),
					{ edgeId },
				)
				this.applyGraphResponse(resp.data)
			} catch (e) {
				console.error('graph-traverse (choice) failed', e)
			} finally {
				this.makingChoice = false
			}
		},

		/**
		 * Quest-Map navigation handler: delegate to makeGraphChoice.
		 */
		handleQuestMapNavigate(edgeId) {
			if (this.currentBotCorrection || this.timerExpired) {
				return
			}
			this.makeGraphChoice(edgeId)
		},

		async onTimerExpired() {
			if (this.timerExpired) {
				return
			}
			this.timerExpired = true
			if (this.makingChoice) {
				return
			}
			const fallbackEdge = this.graphAvailableEdges[0]
			this._timerExpiryTimer = setTimeout(async () => {
				this._timerExpiryTimer = null
				this.currentTimer = null
				if (fallbackEdge) {
					if (this.coopMode) {
						await this.submitCoopVote(fallbackEdge.id)
						return
					}
					await this.makeGraphChoice(fallbackEdge.id, { fromTimer: true })
				}
			}, 2000)
		},

		async onBotSubmit({ errorId, fixId }) {
			if (!errorId || !fixId) {
				return
			}
			if (this.coopMode) {
				await this.submitCoopVote('', {
					botErrorId: errorId,
					botFixId: fixId,
				})
				return
			}
			const targetEdge = this.graphAvailableEdges[0]
			if (!targetEdge) {
				return
			}
			try {
				const cid = this.selectedCampaign.id
				const resp = await axios.post(
					generateUrl(`/apps/learning/api/story/campaigns/${cid}/graph-traverse`),
					{
						edgeId: targetEdge.id,
						bot_error_id: errorId,
						bot_fix_id: fixId,
					},
				)
				this.applyGraphStateSnapshot(resp.data.state || {})
				this.botResult = {
					passed: Boolean(resp.data.bot_correction?.passed),
					bot_correction: resp.data.bot_correction || null,
				}
				this._pendingBotResponse = resp.data
			} catch (e) {
				console.error('bot submit failed', e)
			}
		},

		onBotResultApplied() {
			const pendingResponse = this._pendingBotResponse
			if (!pendingResponse) {
				return
			}
			this.currentBotCorrection = null
			this.botResult = null
			this._pendingBotResponse = null
			this.applyGraphResponse(pendingResponse, { computeDeltas: false })
		},

		/**
		 * Retry the current simulator challenge by changing nodeId key.
		 */
		retrySimulator() {
			if (this.currentGraphNode) {
				this.currentGraphNode = { ...this.currentGraphNode, _retryKey: Date.now() }
			}
		},

		// ===== EPILOG =====

		showEpilog(outcome) {
			const passed = this.sessionStats.skillChecksPassed >= Math.ceil(this.sessionStats.skillChecksTotal / 2)
			this.epilogData = {
				outcome: outcome || (passed ? 'success' : 'partial'),
				narrative: passed
					? t('learning', 'Mission abgeschlossen! Dr. Webers Call startet pünktlich. Du holst dir einen zweiten Kaffee. Gut gemacht.')
					: t('learning', 'Das Netzwerk läuft — größtenteils. Einige Systeme zeigen noch Fehler. Das nächste Mal: erst dokumentieren, dann ändern, immer testen.'),
			}
			this.sessionStats.xpEarned = passed ? 150 : 75
			this.phase = 'epilog'
		},

		restartCampaign() {
			this.sessionStats = { scenesCompleted: 0, skillChecksPassed: 0, skillChecksTotal: 0, xpEarned: 0 }
			this.forceNewCampaign = true
			this.resetGraphUiState()
			this.resetCoopState({ preserveJoinCode: true })
			this.phase = 'character-select'
		},

		// ===== COOP =====

		normalizeCoopJoinCode(code) {
			return String(code || '').trim().toUpperCase()
		},

		normalizeCoopScene(scene) {
			return {
				id: scene?.id || '',
				title: scene?.title || '',
				narrative: scene?.narrative || '',
				type: scene?.type || 'graph_scene',
				act: Number(scene?.act) || 1,
				is_ending: Boolean(scene?.is_ending),
			}
		},

		hasCoopSimulatorProgress(progressEntries) {
			return (progressEntries || []).some(entry => Boolean(entry?.completed))
		},

		hasCoopBotProgress(progressEntries) {
			return (progressEntries || []).some(entry => {
				return Boolean(
					entry?.completed
					|| entry?.submitted_error_id
					|| entry?.submitted_fix_id,
				)
			})
		},

		applyCoopSharedState(sharedState) {
			const safeState = sharedState || {}
			const newReputation = { ...(safeState.reputation || {}) }
			const repDelta = computeReputationDeltas(this.previousReputation, newReputation)
			this.scoreDelta = null
			this.reputationDeltas = this.hasReputationChanges(repDelta) ? repDelta : null
			if (this.reputationDeltas) {
				this.scheduleHudDeltaReset()
			}
			this.previousReputation = { ...newReputation }
			this.currentActNumber = Number(safeState.act_number) || this.currentActNumber || 1
			this.stateBag = {
				flags: { ...(safeState.flags || {}) },
				items: Array.isArray(safeState.items) ? [...safeState.items] : [],
				reputation: newReputation,
			}
		},

		handleCoopSessionEnded(message) {
			const preservedJoinCode = this.coopSessionCode || this.coopJoinCode
			this.resetGraphUiState()
			this.resetCoopState({ preserveJoinCode: true })
			this.coopJoinCode = preservedJoinCode
			this.coopMode = true
			this.coopError = message || t('learning', 'Die Coop-Session wurde beendet.')
			this.phase = 'coop-lobby'
		},

		applyCoopLobbyPayload(payload) {
			const session = payload?.session || {}
			this.syncSelectedCampaignById(session.campaign_id)
			this.coopSessionId = session.id || this.coopSessionId
			this.coopSessionCode = session.code || this.coopSessionCode
			this.coopSessionStatus = session.status || 'lobby'
			this.coopPlayers = Array.isArray(payload?.players) ? payload.players : []
			this.coopLobbyCanStart = Boolean(payload?.lobby?.can_start)
			this.coopPollIntervalMs = Number(session.poll_interval_ms) || this.coopPollIntervalMs
			this.coopVoting = false
			this.coopVotes = {}
			this.coopError = ''
			this.loadingScene = false

			if (this.coopSessionStatus === 'finished') {
				this.handleCoopSessionEnded(t('learning', 'Die Coop-Session wurde beendet.'))
				return 'lobby'
			}

			this.phase = 'coop-lobby'
			return 'lobby'
		},

		applyCoopStatePayload(payload) {
			const session = payload?.session || {}
			const scene = payload?.scene || {}
			const normalizedScene = this.normalizeCoopScene(scene)
			const previousNodeId = this.coopLastNodeId
			const hadInteractionOpen = Boolean(this.currentGraphSimulator || this.currentBotCorrection)

			this.syncSelectedCampaignById(session.campaign_id)
			this.coopSessionId = session.id || this.coopSessionId
			this.coopSessionCode = session.code || this.coopSessionCode
			this.coopSessionStatus = session.status || this.coopSessionStatus || 'playing'
			this.coopPlayers = Array.isArray(payload?.players) ? payload.players : []
			this.coopVotes = payload?.votes || {}
			this.coopPollIntervalMs = Number(session.poll_interval_ms) || this.coopPollIntervalMs
			this.coopSimulatorProgress = Array.isArray(payload?.simulator_progress) ? payload.simulator_progress : []
			this.coopBotProgress = Array.isArray(payload?.bot_progress) ? payload.bot_progress : []
			this.coopError = ''
			this.loadingScene = false
			this.choiceMade = false
			this.applyCoopSharedState(payload?.shared_state || {})

			if (this._timerExpiryTimer) {
				clearTimeout(this._timerExpiryTimer)
				this._timerExpiryTimer = null
			}

			this.currentScene = normalizedScene
			this.currentGraphNode = normalizedScene
			this.graphAvailableEdges = Array.isArray(payload?.available_edges) ? payload.available_edges : []
			this.currentTimer = scene.timer && !scene.timer.expired
				? {
					deadlineAt: scene.timer.deadline_at,
					totalSeconds: scene.timer.seconds,
				}
				: null
			this.timerExpired = Boolean(scene.timer?.expired)

			const simulatorHandled = this.hasCoopSimulatorProgress(this.coopSimulatorProgress)
			const botHandled = this.hasCoopBotProgress(this.coopBotProgress)
			this.currentGraphSimulator = scene.simulator && !simulatorHandled
				? scene.simulator
				: null
			this.currentBotCorrection = scene.bot_correction && !botHandled
				? scene.bot_correction
				: null
			this.botResult = botHandled
				? { passed: this.coopBotProgress.some(entry => entry?.passed === true) }
				: null
			this.coopVoting = Boolean(
				this.graphAvailableEdges.length
				&& !normalizedScene.is_ending
				&& !this.currentGraphSimulator
				&& !this.currentBotCorrection
				&& this.coopSessionStatus === 'playing',
			)

			if (this.coopSessionStatus === 'finished' && !normalizedScene.is_ending) {
				this.handleCoopSessionEnded(t('learning', 'Der Host hat die Coop-Session beendet.'))
				return 'lobby'
			}

			if (normalizedScene.is_ending) {
				this.coopLastNodeId = normalizedScene.id || this.coopLastNodeId
				this.currentGraphSimulator = null
				this.currentBotCorrection = null
				this.displayedNarrative = normalizedScene.narrative || ''
				this.narrativeTyping = false
				this.epilogData = {
					outcome: 'success',
					narrative: normalizedScene.narrative || t('learning', 'Die Coop-Kampagne ist abgeschlossen.'),
				}
				this.phase = 'epilog'
				return 'state'
			}

			if (this.currentGraphSimulator) {
				this.coopLastNodeId = normalizedScene.id || this.coopLastNodeId
				this.displayedNarrative = normalizedScene.narrative || this.displayedNarrative
				this.narrativeTyping = false
				this.phase = 'simulation'
				return 'state'
			}

			const nodeChanged = Boolean(normalizedScene.id && normalizedScene.id !== previousNodeId)
			this.coopLastNodeId = normalizedScene.id || this.coopLastNodeId

			if (nodeChanged) {
				this.showGraphSceneNode(normalizedScene)
				return 'state'
			}

			this.phase = 'scene'
			if (hadInteractionOpen && !this.currentBotCorrection) {
				this.displayedNarrative = normalizedScene.narrative || this.displayedNarrative
				this.narrativeTyping = false
			}
			return 'state'
		},

		applyCoopPayload(payload) {
			if (payload?.scene) {
				return this.applyCoopStatePayload(payload)
			}
			return this.applyCoopLobbyPayload(payload)
		},

		stopCoopPolling() {
			if (this.coopPollTimer) {
				clearInterval(this.coopPollTimer)
				this.coopPollTimer = null
			}
		},

		startCoopPolling(mode = 'state') {
			if (!this.coopSessionId) {
				return
			}
			this.stopCoopPolling()
			this.coopPollMode = mode
			const intervalMs = mode === 'lobby' ? 3000 : this.coopPollIntervalMs

			const pollFn = async() => {
				if (!this.coopSessionId || this.coopPollInFlight || this.coopAdvancing) {
					return
				}
				this.coopPollInFlight = true
				try {
					const payload = mode === 'lobby'
						? await pollCoopLobby(this.coopSessionId)
						: await pollCoopState(this.coopSessionId)
					const nextMode = this.applyCoopPayload(payload)
					if (nextMode && nextMode !== this.coopPollMode && !this.coopAdvancing) {
						this.startCoopPolling(nextMode)
						return
					}
				} catch (error) {
					this.coopError = this.extractCoopErrorMessage(error, t('learning', 'Coop-Synchronisierung fehlgeschlagen.'))
				} finally {
					this.coopPollInFlight = false
				}
			}

			this.coopPollTimer = setInterval(pollFn, intervalMs)
			pollFn()
		},

		beginCoopAdvance() {
			this.stopCoopPolling()
			this.coopAdvancing = true
		},

		finishCoopAdvance(nextMode = 'state') {
			this.coopAdvancing = false
			if (this.coopSessionId) {
				this.startCoopPolling(nextMode)
			}
		},

		async handleCoopCreate() {
			if (!this.selectedCampaign?.is_graph || !this.selectedCharacter) {
				return
			}
			this.coopBusy = true
			this.coopError = ''
			this.coopMode = true
			this.isGraphMode = true
			this.resetGraphUiState()
			this.currentCharacterClass = this.selectedCharacter.id
			try {
				const payload = await createCoopSession(this.selectedCampaign.id, {
					characterId: this.selectedCharacter.id,
				})
				const nextMode = this.applyCoopPayload(payload)
				this.startCoopPolling(nextMode)
			} catch (error) {
				this.coopError = this.extractCoopErrorMessage(error, t('learning', 'Coop-Session konnte nicht erstellt werden.'))
			} finally {
				this.coopBusy = false
			}
		},

		async handleCoopJoin(code) {
			if (!this.selectedCharacter) {
				return
			}
			const normalizedCode = this.normalizeCoopJoinCode(code || this.coopJoinCode)
			if (!normalizedCode) {
				this.coopError = t('learning', 'Bitte gib einen Session-Code ein.')
				return
			}
			this.coopBusy = true
			this.coopError = ''
			this.coopMode = true
			this.isGraphMode = true
			this.coopJoinCode = normalizedCode
			this.resetGraphUiState()
			this.currentCharacterClass = this.selectedCharacter.id
			try {
				const payload = await joinCoopSession(normalizedCode, {
					characterId: this.selectedCharacter.id,
				})
				const nextMode = this.applyCoopPayload(payload)
				this.startCoopPolling(nextMode)
			} catch (error) {
				this.coopError = this.extractCoopErrorMessage(error, t('learning', 'Beitritt zur Coop-Session fehlgeschlagen.'))
			} finally {
				this.coopBusy = false
			}
		},

		async handleCoopReady(forceReady = false) {
			if (!this.coopSessionId) {
				return
			}
			this.coopBusy = true
			this.coopError = ''
			this.beginCoopAdvance()
			let nextMode = 'lobby'
			try {
				const payload = await setCoopReady(
					this.coopSessionId,
					forceReady ? { ready: true } : {},
				)
				nextMode = this.applyCoopPayload(payload)
			} catch (error) {
				this.coopError = this.extractCoopErrorMessage(error, t('learning', 'Bereitschaft konnte nicht aktualisiert werden.'))
			} finally {
				this.coopBusy = false
				this.finishCoopAdvance(nextMode)
			}
		},

		handleCoopStart() {
			if (this.coopSessionId) {
				return this.handleCoopReady(true)
			}
			return this.handleCoopCreate()
		},

		async submitCoopVote(edgeId, payload = {}) {
			if (!this.coopSessionId || this.coopBusy) {
				return
			}
			this.coopBusy = true
			this.makingChoice = true
			this.coopError = ''
			this.beginCoopAdvance()
			let nextMode = 'state'
			try {
				const response = await submitVote(this.coopSessionId, edgeId, payload)
				nextMode = this.applyCoopPayload(response)
			} catch (error) {
				this.coopError = this.extractCoopErrorMessage(error, t('learning', 'Stimme konnte nicht übermittelt werden.'))
			} finally {
				this.coopBusy = false
				this.makingChoice = false
				this.finishCoopAdvance(nextMode)
			}
		},

		async endCoopSession() {
			if (!this.coopSessionId) {
				return
			}
			this.coopBusy = true
			this.coopError = ''
			this.beginCoopAdvance()
			try {
				await setCoopReady(this.coopSessionId, { action: 'end' })
				this.handleCoopSessionEnded(t('learning', 'Die Coop-Session wurde beendet.'))
			} catch (error) {
				this.coopError = this.extractCoopErrorMessage(error, t('learning', 'Coop-Session konnte nicht beendet werden.'))
				this.coopAdvancing = false
				this.startCoopPolling(this.coopPollMode)
			} finally {
				this.coopBusy = false
			}
		},

		async handleCoopLeave() {
			if (this.isCurrentCoopHost && this.coopSessionId) {
				await this.endCoopSession()
				return
			}
			this.resetGraphUiState()
			this.resetCoopState({ preserveJoinCode: true })
			this.phase = 'character-select'
		},

		copyCoopCode() {
			if (navigator.clipboard) {
				navigator.clipboard.writeText(this.coopSessionCode)
			}
		},

		// ===== ABORT =====

		confirmAbort() {
			this.showAbortConfirm = true
		},

		abortCampaign() {
			this.showAbortConfirm = false
			if (this.coopMode && this.coopSessionId) {
				this.handleCoopLeave()
				return
			}
			this.clearTimers()
			this.resetGraphUiState()
			this.resetCoopState({ preserveJoinCode: true })
			this.phase = 'campaign-select'
			this.currentScene = null
			this.selectedCharacter = null
		},

		// ===== FREETEXT =====

		async submitFreetext() {
			if (!this.freetextInput.trim() || this.freetextLoading) return
			this.freetextLoading = true
			this.freetextError = ''
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/learning/api/story/campaigns/${this.selectedCampaign.id}/freetext`),
					{ text: this.freetextInput.trim() },
				)
				if (data.valid && data.next_scene) {
					this.freetextInput = ''
					this.showFreetextNarrative(data.narrative)
				} else {
					this.freetextError = data.narrative || t('learning', 'Aktion nicht möglich')
				}
			} catch (err) {
				this.freetextError = err.response?.data?.error || t('learning', 'Fehler bei der Verarbeitung')
			} finally {
				this.freetextLoading = false
			}
		},

		showFreetextNarrative(text) {
			if (!text) return
			this.choiceMade = true
			this.startTypewriter(text)
			// After narrative finishes, reload the current scene (which has been advanced server-side)
			const waitForTyping = () => {
				if (!this.narrativeTyping) {
					this.choiceMade = false
					this.beginScene()
				} else {
					setTimeout(waitForTyping, 300)
				}
			}
			waitForTyping()
		},

		// ===== HELPERS =====

		resolveNpcCharacterId(speaker) {
			// Look up speaker in campaign's workplace_npcs array
			if (this.selectedCampaign && this.selectedCampaign.workplace_npcs) {
				const wpNpc = this.selectedCampaign.workplace_npcs.find(
					n => n.role_in_story === speaker || n.character_id === speaker,
				)
				if (wpNpc) return wpNpc.character_id
			}
			// Check if speaker is already a valid character ID
			const char = getCharacter(speaker)
			if (char.id !== 'unknown') return speaker
			return 'unknown'
		},

		npcDefaultEmotion(speaker) {
			if (this.selectedCampaign && this.selectedCampaign.workplace_npcs) {
				const wpNpc = this.selectedCampaign.workplace_npcs.find(
					n => n.role_in_story === speaker || n.character_id === speaker,
				)
				if (wpNpc) return wpNpc.default_emotion || 'idle'
			}
			return 'idle'
		},

		npcPortrait(speaker) {
			return (NPC_PORTRAITS[speaker] || { portrait: '👤' }).portrait
		},

		npcName(speaker) {
			return (NPC_PORTRAITS[speaker] || { name: speaker }).name
		},

		characterPortrait(charId) {
			const char = CHARACTERS.find(c => c.id === charId)
			return char ? char.portrait : '👤'
		},

		clearTimers() {
			if (this.typewriterTimer) {
				clearTimeout(this.typewriterTimer)
				this.typewriterTimer = null
			}
			if (this.advanceTimer) {
				clearTimeout(this.advanceTimer)
				this.advanceTimer = null
			}
			this.stopCoopPolling()
			if (this._hudDeltaTimer) {
				clearTimeout(this._hudDeltaTimer)
				this._hudDeltaTimer = null
			}
			if (this._timerExpiryTimer) {
				clearTimeout(this._timerExpiryTimer)
				this._timerExpiryTimer = null
			}
		},
	},
}
</script>

<style scoped>
/* ===== ROOT ===== */
.abenteuer-mode {
  min-height: 400px;
  color: var(--lnc-text);
  background: var(--lnc-bg);
  border-radius: var(--lnc-radius-sm);
  padding: 20px;
  position: relative;
}

/* ===== HEADER ===== */
.ab-header {
  margin-bottom: 24px;
}

.ab-back-btn {
  background: transparent;
  border: 1px solid var(--lnc-border);
  color: var(--lnc-text-secondary);
  padding: 6px 12px;
  border-radius: var(--lnc-radius-sm);
  cursor: pointer;
  font-size: 13px;
  margin-bottom: 12px;
  display: inline-block;
  transition: color 0.15s, border-color 0.15s;
}
.ab-back-btn:hover {
  color: var(--lnc-text);
  border-color: var(--lnc-cyan);
}

.ab-title {
  margin: 8px 0 4px;
  font-size: 1.5rem;
  font-weight: 600;
  color: var(--lnc-text);
}

.ab-subtitle {
  color: var(--lnc-text-secondary);
  font-size: 0.9rem;
  margin: 0;
}

/* ===== LOADING ===== */
.ab-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 40px;
  gap: 12px;
  color: var(--lnc-text-secondary);
}

.ab-spinner {
  width: 32px;
  height: 32px;
  border: 3px solid var(--lnc-border);
  border-top-color: var(--lnc-cyan);
  border-radius: 50%;
  animation: ab-spin 0.7s linear infinite;
}

@keyframes ab-spin {
  to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
  .ab-spinner {
    animation: none;
    border-top-color: var(--lnc-cyan);
  }
}

/* ===== CAMPAIGN GRID ===== */
.ab-campaign-sections {
  display: flex;
  flex-direction: column;
  gap: 28px;
}

.ab-campaign-section {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.ab-section-header h3 {
  margin: 0 0 6px;
  font-size: 1rem;
  color: var(--lnc-text);
}

.ab-section-header p {
  margin: 0;
  color: var(--lnc-text-secondary);
  font-size: 0.9rem;
}

.ab-campaign-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
}

.ab-campaign-card {
  background: var(--lnc-panel);
  border: 1px solid var(--lnc-border);
  border-radius: var(--lnc-radius-sm);
  padding: 16px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 8px;
  transition: border-color 0.15s, transform 0.15s;
  position: relative;
  outline: none;
}
.ab-campaign-card:hover,
.ab-campaign-card:focus-visible {
  border-color: var(--lnc-cyan);
  transform: translateY(-2px);
}
.ab-campaign-card:focus-visible {
  box-shadow: 0 0 0 2px var(--lnc-cyan);
}
.ab-campaign-completed {
  border-color: var(--lnc-green);
}

.ab-campaign-card--bonus {
  border-style: dashed;
}

.ab-bonus-pill {
  position: absolute;
  top: 12px;
  right: 12px;
  padding: 3px 9px;
  border-radius: 999px;
  background: rgba(255, 193, 7, 0.16);
  color: var(--lnc-amber);
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.ab-campaign-icon {
  font-size: 2rem;
  line-height: 1;
}

.ab-campaign-title {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  color: var(--lnc-text);
}

.ab-campaign-desc {
  margin: 0;
  font-size: 0.85rem;
  color: var(--lnc-text-secondary);
  line-height: 1.4;
}

.ab-campaign-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 4px;
}

.ab-difficulty {
  padding: 2px 8px;
  border-radius: 99px;
  font-size: 0.75rem;
  font-weight: 500;
}
.ab-diff-beginner { background: #1c3a1c; color: #3fb950; }
.ab-diff-intermediate { background: #2d2505; color: #d29922; }
.ab-diff-advanced { background: #3a1c1c; color: #f85149; }

.ab-focus-tag {
  padding: 2px 8px;
  border-radius: 99px;
  font-size: 0.75rem;
  background: #1c2535;
  color: var(--lnc-cyan);
}

.ab-campaign-progress {
  margin-top: auto;
}

.ab-prog-badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 99px;
  font-size: 0.75rem;
  font-weight: 500;
}
.ab-prog-new { background: #1c2535; color: var(--lnc-cyan); }
.ab-prog-active { background: #2d2505; color: var(--lnc-amber); }
.ab-prog-done { background: #1c3a1c; color: var(--lnc-green); }

/* ===== CHARACTER GRID ===== */
.ab-character-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 14px;
  margin-bottom: 20px;
}

.ab-character-card {
  background: var(--lnc-panel);
  border: 2px solid var(--lnc-border);
  border-radius: var(--lnc-radius-sm);
  padding: 16px;
  cursor: pointer;
  transition: border-color 0.15s, transform 0.15s;
  position: relative;
  outline: none;
  text-align: left;
}
.ab-character-card:hover,
.ab-character-card:focus-visible {
  border-color: var(--lnc-cyan);
  transform: translateY(-2px);
}
.ab-character-card:focus-visible {
  box-shadow: 0 0 0 2px var(--lnc-cyan);
}
.ab-char-selected {
  border-color: var(--lnc-cyan) !important;
  background: #1c2535;
}

.ab-char-portrait {
  font-size: 2.5rem;
  line-height: 1;
  margin-bottom: 8px;
}

.ab-char-name {
  margin: 0 0 2px;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--lnc-text);
}

.ab-char-role {
  margin: 0 0 6px;
  font-size: 0.75rem;
  color: var(--lnc-cyan);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.ab-char-desc {
  margin: 0 0 10px;
  font-size: 0.82rem;
  color: var(--lnc-text-secondary);
  line-height: 1.4;
}

.ab-char-stats {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.ab-stat {
  font-size: 0.78rem;
  display: flex;
  gap: 6px;
}
.ab-stat-label {
  color: var(--lnc-text-secondary);
  min-width: 50px;
}
.ab-stat-value {
  color: var(--lnc-text);
}

.ab-char-check {
  position: absolute;
  top: 10px;
  right: 12px;
  color: var(--lnc-green);
  font-size: 1.2rem;
  font-weight: 700;
}

.ab-char-actions {
  display: flex;
  justify-content: center;
}

/* ===== BUTTONS ===== */
.ab-btn {
  padding: 10px 20px;
  border-radius: var(--lnc-radius-sm);
  font-size: 0.9rem;
  font-weight: 500;
  cursor: pointer;
  border: none;
  transition: opacity 0.15s, background 0.15s;
}
.ab-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
.ab-btn-primary {
  background: var(--lnc-cyan);
  color: #0d1117;
}
.ab-btn-primary:hover:not(:disabled) {
  background: #79b8ff;
}
.ab-btn-secondary {
  background: var(--lnc-panel);
  color: var(--lnc-text);
  border: 1px solid var(--lnc-border);
}
.ab-btn-secondary:hover:not(:disabled) {
  border-color: var(--lnc-cyan);
}
.ab-btn-ghost {
  background: transparent;
  color: var(--lnc-text-secondary);
  border: 1px solid var(--lnc-border);
}
.ab-btn-ghost:hover:not(:disabled) {
  color: var(--lnc-text);
}

/* ===== SCENE ===== */
.ab-scene {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.ab-scene-header {
  display: flex;
  align-items: center;
  gap: 12px;
}
.ab-scene-title {
  font-size: 1rem;
  font-weight: 600;
  flex: 1;
}
.ab-scene-progress {
  font-size: 0.8rem;
  color: var(--lnc-text-secondary);
}
.ab-map-btn {
  background: none;
  border: 1px solid rgba(0, 229, 255, 0.3);
  border-radius: 6px;
  color: #00e5ff;
  font-size: 18px;
  padding: 4px 8px;
  cursor: pointer;
  transition: border-color 0.2s;
}
.ab-map-btn:hover {
  border-color: #00e5ff;
}

.ab-narrative-box {
  background: #0a0e15;
  border: 1px solid var(--lnc-border);
  border-radius: var(--lnc-radius-sm);
  padding: 20px;
  position: relative;
  min-height: 80px;
}

.ab-narrative-text {
  margin: 0;
  font-size: 0.95rem;
  line-height: 1.7;
  color: var(--lnc-text);
  font-family: Georgia, 'Times New Roman', serif;
  white-space: pre-wrap;
}

/* Typewriter cursor */
.ab-typing::after {
  content: '\u258C';
  animation: ab-blink 1s step-end infinite;
  color: var(--lnc-cyan);
}

@keyframes ab-blink {
  50% { opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
  .ab-typing::after {
    animation: none;
  }
}

.ab-skip-btn {
  margin-top: 8px;
  background: transparent;
  border: none;
  color: var(--lnc-text-secondary);
  font-size: 0.78rem;
  cursor: pointer;
  padding: 2px 0;
  text-decoration: underline;
}

/* ===== NPC DIALOG ===== */
.ab-npc-dialog {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}

.ab-npc-portrait {
  font-size: 2.5rem;
  line-height: 1;
  flex-shrink: 0;
  background: var(--lnc-panel);
  border: 1px solid var(--lnc-border);
  border-radius: 50%;
  width: 56px;
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.ab-speech-bubble {
  background: var(--lnc-panel);
  border: 1px solid var(--lnc-border);
  border-radius: var(--lnc-radius-sm);
  padding: 12px 16px;
  flex: 1;
  position: relative;
}
.ab-speech-bubble::before {
  content: '';
  position: absolute;
  left: -8px;
  top: 18px;
  width: 0;
  height: 0;
  border-top: 6px solid transparent;
  border-bottom: 6px solid transparent;
  border-right: 8px solid var(--lnc-border);
}

.ab-npc-name {
  display: block;
  font-size: 0.75rem;
  color: var(--lnc-cyan);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 4px;
}

.ab-npc-text {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.5;
  color: var(--lnc-text);
}

/* ===== CHOICES ===== */
.ab-choices {
  margin-top: 8px;
}

.ab-choices-label {
  font-size: 0.85rem;
  color: var(--lnc-text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin: 0 0 10px;
}

.ab-choice-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 10px;
}

.ab-choice-card {
  background: var(--lnc-panel);
  border: 1px solid var(--lnc-border);
  border-radius: var(--lnc-radius-sm);
  padding: 14px;
  cursor: pointer;
  text-align: left;
  display: flex;
  gap: 10px;
  align-items: flex-start;
  transition: border-color 0.15s, background 0.15s, transform 0.15s;
  color: var(--lnc-text);
  font-size: 0.9rem;
  line-height: 1.4;
}
.ab-choice-card:hover:not(:disabled) {
  border-color: var(--lnc-cyan);
  background: #1c2535;
  transform: translateY(-1px);
}
.ab-choice-card:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.ab-choice-icon {
  font-size: 1.2rem;
  flex-shrink: 0;
}

/* ===== SKILL CHECK ===== */
.ab-skill-check {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.ab-skill-header {
  display: flex;
  align-items: center;
  gap: 12px;
}

.ab-skill-badge {
  background: #2d2505;
  color: var(--lnc-amber);
  padding: 4px 12px;
  border-radius: 99px;
  font-size: 0.8rem;
  font-weight: 600;
}

.ab-skill-context {
  font-size: 0.8rem;
  color: var(--lnc-text-secondary);
}

.ab-skill-question {
  background: var(--lnc-panel);
  border: 1px solid var(--lnc-border);
  border-radius: var(--lnc-radius-sm);
  padding: 20px;
}

.ab-question-text {
  margin: 0 0 16px;
  font-size: 1rem;
  line-height: 1.6;
  color: var(--lnc-text);
}

.ab-answers {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ab-answer-btn {
  background: var(--lnc-bg);
  border: 1px solid var(--lnc-border);
  border-radius: var(--lnc-radius-sm);
  padding: 12px 16px;
  color: var(--lnc-text);
  font-size: 0.9rem;
  text-align: left;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}
.ab-answer-btn:hover:not(:disabled) {
  border-color: var(--lnc-cyan);
  background: #1c2535;
}
.ab-answer-btn:disabled {
  cursor: not-allowed;
}
.ab-answer-selected {
  border-color: var(--lnc-cyan);
  background: #1c2535;
}
.ab-answer-correct {
  border-color: var(--lnc-green) !important;
  background: #1c3a1c !important;
  color: var(--lnc-green) !important;
}
.ab-answer-wrong {
  border-color: var(--lnc-danger) !important;
  background: #3a1c1c !important;
  color: var(--lnc-danger) !important;
}

/* ===== RESULT OVERLAY ===== */
.ab-result-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 500;
}

.ab-result-box {
  background: var(--lnc-panel);
  border: 2px solid var(--lnc-border);
  border-radius: 12px;
  padding: 32px;
  text-align: center;
  max-width: 360px;
  width: 90%;
}
.ab-result-success {
  border-color: var(--lnc-green);
}
.ab-result-fail {
  border-color: var(--lnc-danger);
}

.ab-result-icon {
  font-size: 3rem;
  margin-bottom: 12px;
}

.ab-result-label {
  margin: 0 0 8px;
  font-size: 1rem;
  font-weight: 600;
}
.ab-result-success .ab-result-label { color: var(--lnc-green); }
.ab-result-fail .ab-result-label { color: var(--lnc-danger); }

.ab-result-explanation {
  margin: 8px 0 0;
  font-size: 0.85rem;
  color: var(--lnc-text-secondary);
  line-height: 1.5;
}

/* ===== SIMULATION ===== */
.ab-simulation {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.ab-sim-header {
  display: flex;
  align-items: center;
  gap: 12px;
}

.ab-sim-badge {
  background: #1c2535;
  color: var(--lnc-cyan);
  padding: 4px 12px;
  border-radius: 99px;
  font-size: 0.8rem;
  font-weight: 600;
}

.ab-sim-context {
  font-size: 0.85rem;
  color: var(--lnc-text-secondary);
}

.ab-sim-body {
  background: var(--lnc-panel);
  border: 1px solid var(--lnc-border);
  border-radius: var(--lnc-radius-sm);
  padding: 20px;
}

.ab-sim-intro {
  margin: 0 0 16px;
  font-size: 0.9rem;
  color: var(--lnc-text-secondary);
  line-height: 1.5;
  font-style: italic;
}

.ab-sim-result {
  margin-top: 20px;
  padding: 16px;
  border-radius: var(--lnc-radius-sm);
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.ab-sim-pass {
  background: #1c3a1c;
  border: 1px solid var(--lnc-green);
}

.ab-sim-partial {
  background: #2d2505;
  border: 1px solid var(--lnc-amber);
}

.ab-sim-result-icon {
  font-size: 2rem;
}

.ab-sim-result-label {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 500;
}

.ab-sim-pass .ab-sim-result-label { color: var(--lnc-green); }
.ab-sim-partial .ab-sim-result-label { color: var(--lnc-amber); }

/* ===== EPILOG ===== */
.ab-epilog {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 300px;
}

.ab-epilog-inner {
  text-align: center;
  max-width: 500px;
}

.ab-epilog-icon {
  font-size: 4rem;
  margin-bottom: 16px;
}

.ab-epilog-title {
  margin: 0 0 12px;
  font-size: 1.5rem;
  font-weight: 700;
}

.ab-epilog-narrative {
  color: var(--lnc-text-secondary);
  font-size: 0.95rem;
  line-height: 1.7;
  font-family: Georgia, 'Times New Roman', serif;
  margin-bottom: 24px;
}

.ab-epilog-score {
  background: var(--lnc-panel);
  border: 1px solid var(--lnc-border);
  border-radius: var(--lnc-radius-sm);
  padding: 16px;
  margin-bottom: 24px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ab-score-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.9rem;
}
.ab-score-val {
  font-weight: 600;
  color: var(--lnc-text);
}
.ab-xp {
  color: var(--lnc-amber);
}

.ab-epilog-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  justify-content: center;
}

/* ===== COOP LOBBY ===== */
.ab-lobby-code-box {
  background: var(--lnc-panel);
  border: 1px solid var(--lnc-border);
  border-radius: var(--lnc-radius-sm);
  padding: 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}

.ab-lobby-code-label {
  font-size: 0.8rem;
  color: var(--lnc-text-secondary);
}

.ab-lobby-code {
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: 0.1em;
  color: var(--lnc-cyan);
  flex: 1;
  text-align: center;
}

.ab-lobby-players {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 16px;
}

.ab-lobby-player {
  background: var(--lnc-panel);
  border: 1px solid var(--lnc-border);
  border-radius: var(--lnc-radius-sm);
  padding: 10px 14px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.ab-player-ready {
  border-color: var(--lnc-green);
}
.ab-player-name { flex: 1; }
.ab-player-char { font-size: 1.2rem; }
.ab-player-status { color: var(--lnc-text-secondary); font-size: 0.9rem; }

.ab-lobby-actions {
  display: flex;
  gap: 10px;
}

/* ===== COOP VOTING OVERLAY ===== */
.ab-coop-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 500;
}

.ab-coop-dialog {
  background: var(--lnc-panel);
  border: 1px solid var(--lnc-border);
  border-radius: 12px;
  padding: 28px;
  max-width: 360px;
  width: 90%;
  text-align: center;
}
.ab-coop-dialog h3 {
  margin: 0 0 8px;
}
.ab-coop-dialog p {
  color: var(--lnc-text-secondary);
  font-size: 0.9rem;
  margin: 0 0 16px;
}

.ab-coop-choices {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.ab-coop-vote-row {
  display: flex;
  justify-content: space-between;
  background: var(--lnc-bg);
  border-radius: var(--lnc-radius-sm);
  padding: 8px 12px;
  font-size: 0.85rem;
}
.ab-coop-vote-count {
  color: var(--lnc-cyan);
  font-weight: 600;
}

/* ===== ABORT DIALOG ===== */
.ab-abort-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 600;
}

.ab-abort-dialog {
  background: var(--lnc-panel);
  border: 1px solid var(--lnc-border);
  border-radius: 12px;
  padding: 24px;
  max-width: 360px;
  width: 90%;
}
.ab-abort-dialog h3 {
  margin: 0 0 8px;
}
.ab-abort-dialog p {
  color: var(--lnc-text-secondary);
  font-size: 0.9rem;
  margin: 0 0 16px;
}
.ab-abort-actions {
  display: flex;
  gap: 10px;
}

/* ===== TRANSITIONS ===== */
.rpg-fade-enter-active,
.rpg-fade-leave-active {
  transition: opacity 0.3s ease;
}
.rpg-fade-enter,
.rpg-fade-leave-to {
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .rpg-fade-enter-active,
  .rpg-fade-leave-active {
    transition: none;
  }
}

.rpg-result-enter-active {
  animation: rpg-result-in 0.3s ease;
}
.rpg-result-leave-active {
  animation: rpg-result-in 0.2s ease reverse;
}

@keyframes rpg-result-in {
  from { opacity: 0; transform: scale(0.85); }
  to { opacity: 1; transform: scale(1); }
}

@media (prefers-reduced-motion: reduce) {
  .rpg-result-enter-active,
  .rpg-result-leave-active {
    animation: none;
  }
}

/* ===== FREETEXT INPUT ===== */
.ab-freetext {
  margin-top: 16px;
  padding: 12px;
  border-top: 1px solid var(--lnc-border);
}
.ab-freetext-label {
  font-size: 14px;
  color: var(--lnc-text-secondary);
  margin: 0 0 8px 0;
  font-weight: 400;
}
.ab-freetext-input-row {
  display: flex;
  gap: 8px;
}
.ab-freetext-field {
  flex: 1;
  padding: 8px 12px;
  border: 1px solid var(--lnc-border);
  border-radius: var(--lnc-radius-sm);
  font-size: 14px;
  background: var(--lnc-panel);
  color: var(--lnc-text);
}
.ab-freetext-field:focus {
  border-color: var(--lnc-cyan);
  outline: none;
}
.ab-freetext-submit {
  padding: 8px 16px;
  background: var(--lnc-cyan);
  color: #fff;
  border: none;
  border-radius: var(--lnc-radius-sm);
  cursor: pointer;
  font-weight: bold;
  font-size: 16px;
}
.ab-freetext-submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.ab-freetext-error {
  color: var(--lnc-danger);
  font-size: 13px;
  margin: 8px 0 0 0;
}

/* ===== DYNAMIC CHOICE BADGE ===== */
.ab-choice-card {
  position: relative;
}
.ab-choice-dynamic-badge {
  position: absolute;
  top: 4px;
  right: 4px;
  background: rgba(88, 166, 255, 0.15);
  color: var(--lnc-cyan);
  font-size: 10px;
  padding: 1px 5px;
  border-radius: 8px;
  font-weight: bold;
  letter-spacing: 0.5px;
}

/* ===== ROLE BADGES ===== */
.ab-role-badge {
  font-size: 12px;
  padding: 2px 8px;
  border-radius: 10px;
  margin-left: 8px;
  font-weight: 500;
}
.ab-role-attacker {
  background: rgba(248, 81, 73, 0.15);
  color: var(--lnc-danger);
}
.ab-role-dau {
  background: rgba(88, 166, 255, 0.15);
  color: var(--lnc-cyan);
}

/* ===== SKILL-CHECK NPC REACTION ===== */
.ab-skill-npc-reaction {
  display: flex;
  justify-content: center;
  margin-top: var(--lnc-space-md, 16px);
}
</style>
