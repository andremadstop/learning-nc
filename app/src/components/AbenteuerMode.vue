<template>
  <div class="abenteuer-mode">

    <!-- ===== CAMPAIGN SELECT PHASE ===== -->
    <div v-if="phase === 'campaign-select'" class="ab-campaign-select">
      <div class="ab-header">
        <button class="ab-back-btn" @click="$emit('back')">&#x2190; {{ t('learning', 'Zurück') }}</button>
        <h2 class="ab-title">&#x1F5FA; {{ t('learning', 'Abenteuer') }}</h2>
        <p class="ab-subtitle">{{ t('learning', 'Wähle eine Kampagne und löse IT-Probleme in einer spannenden Geschichte.') }}</p>
      </div>

      <div v-if="loadingCampaigns" class="ab-loading">
        <div class="ab-spinner"></div>
        <p>{{ t('learning', 'Lade Kampagnen...') }}</p>
      </div>

      <div v-else class="ab-campaign-grid">
        <div
          v-for="campaign in campaigns"
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
              &#x2713; {{ t('learning', 'Abgeschlossen') }}
            </div>
            <div v-else class="ab-prog-badge ab-prog-active">
              {{ t('learning', 'Szene {n}', { n: campaign.current_scene || 1 }) }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== CHARACTER SELECT PHASE ===== -->
    <div v-else-if="phase === 'character-select'" class="ab-character-select">
      <div class="ab-header">
        <button class="ab-back-btn" @click="phase = 'campaign-select'">&#x2190; {{ t('learning', 'Kampagnen') }}</button>
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
          <div v-if="selectedCharacter && selectedCharacter.id === char.id" class="ab-char-check">&#x2713;</div>
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
              <button class="ab-back-btn" @click="confirmAbort">&#x2190;</button>
              <span class="ab-scene-title">{{ currentScene.title }}</span>
              <span class="ab-scene-progress">{{ sceneProgressLabel }}</span>
            </div>

            <!-- Narrative box -->
            <div class="ab-narrative-box" :class="{ 'ab-typewriter': !reducedMotion }">
              <p class="ab-narrative-text" :class="{ 'ab-typing': narrativeTyping }">
                {{ displayedNarrative }}
              </p>
              <button v-if="narrativeTyping" class="ab-skip-btn" @click="skipTypewriter">
                {{ t('learning', 'Überspringen') }}
              </button>
            </div>

            <!-- NPC Dialog -->
            <div v-if="currentScene.npc_dialog && !narrativeTyping" class="ab-npc-dialog">
              <div class="ab-npc-portrait">{{ npcPortrait(currentScene.npc_dialog.speaker) }}</div>
              <div class="ab-speech-bubble">
                <span class="ab-npc-name">{{ npcName(currentScene.npc_dialog.speaker) }}</span>
                <p class="ab-npc-text">{{ currentScene.npc_dialog.text }}</p>
              </div>
            </div>

            <!-- Decision cards -->
            <div v-if="currentScene.choices && !narrativeTyping && !choiceMade" class="ab-choices">
              <h4 class="ab-choices-label">{{ t('learning', 'Was tust du?') }}</h4>
              <div class="ab-choice-grid">
                <button
                  v-for="choice in currentScene.choices"
                  :key="choice.id"
                  class="ab-choice-card"
                  :disabled="makingChoice"
                  @click="makeChoice(choice)"
                >
                  <span class="ab-choice-icon">{{ choice.icon || '&#x1F3AF;' }}</span>
                  <span class="ab-choice-text">{{ choice.text }}</span>
                </button>
              </div>
            </div>

            <!-- Coop voting overlay -->
            <div v-if="coopMode && coopVoting" class="ab-coop-overlay" role="dialog" aria-modal="true" aria-label="Abstimmung">
              <div class="ab-coop-dialog">
                <h3>{{ t('learning', 'Abstimmung läuft...') }}</h3>
                <p>{{ t('learning', '{n} von {total} Spieler haben gewählt', { n: coopVotes, total: coopTotal }) }}</p>
                <div class="ab-coop-choices">
                  <div v-for="cv in coopChoiceVotes" :key="cv.choiceId" class="ab-coop-vote-row">
                    <span>{{ cv.text }}</span>
                    <span class="ab-coop-vote-count">{{ cv.votes }}</span>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </div>
      </transition>
    </div>

    <!-- ===== SKILL CHECK PHASE ===== -->
    <div v-else-if="phase === 'skill-check'" class="ab-skill-check">
      <div class="ab-skill-header">
        <span class="ab-skill-badge">&#x26A1; {{ t('learning', 'Skill Check') }}</span>
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
            <div class="ab-result-icon">{{ lastAnswerCorrect ? '&#x2705;' : '&#x274C;' }}</div>
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
        <button class="ab-back-btn" @click="phase = 'character-select'">&#x2190;</button>
        <h2 class="ab-title">{{ t('learning', 'Koop-Lobby') }}</h2>
      </div>

      <div class="ab-lobby-code-box">
        <span class="ab-lobby-code-label">{{ t('learning', 'Beitrittscode') }}</span>
        <span class="ab-lobby-code">{{ coopSessionCode }}</span>
        <button class="ab-btn ab-btn-secondary" @click="copyCoopCode">
          {{ t('learning', 'Code kopieren') }}
        </button>
      </div>

      <div class="ab-lobby-players">
        <div
          v-for="player in coopPlayers"
          :key="player.user_id"
          class="ab-lobby-player"
          :class="{ 'ab-player-ready': player.is_ready }"
        >
          <span class="ab-player-name">{{ player.display_name || player.user_id }}</span>
          <span v-if="player.character" class="ab-player-char">{{ characterPortrait(player.character) }}</span>
          <span class="ab-player-status">{{ player.is_ready ? '&#x2713;' : '...' }}</span>
        </div>
      </div>

      <div class="ab-lobby-actions">
        <button class="ab-btn ab-btn-primary" :disabled="coopPlayers.length < 2" @click="coopSetReady">
          {{ t('learning', 'Bereit!') }}
        </button>
        <button class="ab-btn ab-btn-ghost" @click="phase = 'character-select'">
          {{ t('learning', 'Abbrechen') }}
        </button>
      </div>
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

  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const STATIC_CAMPAIGNS = [
	{
		id: 'grosser_ausfall',
		icon: '&#x1F4E1;',
		title: 'Der große Ausfall',
		description: 'Montag morgen: nichts geht. Alle Systeme von NovaTech sind down. Du bist das Notfall-Team.',
		difficulty: 'intermediate',
		focus_areas: ['Network+'],
		duration_minutes: 60,
		progress: 'not_started',
		current_scene: null,
	},
	{
		id: 'einbruch_im_netz',
		icon: '&#x1F510;',
		title: 'Einbruch im Netz',
		description: 'Dienstag, 03:47 Uhr. 847 fehlgeschlagene Logins in 12 Minuten. Das ist kein normaler Dienstag.',
		difficulty: 'advanced',
		focus_areas: ['Security+'],
		duration_minutes: 60,
		progress: 'not_started',
		current_scene: null,
	},
	{
		id: 'neuer_standort',
		icon: '&#x1F3E2;',
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
		icon: '&#x1F480;',
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
		icon: '&#x1F4BE;',
		title: 'Das Erbe',
		description: 'NovaTech übernimmt Oldstyle GmbH. Windows Server 2012, ein Hub (kein Switch!) und admin123.',
		difficulty: 'beginner',
		focus_areas: ['A+', 'Network+', 'Linux+'],
		duration_minutes: 75,
		progress: 'not_started',
		current_scene: null,
	},
]

const CHARACTERS = [
	{
		id: 'architect',
		portrait: '&#x1F468;&#x200D;&#x1F4BB;',
		name: 'Alex "The Architect" Neumann',
		role: 'Netzwerk-Architekt',
		description: 'Methodisch, analytisch. Denkt in OSI-Schichten. Stark bei Routing und Topologie-Design.',
		strength: 'Routing, VLANs, Subnetting',
		weakness: 'Security-Fragen dauern länger',
	},
	{
		id: 'security',
		portrait: '&#x1F469;&#x200D;&#x1F4BB;',
		name: 'Sarah "Firewall" Okonkwo',
		role: 'Security-Analystin',
		description: 'Paranoid im besten Sinne. Sieht überall Bedrohungen. Hat für jedes Szenario einen IR-Plan.',
		strength: 'Firewalls, Incident Response, Threats',
		weakness: 'Routing-Fragen brauchen mehr Zeit',
	},
	{
		id: 'sysadmin',
		portrait: '&#x1F9D4;',
		name: 'Kai "Root" Lindström',
		role: 'Sysadmin',
		description: 'Unix-Philosophie pur. Löst alles per Terminal. Hat für jedes Problem ein Bash-Skript.',
		strength: 'Linux, Scripting, DNS, Server',
		weakness: 'Wireless ist "Teufelszeug"',
	},
	{
		id: 'helpdesk',
		portrait: '&#x1F469;&#x200D;&#x1F9BA;',
		name: 'Mia "Helpdesk" Torres',
		role: 'First-Level-Support',
		description: 'Troubleshooting-Talent. Kann Fachjargon in Alltagssprache übersetzen. Kennt das Drucker-Passwort.',
		strength: 'Troubleshooting, Hardware, A+',
		weakness: 'Tiefes Netzwerk-Wissen fehlt manchmal',
	},
]

const NPC_PORTRAITS = {
	dr_weber: { portrait: '&#x1F469;&#x200D;&#x1F4BC;', name: 'Dr. Weber' },
	jens_bug: { portrait: '&#x1F468;&#x200D;&#x1F4BB;', name: 'Jens "Bug" Hoffmann' },
	nova: { portrait: '&#x1F916;', name: 'NOVA' },
}

export default {
	name: 'AbenteuerMode',

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
		coopMode: {
			type: Boolean,
			default: false,
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
			coopSessionCode: '',
			coopPlayers: [],
			coopVoting: false,
			coopVotes: 0,
			coopTotal: 1,
			coopChoiceVotes: [],
			coopPollTimer: null,

			// Abort
			showAbortConfirm: false,

			// Accessibility
			reducedMotion: false,
		}
	},

	computed: {
		epilogIcon() {
			if (!this.epilogData) return '&#x1F4CB;'
			return this.epilogData.outcome === 'success' ? '&#x1F3C6;' : '&#x1F4CB;'
		},
		sceneProgressLabel() {
			if (!this.selectedCampaign) return ''
			const total = this.selectedCampaign.total_scenes || 5
			const current = this.sessionStats.scenesCompleted + 1
			return `${current} / ${total}`
		},
	},

	mounted() {
		this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
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
				this.campaigns = resp.data.campaigns || resp.data || STATIC_CAMPAIGNS
			} catch (e) {
				// Backend not yet available — fall back to static data
				this.campaigns = STATIC_CAMPAIGNS
			} finally {
				this.loadingCampaigns = false
			}
		},

		selectCampaign(campaign) {
			this.selectedCampaign = campaign
			this.phase = 'character-select'
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
		},

		startCampaign() {
			if (!this.selectedCharacter) return
			if (this.coopMode) {
				this.initCoopSession()
			} else {
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
				const url = generateUrl('/apps/learning/api/story/scene')
				const params = {
					campaignId: this.selectedCampaign.id,
					characterClass: this.selectedCharacter.id,
				}
				if (sceneId) params.sceneId = sceneId
				if (this.courseId) params.courseId = this.courseId
				const resp = await axios.get(url, { params })
				this.currentScene = resp.data
				this.loadingScene = false
				this.startTypewriter(this.currentScene.narrative)
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
					{ id: 'c1_serverraum', icon: '&#x1F5A5;', text: t('learning', 'Ich gehe direkt in den Serverraum') },
					{ id: 'c1_befragung', icon: '&#x1F5E3;', text: t('learning', 'Erst befrage ich die Mitarbeiter') },
					{ id: 'c1_schraenke', icon: '&#x1F4E6;', text: t('learning', 'Ich prüfe die Netzwerkschränke auf dieser Etage') },
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

			// If coop: enter voting mode
			if (this.coopMode) {
				this.coopVoting = true
				this.coopVotes = 1
				this.startCoopPoll(choice.id)
				return
			}

			// Check if choice has skill check
			if (choice.skill_check) {
				this.currentSkillCheck = choice
				await this.fetchSkillQuestion(choice)
			} else {
				// Navigate directly to next scene
				await this.advanceToScene(choice.success_scene || choice.next_scene)
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
		},

		// ===== SKILL CHECK =====

		async fetchSkillQuestion(choice) {
			this.phase = 'skill-check'
			this.skillAnswered = false
			this.selectedAnswer = null
			this.skillCheckIndex = 0
			this.skillCheckTotal = choice.skill_check?.question_count || 1

			try {
				const url = generateUrl('/apps/learning/api/story/skill-question')
				const resp = await axios.post(url, {
					campaignId: this.selectedCampaign.id,
					sceneId: this.currentScene.id,
					choiceId: choice.id,
					characterClass: this.selectedCharacter.id,
					courseId: this.courseId || null,
				})
				this.currentSkillQuestion = resp.data
			} catch (e) {
				// Stub question when backend not available
				this.currentSkillQuestion = this.makeStubQuestion()
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

			if (answer.is_correct) {
				this.sessionStats.skillChecksPassed++
			}

			try {
				await axios.post(generateUrl('/apps/learning/api/story/answer'), {
					campaignId: this.selectedCampaign.id,
					sceneId: this.currentScene ? this.currentScene.id : null,
					questionId: this.currentSkillQuestion.id,
					answerId: answer.id,
					characterClass: this.selectedCharacter.id,
					courseId: this.courseId || null,
				})
			} catch (e) {
				// Silently ignore — progress tracking is best-effort
			}

			// Auto-advance after showing result
			this.advanceTimer = setTimeout(() => {
				this.skillAnswered = false
				this.skillCheckIndex++
				if (this.skillCheckIndex < this.skillCheckTotal) {
					// Fetch next question
					this.fetchNextSkillQuestion()
				} else {
					// Return to scene or advance
					this.resolveSkillCheck(answer.is_correct)
				}
			}, 2000)
		},

		async fetchNextSkillQuestion() {
			this.selectedAnswer = null
			try {
				const url = generateUrl('/apps/learning/api/story/skill-question-next')
				const resp = await axios.post(url, {
					campaignId: this.selectedCampaign.id,
					sceneId: this.currentScene ? this.currentScene.id : null,
					questionIndex: this.skillCheckIndex,
					characterClass: this.selectedCharacter.id,
				})
				this.currentSkillQuestion = resp.data
			} catch (e) {
				this.currentSkillQuestion = this.makeStubQuestion()
			}
		},

		async resolveSkillCheck(lastWasCorrect) {
			const passThreshold = this.currentSkillCheck?.skill_check?.pass_threshold || Math.ceil(this.skillCheckTotal / 2)
			const passed = this.sessionStats.skillChecksPassed >= passThreshold

			const nextScene = passed
				? (this.currentSkillCheck?.success_scene || this.currentSkillCheck?.next_scene)
				: (this.currentSkillCheck?.fail_scene || this.currentSkillCheck?.partial_scene)

			await this.advanceToScene(nextScene)
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
			this.phase = 'character-select'
		},

		// ===== COOP =====

		async initCoopSession() {
			try {
				const resp = await axios.post(generateUrl('/apps/learning/api/story/coop/start'), {
					campaignId: this.selectedCampaign.id,
					characterClass: this.selectedCharacter.id,
				})
				this.coopSessionCode = resp.data.session_code
				this.coopPlayers = resp.data.players || []
				this.phase = 'coop-lobby'
				this.startCoopPoll()
			} catch (e) {
				// Stub for demo
				this.coopSessionCode = 'NOVA' + Math.floor(Math.random() * 9000 + 1000)
				this.coopPlayers = [{ user_id: 'me', display_name: t('learning', 'Du'), character: this.selectedCharacter.id, is_ready: false }]
				this.phase = 'coop-lobby'
			}
		},

		coopSetReady() {
			this.beginScene()
		},

		startCoopPoll(choiceId) {
			this.coopPollTimer = setInterval(async () => {
				try {
					const resp = await axios.get(generateUrl('/apps/learning/api/story/coop/poll'), {
						params: { sessionCode: this.coopSessionCode },
					})
					if (resp.data.phase === 'scene') {
						this.clearTimers()
						this.coopVoting = false
						const nextScene = resp.data.next_scene
						await this.advanceToScene(nextScene)
					}
					this.coopVotes = resp.data.votes_cast || 1
					this.coopTotal = resp.data.total_players || 1
					this.coopChoiceVotes = resp.data.choice_votes || []
				} catch (e) {
					// Ignore poll errors
				}
			}, 2000)
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
			this.clearTimers()
			this.phase = 'campaign-select'
			this.currentScene = null
			this.selectedCharacter = null
		},

		// ===== HELPERS =====

		npcPortrait(speaker) {
			return (NPC_PORTRAITS[speaker] || { portrait: '&#x1F464;' }).portrait
		},

		npcName(speaker) {
			return (NPC_PORTRAITS[speaker] || { name: speaker }).name
		},

		characterPortrait(charId) {
			const char = CHARACTERS.find(c => c.id === charId)
			return char ? char.portrait : '&#x1F464;'
		},

		clearTimers() {
			if (this.typewriterTimer) clearTimeout(this.typewriterTimer)
			if (this.advanceTimer) clearTimeout(this.advanceTimer)
			if (this.coopPollTimer) clearInterval(this.coopPollTimer)
		},
	},
}
</script>

<style scoped>
/* ===== ROOT ===== */
.abenteuer-mode {
  --rpg-bg: #0d1117;
  --rpg-surface: #161b22;
  --rpg-border: #30363d;
  --rpg-text: #c9d1d9;
  --rpg-text-muted: #8b949e;
  --rpg-accent: #58a6ff;
  --rpg-success: #3fb950;
  --rpg-danger: #f85149;
  --rpg-gold: #d29922;
  --rpg-radius: 8px;

  min-height: 400px;
  color: var(--rpg-text);
  background: var(--rpg-bg);
  border-radius: var(--rpg-radius);
  padding: 20px;
  position: relative;
}

/* ===== HEADER ===== */
.ab-header {
  margin-bottom: 24px;
}

.ab-back-btn {
  background: transparent;
  border: 1px solid var(--rpg-border);
  color: var(--rpg-text-muted);
  padding: 6px 12px;
  border-radius: var(--rpg-radius);
  cursor: pointer;
  font-size: 13px;
  margin-bottom: 12px;
  display: inline-block;
  transition: color 0.15s, border-color 0.15s;
}
.ab-back-btn:hover {
  color: var(--rpg-text);
  border-color: var(--rpg-accent);
}

.ab-title {
  margin: 8px 0 4px;
  font-size: 1.5rem;
  font-weight: 600;
  color: var(--rpg-text);
}

.ab-subtitle {
  color: var(--rpg-text-muted);
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
  color: var(--rpg-text-muted);
}

.ab-spinner {
  width: 32px;
  height: 32px;
  border: 3px solid var(--rpg-border);
  border-top-color: var(--rpg-accent);
  border-radius: 50%;
  animation: ab-spin 0.7s linear infinite;
}

@keyframes ab-spin {
  to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
  .ab-spinner {
    animation: none;
    border-top-color: var(--rpg-accent);
  }
}

/* ===== CAMPAIGN GRID ===== */
.ab-campaign-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
}

