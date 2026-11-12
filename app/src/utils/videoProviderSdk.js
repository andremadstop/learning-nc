/**
 * videoProviderSdk.js — loads the Vimeo / YouTube player API and turns its events into the few the
 * watch tracker needs (play, time, pause, end, duration).
 *
 * Only VideoConsentOverlay calls this, and only after the learner consented: nothing here runs at
 * import time, so no provider script or connection exists before consent (DSGVO Art. 13).
 *
 * The provider frame is created by the overlay itself (youtube-nocookie.com / player.vimeo.com with
 * dnt=1) and only WRAPPED here, so the API never builds its own frame on www.youtube.com and the
 * Vimeo SDK never has to look the video up via oEmbed.
 */

export const YOUTUBE_API_URL = 'https://www.youtube.com/iframe_api'
export const VIMEO_API_URL = 'https://player.vimeo.com/api/player.js'

// The YouTube API has no time event; its position is read this often while playing.
const YOUTUBE_POLL_MS = 500
// A provider API that never reports ready counts as not loaded.
const LOAD_TIMEOUT_MS = 15000

let youtubeApi = null
let vimeoApi = null

function injectScript(url) {
	return new Promise((resolve, reject) => {
		const script = document.createElement('script')
		script.src = url
		script.async = true
		script.onload = () => resolve()
		script.onerror = () => reject(new Error('Could not load ' + url))
		document.head.appendChild(script)
	})
}

function withTimeout(promise, label) {
	let timer = null
	const timeout = new Promise((resolve, reject) => {
		timer = setTimeout(() => reject(new Error(label + ' did not become ready')), LOAD_TIMEOUT_MS)
	})
	return Promise.race([promise, timeout]).finally(() => clearTimeout(timer))
}

/**
 * @return {Promise<object>} window.YT once the IFrame Player API is ready
 */
export function loadYouTubeApi() {
	if (window.YT && typeof window.YT.Player === 'function') {
		return Promise.resolve(window.YT)
	}
	if (!youtubeApi) {
		const ready = new Promise((resolve, reject) => {
			const previous = window.onYouTubeIframeAPIReady
			window.onYouTubeIframeAPIReady = () => {
				if (typeof previous === 'function') {
					previous()
				}
				resolve(window.YT)
			}
			injectScript(YOUTUBE_API_URL).catch(reject)
		})
		youtubeApi = withTimeout(ready, 'YouTube player API').catch((e) => {
			youtubeApi = null
			throw e
		})
	}
	return youtubeApi
}

/**
 * @return {Promise<object>} window.Vimeo once the Player SDK is loaded
 */
export function loadVimeoApi() {
	if (window.Vimeo && typeof window.Vimeo.Player === 'function') {
		return Promise.resolve(window.Vimeo)
	}
	if (!vimeoApi) {
		const ready = injectScript(VIMEO_API_URL).then(() => {
			if (!window.Vimeo || typeof window.Vimeo.Player !== 'function') {
				throw new Error('Vimeo player SDK is missing after loading')
			}
			return window.Vimeo
		})
		vimeoApi = withTimeout(ready, 'Vimeo player SDK').catch((e) => {
			vimeoApi = null
			throw e
		})
	}
	return vimeoApi
}

function seconds(value) {
	const n = Number(value)
	return Number.isFinite(n) ? n : null
}

async function attachYouTube(iframe, on) {
	const YT = await loadYouTubeApi()
	const state = YT.PlayerState || { ENDED: 0, PLAYING: 1, PAUSED: 2, BUFFERING: 3 }
	let timer = null
	const stopPolling = () => {
		if (timer !== null) {
			clearInterval(timer)
			timer = null
		}
	}
	const player = new YT.Player(iframe, {
		events: {
			onReady: () => on.duration(seconds(player.getDuration())),
			onStateChange: (event) => {
				const position = seconds(player.getCurrentTime())
				if (event.data === state.PLAYING) {
					on.duration(seconds(player.getDuration()))
					on.play(position)
					stopPolling()
					timer = setInterval(() => on.time(seconds(player.getCurrentTime())), YOUTUBE_POLL_MS)
				} else if (event.data === state.PAUSED || event.data === state.BUFFERING) {
					// A seek shows as BUFFERING → PLAYING: the segment closes here and reopens there.
					stopPolling()
					on.pause(position)
				} else if (event.data === state.ENDED) {
					stopPolling()
					on.end(position)
				}
			},
		},
	})
	return {
		destroy() {
			stopPolling()
			try {
				player.destroy()
			} catch (e) {
				// the frame is going away with the component anyway
			}
		},
	}
}

async function attachVimeo(iframe, on) {
	const Vimeo = await loadVimeoApi()
	const player = new Vimeo.Player(iframe)
	player.on('play', (data) => on.play(seconds(data?.seconds)))
	player.on('timeupdate', (data) => {
		on.duration(seconds(data?.duration))
		on.time(seconds(data?.seconds))
	})
	player.on('seeked', (data) => on.time(seconds(data?.seconds)))
	player.on('pause', (data) => on.pause(seconds(data?.seconds)))
	player.on('ended', (data) => on.end(seconds(data?.seconds)))
	Promise.resolve(player.getDuration()).then((d) => on.duration(seconds(d))).catch(() => {})
	return {
		destroy() {
			Promise.resolve()
				.then(() => player.destroy())
				.catch(() => {})
		},
	}
}

/**
 * Wrap the provider frame with its player API.
 *
 * @param {string} sourceType 'youtube-nocookie' | 'vimeo'
 * @param {HTMLIFrameElement} iframe the frame the overlay injected after consent
 * @param {{play: Function, time: Function, pause: Function, end: Function, duration: Function}} on
 *   callbacks, each with seconds (or null when the provider sent none)
 * @return {Promise<{destroy: Function}>} rejects when the API cannot be loaded
 */
export function attachProviderPlayer(sourceType, iframe, on) {
	if (sourceType === 'youtube-nocookie') {
		return attachYouTube(iframe, on)
	}
	if (sourceType === 'vimeo') {
		return attachVimeo(iframe, on)
	}
	return Promise.reject(new Error('Unsupported video provider: ' + sourceType))
}
