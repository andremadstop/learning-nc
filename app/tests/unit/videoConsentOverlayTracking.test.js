// @vitest-environment-options {"settings":{"disableIframePageLoading":true,"disableJavaScriptFileLoading":true,"handleDisabledFileLoadingAsSuccess":true}}
/**
 * Audit 2026-11 (H6): a Vimeo / YouTube video in a gated course could never be completed — the
 * consent overlay only injected a bare iframe, nothing reported the watch progress, and the quiz
 * stayed locked for everyone. After consent the overlay now loads the provider's player API and
 * sends heartbeats + complete like the native player.
 *
 * The test mounts the real VideoConsentOverlay with a mocked provider API (the SDK <script> is
 * intercepted, never fetched) and runs the heartbeats against the JS port of the server rules.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { makeServer } from './support/videoProgressServer.js'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))

const fillVars = (text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)) : text)
globalThis.t = (app, text, vars) => fillVars(text, vars)

const YOUTUBE_API = 'https://www.youtube.com/iframe_api'
const VIMEO_API = 'https://player.vimeo.com/api/player.js'

function fakeYouTube() {
	const players = []
	class Player {
		constructor(el, opts) {
			this.el = el
			this.opts = opts
			this.time = 0
			this.duration = 120
			players.push(this)
			queueMicrotask(() => opts?.events?.onReady?.({ target: this }))
		}

		getCurrentTime() { return this.time }
		getDuration() { return this.duration }
		destroy() {}
		state(data) { this.opts.events.onStateChange({ data, target: this }) }
	}
	return {
		players,
		api: { Player, PlayerState: { UNSTARTED: -1, ENDED: 0, PLAYING: 1, PAUSED: 2, BUFFERING: 3, CUED: 5 } },
	}
}

function fakeVimeo() {
	const players = []
	class Player {
		constructor(el) {
			this.el = el
			this.handlers = {}
			players.push(this)
		}

		on(event, fn) { (this.handlers[event] ||= []).push(fn) }
		off() {}
		getDuration() { return Promise.resolve(120) }
		destroy() { return Promise.resolve() }
		emit(event, data) { (this.handlers[event] || []).forEach((fn) => fn(data)) }
	}
	return { players, api: { Player } }
}

let app = null
let scripts = []
let provider = null
let axios = null

beforeEach(async () => {
	vi.resetModules()
	axios = (await import('@nextcloud/axios')).default
	delete window.YT
	delete window.Vimeo
	delete window.onYouTubeIframeAPIReady
	scripts = []
	provider = null
	document.head.innerHTML = ''
	document.body.innerHTML = '<div id="app"></div>'
	const realAppend = document.head.appendChild.bind(document.head)
	vi.spyOn(document.head, 'appendChild').mockImplementation((node) => {
		if (node && node.tagName === 'SCRIPT') {
			// The SDK is never fetched: record the request and play the provider's loading contract.
			scripts.push(node.src)
			queueMicrotask(() => {
				if (node.src === YOUTUBE_API) {
					window.YT = provider.api
					node.onload?.()
					window.onYouTubeIframeAPIReady?.()
				} else if (node.src === VIMEO_API) {
					window.Vimeo = provider.api
					node.onload?.()
				}
			})
			return node
		}
		return realAppend(node)
	})
})

afterEach(() => {
	if (app) {
		app.unmount()
		app = null
	}
	vi.useRealTimers()
	vi.restoreAllMocks()
})

async function mountOverlay(props) {
	const { default: VideoConsentOverlay } = await import('../../src/components/VideoConsentOverlay.vue')
	const events = { completed: vi.fn(), progress: vi.fn() }
	app = createApp({
		render: () => h(VideoConsentOverlay, {
			contentId: 9,
			durationSeconds: 120,
			onCompleted: events.completed,
			onProgress: events.progress,
			...props,
		}),
	})
	app.config.globalProperties.t = globalThis.t
	app.mount('#app')
	return { root: document.getElementById('app'), events }
}

function routeTo(server) {
	axios.post.mockImplementation((url, body) => {
		if (url.endsWith('/heartbeat')) return Promise.resolve({ data: server.heartbeat(body.start, body.end) })
		if (url.endsWith('/complete')) return Promise.resolve({ data: server.complete() })
		return Promise.reject(new Error('unexpected ' + url))
	})
}

async function consent(root) {
	root.querySelector('.video-consent__accept').click()
	await nextTick()
	await vi.advanceTimersByTimeAsync(0)
}

const STEP = 0.25

describe('VideoConsentOverlay loads the provider player only after consent', () => {
	it('before consent there is no script and no frame; after it, the YouTube API and a nocookie frame', async () => {
		provider = fakeYouTube()
		vi.useFakeTimers()
		const { root } = await mountOverlay({ sourceType: 'youtube-nocookie', embedId: 'dQw4w9WgXcQ' })

		expect(scripts).toEqual([])
		expect(root.querySelector('iframe')).toBeNull()
		expect(root.querySelectorAll('[src], [href], script, link')).toHaveLength(0)
		expect(window.YT).toBeUndefined()

		await consent(root)

		expect(scripts).toEqual([YOUTUBE_API])
		const frame = root.querySelector('iframe')
		expect(frame.src.startsWith('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?')).toBe(true)
		expect(frame.src).toContain('enablejsapi=1')
		expect(frame.src).toContain('dnt=1')
		expect(provider.players).toHaveLength(1)
		expect(provider.players[0].el).toBe(frame)
	})

	it('a Vimeo video loads the Vimeo player SDK only after consent and wraps the frame', async () => {
		provider = fakeVimeo()
		vi.useFakeTimers()
		const { root } = await mountOverlay({ sourceType: 'vimeo', embedId: '76979871' })

		expect(scripts).toEqual([])
		await consent(root)

		expect(scripts).toEqual([VIMEO_API])
		const frame = root.querySelector('iframe')
		expect(frame.src).toBe('https://player.vimeo.com/video/76979871?dnt=1')
		expect(provider.players[0].el).toBe(frame)
	})
})

describe('VideoConsentOverlay reports the watch progress like the native player', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(1_700_000_000_300))
	})

	async function playYouTube(player, seconds) {
		player.state(1) // PLAYING
		await advanceYouTube(player, seconds)
	}

	async function advanceYouTube(player, seconds) {
		const steps = Math.round(seconds / STEP)
		for (let i = 0; i < steps && player.time < player.duration; i++) {
			await vi.advanceTimersByTimeAsync(STEP * 1000)
			player.time = Math.min(player.duration, player.time + STEP)
		}
	}

	it('a YouTube video watched to the end sends heartbeats and completes the gate', async () => {
		provider = fakeYouTube()
		const server = makeServer(120)
		routeTo(server)
		const { root, events } = await mountOverlay({ sourceType: 'youtube-nocookie', embedId: 'dQw4w9WgXcQ' })
		await consent(root)
		const player = provider.players[0]

		await playYouTube(player, 120)
		player.state(0) // ENDED
		await vi.advanceTimersByTimeAsync(30000)

		const calls = axios.post.mock.calls
		const heartbeats = calls.filter(([url]) => url.endsWith('/video-progress/heartbeat'))
		expect(heartbeats.length).toBeGreaterThan(5)
		for (const [, body] of heartbeats) {
			expect(body.contentId).toBe(9)
		}
		expect(calls[calls.length - 1][0]).toMatch(/\/video-progress\/complete$/)
		expect(calls[calls.length - 1][1]).toEqual({ contentId: 9 })
		expect(server.state().completed).toBe(true)
		expect(events.completed).toHaveBeenCalledWith(9)
		expect(root.textContent).toContain('Abgeschlossen')
	})

	it('skipping the middle of a YouTube video does not complete it', async () => {
		provider = fakeYouTube()
		const server = makeServer(120)
		routeTo(server)
		const { root, events } = await mountOverlay({ sourceType: 'youtube-nocookie', embedId: 'dQw4w9WgXcQ' })
		await consent(root)
		const player = provider.players[0]

		await playYouTube(player, 10)
		player.time = 110 // scrubbed while playing: only the poll sees the jump
		await advanceYouTube(player, 10)
		player.state(0)
		await vi.advanceTimersByTimeAsync(30000)

		expect(server.state().completed).toBe(false)
		expect(events.completed).not.toHaveBeenCalled()
		// the jump is a seek: no heartbeat claims any of the skipped seconds 11..109
		expect(server.pings.length).toBeGreaterThan(1)
		for (const [start, end] of server.pings) {
			expect(end <= 11 || start >= 109).toBe(true)
		}
	})

	it('a Vimeo video watched to the end sends heartbeats and completes the gate', async () => {
		provider = fakeVimeo()
		const server = makeServer(120)
		routeTo(server)
		const { root, events } = await mountOverlay({ sourceType: 'vimeo', embedId: '76979871' })
		await consent(root)
		const player = provider.players[0]

		let seconds = 0
		player.emit('play', { seconds, duration: 120 })
		while (seconds < 120) {
			await vi.advanceTimersByTimeAsync(STEP * 1000)
			seconds = Math.min(120, seconds + STEP)
			player.emit('timeupdate', { seconds, duration: 120 })
		}
		player.emit('ended', { seconds: 120, duration: 120 })
		await vi.advanceTimersByTimeAsync(30000)

		const calls = axios.post.mock.calls
		expect(calls.filter(([url]) => url.endsWith('/video-progress/heartbeat')).length).toBeGreaterThan(5)
		expect(calls[calls.length - 1][0]).toMatch(/\/video-progress\/complete$/)
		expect(server.state().completed).toBe(true)
		expect(events.completed).toHaveBeenCalledWith(9)
	})

	it('a provider API that does not load says so instead of pretending to track', async () => {
		provider = fakeYouTube()
		document.head.appendChild.mockImplementation((node) => {
			scripts.push(node.src)
			queueMicrotask(() => node.onerror?.(new Event('error')))
			return node
		})
		const { root } = await mountOverlay({ sourceType: 'youtube-nocookie', embedId: 'dQw4w9WgXcQ' })
		await consent(root)
		await vi.advanceTimersByTimeAsync(0)

		expect(root.querySelector('.video-consent__tracking-error')).not.toBeNull()
	})
})