.ab-campaign-card {
  background: var(--rpg-surface);
  border: 1px solid var(--rpg-border);
  border-radius: var(--rpg-radius);
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
  border-color: var(--rpg-accent);
  transform: translateY(-2px);
}
.ab-campaign-card:focus-visible {
  box-shadow: 0 0 0 2px var(--rpg-accent);
}
.ab-campaign-completed {
  border-color: var(--rpg-success);
}

.ab-campaign-icon {
  font-size: 2rem;
  line-height: 1;
}

.ab-campaign-title {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  color: var(--rpg-text);
}

.ab-campaign-desc {
  margin: 0;
  font-size: 0.85rem;
  color: var(--rpg-text-muted);
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
  color: var(--rpg-accent);
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
.ab-prog-new { background: #1c2535; color: var(--rpg-accent); }
.ab-prog-active { background: #2d2505; color: var(--rpg-gold); }
.ab-prog-done { background: #1c3a1c; color: var(--rpg-success); }

/* ===== CHARACTER GRID ===== */
.ab-character-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 14px;
  margin-bottom: 20px;
}

.ab-character-card {
  background: var(--rpg-surface);
  border: 2px solid var(--rpg-border);
  border-radius: var(--rpg-radius);
  padding: 16px;
  cursor: pointer;
  transition: border-color 0.15s, transform 0.15s;
  position: relative;
  outline: none;
  text-align: left;
}
.ab-character-card:hover,
.ab-character-card:focus-visible {
  border-color: var(--rpg-accent);
  transform: translateY(-2px);
}
.ab-character-card:focus-visible {
  box-shadow: 0 0 0 2px var(--rpg-accent);
}
.ab-char-selected {
  border-color: var(--rpg-accent) !important;
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
  color: var(--rpg-text);
}

.ab-char-role {
  margin: 0 0 6px;
  font-size: 0.75rem;
  color: var(--rpg-accent);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.ab-char-desc {
  margin: 0 0 10px;
  font-size: 0.82rem;
  color: var(--rpg-text-muted);
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
  color: var(--rpg-text-muted);
  min-width: 50px;
}
.ab-stat-value {
  color: var(--rpg-text);
}

.ab-char-check {
  position: absolute;
  top: 10px;
  right: 12px;
  color: var(--rpg-success);
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
  border-radius: var(--rpg-radius);
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
  background: var(--rpg-accent);
  color: #0d1117;
}
.ab-btn-primary:hover:not(:disabled) {
  background: #79b8ff;
}
.ab-btn-secondary {
  background: var(--rpg-surface);
  color: var(--rpg-text);
  border: 1px solid var(--rpg-border);
}
.ab-btn-secondary:hover:not(:disabled) {
  border-color: var(--rpg-accent);
}
.ab-btn-ghost {
  background: transparent;
  color: var(--rpg-text-muted);
  border: 1px solid var(--rpg-border);
}
.ab-btn-ghost:hover:not(:disabled) {
  color: var(--rpg-text);
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
  color: var(--rpg-text-muted);
}

.ab-narrative-box {
  background: #0a0e15;
  border: 1px solid var(--rpg-border);
  border-radius: var(--rpg-radius);
  padding: 20px;
  position: relative;
  min-height: 80px;
}

.ab-narrative-text {
  margin: 0;
  font-size: 0.95rem;
  line-height: 1.7;
  color: var(--rpg-text);
  font-family: Georgia, 'Times New Roman', serif;
  white-space: pre-wrap;
}

/* Typewriter cursor */
.ab-typing::after {
  content: '\u258C';
  animation: ab-blink 1s step-end infinite;
  color: var(--rpg-accent);
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
  color: var(--rpg-text-muted);
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
  background: var(--rpg-surface);
  border: 1px solid var(--rpg-border);
  border-radius: 50%;
  width: 56px;
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.ab-speech-bubble {
  background: var(--rpg-surface);
  border: 1px solid var(--rpg-border);
  border-radius: var(--rpg-radius);
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
  border-right: 8px solid var(--rpg-border);
}

.ab-npc-name {
  display: block;
  font-size: 0.75rem;
  color: var(--rpg-accent);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 4px;
}

.ab-npc-text {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.5;
  color: var(--rpg-text);
}

/* ===== CHOICES ===== */
.ab-choices {
  margin-top: 8px;
}

.ab-choices-label {
  font-size: 0.85rem;
  color: var(--rpg-text-muted);
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
  background: var(--rpg-surface);
  border: 1px solid var(--rpg-border);
  border-radius: var(--rpg-radius);
  padding: 14px;
  cursor: pointer;
  text-align: left;
  display: flex;
  gap: 10px;
  align-items: flex-start;
  transition: border-color 0.15s, background 0.15s, transform 0.15s;
  color: var(--rpg-text);
  font-size: 0.9rem;
  line-height: 1.4;
}
.ab-choice-card:hover:not(:disabled) {
  border-color: var(--rpg-accent);
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
  color: var(--rpg-gold);
  padding: 4px 12px;
  border-radius: 99px;
  font-size: 0.8rem;
  font-weight: 600;
}

.ab-skill-context {
  font-size: 0.8rem;
  color: var(--rpg-text-muted);
}

.ab-skill-question {
  background: var(--rpg-surface);
  border: 1px solid var(--rpg-border);
  border-radius: var(--rpg-radius);
  padding: 20px;
}

.ab-question-text {
  margin: 0 0 16px;
  font-size: 1rem;
  line-height: 1.6;
  color: var(--rpg-text);
}

.ab-answers {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ab-answer-btn {
  background: var(--rpg-bg);
  border: 1px solid var(--rpg-border);
  border-radius: var(--rpg-radius);
  padding: 12px 16px;
  color: var(--rpg-text);
  font-size: 0.9rem;
  text-align: left;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}
.ab-answer-btn:hover:not(:disabled) {
  border-color: var(--rpg-accent);
  background: #1c2535;
}
.ab-answer-btn:disabled {
  cursor: not-allowed;
}
.ab-answer-selected {
  border-color: var(--rpg-accent);
  background: #1c2535;
}
.ab-answer-correct {
  border-color: var(--rpg-success) !important;
  background: #1c3a1c !important;
  color: var(--rpg-success) !important;
}
.ab-answer-wrong {
  border-color: var(--rpg-danger) !important;
  background: #3a1c1c !important;
  color: var(--rpg-danger) !important;
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
  background: var(--rpg-surface);
  border: 2px solid var(--rpg-border);
  border-radius: 12px;
  padding: 32px;
  text-align: center;
  max-width: 360px;
  width: 90%;
}
.ab-result-success {
  border-color: var(--rpg-success);
}
.ab-result-fail {
  border-color: var(--rpg-danger);
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
.ab-result-success .ab-result-label { color: var(--rpg-success); }
.ab-result-fail .ab-result-label { color: var(--rpg-danger); }

.ab-result-explanation {
  margin: 8px 0 0;
  font-size: 0.85rem;
  color: var(--rpg-text-muted);
  line-height: 1.5;
}

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
  color: var(--rpg-text-muted);
  font-size: 0.95rem;
  line-height: 1.7;
  font-family: Georgia, 'Times New Roman', serif;
  margin-bottom: 24px;
}

.ab-epilog-score {
  background: var(--rpg-surface);
  border: 1px solid var(--rpg-border);
  border-radius: var(--rpg-radius);
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
  color: var(--rpg-text);
}
.ab-xp {
  color: var(--rpg-gold);
}

.ab-epilog-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  justify-content: center;
}

/* ===== COOP LOBBY ===== */
.ab-lobby-code-box {
  background: var(--rpg-surface);
  border: 1px solid var(--rpg-border);
  border-radius: var(--rpg-radius);
  padding: 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}

.ab-lobby-code-label {
  font-size: 0.8rem;
  color: var(--rpg-text-muted);
}

.ab-lobby-code {
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: 0.1em;
  color: var(--rpg-accent);
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
  background: var(--rpg-surface);
  border: 1px solid var(--rpg-border);
  border-radius: var(--rpg-radius);
  padding: 10px 14px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.ab-player-ready {
  border-color: var(--rpg-success);
}
.ab-player-name { flex: 1; }
.ab-player-char { font-size: 1.2rem; }
.ab-player-status { color: var(--rpg-text-muted); font-size: 0.9rem; }

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
  background: var(--rpg-surface);
  border: 1px solid var(--rpg-border);
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
  color: var(--rpg-text-muted);
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
  background: var(--rpg-bg);
  border-radius: var(--rpg-radius);
  padding: 8px 12px;
  font-size: 0.85rem;
}
.ab-coop-vote-count {
  color: var(--rpg-accent);
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
  background: var(--rpg-surface);
  border: 1px solid var(--rpg-border);
  border-radius: 12px;
  padding: 24px;
  max-width: 360px;
  width: 90%;
}
.ab-abort-dialog h3 {
  margin: 0 0 8px;
}
.ab-abort-dialog p {
  color: var(--rpg-text-muted);
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
</style>
