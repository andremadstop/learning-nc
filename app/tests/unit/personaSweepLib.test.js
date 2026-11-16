/**
 * GitHub #36 — the persona sweep (scripts/persona-sweep) as a gate: its pure helpers.
 *
 * expectedReason: findings that are the app working as intended (a persona who does not own the
 * pool does not see it). A gate that always shows the same false alarms is read past, so the rules
 * stay narrow. GitHub #63: the student view's own learner record (404) was such a rule; the app no
 * longer asks for it, and the rule is gone, so that 404 counts again wherever it shows up.
 *
 * axeFindings: axe-core violations → findings; contrast at any impact, other rules only when
 * critical or serious.
 */
import { describe, it, expect } from 'vitest'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const { expectedReason, axeFindings, stationText, pickAnswerIndex } = require('../../../scripts/persona-sweep/lib.cjs')

describe('persona sweep: expectedReason', () => {
	const http = (detail) => ({ kind: 'http', detail })

	const sv = { user: 'admin', studentView: true, station: 'Course › Completion & certificate' }

	// GitHub #63: the completion tab no longer asks for the previewer's learner record, so a 404
	// there is a finding again, at every station.
	it('the student view\'s own learner record (404) counts at every station', () => {
		for (const station of ['Course › Completion & certificate', 'Course › Completion & certificate (buttons)', 'Course › Participants › My Progress']) {
			expect(expectedReason(http('GET 404 /apps/learning/api/courses/1/students/admin'), { ...sv, station })).toBeNull()
		}
		expect(expectedReason(http('GET 404 /index.php/apps/learning/api/courses/7/students/admin'), sv)).toBeNull()
	})

	it('a missing pool is expected only for someone who does not own it', () => {
		const missing = { kind: 'pool-missing', detail: 'the pool "Fire safety" is not in this persona\'s pool list' }
		expect(expectedReason(missing, { user: 'learner2', poolOwner: 'instructor' })).toMatch(/not the pool owner/)
		expect(expectedReason(missing, { user: 'admin', poolOwner: 'instructor', studentView: true })).toBeTruthy()
		expect(expectedReason(missing, { user: 'instructor', poolOwner: 'instructor' })).toBeNull()
		// without a known owner nothing is waved through
		expect(expectedReason(missing, { user: 'learner2' })).toBeNull()
	})

	it('other kinds always count', () => {
		expect(expectedReason({ kind: 'console', detail: 'GET 404 /apps/learning/api/courses/1/students/admin' }, sv)).toBeNull()
		expect(expectedReason({ kind: 'a11y', detail: 'x' }, { user: 'admin', studentView: true, poolOwner: 'instructor' })).toBeNull()
	})
})

describe('persona sweep: axeFindings', () => {
	const contrast = {
		id: 'color-contrast',
		impact: 'serious',
		help: 'Elements must meet minimum color contrast ratio thresholds',
		nodes: [{
			impact: 'serious',
			target: ['.exam-hint > span'],
			any: [{ data: { contrastRatio: 2.85, expectedContrastRatio: '4.5:1', fgColor: '#999999', bgColor: '#ffffff' } }],
		}],
	}

	it('contrast: rule, element, ratio and colours', () => {
		const [f] = axeFindings([contrast])
		expect(f.kind).toBe('a11y')
		expect(f.detail).toBe('color-contrast (serious): .exam-hint > span — 2.85:1, needs 4.5:1 (#999999 on #ffffff)')
	})

	it('contrast counts at any impact, other rules only when critical or serious', () => {
		const minorContrast = { ...contrast, impact: 'minor', nodes: [{ ...contrast.nodes[0], impact: 'minor' }] }
		const moderate = { id: 'region', impact: 'moderate', help: 'All page content should be contained by landmarks', nodes: [{ target: ['div'] }] }
		const critical = { id: 'aria-required-children', impact: 'critical', help: 'Certain ARIA roles must contain particular children', nodes: [{ target: ['.lernraum-subnav'] }] }
		const out = axeFindings([minorContrast, moderate, critical])
		expect(out.map((f) => f.detail.split(' ')[0])).toEqual(['color-contrast', 'aria-required-children'])
		expect(out[1].detail).toContain('Certain ARIA roles')
	})

	it('many elements of one rule: the first few, then a count', () => {
		const many = { ...contrast, nodes: Array.from({ length: 11 }, (_, i) => ({ ...contrast.nodes[0], target: [`.n${i}`] })) }
		const out = axeFindings([many], { maxNodes: 3 })
		expect(out).toHaveLength(4)
		expect(out[3].detail).toBe('color-contrast: 8 more elements on this station')
	})

	it('no violations, no findings', () => {
		expect(axeFindings([])).toEqual([])
		expect(axeFindings(undefined)).toEqual([])
	})
})

describe('persona sweep: stationText', () => {
	it('collapses blank lines and spaces, keeps line breaks', () => {
		expect(stationText('  Exam \n\n\n  Fire   safety\t \n')).toBe('Exam\nFire safety')
	})

	it('cuts long text and says so', () => {
		const t = stationText('a'.repeat(50), 10)
		expect(t).toBe('aaaaaaaaaa […]')
	})
})

describe('persona sweep: pickAnswerIndex (fresh-eyes C3)', () => {
	it('picks the exact answer, not a longer one that contains it', () => {
		expect(pickAnswerIndex(['Unlocked, so colleagues can help', ' Locked ', 'Switched off'], 'Locked')).toBe(1)
	})
	it('collapses whitespace from the button markup', () => {
		expect(pickAnswerIndex(['\n  Raise the\n alarm  ', 'Finish your current task'], 'Raise the alarm')).toBe(0)
	})
	it('falls back to the shortest button that contains the answer', () => {
		expect(pickAnswerIndex(['Locked and logged out now', 'Locked now'], 'Locked')).toBe(1)
	})
	it('returns -1 without a match', () => {
		expect(pickAnswerIndex(['Yes', 'No'], 'Maybe')).toBe(-1)
		expect(pickAnswerIndex(['Yes'], '')).toBe(-1)
	})
})
