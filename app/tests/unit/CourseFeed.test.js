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
vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())

import axios from '@nextcloud/axios'
import { setTestLanguage } from './support/l10nCatalog.js'

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

// Audit #177: the labels were a German object literal outside t() and read 'Ankuendigung' in
// every language. The l10n mock answers from the real catalogues, so these only pass if the label
// goes through t() with a key the catalogue has.
describe('CourseFeed.typeLabel()', () => {
	const instance = createInstance()

	it('labels every type in German for a German reader, with umlauts', () => {
		setTestLanguage('de')
		expect(instance.typeLabel('announcement')).toBe('Ankündigung')
		expect(instance.typeLabel('pool_added')).toBe('Neuer Pool')
		expect(instance.typeLabel('milestone')).toBe('Meilenstein')
		expect(instance.typeLabel('content_update')).toBe('Aktualisierung')
		expect(instance.typeLabel('progress')).toBe('Fortschritt')
	})

	it('labels every type in the reader\'s language', () => {
		setTestLanguage('en')
		expect(instance.typeLabel('announcement')).toBe('Announcement')
		expect(instance.typeLabel('pool_added')).toBe('New pool')
		expect(instance.typeLabel('milestone')).toBe('Milestone')
		expect(instance.typeLabel('content_update')).toBe('Update')
		expect(instance.typeLabel('progress')).toBe('Progress')
		setTestLanguage('uk')
		expect(instance.typeLabel('announcement')).toBe('Оголошення')
		setTestLanguage('de')
	})

	it('returns the type itself for unknown types', () => {
		expect(instance.typeLabel('unknown_thing')).toBe('unknown_thing')
	})
})

// Audit #154: a "new pool" item carries the pool name in meta and is titled in the reader's language.
describe('CourseFeed.itemTitle()', () => {
	const instance = createInstance()

	it('titles a new pool in the reader\'s language from meta.pool_name', () => {
		setTestLanguage('fr')
		const item = makeFeedItem({ type: 'pool_added', title: 'Neuer Fragenpool: Subnetting', meta: { pool_id: 3, pool_name: 'Subnetting' } })
		expect(instance.itemTitle(item)).toBe('Nouveau pool de questions : Subnetting')
		setTestLanguage('de')
	})

	it('keeps the stored title of an older row without pool_name, and of instructor items', () => {
		setTestLanguage('en')
		expect(instance.itemTitle(makeFeedItem({ type: 'pool_added', title: 'Neuer Fragenpool: Alt', meta: { pool_id: 3 } }))).toBe('Neuer Fragenpool: Alt')
		expect(instance.itemTitle(makeFeedItem({ title: 'Willkommen im Kurs' }))).toBe('Willkommen im Kurs')
		setTestLanguage('de')
	})

	it('does not HTML-escape the pool name (Vue renders it as text)', () => {
		const item = makeFeedItem({ type: 'pool_added', meta: { pool_name: 'R&D <intern>' } })
		expect(instance.itemTitle(item)).toBe('Neuer Fragenpool: R&D <intern>')
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

// Audit #177/#183: 'gerade eben' / 'vor 3 Stunden' were German literals for every reader.
describe('CourseFeed.formatRelativeTime()', () => {
	const instance = createInstance()
	const now = () => Math.floor(Date.now() / 1000)

	it('speaks German to a German reader', () => {
		setTestLanguage('de')
		expect(instance.formatRelativeTime(now() - 10)).toBe('jetzt')
		expect(instance.formatRelativeTime(now() - 90)).toBe('vor 1 Minute')
		expect(instance.formatRelativeTime(now() - 300)).toBe('vor 5 Minuten')
		expect(instance.formatRelativeTime(now() - 7200)).toBe('vor 2 Stunden')
		expect(instance.formatRelativeTime(now() - 86400 * 3)).toBe('vor 3 Tagen')
	})

	it('speaks the reader\'s language, with its plural forms', () => {
		setTestLanguage('en')
		expect(instance.formatRelativeTime(now() - 10)).toBe('now')
		expect(instance.formatRelativeTime(now() - 7200)).toBe('2 hours ago')
		setTestLanguage('fr')
		expect(instance.formatRelativeTime(now() - 86400 * 3)).toBe('il y a 3 jours')
		setTestLanguage('ru')
		expect(instance.formatRelativeTime(now() - 300)).toBe('5 минут назад')
		setTestLanguage('de')
	})

	it('shows the date in the reader\'s format after 30 days', () => {
		// 2026-01-15 00:00:00 UTC
		setTestLanguage('de')
		expect(instance.formatRelativeTime(1768521600)).toMatch(/^\d{1,2}\.\d{1,2}\.2026$/)
		setTestLanguage('en')
		expect(instance.formatRelativeTime(1768521600)).toMatch(/^\d{1,2}\/\d{1,2}\/2026$/)
		setTestLanguage('de')
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
