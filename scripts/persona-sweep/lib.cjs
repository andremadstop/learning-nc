// persona-sweep/lib.cjs — the pure parts of the sweep (no browser), so that Gate 1 can test them:
// app/tests/unit/personaSweepLib.test.js.
'use strict'

/**
 * Findings that are the app working as intended. They are not counted, but the report lists them
 * in their own section, so that a rule that matches too much stays visible.
 * Each rule is as narrow as the case it covers: persona, method, status and path together.
 *
 * @param {{kind: string, detail: string}} finding
 * @param {{user: string, studentView?: boolean, poolOwner?: string, station?: string}} ctx
 * @return {string|null} the reason it is expected, or null when it counts
 */
function expectedReason(finding, ctx) {
	if (!finding || !ctx) return null
	// GitHub #63: the student view's own learner record (GET …/students/<previewer> → 404) used to be
	// waved through on the completion tab. The app no longer asks for it, so that 404 counts again.
	if (finding.kind === 'pool-missing' && ctx.poolOwner && ctx.user !== ctx.poolOwner) {
		// The Pools tab lists one's own pools and pools shared with one; the seed shares nothing.
		return `not the pool owner (${ctx.poolOwner}): the pool is not shared with this persona`
	}
	// GitHub #78: the station that puts an SVG named logo.png into the logo library expects the
	// server to refuse it (content check, 400). Only there, only that request, only as admin.
	if (finding.kind === 'http' && ctx.user === 'admin' && ctx.station === REFUSED_LOGO_STATION
		&& /^POST 400 (\/index\.php)?\/apps\/learning\/api\/settings\/admin\/cert-logos$/.test(finding.detail)) {
		return 'the logo library refuses an SVG named .png (the station tests exactly that)'
	}
	// GitHub #93: the station that offers the background library an SVG named .png and a picture
	// over 4000 px expects the server to refuse both (400). Only there, only that request, only as admin.
	if (finding.kind === 'http' && ctx.user === 'admin' && ctx.station === REFUSED_BACKGROUND_STATION
		&& /^POST 400 (\/index\.php)?\/apps\/learning\/api\/settings\/admin\/cert-backgrounds$/.test(finding.detail)) {
		return 'the background library refuses an SVG and an oversized picture (the station tests exactly that)'
	}
	// GitHub #82: a dead-link station opens a link whose target is gone or not this persona's; the
	// app asks the server once and gets 404, which is the point of the station. Only at those
	// stations, only GET 404, only for the ids the seed made dead (ctx.deadLinks).
	if (finding.kind === 'http' && String(ctx.station || '').startsWith(DEAD_LINK_STATION_PREFIX) && ctx.deadLinks) {
		const m = /^GET 404 (?:\/index\.php)?\/apps\/learning\/api\/(courses|pools)\/(\d+)$/.exec(finding.detail)
		if (m) {
			const id = Number(m[2])
			const dead = m[1] === 'courses'
				? [ctx.deadLinks.deletedCourseId, ...(ctx.deadLinks.noAccessCourseIds || [])]
				: [ctx.deadLinks.unknownPoolId]
			if (dead.includes(id)) return `dead-link station: ${m[1] === 'courses' ? 'course' : 'pool'} ${id} is gone or not this persona's (the server says 404)`
		}
	}
	return null
}

/** Every dead-link station's name starts with this (GitHub #82). */
const DEAD_LINK_STATION_PREFIX = 'Dead link: '

/** The station name of the refused-logo upload (persona.sweep.cjs uses the same constant). */
const REFUSED_LOGO_STATION = 'Admin settings › Logos: an SVG named .png is refused'

/** The station name of the refused background uploads (GitHub #93; persona.sweep.cjs uses it too). */
const REFUSED_BACKGROUND_STATION = 'Admin settings › Backgrounds: an SVG and a picture over 4000 px are refused (#93)'
const ACHIEVEMENT_LINK_STATION = 'Activity app: an achievement links to the achievements on the dashboard (#85)'

/** axe impacts that count, besides every colour-contrast violation. */
const AXE_IMPACTS = ['critical', 'serious']
const AXE_MAX_NODES = 8

