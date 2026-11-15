/**
 * Lückencheck B2: helpers of the coverage list in the compliance report (every enrolled learner,
 * with or without a certificate).
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { buildCoverageQuery, COVERAGE_STATUSES, formatPoolProgress } from '../../src/utils/cert-report.js'

describe('coverage report helpers (B2)', () => {
	it('lists the same statuses the server accepts as a filter', () => {
		const php = readFileSync(resolve(__dirname, '../../lib/Service/CertificateReportService.php'), 'utf8')
		const match = php.match(/COVERAGE_STATUSES = \[([^\]]*)\]/)
		expect(match).not.toBeNull()
		const server = [...match[1].matchAll(/'([a-z_]+)'/g)].map((m) => m[1])
		expect(COVERAGE_STATUSES).toEqual(server)
	})

	it('builds a query only for a known status', () => {
		expect(buildCoverageQuery('overdue')).toBe('status=overdue')
		expect(buildCoverageQuery('never_passed')).toBe('status=never_passed')
		expect(buildCoverageQuery('')).toBe('')
		expect(buildCoverageQuery(null)).toBe('')
		expect(buildCoverageQuery('x&y=1')).toBe('')
	})

	it('shows required pools as passed/total, a dash without required pools', () => {
		expect(formatPoolProgress(1, 3)).toBe('1/3')
		expect(formatPoolProgress(0, 2)).toBe('0/2')
		expect(formatPoolProgress(0, 0)).toBe('—')
		expect(formatPoolProgress(undefined, undefined)).toBe('—')
	})

	it('labels every status in the component', () => {
		const vue = readFileSync(resolve(__dirname, '../../src/components/CourseTabTeilnehmer.vue'), 'utf8')
		for (const status of COVERAGE_STATUSES) {
			expect(vue).toMatch(new RegExp(`case '${status}': return t\\('learning', '[^']+ \\(Abdeckungsstatus\\)'\\)`))
		}
	})
})
