/**
 * Audit 2026-11 #110: in the 'seeking' event the <video>'s currentTime is already the jump target.
 * VideoPlayer closed the open segment there, so a jump from 0:20 to 5:00 recorded [20, 300] as
 * watched, and addSegment swapped a backward jump into a forward segment as well.
 *
 * Audit 2026-11 #111: the server discards a ping less than 5 s after the last accepted one (a second
 * tab) but answered like a recorded ping; the client had already removed the chunk, so its seconds
 * were lost. The server now answers accepted:false and the client keeps the seconds.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))

globalThis.t = (app, text) => text

import axios from '@nextcloud/axios'
import VideoPlayer from '../../src/components/VideoPlayer.vue'
import { coveredSeconds } from '../../src/utils/videoInterval.js'
import { makeServer } from './support/videoProgressServer.js'

function createPlayer(duration, post) {
	const video = { currentTime: 0, paused: true, seeking: false, duration }
	const vm = {
		contentId: 9, durationSeconds: duration, initialCoveredPct: 0, initialCompleted: false,
		subtitleRef: '', poster: '', title: '',
		$emit: vi.fn(),
		$refs: { video },
	}
	for (const part of [...(VideoPlayer.mixins || []), VideoPlayer]) {
		if (part.data) Object.assign(vm, part.data.call(vm))
		for (const [name, def] of Object.entries(part.computed || {})) {
			const getter = typeof def === 'function' ? def : def.get
			Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
		}
		for (const [name, fn] of Object.entries(part.methods || {})) {
			vm[name] = fn.bind(vm)
		}
	}
	axios.post.mockImplementation(post)
	vm.onLoadedMetadata()
	return { vm, video }
}

async function play(vm, video, seconds) {
	video.paused = false
	vm.onPlay()
	for (let i = 0; i < seconds * 4 && video.currentTime < video.duration; i++) {
		await vi.advanceTimersByTimeAsync(250)
		video.currentTime = Math.min(video.duration, video.currentTime + 0.25)
		vm.onTimeUpdate()
	}
}

/** What a browser does: position first, then 'seeking' (seeking=true), then 'seeked'. */
function seekTo(vm, video, target) {
	video.currentTime = target
	video.seeking = true
	vm.onSeeking()
	video.seeking = false
	vm.onSeeked()
}

describe('VideoPlayer: a seek ends the segment where playback was (audit #110)', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(1_700_000_000_300))
		vi.clearAllMocks()
	})
	afterEach(() => { vi.useRealTimers() })

	const ok = () => Promise.resolve({ data: { covered_pct: 0, completed: false, accepted: true } })

	it('a jump forward does not count the skipped part as watched', async () => {
		const { vm, video } = createPlayer(600, ok)
		await play(vm, video, 20)
		seekTo(vm, video, 300)

		expect(vm.intervals).toEqual([{ start: 0, end: 20 }])
		const sent = axios.post.mock.calls.filter(([url]) => url.endsWith('/heartbeat')).map(([, b]) => b.end)
		expect(Math.max(...sent)).toBeLessThanOrEqual(20)
	})

	it('a jump back records nothing for the jump itself', async () => {
		const { vm, video } = createPlayer(600, ok)
		seekTo(vm, video, 100) // while paused: no segment open
		await play(vm, video, 10)
		seekTo(vm, video, 30)

		// only [100, 110] was played; [30, 110] was recorded before
		expect(coveredSeconds(vm.intervals)).toBeCloseTo(10, 5)
	})
})

describe('VideoPlayer: a ping the server discarded is sent again (audit #111)', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(1_700_000_000_300))
		vi.clearAllMocks()
	})
	afterEach(() => { vi.useRealTimers() })

	it('keeps the seconds of a discarded ping and delivers them with a later one', async () => {
		const server = makeServer(600)
		const { vm, video } = createPlayer(600, (url, body) => {
			if (url.endsWith('/heartbeat')) return Promise.resolve({ data: server.heartbeat(body.start, body.end) })
			return Promise.resolve({ data: server.complete() })
		})
		await play(vm, video, 12) // first ping [0, ~1] at start, second at ~10 s
		// A second tab pings the server just before this tab's next heartbeat …
		await vi.advanceTimersByTimeAsync(7000)
		server.heartbeat(500, 501)
		// … so this tab's pause ping lands under 5 s later and is discarded.
		video.paused = true
		vm.onPause()
		await vi.advanceTimersByTimeAsync(0)
		expect(server.pings.at(-1)[1]).toBe(12)
		expect(vm.pendingSegments.some((seg) => seg.end === 12)).toBe(true)

		// The next accepted ping carries those seconds.
		await vi.advanceTimersByTimeAsync(6000)
		await play(vm, video, 3)
		await vi.advanceTimersByTimeAsync(10000)
		vm.onPause()
		await vi.advanceTimersByTimeAsync(10000)
		// [0, 15] played; the discarded seconds up to 12 reached the server after all
		expect(server.intervals.some(([a, b]) => a <= 11 && b >= 12)).toBe(true)
	})
})
