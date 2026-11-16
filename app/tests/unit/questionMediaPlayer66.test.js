/**
 * GitHub #66, part C: the player for the media of a question — the intro before answering, the
 * dialog with keywords, "misconception → right" and the key takeaway after it; subtitles from the
 * timeline, usable without sound, audio loaded only on "play".
 *
 * Server contract (QuestionMediaService): GET /api/questions/{id}/media → {tracks} (learners: the
 * intro tracks, no cues); the answer responses of training / Leitner / Smart Queue carry
 * question_media = {tracks: dialog tracks with grant URLs, cues}. Track URLs come from
 * linkToRoute and are used as they are.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

vi.setConfig({ testTimeout: 20000 })

vi.hoisted(() => {
	globalThis.t = (_app, text, vars) => String(text).replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})
const l10n = vi.hoisted(() => ({ lang: 'de', rtl: false }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text) => text,
	t: (_app, text, vars) => String(text).replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m)),
	getLanguage: () => l10n.lang,
	isRTL: () => l10n.rtl,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
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
import { showError } from '@nextcloud/dialogs'
import QuestionMediaPlayer from '../../src/components/QuestionMediaPlayer.vue'
import {
	pickLanguage, cuesFor, timelineOf, stageAt, keywordTime, lineIndexAt, resolveLineIndex,
	tracksOfKind, roleName, roleColour, formatSeconds,
} from '../../src/utils/questionMediaPlayer.js'

const here = dirname(fileURLToPath(import.meta.url))
const fixtureDir = resolve(here, '../fixtures/question-media/bundle/demo-q1')
const fixtureJson = (name) => JSON.parse(readFileSync(resolve(fixtureDir, name), 'utf-8'))

// A dialog shaped like the enriched pools: expert, the one with the misconception, expert again.
const DIALOG = [
	{ role: 'ANNA', text: 'Blue and yellow are both primary colours.', start: 0, end: 2 },
	{ role: 'BEN', text: 'So mixing them makes purple, right?', start: 2.2, end: 4 },
	{ role: 'ANNA', text: 'No, together they make green.', start: 4.2, end: 6 },
]
const CUES = {
	icon: '🎨',
	en: { line_keywords: [['primary colours'], ['purple'], ['green']], contrast: { wrong: 'Mixing makes purple', right: 'Blue and yellow make green' }, merksatz: 'Blue plus yellow is green.', scene: 'Art class' },
	de: { line_keywords: [['Grundfarben'], ['Lila'], ['Grün']], contrast: { wrong: 'Mischen ergibt Lila', right: 'Blau und Gelb ergeben Grün' }, merksatz: 'Blau plus Gelb ist Grün.', scene: 'Kunstunterricht' },
}
const track = (kind, lang, extra = {}) => ({
	id: (kind === 'intro' ? 10 : 20) + (lang === 'de' ? 1 : 0),
	kind,
	lang,
	duration_ms: 6000,
	timeline: kind === 'intro' ? [{ role: 'NARRATOR', text: lang === 'de' ? 'Eine Kunstklasse mischt zwei Farben.' : 'An art class mixes two paints.', start: 0, end: 3 }] : DIALOG,
	url: `/index.php/apps/learning/api/question-media/${kind}-${lang}` + (kind === 'dialog' ? '?expires=1&grant=abc' : ''),
	...extra,
})
const REVEAL = { tracks: [track('dialog', 'en'), track('dialog', 'de')], cues: CUES }

let host, app, vm

async function flush() {
	for (let i = 0; i < 6; i++) {
		await Promise.resolve()
		await nextTick()
	}
}

async function mount(Component, props = {}) {
	host = document.createElement('div')
	document.body.appendChild(host)
	const state = { props }
	app = createApp({ render: () => h(Component, { ...state.props, ref: 'c' }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	const root = app.mount(host)
	vm = root.$refs.c
	await flush()
	return { root, state }
}

function buttonByText(text) {
	return [...host.querySelectorAll('button')].find((b) => b.textContent.includes(text))
}

let playSpy
beforeEach(() => {
	document.body.innerHTML = ''
	vi.clearAllMocks()
	l10n.lang = 'de'
	l10n.rtl = false
	window.matchMedia = vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
	playSpy = vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function () { return Promise.resolve() })
	vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {})
	vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => {})
})
afterEach(() => {
	app?.unmount()
	app = null
	vi.restoreAllMocks()
})

describe('questionMediaPlayer utils', () => {
	it('picks the content language, then the interface language (also by its base), then en, de, the first', () => {
		expect(pickLanguage(['en', 'de'], ['de'])).toBe('de')
		expect(pickLanguage(['en', 'de'], ['', 'de_DE'])).toBe('de')
		expect(pickLanguage(['en', 'de'], ['fr'])).toBe('en')
		expect(pickLanguage(['de'], ['fr', 'ar'])).toBe('de')
		expect(pickLanguage(['uk'], ['fr'])).toBe('uk')
		expect(pickLanguage([], ['de'])).toBe('')
	})

	it('reads the cues of the fixture bundle and falls back to another language', () => {
		const cues = fixtureJson('cues.json')
		expect(cuesFor(cues, 'de')).toMatchObject({ icon: '🎨', merksatz: 'Blau plus Gelb ist Grün.', wrong: 'Mischen ergibt Lila', lineKeywords: [['Grundfarben'], ['Grün']] })
		expect(cuesFor(cues, 'fr').merksatz).toBe('Blue plus yellow is green.')
	})

	it('treats unchecked cues defensively: no objects, no long icon, no non-strings', () => {
		expect(cuesFor({ icon: 'a very long icon text', en: { line_keywords: [[1, '', ' x '], 'nope'], contrast: 'x', merksatz: { a: 1 } } }, 'en'))
			.toEqual({ icon: '', scene: '', merksatz: '', wrong: '', right: '', lineKeywords: [['x'], []] })
		expect(cuesFor(null, 'en').lineKeywords).toEqual([])
		expect(cuesFor([], 'en').merksatz).toBe('')
	})

	it('keeps well-formed timeline lines only, sorted by time', () => {
		const lines = timelineOf({ timeline: [{ role: null, text: 'b', start: 2, end: 3 }, { text: 'a', start: 0, end: 1 }, { text: 'bad', start: 5, end: 4 }, 'x'] })
		expect(lines.map((l) => l.text)).toEqual(['a', 'b'])
		expect(lines[1].role).toBe('')
		expect(timelineOf({ timeline: fixtureJson('dialog-en.json') })).toHaveLength(2)
	})

	it('times a keyword by its place in the line; one not in the line comes with the line start', () => {
		const line = { text: 'abcdefghij', start: 10, end: 20 }
		expect(keywordTime(line, 'f')).toBe(15)
		expect(keywordTime(line, 'zzz')).toBe(10)
		expect(lineIndexAt(DIALOG, 2.1)).toBe(0) // the tail after a line keeps its subtitle
		expect(lineIndexAt(DIALOG, 3)).toBe(1)
		expect(lineIndexAt(DIALOG, 9)).toBe(-1)
	})

	it('shows the misconception with the second line and resolves it with the next speaker', () => {
		const cues = cuesFor(CUES, 'en')
		expect(resolveLineIndex(DIALOG)).toBe(2)
		const before = stageAt(DIALOG, cues, { t: 1.9 })
		expect(before).toMatchObject({ wrong: false, right: false, merksatz: false })
		expect(before.keywords.map((k) => k.text)).toEqual(['primary colours'])
		const doubt = stageAt(DIALOG, cues, { t: 3.5 })
		expect(doubt).toMatchObject({ wrong: true, struck: false, right: false })
		expect(doubt.keywords.find((k) => k.text === 'purple').doubt).toBe(true)
		const resolved = stageAt(DIALOG, cues, { t: 5 })
		expect(resolved).toMatchObject({ wrong: true, struck: true, right: true, merksatz: false })
		const ended = stageAt(DIALOG, cues, { t: 6 }, { ended: true })
		expect(ended.merksatz).toBe(true)
	})

	it('keeps two keywords on the stage and moves older ones to the trail', () => {
		const stage = stageAt(DIALOG, cuesFor(CUES, 'en'), { lineIndex: 2 })
		expect(stage.keywords.map((k) => k.text)).toEqual(['purple', 'green'])
		expect(stage.trail).toEqual(['primary colours'])
	})

	it('the intro stage carries subtitles only', () => {
		const stage = stageAt(DIALOG, cuesFor(CUES, 'en'), { t: 3 }, { dialog: false, ended: true })
		expect(stage.line.text).toContain('purple')
		expect(stage).toMatchObject({ keywords: [], wrong: false, right: false, merksatz: false })
	})

	it('names, colours and times generically (no content-specific roles)', () => {
		expect(roleName('MARA')).toBe('Mara')
		expect(roleName('Dr. Keller')).toBe('Dr. Keller')
		expect(roleName(null)).toBe('')
		expect(roleColour('MARA')).toBe(roleColour('mara'))
		expect(formatSeconds(65.7)).toBe('1:05')
		expect(tracksOfKind([track('intro', 'en'), { kind: 'intro', lang: 'de' }, track('dialog', 'en')], 'intro')).toHaveLength(1)
	})
})

describe('QuestionMediaPlayer', () => {
	it('renders nothing for a question without media (pools without imported media look as before)', async () => {
		axios.get.mockResolvedValue({ data: { question_id: 5, tracks: [] } })
		await mount(QuestionMediaPlayer, { questionId: 5, answered: false })
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/questions/5/media')
		expect(host.querySelector('.qmedia')).toBeNull()
	})

	it('stays away silently on 403 (exam running) and 404 (no access)', async () => {
		for (const status of [403, 404]) {
			axios.get.mockRejectedValueOnce({ response: { status } })
			await mount(QuestionMediaPlayer, { questionId: 5, answered: false })
			expect(host.querySelector('.qmedia')).toBeNull()
			app.unmount()
			app = null
		}
		expect(showError).not.toHaveBeenCalled()
	})

	it('before answering: the intro in the interface language, subtitles, no audio loaded until play', async () => {
		axios.get.mockResolvedValue({ data: { question_id: 5, tracks: [track('intro', 'en'), track('intro', 'de')] } })
		await mount(QuestionMediaPlayer, { questionId: 5, answered: false })
		expect(host.querySelector('.qmedia__title').textContent).toContain('Einstieg')
		const audio = host.querySelector('audio')
		expect(audio.getAttribute('preload')).toBe('none')
		expect(audio.getAttribute('src')).toBeNull()
		expect(host.querySelector('.qmedia__transcript').textContent).toContain('Eine Kunstklasse mischt zwei Farben.')
		// no cues before answering: no contrast, no takeaway, no scene
		expect(host.querySelector('.qmedia__contrast')).toBeNull()
		expect(host.querySelector('.qmedia__merksatz')).toBeNull()

		buttonByText('Abspielen').click()
		await flush()
		expect(audio.getAttribute('src')).toBe('/index.php/apps/learning/api/question-media/intro-de')
		expect(playSpy).toHaveBeenCalledTimes(1)
	})

	it('ignores the dialog tracks an editor gets before answering (the student view sees what learners see)', async () => {
		axios.get.mockResolvedValue({ data: { question_id: 5, tracks: [track('intro', 'en'), track('dialog', 'en')], cues: CUES } })
		await mount(QuestionMediaPlayer, { questionId: 5, answered: false })
		expect(vm.tracks.map((t) => t.kind)).toEqual(['intro'])
		expect(host.querySelector('.qmedia__transcript').textContent).not.toContain('purple')
	})

	it('after answering: the dialog of the answer response, switchable language, steppable without sound to the takeaway', async () => {
		axios.get.mockResolvedValue({ data: { question_id: 5, tracks: [] } })
		await mount(QuestionMediaPlayer, { questionId: 5, answered: true, reveal: REVEAL, contentLanguage: 'en' })
		expect(host.querySelector('.qmedia__title').textContent).toContain('Nachbesprechung')
		expect(host.querySelector('.qmedia__langs')).not.toBeNull()
		expect(vm.lang).toBe('en')

		buttonByText('Deutsch').click()
		await flush()
		expect(vm.lang).toBe('de')
		expect(host.querySelector('.qmedia__transcript').textContent).toContain('Ben:')

		const next = buttonByText('Nächste Zeile')
		next.click(); await flush()
		expect(host.querySelector('.qmedia__caption').textContent).toContain('Blue and yellow are both primary colours.')
		next.click(); await flush()
		expect(host.querySelector('.qmedia__wrong').textContent).toContain('Mischen ergibt Lila')
		expect(host.querySelector('.qmedia__right')).toBeNull()
		next.click(); await flush()
		expect(host.querySelector('.qmedia__wrong').classList.contains('qmedia__wrong--struck')).toBe(true)
		expect(host.querySelector('.qmedia__right').textContent).toContain('Blau und Gelb ergeben Grün')
		next.click(); await flush()
		expect(host.querySelector('.qmedia__merksatz').textContent).toContain('Blau plus Gelb ist Grün.')
		expect(host.querySelector('.qmedia__status').textContent).toContain('Zeile 3 von 3')
		// stepping never needs the audio
		expect(host.querySelector('audio').getAttribute('src')).toBeNull()
	})

	it('arrow keys step through the lines, mirrored in RTL', async () => {
		l10n.rtl = true
		axios.get.mockResolvedValue({ data: { tracks: [] } })
		await mount(QuestionMediaPlayer, { questionId: 5, answered: true, reveal: REVEAL })
		const section = host.querySelector('.qmedia')
		section.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }))
		section.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }))
		await flush()
		expect(vm.stage.lineIndex).toBe(1)
		section.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }))
		await flush()
		expect(vm.stage.lineIndex).toBe(0)
	})

	it('falls back to text when the audio fails (expired grant, 503) and says so', async () => {
		axios.get.mockResolvedValue({ data: { tracks: [] } })
		await mount(QuestionMediaPlayer, { questionId: 5, answered: true, reveal: REVEAL })
		buttonByText('Abspielen').click()
		await flush()
		host.querySelector('audio').dispatchEvent(new Event('error'))
		await flush()
		expect(host.querySelector('.qmedia__note')).not.toBeNull()
		expect(host.querySelector('.qmedia__status').textContent).toContain('Zeile 1 von 3')
		expect(showError).not.toHaveBeenCalled()
	})

	it('cues without a dialog track: contrast and takeaway at once', async () => {
		axios.get.mockResolvedValue({ data: { tracks: [] } })
		await mount(QuestionMediaPlayer, { questionId: 5, answered: true, reveal: { tracks: [], cues: CUES } })
		expect(host.querySelector('.qmedia__merksatz').textContent).toContain('Blau plus Gelb ist Grün.')
		expect(host.querySelector('.qmedia__controls')).toBeNull()
	})

	it('a late response for the previous question is dropped', async () => {
		let resolveFirst
		axios.get
			.mockImplementationOnce(() => new Promise((r) => { resolveFirst = r }))
			.mockResolvedValueOnce({ data: { tracks: [] } })
		const { root, state } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false })
		state.props = { questionId: 6, answered: false }
		root.$forceUpdate()
		await flush()
		resolveFirst({ data: { tracks: [track('intro', 'en')] } })
		await flush()
		expect(host.querySelector('.qmedia')).toBeNull()
	})

	it('a new question stops the audio and unloads it', async () => {
		axios.get.mockResolvedValue({ data: { tracks: [track('intro', 'en')] } })
		const { root, state } = await mount(QuestionMediaPlayer, { questionId: 5, answered: false })
		buttonByText('Abspielen').click()
		await flush()
		const audio = host.querySelector('audio')
		expect(audio.getAttribute('src')).not.toBeNull()
		HTMLMediaElement.prototype.pause.mockClear()
		HTMLMediaElement.prototype.load.mockClear()
		state.props = { questionId: 6, answered: false }
		root.$forceUpdate()
		await flush()
		expect(HTMLMediaElement.prototype.pause).toHaveBeenCalled()
		expect(HTMLMediaElement.prototype.load).toHaveBeenCalled()
		expect(audio.getAttribute('src')).toBeNull()
	})

	it('a fresh grant for the same track (Leitner rating response) does not restart the audio', async () => {
		axios.get.mockResolvedValue({ data: { tracks: [] } })
		const { root, state } = await mount(QuestionMediaPlayer, { questionId: 5, answered: true, reveal: REVEAL, contentLanguage: 'en' })
		buttonByText('Abspielen').click()
		await flush()
		const audio = host.querySelector('audio')
		const src = audio.getAttribute('src')
		audio.dispatchEvent(new Event('play'))
		await flush()
		buttonByText('Pause').click()
		audio.dispatchEvent(new Event('pause'))
		await flush()
		const regranted = { ...REVEAL, tracks: REVEAL.tracks.map((t) => ({ ...t, url: t.url.replace('grant=abc', 'grant=new') })) }
		state.props = { questionId: 5, answered: true, reveal: regranted, contentLanguage: 'en' }
		root.$forceUpdate()
		await flush()
		buttonByText('Abspielen').click()
		await flush()
		expect(audio.getAttribute('src')).toBe(src)
	})

	it('announces the line counter while stepping, never the running clock', async () => {
		axios.get.mockResolvedValue({ data: { tracks: [] } })
		await mount(QuestionMediaPlayer, { questionId: 5, answered: true, reveal: REVEAL })
		buttonByText('Abspielen').click()
		await flush()
		vm.currentTime = 3
		await flush()
		const clock = host.querySelector('.qmedia__status')
		expect(clock.textContent).toContain('0:03')
		expect(clock.closest('[aria-live]')).toBeNull()
		buttonByText('Nächste Zeile').click()
		await flush()
		const counter = host.querySelector('.qmedia__status')
		expect(counter.textContent).toContain('von 3')
		expect(counter.getAttribute('aria-live')).toBe('polite')
	})

	it('reduced motion switches the animations off', async () => {
		window.matchMedia = vi.fn(() => ({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
		axios.get.mockResolvedValue({ data: { tracks: [] } })
		await mount(QuestionMediaPlayer, { questionId: 5, answered: true, reveal: REVEAL })
		expect(host.querySelector('.qmedia').classList.contains('qmedia--still')).toBe(true)
	})

	it('renders cue text as text, never as HTML', async () => {
		axios.get.mockResolvedValue({ data: { tracks: [] } })
		const cues = { en: { merksatz: '<img src=x onerror=alert(1)>', contrast: { wrong: '<b>w</b>', right: 'r' } } }
		await mount(QuestionMediaPlayer, { questionId: 5, answered: true, reveal: { tracks: [], cues } })
		expect(host.querySelector('img')).toBeNull()
		expect(host.querySelector('.qmedia__merksatz').textContent).toContain('<img src=x onerror=alert(1)>')
	})
})

describe('the learning modes carry question_media to the player', () => {
	it('TrainingMode: intro before answering, the dialog of the answer response after it, gone on the next question, back on jumping back', async () => {
		axios.get.mockImplementation((url) => {
			if (url === '/apps/learning/api/questions/101/media') {
				return Promise.resolve({ data: { question_id: 101, tracks: [track('intro', 'en')] } })
			}
			return Promise.resolve({ data: {} })
		})
		axios.post.mockImplementation((url) => {
			if (url.endsWith('/training/start')) {
				return Promise.resolve({ data: { session_id: 9, questions: [
					{ id: 101, text: 'Blue + yellow?', question_type: 'mc', answers: [{ id: 1, text: 'Green' }, { id: 2, text: 'Purple' }] },
					{ id: 102, text: 'Second?', question_type: 'mc', answers: [{ id: 3, text: 'A' }, { id: 4, text: 'B' }] },
				] } })
			}
			if (url.endsWith('/training/answer')) {
				return Promise.resolve({ data: { is_correct: false, correct_answer_texts: ['Green'], question_media: REVEAL } })
			}
			return Promise.resolve({ data: {} })
		})
		const { default: TrainingMode } = await import('../../src/components/TrainingMode.vue')
		await mount(TrainingMode, { poolId: 1, totalQuestions: 2 })
		buttonByText('Start Training').click()
		await flush()
		expect(host.querySelector('.qmedia__title').textContent).toContain('Einstieg')

		buttonByText('Purple').click()
		await flush()
		expect(host.querySelector('.qmedia__title').textContent).toContain('Nachbesprechung')
		expect(host.querySelector('.qmedia__transcript').textContent).toContain('So mixing them makes purple')

		buttonByText('Next Question').click()
		await flush()
		expect(host.querySelector('.qmedia')).toBeNull()

		vm.jumpToQuestion(0)
		await flush()
		expect(host.querySelector('.qmedia__title').textContent).toContain('Nachbesprechung')
	})

	it('the player sits in training, Leitner and Smart Queue, never in an exam', () => {
		const source = (name) => readFileSync(resolve(here, '../../src/components', name), 'utf-8')
		for (const name of ['TrainingMode.vue', 'LeitnerMode.vue', 'SmartQueue.vue']) {
			expect(source(name)).toMatch(/<QuestionMediaPlayer[^>]*:reveal="questionMedia"/)
			expect(source(name)).toContain('question_media')
		}
		expect(source('ExamMode.vue')).not.toContain('QuestionMediaPlayer')
		// the Leitner rating response must not wipe the dialog the preview revealed
		expect(source('LeitnerMode.vue')).toContain('this.questionMedia = data.question_media ?? this.questionMedia')
	})
})
