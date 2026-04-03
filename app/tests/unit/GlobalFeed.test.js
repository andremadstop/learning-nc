/**
 * GlobalFeed.vue unit tests.
 *
 * Tests: feed rendering, course name badges, pagination, empty state.
 * Uses component definition directly (no @vue/test-utils -- project pattern).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mock @nextcloud/axios
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

// Mock @nextcloud/router
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

// Mock @nextcloud/l10n
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, str) => str,
}))

// Mock @nextcloud/vue components
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: { name: 'NcLoadingIcon', template: '<span />' },
}))

import axios from '@nextcloud/axios'

import GlobalFeed from '../../src/components/GlobalFeed.vue'

/**
 * Creates a minimal component instance to access methods/data without mounting.
 */
function createFeedInstance() {
	const data = typeof GlobalFeed.data === 'function' ? GlobalFeed.data() : {}
	const instance = {
		...data,
		$emit: vi.fn(),
	}
	// Bind methods
	if (GlobalFeed.methods) {
		for (const [name, fn] of Object.entries(GlobalFeed.methods)) {
			instance[name] = fn.bind(instance)
		}
	}
	return instance
}

describe('GlobalFeed component definition', () => {
	it('exports a valid component with name', () => {
		expect(GlobalFeed.name).toBe('GlobalFeed')
	})

	it('has required data properties for items, loading, hasMore, offset', () => {
		const data = GlobalFeed.data()
		expect(data).toHaveProperty('items')
		expect(data).toHaveProperty('loading')
		expect(data).toHaveProperty('hasMore')
		expect(data).toHaveProperty('offset')
		expect(data.items).toEqual([])
		expect(data.offset).toBe(0)
	})
})

describe('GlobalFeed renders feed items with title and type badge', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('fetchFeed loads items from /api/feed with limit 10', async () => {
		const instance = createFeedInstance()
		const mockItems = [
			{ id: 1, type: 'announcement', title: 'Welcome', body: null, created_at: 1000, course_id: 1, course_name: 'Network+' },
			{ id: 2, type: 'pool_added', title: 'New Pool', body: null, created_at: 900, course_id: 2, course_name: 'Security+' },
		]
		axios.get.mockResolvedValueOnce({ data: { items: mockItems } })
		await instance.fetchFeed()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/feed', {
			params: { limit: 10, offset: 0 },
		})
		expect(instance.items).toHaveLength(2)
		expect(instance.items[0].title).toBe('Welcome')
	})

	it('typeLabel returns German label for known types', () => {
		const instance = createFeedInstance()
		expect(instance.typeLabel('announcement')).toBe('Ankuendigung')
		expect(instance.typeLabel('pool_added')).toBe('Neuer Pool')
		expect(instance.typeLabel('unknown_type')).toBe('unknown_type')
	})

	it('typeIcon returns icon for known types', () => {
		const instance = createFeedInstance()
		expect(instance.typeIcon('announcement')).toBeTruthy()
		expect(instance.typeIcon('milestone')).toBeTruthy()
	})
})

describe('GlobalFeed shows course name badge on each item', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('feed items contain course_name field for badge display', async () => {
		const instance = createFeedInstance()
		const mockItems = [
			{ id: 1, type: 'announcement', title: 'Test', body: null, created_at: 1000, course_id: 1, course_name: 'Network+' },
		]
		axios.get.mockResolvedValueOnce({ data: { items: mockItems } })
		await instance.fetchFeed()
		expect(instance.items[0].course_name).toBe('Network+')
	})
})

describe('GlobalFeed shows max 10 items initially', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('sets hasMore to true when exactly 10 items returned', async () => {
		const instance = createFeedInstance()
		const tenItems = Array.from({ length: 10 }, (_, i) => ({
			id: i, type: 'announcement', title: `Item ${i}`, body: null,
			created_at: 1000 - i, course_id: 1, course_name: 'Course',
		}))
		axios.get.mockResolvedValueOnce({ data: { items: tenItems } })
		await instance.fetchFeed()
		expect(instance.items).toHaveLength(10)
		expect(instance.hasMore).toBe(true)
	})

	it('sets hasMore to false when fewer than 10 items returned', async () => {
		const instance = createFeedInstance()
		const fiveItems = Array.from({ length: 5 }, (_, i) => ({
			id: i, type: 'announcement', title: `Item ${i}`, body: null,
			created_at: 1000 - i, course_id: 1, course_name: 'Course',
		}))
		axios.get.mockResolvedValueOnce({ data: { items: fiveItems } })
		await instance.fetchFeed()
		expect(instance.items).toHaveLength(5)
		expect(instance.hasMore).toBe(false)
	})
})

describe('GlobalFeed shows "Mehr laden" button when hasMore is true', () => {
	it('hasMore is true after receiving exactly limit items', async () => {
		const instance = createFeedInstance()
		const tenItems = Array.from({ length: 10 }, (_, i) => ({
			id: i, type: 'announcement', title: `Item ${i}`, body: null,
			created_at: 1000 - i, course_id: 1, course_name: 'Course',
		}))
		axios.get.mockResolvedValueOnce({ data: { items: tenItems } })
		await instance.fetchFeed()
		expect(instance.hasMore).toBe(true)
	})
})

describe('GlobalFeed shows empty state when no items', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('items stays empty and hasMore is false when no items returned', async () => {
		const instance = createFeedInstance()
		axios.get.mockResolvedValueOnce({ data: { items: [] } })
		await instance.fetchFeed()
		expect(instance.items).toHaveLength(0)
		expect(instance.hasMore).toBe(false)
	})
})

describe('GlobalFeed loadMore increments offset and fetches next batch', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('loadMore increments offset by 10 and calls fetchFeed', async () => {
		const instance = createFeedInstance()
		// First load
		const firstBatch = Array.from({ length: 10 }, (_, i) => ({
			id: i, type: 'announcement', title: `Item ${i}`, body: null,
			created_at: 1000 - i, course_id: 1, course_name: 'Course',
		}))
		axios.get.mockResolvedValueOnce({ data: { items: firstBatch } })
		await instance.fetchFeed()
		expect(instance.offset).toBe(0)

		// Load more
		const secondBatch = Array.from({ length: 5 }, (_, i) => ({
			id: 10 + i, type: 'pool_added', title: `Item ${10 + i}`, body: null,
			created_at: 900 - i, course_id: 2, course_name: 'Security+',
		}))
		axios.get.mockResolvedValueOnce({ data: { items: secondBatch } })
		await instance.loadMore()
		expect(instance.offset).toBe(10)
		expect(axios.get).toHaveBeenLastCalledWith('/apps/learning/api/feed', {
			params: { limit: 10, offset: 10 },
		})
		expect(instance.items).toHaveLength(15)
		expect(instance.hasMore).toBe(false)
	})
})
