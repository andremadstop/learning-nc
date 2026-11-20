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
const { expectedReason, axeFindings, stationText, pickAnswerIndex, REFUSED_LOGO_STATION, REFUSED_BACKGROUND_STATION, ACHIEVEMENT_LINK_STATION, DEAD_LINK_STATION_PREFIX } = require('../../../scripts/persona-sweep/lib.cjs')

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

	// GitHub #78 (C5): the station that uploads an SVG named logo.png wants the 400; nowhere else.
	it('the refused logo upload is expected only at its own station, as admin, for that request', () => {
		const refused = http('POST 400 /apps/learning/api/settings/admin/cert-logos')
		const at = { user: 'admin', station: REFUSED_LOGO_STATION }
		expect(expectedReason(refused, at)).toMatch(/refuses an SVG/)
		expect(expectedReason(http('POST 400 /index.php/apps/learning/api/settings/admin/cert-logos'), at)).toBeTruthy()
		expect(expectedReason(refused, { ...at, station: 'Admin settings' })).toBeNull()
		expect(expectedReason(refused, { ...at, user: 'instructor' })).toBeNull()
		expect(expectedReason(http('POST 500 /apps/learning/api/settings/admin/cert-logos'), at)).toBeNull()
		expect(expectedReason(http('DELETE 400 /apps/learning/api/settings/admin/cert-logos/abc'), at)).toBeNull()
		expect(expectedReason({ kind: 'console', detail: 'POST 400 /apps/learning/api/settings/admin/cert-logos' }, at)).toBeNull()
	})

	// GitHub #93: the station that offers the background library an SVG and an oversized picture wants the 400s.
	it('the refused background uploads are expected only at their own station, as admin, for that request', () => {
		const refused = http('POST 400 /apps/learning/api/settings/admin/cert-backgrounds')
		const at = { user: 'admin', station: REFUSED_BACKGROUND_STATION }
		expect(expectedReason(refused, at)).toMatch(/refuses an SVG and an oversized picture/)
		expect(expectedReason(http('POST 400 /index.php/apps/learning/api/settings/admin/cert-backgrounds'), at)).toBeTruthy()
		expect(expectedReason(refused, { ...at, station: REFUSED_LOGO_STATION })).toBeNull()
		expect(expectedReason(refused, { ...at, user: 'instructor' })).toBeNull()
		expect(expectedReason(http('POST 500 /apps/learning/api/settings/admin/cert-backgrounds'), at)).toBeNull()
		expect(expectedReason(http('POST 400 /apps/learning/api/settings/admin/cert-logos'), at)).toBeNull()
		expect(expectedReason(http('DELETE 400 /apps/learning/api/settings/admin/cert-backgrounds/abc'), at)).toBeNull()
		expect(expectedReason({ kind: 'console', detail: 'POST 400 /apps/learning/api/settings/admin/cert-backgrounds' }, at)).toBeNull()
	})

	// GitHub #85: no page lists a user's achievements app-wide (only a pool's statistics and a
	// course summary); until that is decided, the achievement link's landing is listed, not counted.
	it('the achievement link without achievements is expected only at its own station, as learner1', () => {
		const gap = { kind: 'activity', detail: 'stream: the achievement link opens the dashboard without its achievements (#85)' }
		const at = { user: 'learner1', station: ACHIEVEMENT_LINK_STATION }
		expect(typeof ACHIEVEMENT_LINK_STATION).toBe('string')
		expect(expectedReason(gap, at)).toMatch(/open decision/)
		expect(expectedReason(gap, { ...at, station: 'Activity app: the certificate link opens "Completion & certificate" (#85)' })).toBeNull()
		expect(expectedReason(gap, { ...at, user: 'learner2' })).toBeNull()
		expect(expectedReason({ kind: 'activity', detail: 'stream: no clickable achievement' }, at)).toBeNull()
		expect(expectedReason({ kind: 'activity', detail: 'stream: "Achievement unlocked: x" does not link to the dashboard (#85)' }, at)).toBeNull()
		expect(expectedReason({ ...gap, kind: 'http' }, at)).toBeNull()
	})

	// GitHub #82: a dead-link station opens a link to a deleted course, a course without access or a
	// pool that never existed; the server's 404 for exactly those ids is the station's subject.
	it('the 404 of a dead link is expected only at a dead-link station, only for the seeded ids', () => {
		const deadLinks = { deletedCourseId: 9, privateCourseId: 8, archivedCourseId: 7, unknownPoolId: 999999, noAccessCourseIds: [8] }
		const at = { user: 'learner1', station: DEAD_LINK_STATION_PREFIX + 'deleted course (certificate notification)', deadLinks }
		expect(expectedReason(http('GET 404 /apps/learning/api/courses/9'), at)).toMatch(/dead-link station/)
		expect(expectedReason(http('GET 404 /index.php/apps/learning/api/courses/8'), at)).toBeTruthy()
		expect(expectedReason(http('GET 404 /index.php/apps/learning/api/pools/999999'), at)).toBeTruthy()
		// a 500, another method, another id, a sub-resource or another station still counts
		expect(expectedReason(http('GET 500 /apps/learning/api/courses/9'), at)).toBeNull()
		expect(expectedReason(http('GET 403 /apps/learning/api/courses/9'), at)).toBeNull()
		expect(expectedReason(http('DELETE 404 /apps/learning/api/courses/9'), at)).toBeNull()
		expect(expectedReason(http('GET 404 /apps/learning/api/courses/7'), at)).toBeNull()
		expect(expectedReason(http('GET 404 /apps/learning/api/courses/9/students/learner1'), at)).toBeNull()
		expect(expectedReason(http('GET 404 /apps/learning/api/pools/9'), at)).toBeNull()
		expect(expectedReason(http('GET 404 /apps/learning/api/courses/9'), { ...at, station: 'Course › Learning space' })).toBeNull()
		// the instructor owns the private course: a 404 for it would be a bug
		expect(expectedReason(http('GET 404 /apps/learning/api/courses/8'), { ...at, user: 'instructor', deadLinks: { ...deadLinks, noAccessCourseIds: [] } })).toBeNull()
		expect(expectedReason(http('GET 404 /apps/learning/api/courses/9'), { ...at, deadLinks: undefined })).toBeNull()
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

	// GitHub #76: axe files text in its background's colour (1:1) under incomplete ("equalRatio");
	// the white "Reset profile" on the white settings page was never reported.
	const incomplete = [{
		id: 'color-contrast',
		impact: 'serious',
		help: 'Elements must meet minimum color contrast ratio thresholds',
		nodes: [
			{ impact: 'serious', target: ['.actions > button:nth-child(2) > span'], any: [{ data: { messageKey: 'equalRatio', contrastRatio: 1, expectedContrastRatio: '4.5:1', fgColor: '#ffffff', bgColor: '#ffffff' } }] },
			{ impact: 'serious', target: ['.hero > h2'], any: [{ data: { messageKey: 'bgImage' } }] },
		],
	}, {
		id: 'link-in-text-block',
		impact: 'serious',
		nodes: [{ target: ['p > a'], any: [{ data: { messageKey: 'equalRatio' } }] }],
	}]

	it('a 1:1 contrast node from axe\'s incomplete results counts; other incomplete reasons do not', () => {
		const out = axeFindings([], { incomplete })
		expect(out).toHaveLength(1)
		expect(out[0].detail).toBe('color-contrast (serious): .actions > button:nth-child(2) > span — 1:1, needs 4.5:1 (#ffffff on #ffffff)')
	})

	it('incomplete is optional and adds to the violations', () => {
		expect(axeFindings([contrast])).toHaveLength(1)
		expect(axeFindings([contrast], { incomplete })).toHaveLength(2)
		expect(axeFindings([], { incomplete: undefined })).toEqual([])
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
