/**
 * GitHub #65 (review after `comments: false`): with template comments gone from the bundle,
 * PbqRenderer became a single-root component in production. It emitted change/submit/skip
 * without an `emits` option, so the parents' listeners sat in `$attrs` and fell through to the
 * root <div> as native DOM listeners. A bubbling native `change` from an inner <select> called
 * ExamMode's @change a second time, now with the Event, and the PBQ answer sent with the exam
 * became {"isTrusted":true}. The dev runtime Vitest uses lets listeners fall through the same way,
 * so the regression test below needs no production build.
 *
 * The gate: every component whose root is now a single element turns each undeclared emit into a
 * native listener on that root. vue/require-explicit-emits is run over src/ (through ESLint's API,
 * with the project config) and compared with the baseline of components that already were single
 * roots before #65 and still emit undeclared events. The baseline may only shrink: a new offender
 * fails, and an entry that no longer offends must be removed.
 */
import { describe, it, expect, vi, afterEach } from 'vitest'
import { createApp, h, nextTick, reactive, ref } from 'vue'
import { createPinia } from 'pinia'
import { ESLint } from 'eslint'
import { join } from 'path'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		emits: ['click'],
		props: { disabled: Boolean },
		render() { return h('button', { class: 'nc-btn', disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) },
	},
}))

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../src/utils/character-animations.js', async (importOriginal) => ({
	...(await importOriginal()),
	playWave: vi.fn(),
}))

globalThis.t = (app, text) => text

import PbqRenderer from '../../src/components/PbqRenderer.vue'
import KosmologeAvatar from '../../src/components/KosmologeAvatar.vue'
import TheoretikerAvatar from '../../src/components/TheoretikerAvatar.vue'
import ProfLernAvatar from '../../src/components/ProfLernAvatar.vue'
import PopularisiererAvatar from '../../src/components/PopularisiererAvatar.vue'
import NovaAvatar from '../../src/components/nova/NovaAvatar.vue'
import NovaPanel from '../../src/components/nova/NovaPanel.vue'
import DauBotDialog from '../../src/components/DauBotDialog.vue'
import QuestionLanguageSwitcher from '../../src/components/QuestionLanguageSwitcher.vue'
import VideoPlayer from '../../src/components/VideoPlayer.vue'

const APP = join(__dirname, '../..')
const RULE = 'vue/require-explicit-emits'

/**
 * Components that emit events they do not declare, path relative to src/ → event names.
 * Pre-existing (single roots already before #65); may only shrink. Never add an entry: declare
 * the event in `emits` instead.
 */
const UNDECLARED_EMITS_BASELINE = {
	'components/AIGenerator.vue': ['close', 'imported'],
	'components/AnalyticsDashboard.vue': ['back', 'basis'],
	'components/AuthFlowSimulator.vue': ['result'],
	'components/CampaignIntro.vue': ['done'],
	'components/CourseDetail.vue': ['back', 'clearPresetDuel', 'open-tool', 'openPool', 'selectStudent'],
	'components/CourseList.vue': ['selectCourse'],
	'components/CourseTabLernraum.vue': ['all-pools-loaded', 'course-patched', 'error', 'gate-updated', 'knowledge-pending-count', 'mode-activated', 'openPool', 'pool-selected', 'refresh-course-detail', 'tab-change', 'videos-available'],
	'components/CourseTabTeilnehmer.vue': ['error', 'members-changed', 'selectStudent', 'tab-change'],
	'components/CourseTabVerwaltung.vue': ['error', 'refresh-course-detail', 'tab-change'],
	'components/CourseTabWettbewerb.vue': ['arena-sub-mode', 'error', 'preset-consumed', 'selectStudent', 'tab-change'],
	'components/DnsResolver.vue': ['result'],
	'components/DuelMode.vue': ['back', 'preset-consumed'],
	'components/FirewallBuilder.vue': ['result'],
	'components/GameTimer.vue': ['expired'],
	'components/GameshowMode.vue': ['back'],
	'components/ImportDialog.vue': ['close', 'imported'],
	'components/InstructorDashboard.vue': ['selectCourse'],
	'components/KnowledgeModeration.vue': ['pending-count'],
	'components/LeitnerMode.vue': ['back'],
	'components/NatTable.vue': ['result'],
	'components/OnboardingIntro.vue': ['done'],
	'components/PbqAuthorTool.vue': ['apply'],
	'components/PbqCable.vue': ['update'],
	'components/PbqCli.vue': ['update'],
	'components/PbqDiagnostic.vue': ['update'],
	'components/PbqDropdown.vue': ['update'],
	'components/PbqMultiPanel.vue': ['update'],
	'components/PbqPlacement.vue': ['update'],
	'components/PbqRanking.vue': ['update'],
	'components/PbqRoutingConfig.vue': ['update'],
	'components/PbqSwitchConfig.vue': ['update'],
	'components/PoolList.vue': ['openRemediation', 'openSmartQueue', 'selectPool'],
	'components/PortScanner.vue': ['result'],
	'components/QuestMap.vue': ['close', 'navigate'],
	'components/QuestionForm.vue': ['close', 'save'],
	'components/RoutingTable.vue': ['result'],
	'components/ShareDialog.vue': ['close'],
	'components/SimulatorShell.vue': ['complete'],
	'components/SkillMap.vue': ['openPool'],
	'components/SmartQueue.vue': ['back'],
	'components/StudentDashboard.vue': ['openRemediation', 'openSmartQueue', 'switchView'],
	'components/StudentDetail.vue': ['back'],
	'components/SubnetCalculator.vue': ['result'],
	'components/TerminalPuzzle.vue': ['result'],
	'components/TrainingMode.vue': ['back', 'manage'],
	'components/TranslationDialog.vue': ['close', 'saved'],
	'components/VirtuProf.vue': ['ai-enabled-change', 'close-fullscreen', 'enabled-change', 'open-duel', 'open-fullscreen', 'ready'],
	'components/VirtuProfBubble.vue': ['action', 'chat-send', 'dismiss', 'next', 'open-fullscreen', 'report-error', 'update:ticketDraft', 'update:ticketSubject'],
	'components/VirtuProfFullscreen.vue': ['close', 'report-error', 'send'],
	'components/WiresharkLite.vue': ['result'],
}

