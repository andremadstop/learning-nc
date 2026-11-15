/**
 * GitHub #36 — the persona sweep (scripts/persona-sweep) as a gate: its pure helpers.
 *
 * expectedReason: the first runs reported two findings at every run that were the app working as
 * intended (an instructor's student view asks for his own learner record → 404; a persona who does
 * not own the pool does not see it). A gate that always shows the same false alarms is read past.
 * The rules must stay narrow: the same 404 for another user, another status or another persona
 * still counts.
 *
 * axeFindings: axe-core violations → findings; contrast at any impact, other rules only when
 * critical or serious.
 */
import { describe, it, expect } from 'vitest'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const { expectedReason, axeFindings, stationText } = require('../../../scripts/persona-sweep/lib.cjs')

describe('persona sweep: expectedReason', () => {
	const http = (detail) => ({ kind: 'http', detail })

	it('the student view\'s own learner record (404) is expected', () => {
		expect(expectedReason(http('GET 404 /apps/learning/api/courses/1/students/admin'), { user: 'admin', studentView: true })).toMatch(/student view/)
		expect(expectedReason(http('GET 404 /index.php/apps/learning/api/courses/7/students/admin'), { user: 'admin', studentView: true })).toBeTruthy()
	})

	it('the same 404 still counts outside the student view, for another user, status or method', () => {
		expect(expectedReason(http('GET 404 /apps/learning/api/courses/1/students/admin'), { user: 'admin', studentView: false })).toBeNull()
		expect(expectedReason(http('GET 404 /apps/learning/api/courses/1/students/learner1'), { user: 'admin', studentView: true })).toBeNull()
		expect(expectedReason(http('GET 500 /apps/learning/api/courses/1/students/admin'), { user: 'admin', studentView: true })).toBeNull()
		expect(expectedReason(http('POST 404 /apps/learning/api/courses/1/students/admin'), { user: 'admin', studentView: true })).toBeNull()
		expect(expectedReason(http('GET 404 /apps/learning/api/courses/1/students/admin/sessions'), { user: 'admin', studentView: true })).toBeNull()
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
		expect(expectedReason({ kind: 'console', detail: 'GET 404 /apps/learning/api/courses/1/students/admin' }, { user: 'admin', studentView: true })).toBeNull()
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
