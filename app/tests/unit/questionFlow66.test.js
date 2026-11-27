/**
 * GitHub #66, Andre's audio test of 18.11. (points 1–7): the question as a mini slideshow in the
 * same card — intro first (slides in time with the sound), the question and its answers hidden
 * until the intro ends (uncovered by hover, keyboard focus or a tap, no button), "Automatisch
 * abspielen" and "Töne" per user, after answering the voice of the chosen option and then the
 * dialog, a synthesised "Pling"/"Zonk". Exams are untouched; questions without media as before.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

vi.setConfig({ testTimeout: 20000 })

vi.hoisted(() => {
	globalThis.t = (_app, text, vars) => String(text).replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p).replace('%n', String(c))
})
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text) => text,
	t: (_app, text, vars) => String(text).replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m)),
	getLanguage: () => 'de',
	isRTL: () => false,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params) => url.replace(/{(\w+)}/g, (m, k) => (params && k in params ? String(params[k]) : m))),
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
const { withProps } = vi.hoisted(() => ({
	withProps: (name, props, template) => ({ default: { name, props, template } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => withProps('NcButton', ['variant', 'disabled'], '<button type="button" :disabled="disabled"><slot /></button>'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => withProps('NcNoteCard', ['type'], '<div class="nc-note" :data-type="type"><slot /></div>'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => withProps('NcEmptyContent', ['name', 'description'], '<div class="nc-empty">{{ description }}</div>'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => withProps('NcProgressBar', ['value'], '<div />'))
vi.mock('../../src/components/AccessibleDialog.vue', () => withProps('AccessibleDialog', ['name', 'size'], '<div class="dialog"><slot /><slot name="actions" /></div>'))

import axios from '@nextcloud/axios'
import QuestionMediaPlayer from '../../src/components/QuestionMediaPlayer.vue'
import QuestionConceal, { HOVER_DWELL_MS } from '../../src/components/QuestionConceal.vue'
import { optionSegments } from '../../src/utils/questionMediaPlayer.js'
import { answerSoundNotes, playAnswerSound, resetAnswerSoundsForTests, unlockAnswerSounds } from '../../src/utils/answerSounds.js'
import { soundsOn, useQuestionMediaPrefsStore } from '../../src/stores/questionMediaPrefsStore.js'
import { useA11yStore } from '../../src/stores/a11yStore.js'

const here = dirname(fileURLToPath(import.meta.url))

const INTRO = (lang = 'de') => ({
	id: 10, kind: 'intro', lang, duration_ms: 4000,
	timeline: [
		{ role: 'NARRATOR', text: 'Ein Labor am Morgen.', start: 0, end: 2 },
		{ role: 'NARRATOR', text: 'Zwei Farben stehen bereit.', start: 2, end: 4 },
	],
	url: '/index.php/apps/learning/api/question-media/intro-' + lang,
})
const DIALOG = { id: 20, kind: 'dialog', lang: 'de', duration_ms: 3000, timeline: [{ role: 'ANNA', text: 'Gemischt ergibt das Grün.', start: 0, end: 3 }], url: '/media/dialog-de?grant=g' }
const OPT = (answerId, position) => ({
	answer_id: answerId,
	position,
	tracks: [{ id: 30 + position, kind: 'opt' + position, lang: 'en', duration_ms: 1000, timeline: [{ role: 'MARA', text: 'Option ' + position + ' explained.', start: 0, end: 1 }], url: '/media/opt' + position + '-en?grant=g' }],
})
const REVEAL = { tracks: [DIALOG], cues: null, option_tracks: [OPT(1, 0), OPT(2, 1)] }

let host, app, playSpy, audioCtx

async function flush() {
	for (let i = 0; i < 6; i++) {
		await Promise.resolve()
		await nextTick()
	}
}

async function mount(Component, props = {}, { pinia = null, listeners = {}, slot = null } = {}) {
	host = document.createElement('div')
	document.body.appendChild(host)
	const state = { props }
	app = createApp({ render: () => h(Component, { ...state.props, ...listeners, ref: 'c' }, slot ? { default: slot } : undefined) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	if (pinia) {
		app.use(pinia)
	}
	const root = app.mount(host)
	await flush()
	return { root, state }
}

function sleep(ms) {
	return new Promise((done) => setTimeout(done, ms))
}

// jsdom has no PointerEvent: a plain event with the pointer type set
function pointer(type, pointerType = 'mouse') {
	const event = new Event(type, { bubbles: type === 'pointermove' })
	Object.defineProperty(event, 'pointerType', { value: pointerType })
	return event
}

function buttonByText(text) {
	return [...host.querySelectorAll('button')].find((b) => b.textContent.includes(text))
}

function fakeAudioContext() {
	const created = []
	class FakeContext {
		constructor() {
			this.state = 'running'
			this.currentTime = 0
			this.destination = {}
			created.push(this)
			this.oscillators = []
		}

		createOscillator() {
			const osc = { type: '', frequency: { setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() }, connect: vi.fn(), start: vi.fn(), stop: vi.fn() }
			this.oscillators.push(osc)
			return osc
		}

		createGain() {
			return { gain: { setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() }, connect: vi.fn() }
		}

		resume() { return Promise.resolve() }
	}
	return { FakeContext, created }
}

beforeEach(() => {
	document.body.innerHTML = ''
	vi.clearAllMocks()
	setActivePinia(undefined)
	resetAnswerSoundsForTests()
	window.matchMedia = vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
	playSpy = vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function () { return Promise.resolve() })
	vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {})
	vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => {})
	audioCtx = fakeAudioContext()
	window.AudioContext = audioCtx.FakeContext
	axios.get.mockResolvedValue({ data: { question_id: 5, tracks: [INTRO()] } })
	axios.put.mockResolvedValue({ data: {} })
})
afterEach(() => {
	app?.unmount()
	app = null
	delete window.AudioContext
	vi.restoreAllMocks()
})

function piniaWith(prefs = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useQuestionMediaPrefsStore(pinia)
	Object.assign(store, prefs)
	return { pinia, store }
}

describe('preferences: autoplay off by default, tones follow it until chosen', () => {
	it('soundsOn: auto follows autoplay, yes/no are explicit', () => {
		expect(soundsOn({ autoplay: false, sounds: 'auto' })).toBe(false)
		expect(soundsOn({ autoplay: true, sounds: 'auto' })).toBe(true)
		expect(soundsOn({ autoplay: true, sounds: 'no' })).toBe(false)
		expect(soundsOn({ autoplay: false, sounds: 'yes' })).toBe(true)
	})

	it('the store reads the personal settings and saves a switch on the server, back on failure', async () => {
		const { store } = piniaWith()
		expect(store.autoplay).toBe(false)
		expect(store.sounds).toBe('auto')
		store.loadFromServerPayload({ question_media_autoplay: 'yes', answer_sounds: 'no' })
		expect(store.autoplay).toBe(true)
		expect(store.sounds).toBe('no')

		axios.put.mockResolvedValueOnce({ data: { question_media_autoplay: 'no', answer_sounds: 'no' } })
		expect(await store.save({ autoplay: false })).toBe(true)
		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/settings/personal/question-media', { autoplay: false })
		expect(store.autoplay).toBe(false)

		axios.put.mockRejectedValueOnce(new Error('500'))
		expect(await store.save({ sounds: 'yes' })).toBe(false)
		expect(store.sounds).toBe('no')
	})

	it('App.vue hydrates the store from the personal settings it loads anyway', () => {
		const source = readFileSync(resolve(here, '../../src/App.vue'), 'utf-8')
		expect(source).toContain('useOptionalQuestionMediaPrefsStore()?.loadFromServerPayload(response.data)')
	})
})

describe('answer tones', () => {
	it('"Pling" goes up, "Zonk" goes down', () => {
		const pling = answerSoundNotes(true)
		const zonk = answerSoundNotes(false)
		expect(pling[1].from).toBeGreaterThan(pling[0].from)
		expect(zonk.every((note) => note.to < note.from)).toBe(true)
	})

	it('are synthesised with Web Audio and fail silently without it', () => {
		expect(unlockAnswerSounds()).not.toBeNull()
		expect(playAnswerSound(true)).toBe(true)
		expect(audioCtx.created).toHaveLength(1)
		expect(audioCtx.created[0].oscillators).toHaveLength(2)
		expect(audioCtx.created[0].oscillators[0].start).toHaveBeenCalled()

		resetAnswerSoundsForTests()
		delete window.AudioContext
		expect(unlockAnswerSounds()).toBeNull()
		expect(playAnswerSound(false)).toBe(false)
	})
})

describe('option voices: only those of the chosen answers, in answer order', () => {
	it('picks well-formed option tracks of chosen answers only', () => {
		expect(optionSegments(REVEAL.option_tracks, [2]).map((tracks) => tracks[0].kind)).toEqual(['opt1'])
		expect(optionSegments(REVEAL.option_tracks, [2, 1]).map((tracks) => tracks[0].kind)).toEqual(['opt0', 'opt1'])
		expect(optionSegments(REVEAL.option_tracks, [])).toEqual([])
		expect(optionSegments([{ answer_id: 1, tracks: [{ kind: 'dialog', lang: 'en', url: '/x' }] }], [1])).toEqual([])
		expect(optionSegments(null, [1])).toEqual([])
	})
})

describe('QuestionMediaPlayer: the intro keeps the question hidden until it ends', () => {
	async function mountIntro(props = {}, options = {}) {
		const events = []
		await mount(QuestionMediaPlayer, { questionId: 5, answered: false, ...props }, { ...options, listeners: { onConceal: (value) => events.push(value) } })
		return events
	}

	it('a spoken intro asks to conceal; its end lets the question go, for good', async () => {
		const events = await mountIntro()
		expect(events.at(-1)).toBe(true)
		// the intro is shown as slides: a cover first, one slide per line with progress dots
		expect(host.querySelector('.qmedia__cover').textContent).toContain('2 Zeilen')
		expect(host.querySelectorAll('.qmedia__progress li')).toHaveLength(2)

		buttonByText('Abspielen').click()
		await flush()
		expect(events.at(-1)).toBe(true)
		host.querySelector('audio').dispatchEvent(new Event('ended'))
		await flush()
		expect(events.at(-1)).toBe(false)

		buttonByText('Von vorn').click()
		buttonByText('Abspielen').click()
		await flush()
		expect(events.at(-1)).toBe(false)
	})

	it('stepping through the intro to its end lets the question go as well', async () => {
		const events = await mountIntro()
		buttonByText('Nächste Zeile').click()
		await flush()
		expect(host.querySelector('.qmedia__slide').textContent).toContain('Ein Labor am Morgen.')
		expect(events.at(-1)).toBe(true)
		buttonByText('Nächste Zeile').click()
		buttonByText('Nächste Zeile').click()
		await flush()
		expect(events.at(-1)).toBe(false)
	})

	it('an audio error shows the question at once', async () => {
		const events = await mountIntro()
		buttonByText('Abspielen').click()
		await flush()
		host.querySelector('audio').dispatchEvent(new Event('error'))
		await flush()
		expect(events.at(-1)).toBe(false)
	})

	it('no intro, or an intro without lines: nothing is concealed (questions without media as before)', async () => {
		axios.get.mockResolvedValue({ data: { question_id: 5, tracks: [] } })
		let events = await mountIntro()
		expect(events.includes(true)).toBe(false)
		app.unmount()

		axios.get.mockResolvedValue({ data: { question_id: 5, tracks: [{ ...INTRO(), timeline: [] }] } })
		events = await mountIntro()
		expect(events.includes(true)).toBe(false)
	})

	it('a new question with an intro conceals again', async () => {
		const events = []
		const { state, root } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { listeners: { onConceal: (value) => events.push(value) } })
		buttonByText('Abspielen').click()
		await flush()
		host.querySelector('audio').dispatchEvent(new Event('ended'))
		await flush()
		expect(events.at(-1)).toBe(false)
		state.props = { questionId: 6, answered: false }
		root.$forceUpdate()
		await flush()
		expect(events.at(-1)).toBe(true)
	})
})

describe('QuestionMediaPlayer: "Automatisch abspielen"', () => {
	it('off (the default): nothing plays by itself', async () => {
		piniaWith()
		await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { pinia: piniaWith().pinia })
		expect(playSpy).not.toHaveBeenCalled()
		expect(host.querySelector('input[role="switch"]').checked).toBe(false)
	})

	it('on: the intro starts when the question is shown', async () => {
		const { pinia } = piniaWith({ autoplay: true })
		await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { pinia })
		expect(playSpy).toHaveBeenCalledTimes(1)
		expect(host.querySelector('audio').getAttribute('src')).toBe('/index.php/apps/learning/api/question-media/intro-de')
	})

	it('a refused autoplay (Safari/iOS) falls back to the play button silently and shows the question', async () => {
		playSpy.mockImplementation(() => Promise.reject(Object.assign(new Error('no gesture'), { name: 'NotAllowedError' })))
		const { pinia } = piniaWith({ autoplay: true })
		const events = []
		await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { pinia, listeners: { onConceal: (value) => events.push(value) } })
		expect(events.at(-1)).toBe(false)
		expect(host.querySelector('.qmedia__note').textContent.trim()).toBe('')
		expect(buttonByText('Abspielen').disabled).toBe(false)
	})

	it('switching it on stores it per user and starts the intro (the click is the gesture)', async () => {
		const { pinia, store } = piniaWith()
		await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { pinia })
		const toggle = host.querySelector('input[role="switch"]')
		toggle.checked = true
		toggle.dispatchEvent(new Event('change'))
		await flush()
		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/settings/personal/question-media', { autoplay: true })
		expect(store.autoplay).toBe(true)
		expect(playSpy).toHaveBeenCalledTimes(1)
	})

	it('after answering: the voice of the chosen option, then the dialog', async () => {
		const { pinia } = piniaWith({ autoplay: true, sounds: 'no' })
		const { state, root } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { pinia })
		playSpy.mockClear()
		state.props = { questionId: 5, answered: true, reveal: REVEAL, answerCorrect: false, selectedAnswerIds: [2] }
		root.$forceUpdate()
		await flush()
		const audio = host.querySelector('audio')
		expect(host.querySelector('.qmedia__title').textContent).toContain('Zu deiner Antwort')
		expect(audio.getAttribute('src')).toBe('/media/opt1-en?grant=g')
		expect(playSpy).toHaveBeenCalledTimes(1)

		audio.dispatchEvent(new Event('ended'))
		await flush()
		expect(host.querySelector('.qmedia__title').textContent).toContain('Nachbesprechung')
		expect(audio.getAttribute('src')).toBe('/media/dialog-de?grant=g')
		expect(playSpy).toHaveBeenCalledTimes(2)
	})

	it('with a tone, the option voice waits until the tone is over, and a new question cancels it', async () => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
		try {
			const { pinia } = piniaWith({ autoplay: true })
			const { state, root } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { pinia })
			playSpy.mockClear()
			state.props = { questionId: 5, answered: true, reveal: REVEAL, answerCorrect: true, selectedAnswerIds: [1] }
			root.$forceUpdate()
			await flush()
			expect(audioCtx.created).toHaveLength(1)
			expect(playSpy).not.toHaveBeenCalled()
			vi.advanceTimersByTime(450)
			await flush()
			expect(playSpy).toHaveBeenCalledTimes(1)
			expect(host.querySelector('audio').getAttribute('src')).toBe('/media/opt0-en?grant=g')

			// answered, then straight on to the next question: the pending start never fires
			state.props = { questionId: 6, answered: false }
			root.$forceUpdate()
			await flush()
			state.props = { questionId: 6, answered: true, reveal: REVEAL, answerCorrect: false, selectedAnswerIds: [1] }
			root.$forceUpdate()
			await flush()
			state.props = { questionId: 7, answered: false }
			root.$forceUpdate()
			await flush()
			playSpy.mockClear()
			axios.get.mockResolvedValue({ data: { question_id: 7, tracks: [] } })
			vi.advanceTimersByTime(1000)
			await flush()
			expect(host.querySelector('audio')?.getAttribute('src') ?? null).not.toBe('/media/opt0-en?grant=g')
		} finally {
			vi.useRealTimers()
		}
	})

	it('without autoplay the parts are there to play or step through: past the option voice comes the dialog', async () => {
		const { state, root } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false })
		state.props = { questionId: 5, answered: true, reveal: REVEAL, answerCorrect: true, selectedAnswerIds: [1] }
		root.$forceUpdate()
		await flush()
		expect(playSpy).not.toHaveBeenCalled()
		expect(host.querySelector('.qmedia__title').textContent).toContain('Zu deiner Antwort')
		buttonByText('Nächste Zeile').click()
		buttonByText('Nächste Zeile').click()
		await flush()
		expect(host.querySelector('.qmedia__title').textContent).toContain('Nachbesprechung')
	})

	it('the next question stops a running sound', async () => {
		const pause = HTMLMediaElement.prototype.pause
		const { pinia } = piniaWith({ autoplay: true })
		const { state, root } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { pinia })
		pause.mockClear()
		state.props = { questionId: 6, answered: false }
		root.$forceUpdate()
		await flush()
		expect(pause).toHaveBeenCalled()
	})
})

describe('QuestionMediaPlayer: "Töne"', () => {
	async function answer(prefs, correct, { restore = false } = {}) {
		const { pinia } = piniaWith(prefs)
		const { state, root } = await mount(QuestionMediaPlayer, { questionId: 5, answered: restore }, { pinia })
		state.props = { questionId: restore ? 6 : 5, answered: true, reveal: REVEAL, answerCorrect: correct, selectedAnswerIds: [1] }
		root.$forceUpdate()
		await flush()
	}

	it('off by default (autoplay off): no tone', async () => {
		await answer({}, true)
		expect(audioCtx.created).toHaveLength(0)
	})

	it('on with autoplay: "Pling" for a right answer', async () => {
		await answer({ autoplay: true }, true)
		expect(audioCtx.created).toHaveLength(1)
		expect(audioCtx.created[0].oscillators[0].type).toBe('sine')
	})

	it('chosen explicitly without autoplay: "Zonk" for a wrong answer', async () => {
		await answer({ sounds: 'yes' }, false)
		expect(audioCtx.created[0].oscillators[0].type).toBe('square')
	})

	it('switched off explicitly: silent even with autoplay', async () => {
		await answer({ autoplay: true, sounds: 'no' }, true)
		expect(audioCtx.created).toHaveLength(0)
	})

	it('jumping back to an answered question is silent', async () => {
		await answer({ sounds: 'yes', autoplay: true }, true, { restore: true })
		expect(audioCtx.created).toHaveLength(0)
		expect(playSpy).not.toHaveBeenCalled()
	})

	it('the tones switch stores an explicit choice', async () => {
		const { pinia, store } = piniaWith({ autoplay: true })
		await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { pinia })
		const toggle = host.querySelectorAll('input[role="switch"]')[1]
		expect(toggle.checked).toBe(true)
		toggle.checked = false
		toggle.dispatchEvent(new Event('change'))
		await flush()
		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/settings/personal/question-media', { sounds: 'no' })
		expect(store.sounds).toBe('no')
	})
})

describe('QuestionConceal: hidden in place, uncovered by hover, focus or tap — no button', () => {
	const slot = () => h('div', [h('p', { class: 'q' }, 'Welche Farbe?'), h('button', { class: 'answer' }, 'Grün')])

	it('without an intro it is a plain wrapper', async () => {
		await mount(QuestionConceal, { conceal: false, questionKey: 1 }, { slot })
		const el = host.querySelector('.qconceal')
		expect(el.getAttribute('tabindex')).toBeNull()
		expect(el.getAttribute('role')).toBeNull()
		expect(host.querySelector('.qconceal__content').hasAttribute('inert')).toBe(false)
		expect(host.querySelector('.qconceal__veil')).toBeNull()
	})

	it('while concealed: a named Tab stop, the answers inert and hidden from screen readers', async () => {
		await mount(QuestionConceal, { conceal: true, questionKey: 1 }, { slot })
		const el = host.querySelector('.qconceal')
		expect(el.classList.contains('qconceal--hidden')).toBe(true)
		expect(el.getAttribute('tabindex')).toBe('0')
		// the visible text says how to uncover it now, not only to wait (autoplay is off by default),
		// and it is the button's name (WCAG 2.5.3: the visible label is part of the accessible name)
		const veil = host.querySelector('.qconceal__veil')
		expect(veil.textContent.trim()).toBe('Die Frage erscheint nach dem Einstieg – oder sofort, wenn du hierher zeigst oder tippst.')
		expect(el.hasAttribute('aria-label')).toBe(false)
		expect(veil.id).toMatch(/^qconceal-veil-\d+$/)
		expect(el.getAttribute('aria-labelledby')).toBe(veil.id)
		expect(host.querySelectorAll('#' + veil.id)).toHaveLength(1)
		expect(el.getAttribute('aria-expanded')).toBe('false')
		const content = host.querySelector('.qconceal__content')
		expect(content.hasAttribute('inert')).toBe(true)
		expect(content.getAttribute('aria-hidden')).toBe('true')
		// the space stays: the content is rendered, only invisible
		expect(content.querySelector('.answer')).not.toBeNull()
		expect(host.querySelectorAll('button')).toHaveLength(1)
	})

	for (const [name, fire] of [
		['hovering', async (el) => { el.dispatchEvent(pointer('pointermove')); await sleep(HOVER_DWELL_MS + 60) }],
		['keyboard focus', (el) => el.focus()],
		['a tap', (el) => el.click()],
		['Enter', (el) => el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))],
	]) {
		it(`${name} uncovers it for good, and the focus stays where it was`, async () => {
			const { state, root } = await mount(QuestionConceal, { conceal: true, questionKey: 1 }, { slot })
			const el = host.querySelector('.qconceal')
			// Vue drops an event stamped in the very millisecond its listener was attached (its
			// guard against events that caused the render); a real key press is never that fast.
			await new Promise((done) => setTimeout(done, 5))
			await fire(el)
			await flush()
			expect(el.classList.contains('qconceal--hidden')).toBe(false)
			expect(el.classList.contains('qconceal--shown')).toBe(true)
			expect(host.querySelector('.qconceal__content').hasAttribute('inert')).toBe(false)
			// still focusable (-1): a focused area does not drop the focus to <body>
			expect(el.getAttribute('tabindex')).toBe('-1')
			if (name === 'keyboard focus') {
				expect(document.activeElement).toBe(el)
			}
			// the intro ending later changes nothing; the next question starts covered again
			state.props = { conceal: false, questionKey: 1 }
			root.$forceUpdate()
			await flush()
			state.props = { conceal: true, questionKey: 2 }
			root.$forceUpdate()
			await flush()
			expect(el.classList.contains('qconceal--hidden')).toBe(true)
		})
	}

	/**
	 * Review of #66: the area stays in place from one question to the next (no :key), and
	 * "Nächste Frage" sits inside it. Uncovering only on mouseenter meant a pointer already inside
	 * the next hidden question could move as it liked — no enter, no uncovering; and a mouse
	 * crossing the area on its way to the player above uncovered the question before the intro.
	 */
	it('after "Nächste Frage" a pointer resting inside the next hidden question uncovers it', async () => {
		const { state, root } = await mount(QuestionConceal, { conceal: true, questionKey: 1 }, { slot })
		const el = host.querySelector('.qconceal')
		// Vue drops an event stamped in the millisecond its listener was attached (see above)
		await sleep(5)
		el.dispatchEvent(new MouseEvent('mouseenter'))
		el.click()
		await flush()
		expect(el.classList.contains('qconceal--hidden')).toBe(false)
		// the next question, the pointer still inside the same element: no new mouseenter
		state.props = { conceal: true, questionKey: 2 }
		root.$forceUpdate()
		await flush()
		expect(el.classList.contains('qconceal--hidden')).toBe(true)
		el.dispatchEvent(pointer('pointermove'))
		el.dispatchEvent(pointer('pointermove'))
		await flush()
		expect(el.classList.contains('qconceal--hidden')).toBe(true)
		await sleep(HOVER_DWELL_MS + 60)
		await flush()
		expect(el.classList.contains('qconceal--hidden')).toBe(false)
	})

	/**
	 * Persona flow station (review of #66): pressing "Nächste Frage" uncovered the next question at
	 * once. The button sits inside the area; a real browser runs Vue's render between the button's
	 * listener and the area's, so the same click reached the area with the next question already
	 * hidden. Emulated here by a click and an Enter bubbling up from inside the hidden question.
	 */
	it('a click or Enter bubbling up from a control inside (the "Nächste Frage" press) does not uncover the next question', async () => {
		const { state, root } = await mount(QuestionConceal, { conceal: false, questionKey: 1 }, { slot })
		state.props = { conceal: true, questionKey: 2 }
		root.$forceUpdate()
		await flush()
		await sleep(5)
		const el = host.querySelector('.qconceal')
		const inner = host.querySelector('.answer')
		inner.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		inner.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
		await flush()
		expect(el.classList.contains('qconceal--hidden')).toBe(true)
		// a tap on the area itself still uncovers it
		el.click()
		await flush()
		expect(el.classList.contains('qconceal--hidden')).toBe(false)
	})

	// Persona flow station (gamification on): after "Nächste Frage" the content already showed the
	// next question while it faded out over .45 s — readable for that time. Hiding is instant now,
	// also in the calm variants; only uncovering glides.
	it('hiding is instant (no fade-out of the next question), uncovering still glides', () => {
		const css = readFileSync(resolve(here, '../../src/components/QuestionConceal.vue'), 'utf-8')
		expect(css).toMatch(/\.qconceal--hidden \.qconceal__content \{\s*transition: none;/)
		expect(css).toMatch(/\.qconceal--still\.qconceal--hidden \.qconceal__content \{[^}]*transition: none;/)
		expect(css).toMatch(/^\.qconceal__content \{ transition: opacity \.45s/m)
	})

	it('a mouse crossing the hidden question on its way to the player does not uncover it', async () => {
		await mount(QuestionConceal, { conceal: true, questionKey: 1 }, { slot })
		const el = host.querySelector('.qconceal')
		await new Promise((done) => setTimeout(done, 5))
		el.dispatchEvent(new MouseEvent('mouseenter'))
		el.dispatchEvent(pointer('pointermove'))
		await sleep(HOVER_DWELL_MS / 3)
		el.dispatchEvent(new MouseEvent('mouseleave'))
		el.dispatchEvent(pointer('pointerleave'))
		await sleep(HOVER_DWELL_MS + 60)
		await flush()
		expect(el.classList.contains('qconceal--hidden')).toBe(true)
	})

	it('a finger moving over it (scrolling) is no hover; a question change drops a started dwell', async () => {
		const { state, root } = await mount(QuestionConceal, { conceal: true, questionKey: 1 }, { slot })
		const el = host.querySelector('.qconceal')
		el.dispatchEvent(pointer('pointermove', 'touch'))
		await sleep(HOVER_DWELL_MS + 60)
		await flush()
		expect(el.classList.contains('qconceal--hidden')).toBe(true)
		el.dispatchEvent(pointer('pointermove'))
		state.props = { conceal: true, questionKey: 2 }
		root.$forceUpdate()
		await flush()
		await sleep(HOVER_DWELL_MS + 60)
		await flush()
		expect(el.classList.contains('qconceal--hidden')).toBe(true)
	})

	it('the end of the intro uncovers it by itself', async () => {
		const { state, root } = await mount(QuestionConceal, { conceal: true, questionKey: 1 }, { slot })
		state.props = { conceal: false, questionKey: 1 }
		root.$forceUpdate()
		await flush()
		expect(host.querySelector('.qconceal').classList.contains('qconceal--hidden')).toBe(false)
	})

	it('reduced motion and "Ruhige Darstellung" switch the gliding off', async () => {
		window.matchMedia = vi.fn(() => ({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
		await mount(QuestionConceal, { conceal: true, questionKey: 1 }, { slot })
		expect(host.querySelector('.qconceal').classList.contains('qconceal--still')).toBe(true)
		app.unmount()

		window.matchMedia = vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
		const pinia = createPinia()
		setActivePinia(pinia)
		useA11yStore(pinia).setEnabled(false)
		await mount(QuestionConceal, { conceal: true, questionKey: 1 }, { slot, pinia })
		expect(host.querySelector('.qconceal').classList.contains('qconceal--still')).toBe(true)
		const css = readFileSync(resolve(here, '../../src/components/QuestionConceal.vue'), 'utf-8')
		expect(css).toMatch(/@media \(prefers-reduced-motion: reduce\)[\s\S]*\.qconceal--hidden \.qconceal__content \{ transform: none; \}/)
	})

	it('reduced motion also stills the player\'s slides', async () => {
		window.matchMedia = vi.fn(() => ({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
		await mount(QuestionMediaPlayer, { questionId: 5, answered: false })
		expect(host.querySelector('.qmedia').classList.contains('qmedia--still')).toBe(true)
	})

	it('a press inside opens the audio output for the tone when tones are on (Safari needs the gesture)', async () => {
		const { pinia } = piniaWith({ sounds: 'yes' })
		await mount(QuestionConceal, { conceal: false, questionKey: 1 }, { slot, pinia })
		host.querySelector('.answer').dispatchEvent(new Event('pointerdown', { bubbles: true }))
		expect(audioCtx.created).toHaveLength(1)
	})

	it('a tap (click without pointerdown, as on touch screens) opens it too', async () => {
		const { pinia } = piniaWith({ sounds: 'yes' })
		await mount(QuestionConceal, { conceal: false, questionKey: 1 }, { slot, pinia })
		host.querySelector('.answer').click()
		expect(audioCtx.created).toHaveLength(1)
	})

	it('a context opened outside a gesture is resumed when the tone plays', () => {
		audioCtx.FakeContext.prototype.resume = vi.fn(() => Promise.resolve())
		const ctx = unlockAnswerSounds()
		ctx.state = 'suspended'
		ctx.resume.mockClear()
		playAnswerSound(true)
		expect(ctx.resume).toHaveBeenCalled()
	})

	it('the feedback fades in only for a question that had an intro (others as before)', async () => {
		const { state, root } = await mount(QuestionConceal, { conceal: false, questionKey: 1 }, { slot })
		const el = host.querySelector('.qconceal')
		expect(el.classList.contains('qconceal--flow')).toBe(false)
		state.props = { conceal: true, questionKey: 2 }
		root.$forceUpdate()
		await flush()
		expect(el.classList.contains('qconceal--flow')).toBe(true)
		state.props = { conceal: false, questionKey: 3 }
		root.$forceUpdate()
		await flush()
		expect(el.classList.contains('qconceal--flow')).toBe(false)
		const css = readFileSync(resolve(here, '../../src/components/QuestionConceal.vue'), 'utf-8')
		expect(css).not.toMatch(/^\.qconceal :deep\(\.answer-feedback\) \{ animation: qconceal-rise/m)
	})

	it('…and not when they are off', async () => {
		const { pinia } = piniaWith()
		await mount(QuestionConceal, { conceal: false, questionKey: 1 }, { slot, pinia })
		host.querySelector('.answer').dispatchEvent(new Event('pointerdown', { bubbles: true }))
		expect(audioCtx.created).toHaveLength(0)
	})
})

describe('the learning modes: player first, question hidden until the intro is heard', () => {
	function trainingServer({ intro = true } = {}) {
		axios.get.mockImplementation((url) => {
			if (url === '/apps/learning/api/questions/101/media') {
				return Promise.resolve({ data: { question_id: 101, tracks: intro ? [INTRO()] : [] } })
			}
			return Promise.resolve({ data: {} })
		})
		axios.post.mockImplementation((url) => {
			if (url.endsWith('/training/start')) {
				return Promise.resolve({ data: { session_id: 9, questions: [
					{ id: 101, text: 'Blau + Gelb?', question_type: 'mc', answers: [{ id: 1, text: 'Grün' }, { id: 2, text: 'Lila' }] },
				] } })
			}
			if (url.endsWith('/training/answer')) {
				return Promise.resolve({ data: { is_correct: true, correct_answer_texts: ['Grün'], question_media: REVEAL } })
			}
			return Promise.resolve({ data: {} })
		})
	}

	it('TrainingMode: the intro comes first, the question shows after it', async () => {
		trainingServer()
		const { default: TrainingMode } = await import('../../src/components/TrainingMode.vue')
		await mount(TrainingMode, { poolId: 1, totalQuestions: 1 })
		buttonByText('Start Training').click()
		await flush()
		const card = host.querySelector('.question-card')
		// order in the card: player, then the (hidden) question
		expect(card.querySelector('.qmedia').compareDocumentPosition(card.querySelector('.question-text')) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
		const area = host.querySelector('.qconceal')
		expect(area.classList.contains('qconceal--hidden')).toBe(true)
		expect(area.querySelector('.question-text').closest('[inert]')).not.toBeNull()

		buttonByText('Abspielen').click()
		await flush()
		host.querySelector('audio').dispatchEvent(new Event('ended'))
		await flush()
		expect(area.classList.contains('qconceal--hidden')).toBe(false)

		buttonByText('Grün').click()
		await flush()
		expect(host.querySelector('.qmedia__title').textContent).toContain('Zu deiner Antwort')
	})

	// Review of #66 (persona flow station): after "Nächste Frage" the second media question was
	// hidden for one render and then shown at once, its intro unheard.
	for (const deferSecond of [true, false]) it(`TrainingMode: after "Nächste Frage" the next media question stays hidden for its intro (media ${deferSecond ? 'late' : 'at once'})`, async () => {
		let release = () => {}
		axios.get.mockImplementation((url) => {
			const m = url.match(/questions\/(\d+)\/media$/)
			if (m && m[1] === '102' && deferSecond) return new Promise((r) => { release = () => r({ data: { question_id: 102, tracks: [INTRO()] } }) })
			if (m) return Promise.resolve({ data: { question_id: Number(m[1]), tracks: [INTRO()] } })
			return Promise.resolve({ data: {} })
		})
		axios.post.mockImplementation((url) => {
			if (url.endsWith('/training/start')) {
				return Promise.resolve({ data: { session_id: 9, questions: [
					{ id: 101, text: 'Blau + Gelb?', question_type: 'mc', answers: [{ id: 1, text: 'Grün' }, { id: 2, text: 'Lila' }] },
					{ id: 102, text: 'Beine einer Spinne?', question_type: 'mc', answers: [{ id: 3, text: 'Acht' }, { id: 4, text: 'Sechs' }] },
				] } })
			}
			if (url.endsWith('/training/answer')) {
				return Promise.resolve({ data: { is_correct: true, correct_answer_texts: ['Grün'], question_media: REVEAL } })
			}
			return Promise.resolve({ data: {} })
		})
		const { default: TrainingMode } = await import('../../src/components/TrainingMode.vue')
		await mount(TrainingMode, { poolId: 1, totalQuestions: 2 })
		buttonByText('Start Training').click()
		await flush()
		const area = host.querySelector('.qconceal')
		expect(area.classList.contains('qconceal--hidden')).toBe(true)
		await sleep(5)
		area.click()
		await flush()
		expect(area.classList.contains('qconceal--hidden')).toBe(false)
		buttonByText('Grün').click()
		for (let i = 0; i < 200 && !buttonByText('Next Question'); i++) await sleep(10)
		await flush()
		const classes = []
		const watch = new MutationObserver(() => classes.push(host.querySelector('.qconceal')?.className))
		watch.observe(host, { subtree: true, attributes: true, attributeFilter: ['class'] })
		buttonByText('Next Question').click()
		for (let i = 0; i < 200 && !host.querySelector('.question-text').textContent.includes('Beine'); i++) await sleep(10)
		await flush()
		expect(host.querySelector('.question-text').textContent).toContain('Beine einer Spinne?')
		expect(host.querySelector('.qconceal').classList.contains('qconceal--hidden')).toBe(true)
		release()
		await flush()
		watch.disconnect()
		expect(host.querySelector('.qconceal').classList.contains('qconceal--hidden')).toBe(true)
		expect(classes.filter((c) => c && c.includes('qconceal--shown'))).toEqual([])
	})

	it('TrainingMode: a question without media is never hidden', async () => {
		trainingServer({ intro: false })
		const { default: TrainingMode } = await import('../../src/components/TrainingMode.vue')
		await mount(TrainingMode, { poolId: 1, totalQuestions: 1 })
		buttonByText('Start Training').click()
		await flush()
		expect(host.querySelector('.qconceal').classList.contains('qconceal--hidden')).toBe(false)
		expect(host.querySelector('.qconceal').getAttribute('tabindex')).toBeNull()
	})

	it('all three modes wire the player to the hidden area; the exam has neither', () => {
		const source = (name) => readFileSync(resolve(here, '../../src/components', name), 'utf-8')
		for (const name of ['TrainingMode.vue', 'LeitnerMode.vue', 'SmartQueue.vue']) {
			const text = source(name)
			expect(text).toContain('@conceal="questionConcealed = $event"')
			expect(text).toMatch(/<QuestionConceal :conceal="questionConcealed"/)
			expect(text).toContain(':selected-answer-ids="mediaSelectedIds"')
			expect(text.indexOf('<QuestionMediaPlayer')).toBeLessThan(text.indexOf('<QuestionScenario'))
		}
		expect(source('ExamMode.vue')).not.toContain('QuestionConceal')
		expect(source('ExamMode.vue')).not.toContain('QuestionMediaPlayer')
	})
})

describe('review of the mini slideshow (#66): gaps closed', () => {
	function deferred() {
		let resolve
		const promise = new Promise((r) => { resolve = r })
		return { promise, resolve }
	}

	it('an option voice without a timeline (its sidecar is optional) still leads on to the dialog', async () => {
		const bare = { ...OPT(1, 0), tracks: [{ ...OPT(1, 0).tracks[0], timeline: [] }] }
		const { state, root } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false })
		state.props = { questionId: 5, answered: true, reveal: { tracks: [DIALOG], cues: null, option_tracks: [bare] }, answerCorrect: true, selectedAnswerIds: [1] }
		root.$forceUpdate()
		await flush()
		expect(host.querySelector('.qmedia__title').textContent).toContain('Zu deiner Antwort')
		expect(host.textContent).not.toContain('0 Zeilen')
		expect(buttonByText('Abspielen').disabled).toBe(false)
		const next = buttonByText('Nächste Zeile')
		expect(next.disabled).toBe(false)
		next.click()
		await flush()
		expect(host.querySelector('.qmedia__title').textContent).toContain('Nachbesprechung')
	})

	it('…and its end, when played, chains to the dialog as well', async () => {
		const bare = { ...OPT(1, 0), tracks: [{ ...OPT(1, 0).tracks[0], timeline: [] }] }
		const { state, root } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false })
		state.props = { questionId: 5, answered: true, reveal: { tracks: [DIALOG], cues: null, option_tracks: [bare] }, answerCorrect: true, selectedAnswerIds: [1] }
		root.$forceUpdate()
		await flush()
		buttonByText('Abspielen').click()
		await flush()
		host.querySelector('audio').dispatchEvent(new Event('ended'))
		await flush()
		expect(host.querySelector('.qmedia__title').textContent).toContain('Nachbesprechung')
	})

	it('after a question with an intro, the next one stays hidden in its reserved place until its media are known', async () => {
		const events = []
		const { state, root } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { listeners: { onConceal: (value) => events.push(value) } })
		const slow = deferred()
		axios.get.mockReturnValue(slow.promise)
		state.props = { questionId: 6, answered: false }
		root.$forceUpdate()
		await flush()
		// no moment in which the next question is shown and then slides away
		expect(events.at(-1)).toBe(true)
		const section = host.querySelector('.qmedia')
		expect(section).not.toBeNull()
		expect(section.getAttribute('aria-busy')).toBe('true')
		expect(host.querySelector('.qmedia__stage--intro')).not.toBeNull()
		// the controls hold their place, invisible and out of reach
		expect(host.querySelector('.qmedia__controls').classList.contains('qmedia__reserved')).toBe(true)
		const since = events.length

		slow.resolve({ data: { question_id: 6, tracks: [INTRO()] } })
		await flush()
		expect(events.slice(since).includes(false)).toBe(false)
		expect(events.at(-1)).toBe(true)
		expect(host.querySelector('.qmedia').getAttribute('aria-busy')).toBeNull()
		expect(host.querySelector('.qmedia__controls').classList.contains('qmedia__reserved')).toBe(false)
	})

	it('…and shows at once when that question has no intro after all', async () => {
		const events = []
		const { state, root } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { listeners: { onConceal: (value) => events.push(value) } })
		const slow = deferred()
		axios.get.mockReturnValue(slow.promise)
		state.props = { questionId: 6, answered: false }
		root.$forceUpdate()
		await flush()
		slow.resolve({ data: { question_id: 6, tracks: [] } })
		await flush()
		expect(events.at(-1)).toBe(false)
		expect(host.querySelector('.qmedia')).toBeNull()
	})

	it('a pool without media is never hidden, not even while the media request is open', async () => {
		const slow = deferred()
		axios.get.mockReturnValue(slow.promise)
		const events = []
		await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { listeners: { onConceal: (value) => events.push(value) } })
		expect(events.includes(true)).toBe(false)
		expect(host.querySelector('.qmedia')).toBeNull()
		slow.resolve({ data: { question_id: 5, tracks: [] } })
		await flush()
		expect(events.includes(true)).toBe(false)
	})

	it('no answer tone for a question without media, even with tones and autoplay on', async () => {
		axios.get.mockResolvedValue({ data: { question_id: 5, tracks: [] } })
		const { pinia } = piniaWith({ autoplay: true, sounds: 'yes' })
		const { state, root } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { pinia })
		state.props = { questionId: 5, answered: true, reveal: null, answerCorrect: true, selectedAnswerIds: [1] }
		root.$forceUpdate()
		await flush()
		expect(audioCtx.created).toHaveLength(0)
	})

	it('a question with only an intro still gets its tone', async () => {
		const { pinia } = piniaWith({ sounds: 'yes' })
		const { state, root } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false }, { pinia })
		state.props = { questionId: 5, answered: true, reveal: null, answerCorrect: false, selectedAnswerIds: [1] }
		root.$forceUpdate()
		await flush()
		expect(audioCtx.created).toHaveLength(1)
	})
})
