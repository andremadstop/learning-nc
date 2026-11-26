import { translate as t, getCanonicalLocale } from '@nextcloud/l10n'
import { intlLocale } from './relativeTime.js'

/**
 * GitHub #92: the wait until the next counting exam on a pool may start, shared by the result
 * page (ExamMode), the Exam tab's pool list and the exam setup screen, so all three say the same.
 * Times are server seconds; the clock offset (server minus browser, in seconds) turns them into
 * the browser's time for display.
 */

const RAW = { escape: false, sanitize: false }

/**
 * The next possible start on a pool from the pass status' attempt_policy (next_start_by_pool),
 * or null when one may start now or the server did not say.
 *
 * @param {object|null|undefined} policy attempt_policy
 * @param {number} poolId the pool
 * @return {?number} server seconds
 */
export function nextStartFor(policy, poolId) {
	const map = policy?.next_start_by_pool
	if (!map || typeof map !== 'object') {
		return null
	}
	const at = Number(map[poolId] ?? map[String(poolId)])
	return Number.isFinite(at) && at > 0 ? at : null
}

/**
 * Seconds left until the next start, 0 when there is nothing to wait for.
 *
 * @param {?number} nextStartAt server seconds
 * @param {number} nowServerSeconds the server's current time, in seconds
 * @return {number}
 */
export function waitSecondsUntil(nextStartAt, nowServerSeconds) {
	if (nextStartAt === null || nextStartAt === undefined || !Number.isFinite(Number(nextStartAt))) {
		return 0
	}
	return Math.max(0, Math.ceil(Number(nextStartAt) - nowServerSeconds))
}

/**
 * The countdown as a clock: 4:05, or 1:02:09 from one hour on.
 *
 * @param {number} totalSeconds seconds left
 * @return {string}
 */
export function waitClock(totalSeconds) {
	const total = Math.max(0, Math.floor(totalSeconds))
	const h = Math.floor(total / 3600)
	const m = Math.floor((total % 3600) / 60)
	const sec = String(total % 60).padStart(2, '0')
	return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`
}

/**
 * The visible countdown line.
 *
 * @param {number} totalSeconds seconds left
 * @return {string}
 */
export function waitCountdownText(totalSeconds) {
	return t('learning', 'Nächster Versuch möglich in {time}', { time: waitClock(totalSeconds) })
}

/**
 * The screen-reader text for the next start, in the Nextcloud locale (not the browser's), with the
 * day when it is not today: the 24-hour limit moves it to tomorrow, while sighted users see the
 * countdown.
 *
 * @param {?number} nextStartAt server seconds
 * @param {number} nowServerSeconds the server's current time, in seconds (0 = use the browser's)
 * @param {number} clockOffsetSeconds server minus browser, in seconds
 * @return {string}
 */
export function nextStartText(nextStartAt, nowServerSeconds, clockOffsetSeconds) {
	if (nextStartAt === null || nextStartAt === undefined) {
		return ''
	}
	const offset = clockOffsetSeconds || 0
	const at = new Date((nextStartAt - offset) * 1000)
	const locale = intlLocale(getCanonicalLocale())
	const time = at.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })
	const now = nowServerSeconds > 0 ? (nowServerSeconds - offset) * 1000 : Date.now()
	if (at.toDateString() === new Date(now).toDateString()) {
		return t('learning', 'Nächster Versuch möglich ab {time} Uhr', { time }, undefined, RAW)
	}
	const date = at.toLocaleDateString(locale, { weekday: 'long', day: 'numeric', month: 'long' })
	return t('learning', 'Nächster Versuch möglich am {date} ab {time} Uhr', { date, time }, undefined, RAW)
}
