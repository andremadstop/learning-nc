/**
 * Audit 2026-11 (S11): VideoPlayer posted the MERGED interval, which grows as [0,t] during linear
 * playback, while VideoProgressService caps every ping's length from its START to the wall-clock
 * time since the last accepted ping (+1 s). So [0,20], [0,30] … were all cut back to [0,11] and a
 * 120 s course video never got past ~9 % — the video gate stayed shut for every learner.
 *
 * The test drives the real VideoPlayer methods against a JS port of the server rules
 * (VideoProgressService: isPlausiblePing ≥5 s, first-ping cap min(15, 0.5·d), capInterval,
 * mergeIntervals, coveredPct, 95 % threshold; integer server seconds) and checks the OUTCOME:
 * genuine watching completes, skipping does not.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))

globalThis.t = (app, text) => text

import axios from '@nextcloud/axios'
import VideoPlayer from '../../src/components/VideoPlayer.vue'
import { makeServer } from './support/videoProgressServer.js'

function createPlayer(duration, server) {
	const video = { currentTime: 0, paused: true, duration }
	const vm = {
		contentId: 9, durationSeconds: duration, initialCoveredPct: 0, initialCompleted: false,
		subtitleRef: '', poster: '', title: '',
		$emit: vi.fn(),
		$refs: { video },
	}
	// the heartbeat logic lives in a mixin shared with the third-party player (audit 2026-11, H6)
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
	axios.post.mockImplementation((url, body) => {
		if (url.endsWith('/heartbeat')) return Promise.resolve({ data: server.heartbeat(body.start, body.end) })
		if (url.endsWith('/complete')) return Promise.resolve({ data: server.complete() })
		return Promise.reject(new Error('unexpected ' + url))
	})
	vm.onLoadedMetadata()
	return { vm, video }
}

const STEP = 0.25

async function play(vm, video, seconds) {
	video.paused = false
	vm.onPlay()
	const steps = Math.round(seconds / STEP)
	for (let i = 0; i < steps && video.currentTime < video.duration; i++) {
		await vi.advanceTimersByTimeAsync(STEP * 1000)
		video.currentTime = Math.min(video.duration, video.currentTime + STEP)
		vm.onTimeUpdate()
	}
}

async function pause(vm, video, waitSeconds) {
	video.paused = true
	vm.onPause()
	await vi.advanceTimersByTimeAsync(waitSeconds * 1000)
}

async function end(vm, video) {
	video.paused = true
	vm.onEnded()
	await vi.advanceTimersByTimeAsync(30000)
}

describe('VideoPlayer heartbeat contract vs. the server cap', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(1_700_000_000_300))
		vi.clearAllMocks()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('a 120 s video watched linearly to the end is completed', async () => {
		const server = makeServer(120)
		const { vm, video } = createPlayer(120, server)
		await play(vm, video, 120)
		await end(vm, video)
		expect(server.state().completed).toBe(true)
		expect(vm.isComplete).toBe(true)
	})

	it('pausing shortly after a heartbeat does not lose the seconds before the pause', async () => {
		const server = makeServer(60)
		const { vm, video } = createPlayer(60, server)
		await play(vm, video, 13.5) // throttled flush at ~11 s, pause 2.5 s later
		await pause(vm, video, 1)
		await play(vm, video, 21) // again: flush, then pause shortly after
		await pause(vm, video, 2)
		await play(vm, video, 60)
		await end(vm, video)
		expect(server.state().completed).toBe(true)
	})

	it('the final seconds count when the video ends shortly after a heartbeat', async () => {
		// flushes at ~1, 11, 21 s; without the last [21,23] chunk coverage is 21/23 = 91 %
		const server = makeServer(23)
		const { vm, video } = createPlayer(23, server)
		await play(vm, video, 23)
		await end(vm, video)
		expect(server.state().completed).toBe(true)
	})

	it('posts consecutive, non-overlapping chunks instead of the growing [0,t] interval', async () => {
		const server = makeServer(120)
		const { vm, video } = createPlayer(120, server)
		await play(vm, video, 31)
		const starts = server.pings.map(([a]) => a)
		expect(starts.filter((a) => a === 0).length).toBe(1)
		for (let i = 1; i < server.pings.length; i++) {
			expect(server.pings[i][0]).toBe(server.pings[i - 1][1])
		}
	})

	it('skipping the middle by seeking still does not complete (anti-fraud cap stays effective)', async () => {
		const server = makeServer(120)
		const { vm, video } = createPlayer(120, server)
		await play(vm, video, 10)
		vm.onSeeking()
		video.currentTime = 110
		vm.onSeeked()
		await play(vm, video, 10)
		await end(vm, video)
		expect(server.state().completed).toBe(false)
		expect(vm.isComplete).toBe(false)
	})
})