/**
 * axe files text whose colour equals its background (1:1) under `incomplete`, reason "equalRatio",
 * not under `violations` (it might be hidden on purpose). On a visible element in the app's area
 * it is invisible text: GitHub #76, white "Reset profile" (--color-primary-element-text) on the
 * white settings page passed the sweep unseen. These nodes count like a contrast violation; every
 * other incomplete reason (gradients, background images, overlap) stays out, it would only be noise.
 *
 * @param {Array} incomplete axe.run(...).incomplete
 * @return {Array} violation-shaped entries (id "color-contrast", impact "serious") for axeFindings
 */
function equalRatioContrast(incomplete) {
	const out = []
	for (const v of incomplete || []) {
		if (v.id !== 'color-contrast' && v.id !== 'color-contrast-enhanced') continue
		const nodes = (v.nodes || []).filter((n) => (n.any || []).some((c) => c.data && c.data.messageKey === 'equalRatio'))
		if (nodes.length) out.push({ ...v, impact: 'serious', nodes: nodes.map((n) => ({ ...n, impact: 'serious' })) })
	}
	return out
}

/**
 * axe-core violations → findings of kind "a11y", one per rule and element.
 * Contrast counts at any impact; other rules only when critical or serious. With opts.incomplete,
 * the 1:1 contrast nodes among axe's incomplete results count too (equalRatioContrast).
 *
 * @param {Array} violations axe.run(...).violations
 * @param {{impacts?: string[], maxNodes?: number, incomplete?: Array}} [opts]
 * @return {Array<{kind: string, detail: string}>}
 */
function axeFindings(violations, opts = {}) {
	const impacts = opts.impacts || AXE_IMPACTS
	const maxNodes = opts.maxNodes || AXE_MAX_NODES
	const out = []
	for (const v of [...(violations || []), ...equalRatioContrast(opts.incomplete)]) {
		const contrast = v.id === 'color-contrast' || v.id === 'color-contrast-enhanced'
		if (!contrast && !impacts.includes(v.impact)) continue
		const nodes = v.nodes || []
		for (const node of nodes.slice(0, maxNodes)) {
			const target = (node.target || []).flat().join(' ').slice(0, 160)
			let extra = ''
			if (contrast) {
				const data = (node.any || []).find((c) => c.data && c.data.contrastRatio)?.data
				if (data) extra = ` — ${data.contrastRatio}:1, needs ${data.expectedContrastRatio} (${data.fgColor} on ${data.bgColor})`
			} else {
				extra = ` — ${v.help}`
			}
			out.push({ kind: 'a11y', detail: `${v.id} (${node.impact || v.impact || '?'}): ${target}${extra}`.slice(0, 300) })
		}
		if (nodes.length > maxNodes) out.push({ kind: 'a11y', detail: `${v.id}: ${nodes.length - maxNodes} more elements on this station` })
	}
	return out
}

/** Visible text of a station for the fresh-eyes reviewer: blank lines collapsed, cut at max. */
function stationText(raw, max = 1500) {
	const text = String(raw || '')
		.replace(/\r/g, '')
		.split('\n')
		.map((l) => l.replace(/[ \t ]+/g, ' ').trim())
		.filter(Boolean)
		.join('\n')
	return text.length > max ? `${text.slice(0, max)} […]` : text
}

/**
 * Index of the answer button whose text is the seeded answer, -1 if none (fresh-eyes C3, #36).
 * Playwright's hasText (case-insensitive substring, first wins) clicked "Unlocked, so colleagues
 * can help" for the answer "Locked", so the
 * "all answers right" exam scored 7 of 8. Exact (trimmed, whitespace collapsed) match first; only
 * without one the shortest button text that contains the answer.
 */
function pickAnswerIndex(buttonTexts, answer) {
	const norm = (s) => String(s || '').replace(/\s+/g, ' ').trim()
	const want = norm(answer)
	if (!want) return -1
	const texts = buttonTexts.map(norm)
	const exact = texts.indexOf(want)
	if (exact !== -1) return exact
	let best = -1
	texts.forEach((t, i) => {
		if (t.includes(want) && (best === -1 || t.length < texts[best].length)) best = i
	})
	return best
}

module.exports = { DEAD_LINK_STATION_PREFIX, REFUSED_LOGO_STATION, REFUSED_BACKGROUND_STATION, ACHIEVEMENT_LINK_STATION, expectedReason, axeFindings, equalRatioContrast, stationText, pickAnswerIndex, AXE_IMPACTS }
