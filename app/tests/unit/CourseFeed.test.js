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
		put: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('../../src/components/AccessibleDialog.vue', () => ({ default: { name: 'AccessibleDialog', template: '<div><slot /></div>' } }))

// Mock @nextcloud/router
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

// Mock @nextcloud/l10n
vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { setTestLanguage } from './support/l10nCatalog.js'
import { createApp, h, nextTick, reactive } from 'vue'
import GlobalFeed from '../../src/components/GlobalFeed.vue'

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
function createInstance(courseId = 5, canManage = false) {
	const data = typeof CourseFeed.data === 'function' ? CourseFeed.data() : {}
	return {
		...data,
		courseId,
		canManage,
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

// GitHub #40: the separate announcement list under "New announcement" duplicated the feed and was
// the only place to delete. Instructors now delete in the feed, which also removes the feed copy
// the old delete left behind for every course member.
describe('CourseFeed announcement delete (GitHub #40)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('offers delete only to instructors, and only on announcements', () => {
		const learner = createInstance(5, false)
		const instructor = createInstance(5, true)
		const linked = makeFeedItem({ meta: { announcement_id: 9 } })

		expect(learner.canDelete(linked)).toBe(false)
		expect(instructor.canDelete(linked)).toBe(true)
		expect(instructor.canDelete(makeFeedItem({ type: 'pool_added', meta: { pool_id: 3 } }))).toBe(false)
	})

	it('finds the announcement of a legacy feed row by title and creation time', () => {
		const instance = createInstance(5, true)
		instance.announcements = [
			{ id: 4, title: 'Willkommen im Kurs', created_at: 1700000000 },
			{ id: 5, title: 'Andere', created_at: 1700000000 },
		]

		expect(instance.announcementIdFor(makeFeedItem({ created_at: 1700000001 }))).toBe(4)
		expect(instance.announcementIdFor(makeFeedItem({ created_at: 1700000100 }))).toBe(null)
		expect(instance.canDelete(makeFeedItem({ created_at: 1700000100 }))).toBe(false)
	})

	it('loads the announcement list for instructors only', async () => {
		axios.get.mockResolvedValue({ data: { items: [], announcements: [] } })
		const learner = createInstance(5, false)
		CourseFeed.mounted.call(learner)
		expect(axios.get).not.toHaveBeenCalledWith('/apps/learning/api/courses/{courseId}/announcements')

		const instructor = createInstance(5, true)
		CourseFeed.mounted.call(instructor)
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/{courseId}/announcements')
	})

	it('deletes through the existing announcement route after the confirmation and drops the item', async () => {
		axios.delete.mockResolvedValue({})
		const instance = createInstance(5, true)
		const item = makeFeedItem({ id: 31, meta: { announcement_id: 9 } })
		const other = makeFeedItem({ id: 32, meta: { announcement_id: 10 } })
		instance.items = [item, other]

		instance.requestDelete(item)
		expect(axios.delete).not.toHaveBeenCalled()
		expect(instance.confirmItem).toBe(item)

		await instance.confirmDelete()

		expect(axios.delete).toHaveBeenCalledWith('/apps/learning/api/courses/{courseId}/announcements/{announcementId}')
		expect(instance.items).toEqual([other])
		expect(instance.confirmItem).toBe(null)
		expect(showError).not.toHaveBeenCalled()
	})

	it('cancelling the confirmation deletes nothing', () => {
		const instance = createInstance(5, true)
		instance.requestDelete(makeFeedItem({ meta: { announcement_id: 9 } }))
		instance.cancelDelete()
		expect(instance.confirmItem).toBe(null)
		expect(axios.delete).not.toHaveBeenCalled()
	})

	it('a refused delete is shown and keeps the item', async () => {
		axios.delete.mockRejectedValue({ response: { status: 403 } })
		const instance = createInstance(5, true)
		const item = makeFeedItem({ id: 31, meta: { announcement_id: 9 } })
		instance.items = [item]
		instance.requestDelete(item)

		await instance.confirmDelete()

		expect(showError).toHaveBeenCalledTimes(1)
		expect(instance.items).toEqual([item])
		expect(instance.deleting).toBe(false)
	})
})

// GitHub #40: multi-line announcements ran together in the feed. Text interpolation, no v-html.
describe('feed body keeps line breaks (GitHub #40)', () => {
	for (const file of ['CourseFeed.vue', 'GlobalFeed.vue']) {
		it(`${file} renders the body as text with pre-wrap`, () => {
			const source = readFileSync(resolve(__dirname, `../../src/components/${file}`), 'utf8')
			const rule = source.match(/\.feed-body\s*\{[^}]*\}/)?.[0] || ''
			expect(rule).toMatch(/white-space:\s*pre-wrap/)
			expect(source).toMatch(/class="feed-body">\{\{ item\.body \}\}/)
			expect(source.split('</template>')[0]).not.toMatch(/v-html=/)
		})
	}
})

