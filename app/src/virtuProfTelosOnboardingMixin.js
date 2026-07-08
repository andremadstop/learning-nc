/**
 * VirtuProf-Telos-Onboarding / Journey (opt-in Lernprofil-Einrichtung).
 * Aus VirtuProf.vue extrahiert als Options-API-Mixin (Zero-Behavior-Change).
 *
 * Deckt: Welcome-Intro → Preset-Auswahl/Custom → Journey-Schritte → Speichern → Complete,
 * plus Ablehnen (declineOnboarding) und Statusabfrage (checkTelosOnboarding). Die zugehörigen
 * Step-Builder (buildWelcomeStepContent/… ) leben hier; der zentrale buildHelpStep-Dispatcher
 * bleibt im Host-Component und ruft sie über die gemergte Instanz auf.
 *
 * Cross-Cutter, die bewusst im Host-Component bleiben und via Merge aufgelöst werden:
 * applyReaction (Reaktions-Engine), saveVirtuProfPreferences (Persistenz), vt (i18n).
 * Lifecycle bleibt zentral: checkTelosOnboarding wird aus dem mounted-Flow des Hosts gerufen —
 * KEIN eigener Hook hier.
 *
 * journeyTotalSteps ist ein (aktuell ungenutzter) Computed, der 1:1 mitgezogen wurde
 * (Zero-Behavior-Change; nicht gelöscht — Löschung wäre separate Entscheidung).
 */
import axios from "@nextcloud/axios"
import { generateUrl } from "@nextcloud/router"
import { formatTelosSummaryValue, formatTelosHours } from "./utils/virtuprof-telos-format.js"
import {
	applyTelosToForm,
	buildTelosPayload,
	createTelosForm,
} from "./utils/telosProfile.js"

const ONBOARDING_PRESETS = [
	{
		id: 'career_changer_comptia',
		label: 'Career changer doing CompTIA',
		icon: '🔄',
		telos: {
			role: 'Quereinsteiger',
			experience_level: 'beginner',
			target_cert: 'CompTIA Network+',
			learning_style: 'solo',
			hours_per_week: 10,
		},
		visibility: 'course',
	},
	{
		id: 'it_admin',
		label: 'IT Admin expanding skills',
		icon: '🖥️',
		telos: {
			role: 'IT-Administrator',
			experience_level: 'intermediate',
			target_cert: 'CompTIA Security+',
			learning_style: 'mixed',
			hours_per_week: 5,
		},
		visibility: 'course',
	},
	{
		id: 'student_general',
		label: 'Student — general learning',
		icon: '📚',
		telos: {
			role: 'Student',
			experience_level: 'beginner',
			target_cert: '',
			learning_style: 'group',
			hours_per_week: 8,
		},
		visibility: 'course',
	},
]

const JOURNEY_STEPS = [
	{
		id: 'background',
		title: 'Was ist dein Hintergrund?',
		explanation: 'Damit passe ich Erklärungen an dein Level an. Ein Quereinsteiger braucht andere Worte als ein Admin.',
		privacyHint: 'Your data is stored only on this DevCloud. Instructors see aggregated class statistics, not your individual profile.',
		fields: ['telos.role', 'telos.experience_level'],
	},
	{
		id: 'goal',
		title: 'Worauf arbeitest du hin?',
		explanation: 'Ich kann dich gezielt auf deine Prüfung vorbereiten und deinen Lernplan danach ausrichten.',
		privacyHint: 'Your certification goal helps VirtuProf tailor recommendations. You can change or delete it any time in Settings.',
		fields: ['telos.target_cert', 'telos.target_date'],
	},
	{
		id: 'strengths',
		title: 'Wo stehst du?',
		explanation: 'So weiß ich wo ich mehr erklären muss und wo ich direkt zum Punkt kommen kann.',
		privacyHint: 'Strengths and weaknesses are only used locally by VirtuProf. They are not shared with other users or instructors.',
		fields: ['telos.strengths', 'telos.weaknesses'],
	},
	{
		id: 'style',
		title: 'Wie lernst du am liebsten?',
		explanation: 'Das beeinflusst wie ich dir Inhalte vorschlage — allein durcharbeiten, mit anderen üben, oder gemischt.',
		privacyHint: 'When you chat with VirtuProf, your profile context is sent to Google Gemini — only during the chat, not stored permanently.',
		fields: ['telos.learning_style', 'telos.hours_per_week'],
	},
	{
		id: 'peers',
		title: 'Du lernst hier nicht allein',
		explanation: 'Andere Kursteilnehmer haben ähnliche Ziele. Wenn du magst, können sie sehen wobei du helfen kannst — und du siehst wer dir helfen kann.',
		privacyHint: 'With Course or Public visibility, other participants can see your help topics. With Private, nobody sees your profile. You can change this any time in Settings.',
		fields: ['help_offer', 'help_wanted', 'visibility'],
	},
	{
		id: 'extra',
		title: 'Noch was?',
		explanation: 'Alles was mir hilft dich besser zu unterstützen. Du kannst diesen Schritt auch überspringen.',
		privacyHint: 'These fields are completely optional and only visible to VirtuProf.',
		fields: ['telos.motivation', 'telos.notes'],
		optional: true,
	},
]

