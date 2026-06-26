import { describe, expect, it, vi, beforeEach } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		patch: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

import axios from '@nextcloud/axios'
import { getPassStatus, updateCertConfig } from '../../src/services/CourseService.js'

describe('CourseService — Phase 154 additions', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	describe('getPassStatus', () => {
		it('calls the pass-status endpoint for the given courseId', async () => {
			const mockData = {
				applicable: true,
				passed: false,
				score: 70,
				threshold: 80,
				poolsMastered: false,
				passedAt: null,
			}
			axios.get.mockResolvedValueOnce({ data: mockData })

			const result = await getPassStatus(7)

			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('/apps/learning/api/courses/7/pass-status'),
			)
			expect(result).toEqual(mockData)
		})

		it('returns applicable=false when cert is disabled for the course', async () => {
			axios.get.mockResolvedValueOnce({ data: { applicable: false, passed: false, score: null, threshold: 80, poolsMastered: false, passedAt: null } })
			const result = await getPassStatus(3)
			expect(result.applicable).toBe(false)
		})
	})

	describe('updateCertConfig', () => {
		it('sends a PATCH request with the config payload and courseId', async () => {
			const config = { certEnabled: true, certPassPercent: 80 }
			const responseData = {
				certEnabled: true,
				certPassPercent: 80,
				certRequiredPoolIds: [],
				certValidityDays: 0,
			}
			axios.patch.mockResolvedValueOnce({ data: responseData })

			const result = await updateCertConfig(7, config)

			expect(axios.patch).toHaveBeenCalledWith(
				expect.stringContaining('/apps/learning/api/courses/7/cert-config'),
				config,
			)
			expect(result).toEqual(responseData)
		})
	})
})