async function undeclaredEmits() {
	const eslint = new ESLint({
		cwd: APP,
		overrideConfig: { rules: { [RULE]: 'error' } },
		ruleFilter: ({ ruleId }) => ruleId === RULE,
	})
	const results = await eslint.lintFiles(['src/**/*.vue'])
	const found = {}
	for (const result of results) {
		const events = [...new Set(result.messages
			.filter((message) => message.ruleId === RULE)
			.map((message) => message.message.match(/"(.+?)"/)?.[1] ?? message.message))].sort()
		if (events.length) {
			found[result.filePath.slice(join(APP, 'src').length + 1)] = events
		}
	}
	return found
}

describe('declared emits (GitHub #65)', () => {
	it('no component emits an undeclared event outside the shrinking baseline', async () => {
		const found = await undeclaredEmits()
		const grown = {}
		for (const [file, events] of Object.entries(found)) {
			const allowed = UNDECLARED_EMITS_BASELINE[file] || []
			const extra = events.filter((event) => !allowed.includes(event))
			if (extra.length) {
				grown[file] = extra
			}
		}
		expect(grown).toEqual({})

		const stale = {}
		for (const [file, events] of Object.entries(UNDECLARED_EMITS_BASELINE)) {
			const gone = events.filter((event) => !(found[file] || []).includes(event))
			if (gone.length) {
				stale[file] = gone
			}
		}
		expect(stale, 'declared now: remove these from the baseline').toEqual({})
	}, 60000)

	it('PbqRenderer declares change, submit and skip', () => {
		expect(PbqRenderer.emits).toEqual(expect.arrayContaining(['change', 'submit', 'skip']))
	})
})

describe('PbqRenderer inside a parent listening to @change (GitHub #65)', () => {
	let app = null
	let host = null

	afterEach(() => {
		app?.unmount()
		host?.remove()
		app = null
		host = null
	})

	function mountLikeExamMode() {
		const state = reactive({ calls: [], submits: [], skips: 0 })
		const question = {
			id: 7,
			question_type: 'pbq',
			pbq_subtype: 'dropdown',
			pbq_config: { questions: [{ id: 'q1', label: 'Port', options: ['22', '443'] }] },
		}
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({
			render() {
				return h(PbqRenderer, {
					question,
					onChange: (ans) => { state.calls.push(ans) },
					onSubmit: (ans) => { state.submits.push(ans) },
					onSkip: () => { state.skips += 1 },
				})
			},
		})
		app.config.globalProperties.t = globalThis.t
		app.mount(host)
		return state
	}

	it('a bubbling native change on the inner select reaches the parent once, with the answer', async () => {
		const state = mountLikeExamMode()
		const select = host.querySelector('select.pbq-dd-select')
		select.value = '443'
		select.dispatchEvent(new Event('change', { bubbles: true }))
		await nextTick()

		expect(state.calls).toHaveLength(1)
		expect(state.calls[0]).not.toBeInstanceOf(Event)
		expect(state.calls[0]).toEqual({ q1: '443' })
	})

	it('no native listener for change, submit or skip sits on the root element', async () => {
		const state = mountLikeExamMode()
		const root = host.querySelector('.pbq-renderer')
		root.dispatchEvent(new Event('change', { bubbles: true }))
		root.dispatchEvent(new Event('submit', { bubbles: true }))
		root.dispatchEvent(new Event('skip', { bubbles: true }))
		await nextTick()

		expect(state.calls).toEqual([])
		expect(state.submits).toEqual([])
		expect(state.skips).toBe(0)
	})
})