export default {
	data() {
		return {
			telosOnboardingActive: false,
			telosForm: createTelosForm(),
			telosSaving: false,
			telosError: '',
			telosSaved: false,
			telosCompletionProfile: null,
			onboardingReminderCount: 0,
			// Journey state (opt-in step-by-step onboarding)
			journeyStepIndex: 0,
			onboardingDeclined: false,
			telosProfileLoaded: false,
			showOnboardingIntro: false,
			selectedPresetId: null,
		}
	},
	computed: {
		journeyTotalSteps() {
			return JOURNEY_STEPS.length
		},
	},
	methods: {
		resetTelosReminderCount() {
			this.onboardingReminderCount = 0
			this.saveVirtuProfPreferences({ onboardingReminderCount: 0 }).catch(() => {})
		},
		async checkTelosOnboarding() {
			if (this.userRole !== 'student' || !this.enabled) {
				return
			}

			try {
				const response = await axios.get(generateUrl('/apps/learning/api/profile/telos/status'))
				this.telosProfileLoaded = true
				if (response.data?.onboarding_completed) {
					this.telosSaved = true
					if (response.data?.telos) {
						this.telosForm = applyTelosToForm(response.data.telos)
					}
					return
				}
				if (response.data?.onboarding_declined) {
					this.onboardingDeclined = true
					return
				}
				this.showWelcomeStep()
			} catch (e) {
				this.telosProfileLoaded = true
			}
		},
		showWelcomeStep() {
			this.showOnboardingIntro = true
		},
		onIntroFinished() {
			this.showOnboardingIntro = false
			this.helpView = 'welcome'
			this.visible = true
			this.isMinimized = false
			this.currentAnimation = 'wave'
		},
		startJourney() {
			this.telosOnboardingActive = true
			this.telosError = ''
			this.telosSaved = false
			this.journeyStepIndex = 0
			this.telosForm = createTelosForm()
			this.telosCompletionProfile = null
			this.selectedPresetId = null
			this.helpView = 'preset-select'
			this.visible = true
			this.isMinimized = false
			this.currentAnimation = 'talk'
		},
		applyPreset(presetId) {
			const preset = ONBOARDING_PRESETS.find(p => p.id === presetId)
			if (!preset) return
			this.selectedPresetId = presetId
			const form = createTelosForm()
			Object.keys(preset.telos || {}).forEach(key => {
				form.telos[key] = preset.telos[key]
			})
			if (preset.visibility) {
				form.visibility = preset.visibility
			}
			this.telosForm = form
			this.helpView = 'telos-journey'
			this.journeyStepIndex = 0
		},
		startCustomJourney() {
			this.telosForm = createTelosForm()
			this.selectedPresetId = null
			this.helpView = 'telos-journey'
			this.journeyStepIndex = 0
		},
		declineOnboarding() {
			this.onboardingDeclined = true
			this.helpView = null
			this.visible = false
			this.currentAnimation = 'idle'
			this.telosOnboardingActive = false
			this.saveVirtuProfPreferences({ onboardingDeclined: true }).catch(() => {})
		},
		journeyNext() {
			if (this.journeyStepIndex < JOURNEY_STEPS.length - 1) {
				this.journeyStepIndex += 1
				this.currentAnimation = 'talk'
			}
		},
		journeyBack() {
			if (this.journeyStepIndex > 0) {
				this.journeyStepIndex -= 1
				this.currentAnimation = 'talk'
			}
		},
		openTelosForm() {
			this.telosOnboardingActive = true
			this.helpView = 'telos-form'
			this.chatMessages = []
			this.visible = true
			this.isMinimized = false
			this.currentAnimation = 'talk'
		},
		updateTelosField(field, value) {
			if (!field) {
				return
			}
			const segments = String(field).split('.')
			if (segments.length === 1) {
				this.telosForm[segments[0]] = value
				return
			}

			let target = this.telosForm
			for (let index = 0; index < segments.length - 1; index += 1) {
				const key = segments[index]
				if (!target[key] || typeof target[key] !== 'object') {
					target[key] = {}
				}
				target = target[key]
			}
			target[segments[segments.length - 1]] = value
		},
		async submitTelosForm() {
			this.telosSaving = true
			this.telosError = ''
			this.telosSaved = false
			try {
				const payload = buildTelosPayload(this.telosForm)
				await axios.post(generateUrl('/apps/learning/api/profile/telos'), payload)
				this.telosSaved = true
				this.telosOnboardingActive = false
				this.telosCompletionProfile = payload
				this.helpView = 'telos-complete'
				this.applyReaction('milestone')
				this.resetTelosReminderCount()
			} catch (e) {
				this.telosError = e?.response?.data?.error || this.vt('Could not save your learning profile.')
			} finally {
				this.telosSaving = false
			}
		},
		buildWelcomeStepContent() {
			return {
				title: this.vt('Welcome to DevCloud!'),
				text: this.vt('I am VirtuProf — your personal learning assistant. I can explain topics, give tips when you get stuck, and help you find the right exercises.\n\nThe more I know about you, the better I can help. Want to set up your learning profile? It takes about 2 minutes.'),
				kind: 'welcome',
				hideMoreOptions: true,
				showIntroInline: true,
				renderActionsInline: true,
				disableSuggestions: true,
				actions: [
					{ label: this.vt('Set up profile'), type: 'start-journey' },
					{ label: this.vt('Skip for now'), type: 'decline-onboarding' },
				],
			}
		},
		buildPresetSelectStep() {
			return {
				title: this.vt('Quick setup'),
				text: this.vt('Choose a preset that fits you, or set up a custom profile.'),
				kind: 'preset-select',
				presets: ONBOARDING_PRESETS.map(p => ({
					id: p.id,
					label: this.vt(p.label),
					icon: p.icon,
				})),
				hideMoreOptions: true,
				showIntroInline: true,
				renderActionsInline: true,
				disableSuggestions: true,
				actions: [],
			}
		},
		buildTelosJourneyStep() {
			const step = JOURNEY_STEPS[this.journeyStepIndex] || JOURNEY_STEPS[0]
			const isLast = this.journeyStepIndex >= JOURNEY_STEPS.length - 1
			const actions = []
			if (step.optional) {
				actions.push({ label: this.vt('Save profile'), type: 'submit-telos-form' })
				actions.push({ label: this.vt('Skip — that is enough'), type: 'submit-telos-form' })
			} else {
				actions.push({ label: isLast ? this.vt('Save profile') : this.vt('Next'), type: isLast ? 'submit-telos-form' : 'journey-next' })
				if (this.journeyStepIndex > 0) {
					actions.push({ label: this.vt('Back'), type: 'journey-back' })
				}
			}
			return {
				title: this.vt(step.title),
				text: this.vt(step.explanation),
				kind: 'telos-journey',
				journeyStepId: step.id,
				journeyStepIndex: this.journeyStepIndex,
				journeyTotalSteps: JOURNEY_STEPS.length,
				journeyFields: step.fields,
				privacyHint: step.privacyHint ? this.vt(step.privacyHint) : '',
				hideMoreOptions: true,
				showIntroInline: true,
				renderActionsInline: true,
				disableSuggestions: true,
				actions,
			}
		},
		buildTelosFormStep() {
			return {
				title: this.vt('Learning profile'),
				text: this.vt('Fill in the most important learning goals and self-assessment fields. This helps VirtuProf and gives instructors only aggregated class-level insight.'),
				kind: 'telos-form',
			}
		},
		buildTelosCompleteStep() {
			const profile = this.telosCompletionProfile || buildTelosPayload(this.telosForm)
			const telos = profile?.telos || {}
			const targetCert = formatTelosSummaryValue(telos.target_cert, this.vt('Open goal'))
			const targetDate = formatTelosSummaryValue(telos.target_date, this.vt('without fixed date'))
			const role = formatTelosSummaryValue(telos.role, this.vt('Learner'))
			const strengths = formatTelosSummaryValue(telos.strengths, this.vt('still building up'))
			const weaknesses = formatTelosSummaryValue(telos.weaknesses, this.vt('no focus topic yet'))
			const learningStyle = formatTelosSummaryValue(telos.learning_style, this.vt('Mixed'))
			return {
				title: this.vt('Profile saved'),
				text: this.vt('Your learning profile is set up.') + `\n\n${role}\n` + this.vt('Goal') + `: ${targetCert} ` + this.vt('by') + ` ${targetDate}\n` + this.vt('Strong') + `: ${strengths}\n` + this.vt('Practice') + `: ${weaknesses}\n${formatTelosHours(telos.hours_per_week, this.vt)}\n${learningStyle}\n\n` + this.vt('My tips are now tailored to you. Want a tour of the app, or start learning right away?'),
				hideMoreOptions: true,
				showIntroInline: true,
				renderActionsInline: true,
				disableSuggestions: true,
				actions: [
					{ label: this.vt('App-Tour'), type: 'start-app-tour' },
					{ label: this.vt('Direkt lernen'), type: 'close-help' },
				],
			}
		},
	},
}
