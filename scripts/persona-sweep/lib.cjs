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
	return null
}

/** axe impacts that count, besides every colour-contrast violation. */
const AXE_IMPACTS = ['critical', 'serious']
const AXE_MAX_NODES = 8

/**
 * axe-core violations → findings of kind "a11y", one per rule and element.
 * Contrast counts at any impact; other rules only when critical or serious.
 *
 * @param {Array} violations axe.run(...).violations
 * @param {{impacts?: string[], maxNodes?: number}} [opts]
 * @return {Array<{kind: string, detail: string}>}
 */
function axeFindings(violations, opts = {}) {
	const impacts = opts.impacts || AXE_IMPACTS
	const maxNodes = opts.maxNodes || AXE_MAX_NODES
	const out = []
	for (const v of violations || []) {
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

module.exports = { expectedReason, axeFindings, stationText, pickAnswerIndex, AXE_IMPACTS }