// GitHub #40: announcements could only be deleted. Edit sits next to Delete, saves through the
// PUT route, and the feed row changes in place with an "edited" marker.
describe('CourseFeed announcement edit (GitHub #40)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	function mountFeed(component, props) {
		const el = document.createElement('div')
		document.body.appendChild(el)
		const app = createApp(component, props)
		const vm = app.mount(el)
		return { vm, el, unmount: () => { app.unmount(); el.remove() } }
	}

	it('renders Edit only for instructors and only on announcements, and marks edited items', async () => {
		axios.get.mockImplementation((url) => Promise.resolve({
			data: url.endsWith('/feed')
				? {
					items: [
						makeFeedItem({ id: 1, meta: { announcement_id: 9, edited_at: 1700005000 } }),
						makeFeedItem({ id: 2, type: 'pool_added', title: 'Pool', meta: { pool_id: 3 } }),
					],
				}
				: { announcements: [] },
		}))

		const learner = mountFeed(CourseFeed, { courseId: 5, canManage: false })
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
		expect(learner.el.querySelectorAll('.feed-edit')).toHaveLength(0)
		expect(learner.el.querySelectorAll('.feed-edited')).toHaveLength(1)
		learner.unmount()

		const instructor = mountFeed(CourseFeed, { courseId: 5, canManage: true })
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
		const edits = instructor.el.querySelectorAll('.feed-edit')
		expect(edits).toHaveLength(1)
		expect(edits[0].closest('.feed-item').classList.contains('feed-type--announcement')).toBe(true)
		instructor.unmount()
	})

	// The feed tab stays mounted when the instructor turns the student view off. The legacy
	// row (no meta.announcement_id) is matched through the announcement list, which was only
	// loaded in mounted(), so it stayed without buttons until a remount.
	it('loads the announcement list when the student view is turned off on an open feed', async () => {
		const legacy = makeFeedItem({ id: 1, created_at: 1700000000 })
		axios.get.mockImplementation((url) => Promise.resolve({
			data: url.endsWith('/feed')
				? { items: [legacy] }
				: { announcements: [{ id: 4, title: legacy.title, created_at: 1700000001 }] },
		}))
		const state = reactive({ canManage: false })
		const { el, unmount } = mountFeed({ render: () => h(CourseFeed, { courseId: 5, canManage: state.canManage }) })
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
		expect(axios.get).not.toHaveBeenCalledWith('/apps/learning/api/courses/{courseId}/announcements')
		expect(el.querySelectorAll('.feed-delete')).toHaveLength(0)

		state.canManage = true
		await nextTick()
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/{courseId}/announcements')
		expect(el.querySelectorAll('.feed-delete')).toHaveLength(1)
		expect(el.querySelectorAll('.feed-edit')).toHaveLength(1)
		unmount()
	})

	it('GlobalFeed shows the edited marker too', async () => {
		axios.get.mockResolvedValue({ data: { items: [makeFeedItem({ id: 1, meta: { announcement_id: 9, edited_at: 1700005000 } }), makeFeedItem({ id: 2 })] } })
		const { el, unmount } = mountFeed(GlobalFeed, {})
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
		expect(el.querySelectorAll('.feed-edited')).toHaveLength(1)
		unmount()
	})

	it('opens the dialog with the stored text and PUTs title and body', async () => {
		axios.put.mockResolvedValue({ data: { announcement: { id: 9, title: 'Neu', body: 'Zeile 1\nZeile 2', edited_at: 1700005000 } } })
		const instance = createInstance(5, true)
		const item = makeFeedItem({ id: 31, title: 'Alt', body: 'alt', meta: { announcement_id: 9 } })
		const other = makeFeedItem({ id: 32, title: 'Andere', meta: { announcement_id: 10 } })
		instance.items = [item, other]

		instance.requestEdit(item)
		expect(instance.editItem).toBe(item)
		expect(instance.editTitle).toBe('Alt')
		expect(instance.editBody).toBe('alt')

		instance.editTitle = 'Neu'
		instance.editBody = 'Zeile 1\nZeile 2'
		await instance.saveEdit()

		expect(axios.put).toHaveBeenCalledWith(
			'/apps/learning/api/courses/{courseId}/announcements/{announcementId}',
			{ title: 'Neu', body: 'Zeile 1\nZeile 2' },
		)
		expect(instance.items[0]).toMatchObject({ id: 31, title: 'Neu', body: 'Zeile 1\nZeile 2', meta: { announcement_id: 9, edited_at: 1700005000 } })
		expect(instance.items[1]).toBe(other)
		expect(instance.editItem).toBe(null)
		expect(instance.isEdited(instance.items[0])).toBe(true)
		expect(showError).not.toHaveBeenCalled()
	})

	it('a legacy row keeps its buttons after the edit (the announcement list follows the new title)', async () => {
		axios.put.mockResolvedValue({ data: { announcement: { id: 4, title: 'Neuer Titel', body: '', edited_at: 1700005000 } } })
		const instance = createInstance(5, true)
		instance.announcements = [{ id: 4, title: 'Willkommen im Kurs', created_at: 1700000000 }]
		const legacy = makeFeedItem({ id: 40, created_at: 1700000001, meta: null })
		instance.items = [legacy]

		instance.requestEdit(legacy)
		instance.editTitle = 'Neuer Titel'
		await instance.saveEdit()

		expect(instance.announcementIdFor(instance.items[0])).toBe(4)
		expect(instance.canDelete(instance.items[0])).toBe(true)
		expect(instance.announcements[0].title).toBe('Neuer Titel')
	})

	it('an empty title is not sent', async () => {
		const instance = createInstance(5, true)
		instance.requestEdit(makeFeedItem({ meta: { announcement_id: 9 } }))
		instance.editTitle = '   '
		await instance.saveEdit()
		expect(axios.put).not.toHaveBeenCalled()
	})

	it('a refused edit is shown, keeps the dialog open with the typed text and changes nothing', async () => {
		axios.put.mockRejectedValue({ response: { status: 403 } })
		const instance = createInstance(5, true)
		const item = makeFeedItem({ id: 31, title: 'Alt', meta: { announcement_id: 9 } })
		instance.items = [item]
		instance.requestEdit(item)
		instance.editTitle = 'Neu'

		await instance.saveEdit()

		expect(showError).toHaveBeenCalledTimes(1)
		expect(instance.items).toEqual([item])
		expect(instance.editItem).toBe(item)
		expect(instance.editTitle).toBe('Neu')
		expect(instance.saving).toBe(false)
	})

	it('cancelling the dialog saves nothing', () => {
		const instance = createInstance(5, true)
		instance.requestEdit(makeFeedItem({ meta: { announcement_id: 9 } }))
		instance.cancelEdit()
		expect(instance.editItem).toBe(null)
		expect(axios.put).not.toHaveBeenCalled()
	})

	it('the dialog fields take the full width (core fixes textareas at 130px)', () => {
		const source = readFileSync(resolve(__dirname, '../../src/components/CourseFeed.vue'), 'utf8')
		const style = source.slice(source.indexOf('<style'))
		expect(style).toMatch(/\.feed-edit-textarea\s*\{[^}]*width:\s*100%/)
	})
})