/**
 * U9 (after C8): the same fall-through for components whose event names are native DOM events.
 * Each test mounts the real component under a parent listener and fails against the undeclared
 * version, where the parent's handler sits on the root element as a native listener as well.
 */
describe('components emitting native DOM event names (GitHub #65)', () => {
	let app = null
	let host = null

	afterEach(() => {
		app?.unmount()
		host?.remove()
		app = null
		host = null
	})

	function mount(render) {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render })
		app.use(createPinia())
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = (a, s, p, c) => (c === 1 ? s : p)
		app.mount(host)
	}

	const AVATARS = [
		['KosmologeAvatar', KosmologeAvatar, '.kosmologe-avatar-wrapper'],
		['TheoretikerAvatar', TheoretikerAvatar, '.theoretiker-avatar-wrapper'],
		['ProfLernAvatar', ProfLernAvatar, '.virtuprof-avatar-wrapper'],
		['PopularisiererAvatar', PopularisiererAvatar, '.popularisierer-avatar-wrapper'],
		['NovaAvatar', NovaAvatar, '.nova-avatar-wrapper'],
	]

	it.each(AVATARS)('%s: a click reaches a parent @click once, without the DOM event', async (name, Avatar, rootSelector) => {
		const calls = []
		mount(() => h(Avatar, { onClick: (...args) => { calls.push(args) } }))
		host.querySelector(rootSelector).dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await nextTick()

		expect(Avatar.emits).toContain('click')
		expect(calls).toHaveLength(1)
		expect(calls[0][0]).not.toBeInstanceOf(Event)
	})

	it('NovaPanel: one touchstart/touchend runs VirtuProf\'s handler once each', async () => {
		const starts = []
		const ends = []
		mount(() => h(NovaPanel, {
			title: 'VirtuProf',
			onTouchstart: (e) => { starts.push(e) },
			onTouchend: (e) => { ends.push(e) },
		}))
		const root = host.querySelector('.virtuprof-panel')
		root.dispatchEvent(new Event('touchstart', { bubbles: true }))
		root.dispatchEvent(new Event('touchend', { bubbles: true }))
		await nextTick()

		expect(starts).toHaveLength(1)
		expect(ends).toHaveLength(1)
	})

	it('DauBotDialog: a native submit on the root does not reach AbenteuerMode\'s @submit; a chosen fix does, once', async () => {
		const submits = []
		mount(() => h(DauBotDialog, {
			scenario: {
				bot_action: 'Klaus pulled the cable',
				symptom: 'No network',
				error_options: [{ id: 'e1', label: 'Cable unplugged' }],
				fix_options: [{ id: 'f1', label: 'Plug it back in' }],
			},
			onSubmit: (payload) => { submits.push(payload) },
		}))
		const root = host.querySelector('.daubot-dialog')
		root.dispatchEvent(new Event('submit', { bubbles: true }))
		await nextTick()
		expect(submits).toEqual([])

		host.querySelector('.daubot-option').click()
		await nextTick()
		host.querySelector('.daubot-option').click()
		await nextTick()
		expect(submits).toEqual([{ errorId: 'e1', fixId: 'f1' }])
		expect(DauBotDialog.emits).toEqual(expect.arrayContaining(['submit', 'result-applied']))
	})

	it('QuestionLanguageSwitcher: v-model in a parent switches the language and marks the pressed button', async () => {
		const language = ref('')
		const question = { id: 1, translations: ['en'] }
		mount(() => h(QuestionLanguageSwitcher, {
			question,
			modelValue: language.value,
			'onUpdate:modelValue': (value) => { language.value = value },
		}))
		const buttons = [...host.querySelectorAll('.lang-btn')]
		expect(buttons.map((b) => b.textContent.trim())).toEqual(['DE', 'EN'])
		expect(buttons[0].getAttribute('aria-pressed')).toBe('true')

		buttons[1].click()
		await nextTick()

		expect(language.value).toBe('en')
		expect(host.querySelector('.question-language-switcher').hasAttribute('modelvalue')).toBe(false)
		const after = [...host.querySelectorAll('.lang-btn')]
		expect(after[1].getAttribute('aria-pressed')).toBe('true')
		expect(after[0].getAttribute('aria-pressed')).toBe('false')
	})

	it('VideoPlayer declares the completed and progress events its heartbeat mixin emits', () => {
		// vue/require-explicit-emits does not see $emit calls inside a mixin, so the baseline gate
		// above cannot catch this component.
		expect(VideoPlayer.emits).toEqual(expect.arrayContaining(['completed', 'progress']))
	})
})
