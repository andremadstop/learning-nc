/**
 * Audit 2026-11 (S31): VirtuProf.vue appends chat messages with push() and replaces the array only
 * when it trims to 20 entries. VirtuProfBubble and VirtuProfFullscreen watched the chatMessages
 * prop without deep/length — in Vue 3 that fires on replacement only. TTS auto-read ran from about
 * the 11th exchange on, and the user's own message did not scroll into view.
 *
 * Runs the components' real computed + watch definitions under real Vue reactivity; only the
 * side-effect methods (speakText, scrollChatToBottom) are spies.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, reactive, ref } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'de',
	getCanonicalLocale: () => 'de-DE',
	isRTL: () => false,
}))

import VirtuProfBubble from '../../src/components/VirtuProfBubble.vue'
import VirtuProfFullscreen from '../../src/components/VirtuProfFullscreen.vue'

let host, app

function harness(Component, spies) {
	return {
		name: Component.name + 'Harness',
		props: Component.props,
		data() {
			return { ...Component.data.call(this), supportsSpeechSynthesis: true }
		},
		computed: Component.computed,
		watch: Component.watch,
		methods: { ...Component.methods, ...spies },
		render: () => h('div'),
	}
}

async function mountWith(Component) {
	const spies = { speakText: vi.fn(), scrollChatToBottom: vi.fn(), focusComposer: vi.fn() }
	const state = { list: ref(reactive([])) }
	const Child = harness(Component, spies)
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(Child, { chatMessages: state.list.value, ttsEnabled: true }) })
	app.config.warnHandler = () => {}
	app.mount(host)
	await nextTick()
	spies.scrollChatToBottom.mockClear()
	return { spies, state }
}

const flush = async () => { await nextTick(); await nextTick() }

afterEach(() => {
	app?.unmount()
	host?.remove()
})

describe('VirtuProfBubble: chat watcher sees appended messages', () => {
	it('a pushed assistant answer is read aloud exactly once', async () => {
		const { spies, state } = await mountWith(VirtuProfBubble)
		state.list.value.push({ role: 'user', text: 'Frage' })
		await flush()
		expect(spies.speakText).not.toHaveBeenCalled()
		state.list.value.push({ role: 'assistant', text: 'Antwort', speakable: true })
		await flush()
		expect(spies.speakText).toHaveBeenCalledTimes(1)
		expect(spies.speakText).toHaveBeenCalledWith('Antwort')
	})

	it('the user message scrolls into view', async () => {
		const { spies, state } = await mountWith(VirtuProfBubble)
		state.list.value.push({ role: 'user', text: 'Frage' })
		await flush()
		expect(spies.scrollChatToBottom).toHaveBeenCalled()
	})

	it('trimming the array to 20 does not read the same answer again', async () => {
		const { spies, state } = await mountWith(VirtuProfBubble)
		for (let i = 0; i < 20; i++) state.list.value.push({ role: 'user', text: 'q' + i })
		state.list.value.push({ role: 'assistant', text: 'Antwort', speakable: true })
		await flush()
		expect(spies.speakText).toHaveBeenCalledTimes(1)
		state.list.value = reactive(state.list.value.slice(state.list.value.length - 20))
		await flush()
		expect(spies.speakText).toHaveBeenCalledTimes(1)
	})

	it('popping a refused user message does not repeat the previous answer', async () => {
		const { spies, state } = await mountWith(VirtuProfBubble)
		state.list.value.push({ role: 'assistant', text: 'Erste Antwort', speakable: true })
		await flush()
		state.list.value.push({ role: 'user', text: 'Zweite Frage' })
		await flush()
		state.list.value.pop()
		await flush()
		expect(spies.speakText).toHaveBeenCalledTimes(1)
	})

	it('a non-speakable assistant message is not read aloud', async () => {
		const { spies, state } = await mountWith(VirtuProfBubble)
		state.list.value.push({ role: 'assistant', text: 'Verlauf' })
		await flush()
		expect(spies.speakText).not.toHaveBeenCalled()
	})
})

describe('VirtuProfFullscreen: chat watcher sees appended messages', () => {
	it('the user message scrolls into view', async () => {
		const { spies, state } = await mountWith(VirtuProfFullscreen)
		state.list.value.push({ role: 'user', text: 'Frage' })
		await flush()
		expect(spies.scrollChatToBottom).toHaveBeenCalled()
	})
})