// Review of #40: after a confirmed delete the row with the Delete button that opened the dialog
// is gone, and the dialog's focus trap returned focus to that detached button, so keyboard focus
// fell to <body> (WCAG 2.4.3). Focus now moves to the next remaining Delete button, else the
// previous one, else the feed itself.
describe('CourseFeed focus after delete (GitHub #40)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	async function mountWith(items) {
		axios.get.mockImplementation((url) => Promise.resolve({
			data: url.endsWith('/feed') ? { items } : { announcements: [] },
		}))
		axios.delete.mockResolvedValue({})
		const el = document.createElement('div')
		document.body.appendChild(el)
		const app = createApp(CourseFeed, { courseId: 5, canManage: true })
		const vm = app.mount(el)
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
		return { vm, el, unmount: () => { app.unmount(); el.remove() } }
	}

	async function deleteRow(vm, el, feedId) {
		const button = el.querySelector(`[data-feed-id="${feedId}"] .feed-delete`)
		button.focus()
		expect(document.activeElement).toBe(button)
		vm.requestDelete(vm.items.find((i) => i.id === feedId))
		await vm.confirmDelete()
		await nextTick()
		await nextTick()
	}

	it('moves focus to the Delete button of the next announcement', async () => {
		const { vm, el, unmount } = await mountWith([
			makeFeedItem({ id: 1, meta: { announcement_id: 9 } }),
			makeFeedItem({ id: 2, type: 'pool_added', title: 'Pool', meta: { pool_id: 3 } }),
			makeFeedItem({ id: 3, meta: { announcement_id: 10 } }),
		])
		await deleteRow(vm, el, 1)
		expect(document.activeElement).not.toBe(document.body)
		expect(document.activeElement).toBe(el.querySelector('[data-feed-id="3"] .feed-delete'))
		unmount()
	})

	it('falls back to the previous announcement, then to the feed', async () => {
		const { vm, el, unmount } = await mountWith([
			makeFeedItem({ id: 1, meta: { announcement_id: 9 } }),
			makeFeedItem({ id: 2, meta: { announcement_id: 10 } }),
		])
		await deleteRow(vm, el, 2)
		expect(document.activeElement).toBe(el.querySelector('[data-feed-id="1"] .feed-delete'))

		await deleteRow(vm, el, 1)
		expect(document.activeElement).toBe(el.querySelector('.course-feed'))
		unmount()
	})
})
