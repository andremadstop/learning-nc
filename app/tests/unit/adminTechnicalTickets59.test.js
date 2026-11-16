/**
 * GitHub #59 (admin side): technical tickets reached the admin inbox without telling anyone,
 * and the inbox could only answer them. The admin page now
 *  - picks the administrators who are notified of a new technical ticket (the #60 picker, in
 *    its "ticket-recipients" kind: administrators only), saved on its own, and says plainly when
 *    nobody would be notified or when a chosen person gets no notice;
 *  - archives, restores and deletes technical tickets, like instructors do with the questions
 *    of their course; the open count is the server's, so it stays right in the archive view.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), url)),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, str) => str }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'disabled'],
		emits: ['search', 'update:modelValue'],
		template: '<div class="select-stub" :data-label="inputLabel"><span v-for="o in modelValue" :key="o.id" class="chip">{{ o.label }}</span></div>',
	},
}))
vi.mock('../../src/components/AccessibleDialog.vue', () => ({
	default: { name: 'AccessibleDialog', props: ['name'], emits: ['closing'], template: '<div class="dialog-stub" :data-name="name"><slot /><slot name="actions" /></div>' },
}))
vi.mock('../../src/components/OperatorSettings.vue', () => ({ default: { name: 'OperatorSettings', template: '<div />' } }))

globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)

import axios from '@nextcloud/axios'
import AdminSettings from '../../src/components/AdminSettings.vue'
import InstructorPicker from '../../src/components/InstructorPicker.vue'

const TICKETS = [
	{ id: 1, subject: 'Login geht nicht', message: 'm', status: 'open', user_id: 'learner' },
	{ id: 2, subject: 'PDF fehlt', message: 'm', status: 'answered', answer_text: 'Erledigt.', user_id: 'learner' },
]

function stubServer({ recipients = [], tickets = TICKETS, archived = [], openCount = 1 } = {}) {
	axios.get.mockImplementation((url, config) => {
		if (url.includes('/ticket-recipients')) return Promise.resolve({ data: { users: recipients } })
		if (url.includes('/support-tickets')) {
			const list = config?.params?.archived ? archived : tickets
			return Promise.resolve({ data: { tickets: list, open_count: openCount } })
		}
		if (url.includes('/settings/tools')) return Promise.resolve({ data: { enabled_tools: [] } })
		if (url.includes('/instructors')) return Promise.resolve({ data: { groups: [], users: [] } })
		if (url.includes('/audit')) return Promise.resolve({ data: { events: [] } })
		return Promise.resolve({ data: {} })
	})
}

async function flush() {
	for (let i = 0; i < 6; i++) {
		await Promise.resolve()
		await nextTick()
	}
}

describe('#59 technical tickets on the admin page', () => {
	let host, app, vm

	async function mount() {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({
			components: { AdminSettings },
			template: '<AdminSettings ref="s" />',
			mounted() { vm = this.$refs.s },
		})
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
		await flush()
	}

	beforeEach(() => {
		vi.clearAllMocks()
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
		vi.useRealTimers()
	})

	it('says that nobody is notified while no recipient is saved, and saves the chosen ones on their own', async () => {
		stubServer()
		await mount()
		expect(host.querySelector('.ticket-recipients .select-stub').dataset.label).toBe('Bei neuen technischen Anfragen benachrichtigen')
		expect(host.querySelector('.ticket-recipients-none')?.textContent).toContain('Niemand wird über neue technische Anfragen benachrichtigt')

		// an unsaved choice does not hide the warning: it is about what is stored
		vm.ticketRecipients = [{ id: 'user:ops', type: 'user', value: 'ops', label: 'Ops' }]
		await flush()
		expect(host.querySelector('.ticket-recipients-none')).not.toBeNull()

		axios.put.mockResolvedValue({ data: { users: [{ id: 'ops', label: 'Ops', notified: true }] } })
		await vm.saveTicketRecipients()
		await flush()
		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/settings/admin/ticket-recipients', { userIds: ['ops'] })
		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(host.querySelector('.ticket-recipients-none')).toBeNull()
		expect(host.querySelector('.ticket-recipients-silent')).toBeNull()
		expect(host.textContent).toContain('Empfänger gespeichert.')
	})

	it('names a chosen person who gets no notice (no administrator, disabled or deleted)', async () => {
		stubServer({ recipients: [{ id: 'ops', label: 'Ops', notified: true }, { id: 'plain', label: 'Plain User', notified: false }] })
		await mount()
		expect(host.querySelector('.ticket-recipients-none')).toBeNull()
		expect(host.querySelector('.ticket-recipients-silent').textContent).toContain('Plain User')
		expect(host.querySelector('.ticket-recipients-silent').textContent).not.toContain('Ops')
	})

	it('never saves a recipient list that did not load', async () => {
		axios.get.mockImplementation((url) => (url.includes('/ticket-recipients')
			? Promise.reject(new Error('down'))
			: Promise.resolve({ data: url.includes('/support-tickets') ? { tickets: [], open_count: 0 } : {} })))
		await mount()
		expect(host.querySelector('.ticket-recipients-error').textContent.trim()).toBe('Die Empfänger konnten nicht geladen werden.')
		expect(host.querySelector('.ticket-recipients-none')).toBeNull()
		await vm.saveTicketRecipients()
		expect(axios.put).not.toHaveBeenCalled()
	})

	it('archives a ticket out of the inbox, lists the archive apart and keeps the server count', async () => {
		stubServer({ archived: [{ id: 3, subject: 'Alt', message: 'm', status: 'archived', user_id: 'x' }], openCount: 1 })
		await mount()
		expect(vm.openSupportTicketCount).toBe(1)
		expect([...host.querySelectorAll('.ticket-card-header strong')].map((e) => e.textContent)).toEqual(['Login geht nicht', 'PDF fehlt'])

		axios.post.mockResolvedValue({ data: {} })
		const archiveButton = [...host.querySelectorAll('.ticket-card')][0].querySelector('.ticket-manage button')
		expect(archiveButton.textContent.trim()).toBe('Archivieren')
		archiveButton.click()
		await flush()
		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/settings/admin/support-tickets/1/archive')
		expect([...host.querySelectorAll('.ticket-card-header strong')].map((e) => e.textContent)).toEqual(['PDF fehlt'])

		vm.toggleTicketArchive()
		await flush()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/settings/admin/support-tickets', { params: { limit: 100, archived: 1 } })
		expect([...host.querySelectorAll('.ticket-card-header strong')].map((e) => e.textContent)).toEqual(['Alt'])
		expect(host.querySelector('.ticket-status').textContent).toBe('Archiviert')
		// the inbox count does not drop to 0 because the archive has no open tickets in it
		expect(vm.openSupportTicketCount).toBe(1)

		const restore = host.querySelector('.ticket-manage button')
		expect(restore.textContent.trim()).toBe('Wiederherstellen')
		restore.click()
		await flush()
		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/settings/admin/support-tickets/3/restore')
		expect(host.textContent).toContain('Keine archivierten Anfragen.')
	})

	it('deletes a ticket only after the confirmation', async () => {
		stubServer()
		await mount()
		axios.delete.mockResolvedValue({ data: { deleted: true } })
		const deleteButton = [...host.querySelectorAll('.ticket-card')][1].querySelectorAll('.ticket-manage button')[1]
		expect(deleteButton.textContent.trim()).toBe('Löschen')
		deleteButton.click()
		await flush()
		expect(axios.delete).not.toHaveBeenCalled()
		const dialog = host.querySelector('.dialog-stub')
		expect(dialog.dataset.name).toBe('Anfrage löschen')
		expect(dialog.textContent).toContain('„PDF fehlt“')

		const confirm = [...dialog.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Löschen')
		confirm.click()
		await flush()
		expect(axios.delete).toHaveBeenCalledWith('/apps/learning/api/settings/admin/support-tickets/2')
		expect(host.querySelector('.dialog-stub')).toBeNull()
		expect([...host.querySelectorAll('.ticket-card-header strong')].map((e) => e.textContent)).toEqual(['Login geht nicht'])
	})

	it('takes the inbox count from the archive, restore and delete answers, with no extra list request', async () => {
		// the list route allows 20 requests a minute, the actions 30: a count refresh after each
		// action ran into the list limit while cleaning up, and the next load emptied the list
		stubServer({ openCount: 1 })
		await mount()
		const listCalls = () => axios.get.mock.calls.filter(([url]) => url.includes('/support-tickets')).length
		const before = listCalls()

		axios.post.mockResolvedValue({ data: { ticket: { id: 1, status: 'archived' }, open_count: 0 } })
		await vm.setTicketArchived(TICKETS[0], true)
		await flush()
		expect(vm.openSupportTicketCount).toBe(0)

		axios.post.mockResolvedValue({ data: { ticket: { id: 1, status: 'open' }, open_count: 1 } })
		await vm.setTicketArchived(TICKETS[0], false)
		await flush()
		expect(vm.openSupportTicketCount).toBe(1)

		axios.delete.mockResolvedValue({ data: { deleted: true, open_count: 0 } })
		vm.confirmTicket = TICKETS[1]
		await vm.deleteTicket()
		await flush()
		expect(vm.openSupportTicketCount).toBe(0)
		expect(listCalls()).toBe(before)
	})

	it('a late answer for an older load neither shows its list nor ends the loading state', async () => {
		const pending = []
		axios.get.mockImplementation((url, config) => {
			if (url.includes('/support-tickets')) {
				return new Promise((resolve) => pending.push({ archived: !!config?.params?.archived, resolve }))
			}
			if (url.includes('/ticket-recipients')) return Promise.resolve({ data: { users: [] } })
			if (url.includes('/audit')) return Promise.resolve({ data: { events: [] } })
			return Promise.resolve({ data: {} })
		})
		await mount()
		expect(pending).toHaveLength(1)

		// the admin switches to the archive while the inbox is still loading
		vm.toggleTicketArchive()
		await flush()
		expect(pending.map((p) => p.archived)).toEqual([false, true])

		pending[0].resolve({ data: { tickets: TICKETS, open_count: 1 } })
		await flush()
		expect(vm.ticketLoading).toBe(true)
		expect(host.querySelectorAll('.ticket-card')).toHaveLength(0)
		expect(host.textContent).not.toContain('Keine archivierten Anfragen.')

		pending[1].resolve({ data: { tickets: [{ id: 3, subject: 'Alt', message: 'm', status: 'archived', user_id: 'x' }], open_count: 1 } })
		await flush()
		expect(vm.ticketLoading).toBe(false)
		expect([...host.querySelectorAll('.ticket-card-header strong')].map((e) => e.textContent)).toEqual(['Alt'])

		// toggling twice quickly: the older inbox load must not overwrite the newer one
		vm.toggleTicketArchive()
		vm.toggleTicketArchive()
		vm.toggleTicketArchive()
		await flush()
		const [, older, newer] = pending.slice(2)
		expect([older.archived, newer.archived]).toEqual([true, false])
		newer.resolve({ data: { tickets: [TICKETS[1]], open_count: 0 } })
		await flush()
		older.resolve({ data: { tickets: [{ id: 9, subject: 'Spät', message: 'm', status: 'archived', user_id: 'x' }], open_count: 5 } })
		pending[2].resolve({ data: { tickets: TICKETS, open_count: 7 } })
		await flush()
		expect(vm.ticketLoading).toBe(false)
		expect([...host.querySelectorAll('.ticket-card-header strong')].map((e) => e.textContent)).toEqual(['PDF fehlt'])
		expect(vm.openSupportTicketCount).toBe(0)
	})

	it('a refused delete keeps the ticket and says so', async () => {
		stubServer()
		await mount()
		axios.delete.mockRejectedValue(new Error('403'))
		vm.confirmTicket = TICKETS[0]
		await vm.deleteTicket()
		await flush()
		expect(host.querySelectorAll('.ticket-card')).toHaveLength(2)
		expect(host.textContent).toContain('Die Anfrage konnte nicht gelöscht werden.')
	})
})

describe('#59 the picker in its ticket-recipients kind', () => {
	let host, app

	afterEach(() => {
		app?.unmount()
		host?.remove()
		vi.useRealTimers()
	})

	it('searches administrators through its own endpoint, not the instructor search', async () => {
		vi.useFakeTimers()
		axios.get.mockResolvedValue({ data: { users: [{ id: 'ops', label: 'Ops Team' }] } })
		host = document.createElement('div')
		document.body.appendChild(host)
		let picker
		app = createApp({
			components: { InstructorPicker },
			data: () => ({ value: [] }),
			template: '<InstructorPicker ref="p" v-model="value" kind="ticket-recipients" label="Empfänger" />',
			mounted() { picker = this.$refs.p },
		})
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
		expect(host.querySelector('.select-stub').dataset.label).toBe('Empfänger')

		picker.onSearch('op')
		await vi.advanceTimersByTimeAsync(300)
		await nextTick()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/settings/admin/ticket-recipient-search', { params: { search: 'op' } })
		expect(picker.options.map((o) => o.id)).toEqual(['user:ops'])
	})
})
