import { describe, expect, it } from 'vitest'
import {
	buildAuthSequence,
	buildShuffledSequence,
	getMethodComparison,
	getMethodDetails,
	validateSequence,
} from '../../src/utils/authFlowEngine.js'

describe('getMethodComparison', () => {
	it('returns three 802.1X methods', () => {
		expect(getMethodComparison()).toHaveLength(3)
	})
})

describe('getMethodDetails', () => {
	it.each([
		['eap-tls', 'EAP-TLS'],
		['peap', 'PEAP'],
		['eap-fast', 'EAP-FAST'],
	])('returns metadata for %s', (method, label) => {
		expect(getMethodDetails(method).label).toBe(label)
	})
})

describe('buildAuthSequence', () => {
	it('builds eight ordered steps for EAP-TLS', () => {
		expect(buildAuthSequence('eap-tls')).toHaveLength(8)
	})

	it('ends with Access-Reject for reject outcomes', () => {
		expect(buildAuthSequence('peap', 'reject').at(-1).label).toBe('Access-Reject')
	})

	it('includes the method-specific challenge text', () => {
		expect(buildAuthSequence('eap-fast')[5].detail).toContain('PAC')
	})
})

describe('validateSequence', () => {
	it('accepts the correct order', () => {
		const expectedOrder = buildAuthSequence('eap-tls').map((step) => step.id)
		expect(validateSequence(expectedOrder, 'eap-tls').isCorrect).toBe(true)
	})

	it('counts correct positions for a shuffled order', () => {
		const shuffled = buildShuffledSequence('peap').map((step) => step.id)
		expect(validateSequence(shuffled, 'peap').correctCount).toBeLessThan(8)
	})

	it('keeps the expected index metadata', () => {
		const expectedOrder = buildAuthSequence('eap-fast').map((step) => step.id)
		expect(validateSequence(expectedOrder, 'eap-fast').steps[0].expectedIndex).toBe(0)
	})
})

describe('buildShuffledSequence', () => {
	it('returns eight steps in a non-default order', () => {
		const shuffled = buildShuffledSequence('eap-tls').map((step) => step.id)
		expect(shuffled).toHaveLength(8)
		expect(shuffled[0]).not.toBe(buildAuthSequence('eap-tls')[0].id)
	})
})
