/**
 * StudentKnowledgeContribute (2026-11 audit #189): the list showed one entry per stored chunk, so a
 * long contribution appeared several times under one title, and the counter called chunks
 * "Beiträge". Dates were always de-DE, and blocked localStorage threw while the component was created.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, str, vars) => (vars ? str.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : str),
	getLanguage: () => 'en',
}))

import axios from '@nextcloud/axios'
import StudentKnowledgeContribute, { groupContributions } from '../../src/components/StudentKnowledgeContribute.vue'

const T = 1_731_500_000
const chunk = (id, title, createdAt, status = 'pending') => ({ id, source_file: title, created_at: createdAt, status })

let host, app

async function mount() {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(StudentKnowledgeContribute, { courseId: 3 })
	app.config.warnHandler = () => {}
	app.mount(host)
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
		await nextTick()
	}
}

beforeEach(() => {
	vi.restoreAllMocks()
	axios.get.mockResolvedValue({ data: [
		chunk(1, 'OSI-Modell', T), chunk(2, 'OSI-Modell', T), chunk(3, 'OSI-Modell', T), chunk(4, 'OSI-Modell', T),
		chunk(5, 'Subnetting', T + 60, 'approved'),
	] })
})
afterEach(() => { app?.unmount(); host?.remove() })

describe('groupContributions', () => {
	it('one entry per contribution, approved only when every chunk is', () => {
		const groups = groupContributions([
			chunk(1, 'A', T, 'approved'), chunk(2, 'A', T, 'pending'),
			chunk(3, 'B', T, 'approved'), chunk(4, 'B', T, 'approved'),
			chunk(5, 'A', T + 1, 'rejected'),
		])
		expect(groups.map((g) => [g.title, g.status, g.chunks])).toEqual([
			['A', 'pending', 2], ['B', 'approved', 2], ['A', 'rejected', 1],
		])
	})
})

describe('StudentKnowledgeContribute', () => {
	it('lists a four-chunk contribution once and counts sections, not contributions', async () => {
		await mount()
		const titles = [...host.querySelectorAll('.contribution-title')].map((el) => el.textContent.trim())
		expect(titles).toEqual(['OSI-Modell', 'Subnetting'])
		expect(host.querySelector('.contribute-counter').textContent.trim()).toBe('5 / 50 Abschnitte')
	})

	it('formats dates in the reader\'s language', async () => {
		const spy = vi.spyOn(Date.prototype, 'toLocaleDateString')
		await mount()
		expect(spy).toHaveBeenCalled()
		expect(spy.mock.calls.every(([locale]) => locale === 'en')).toBe(true)
	})

	it('blocked storage neither breaks the component nor hides the info dialog', async () => {
		vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('SecurityError') })
		vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('SecurityError') })
		await mount()
		expect(host.querySelector('.swarm-consent-dialog')).not.toBeNull()
		host.querySelector('.swarm-consent-btn').click()
		await nextTick()
		expect(host.querySelector('.swarm-consent-dialog')).toBeNull()
	})
})
