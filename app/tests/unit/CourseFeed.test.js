/**
 * CourseFeed.vue unit tests.
 *
 * Tests component logic: data model, fetch behavior, type labels/icons,
 * and relative time formatting. Uses component definition directly
 * (no @vue/test-utils needed — consistent with project test patterns).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mock @nextcloud/axios
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
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

import axios from '@nextcloud/axios'

// Import the component definition
import CourseFeed from '../../src/components/CourseFeed.vue'

function makeFeedItem(overrides = {}) {
	return {
		id: 1,
		course_id: 5,
		type: 'announcement',
		title: 'Willkommen im Kurs',
		body: 'Heute starten wir...',
		actor_id: 'dozent1',
		meta: null,
		created_at: Math.floor(Date.now() / 1000) - 120,
		...overrides,
	}
}

/**
 * Creates a minimal component instance to access methods without mounting.
 */
function createInstance(courseId = 5) {
	const data = typeof CourseFeed.data === 'function' ? CourseFeed.data() : {}
	return {
		...data,
		courseId,
		...CourseFeed.methods,
	}
}

describe('CourseFeed component definition', () => {
	it('has required props', () => {
		expect(CourseFeed.props.courseId).toBeDefined()
		expect(CourseFeed.props.courseId.type).toBe(Number)
		expect(CourseFeed.props.courseId.required).toBe(true)
	})

	it('initializes with correct default data', () => {
		const data = CourseFeed.data()
		expect(data.items).toEqual([])
		expect(data.loading).toBe(false)
		expect(data.hasMore).toBe(false)
		expect(data.offset).toBe(0)
	})
})

describe('CourseFeed.typeLabel()', () => {
	const instance = createInstance()

	it('maps announcement to Ankuendigung', () => {
		expect(instance.typeLabel('announcement')).toBe('Ankuendigung')
	})

	it('maps pool_added to Neuer Pool', () => {
		expect(instance.typeLabel('pool_added')).toBe('Neuer Pool')
	})

	it('maps milestone to Meilenstein', () => {
		expect(instance.typeLabel('milestone')).toBe('Meilenstein')
	})

	it('maps content_update to Aktualisierung', () => {
		expect(instance.typeLabel('content_update')).toBe('Aktualisierung')
	})

	it('maps progress to Fortschritt', () => {
		expect(instance.typeLabel('progress')).toBe('Fortschritt')
	})

	it('returns capitalized type for unknown types', () => {
		expect(instance.typeLabel('unknown_thing')).toBe('unknown_thing')
	})
})

describe('CourseFeed.typeIcon()', () => {
	const instance = createInstance()

	it('returns correct emoji for each type', () => {
		expect(instance.typeIcon('announcement')).toBe('\uD83D\uDCE2')
		expect(instance.typeIcon('pool_added')).toBe('\uD83D\uDCDA')
		expect(instance.typeIcon('milestone')).toBe('\u2B50')
		expect(instance.typeIcon('content_update')).toBe('\uD83D\uDD04')
		expect(instance.typeIcon('progress')).toBe('\uD83D\uDCCA')
	})

	it('returns default icon for unknown type', () => {
		expect(instance.typeIcon('unknown')).toBe('\uD83D\uDCCC')
	})
})

describe('CourseFeed.formatRelativeTime()', () => {
	const instance = createInstance()

	it('returns "gerade eben" for timestamps less than 60 seconds ago', () => {
		const now = Math.floor(Date.now() / 1000)
		expect(instance.formatRelativeTime(now - 10)).toBe('gerade eben')
		expect(instance.formatRelativeTime(now)).toBe('gerade eben')
	})

	it('returns "vor X Minuten" for timestamps within the hour', () => {
		const now = Math.floor(Date.now() / 1000)
		expect(instance.formatRelativeTime(now - 120)).toBe('vor 2 Minuten')
		expect(instance.formatRelativeTime(now - 300)).toBe('vor 5 Minuten')
	})

	it('returns "vor 1 Minute" for singular', () => {
		const now = Math.floor(Date.now() / 1000)
		expect(instance.formatRelativeTime(now - 90)).toBe('vor 1 Minute')
	})

	it('returns "vor X Stunden" for timestamps within the day', () => {
		const now = Math.floor(Date.now() / 1000)
		expect(instance.formatRelativeTime(now - 7200)).toBe('vor 2 Stunden')
	})

	it('returns "vor 1 Stunde" for singular', () => {
		const now = Math.floor(Date.now() / 1000)
		expect(instance.formatRelativeTime(now - 3600)).toBe('vor 1 Stunde')
	})

	it('returns "vor X Tagen" for timestamps within 30 days', () => {
		const now = Math.floor(Date.now() / 1000)
		expect(instance.formatRelativeTime(now - 86400 * 3)).toBe('vor 3 Tagen')
	})

	it('returns "vor 1 Tag" for singular', () => {
		const now = Math.floor(Date.now() / 1000)
		expect(instance.formatRelativeTime(now - 86400)).toBe('vor 1 Tag')
	})

	it('returns DD.MM.YYYY for timestamps older than 30 days', () => {
		// 2026-01-15 00:00:00 UTC
		const result = instance.formatRelativeTime(1768521600)
		expect(result).toMatch(/^\d{2}\.\d{2}\.\d{4}$/)
	})
})

describe('CourseFeed.fetchFeed()', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('sets loading true during fetch and false after', async () => {
		const items = [makeFeedItem()]
		axios.get.mockResolvedValue({ data: { items } })
		const instance = createInstance()

		// Bind 'this' properly
		const fetchFeed = CourseFeed.methods.fetchFeed.bind(instance)
		await fetchFeed()

		expect(instance.loading).toBe(false)
		expect(instance.items).toEqual(items)
	})

	it('sets hasMore true when 50 items returned', async () => {
		const items = Array.from({ length: 50 }, (_, i) =>
			makeFeedItem({ id: i + 1 }),
		)
		axios.get.mockResolvedValue({ data: { items } })
		const instance = createInstance()

		const fetchFeed = CourseFeed.methods.fetchFeed.bind(instance)
		await fetchFeed()

		expect(instance.hasMore).toBe(true)
	})

	it('sets hasMore false when fewer than 50 items returned', async () => {
		const items = [makeFeedItem()]
		axios.get.mockResolvedValue({ data: { items } })
		const instance = createInstance()

		const fetchFeed = CourseFeed.methods.fetchFeed.bind(instance)
		await fetchFeed()

		expect(instance.hasMore).toBe(false)
	})

	it('appends items on loadMore', async () => {
		const batch1 = Array.from({ length: 50 }, (_, i) =>
			makeFeedItem({ id: i + 1 }),
		)
		const batch2 = [makeFeedItem({ id: 51 })]

		axios.get
			.mockResolvedValueOnce({ data: { items: batch1 } })
			.mockResolvedValueOnce({ data: { items: batch2 } })

		const instance = createInstance()
		const fetchFeed = CourseFeed.methods.fetchFeed.bind(instance)
		const loadMore = CourseFeed.methods.loadMore.bind(instance)

		await fetchFeed()
		expect(instance.items).toHaveLength(50)
		expect(instance.offset).toBe(0)

		await loadMore()
		expect(instance.items).toHaveLength(51)
		expect(instance.offset).toBe(50)
		expect(instance.hasMore).toBe(false)
	})
})
