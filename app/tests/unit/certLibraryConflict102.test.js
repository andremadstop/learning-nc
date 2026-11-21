/**
 * GitHub #102: two administrators changing the certificate logo or background library at once.
 * Every answer of the library carries the `revision` of the list; a rename sends it in the JSON body
 * (`revision`, read by Cert*Controller::rename), a delete as the query parameter `revision` (read by
 * Cert*Controller::delete). A list changed since is answered with 409 library_changed (or
 * library_busy): the page says so, loads the list again and does not retry or overwrite anything.
 * An upload sends no revision: the server checks none (two additions both stay), and a key no
 * controller reads would be a contract that silently does nothing.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	const fill = (text, vars) => (vars ? text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)) : text)
	globalThis.t = (_app, text, vars) => fill(text, vars)
	globalThis.n = (_app, one, many, count, vars) => fill((count === 1 ? one : many).replace('%n', String(count)), vars)
})
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => (params ? url.replace(/{(\w+)}/g, (m, k) => (k in params ? String(params[k]) : m)) : url),
	imagePath: (app, file) => `/apps/${app}/img/${file}`,
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text, getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		patch: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))

const A = 'a'.repeat(64)
const B = 'b'.repeat(64)
const SEEN = '1'.repeat(64)
const NOW = '2'.repeat(64)
const CHANGED = 'Die Liste wurde inzwischen geändert, etwa von einer anderen Person mit Administrationsrechten. Sie ist jetzt neu geladen. Bitte prüfe sie und versuche es dann erneut.'
const BUSY = 'Die Liste wird gerade an anderer Stelle geändert. Sie ist jetzt neu geladen. Bitte versuche es gleich noch einmal.'

let host, app, axios

beforeEach(async () => {
	axios = (await import('@nextcloud/axios')).default
	for (const verb of ['get', 'post', 'put', 'patch', 'delete']) {
		axios[verb].mockReset()
		axios[verb].mockImplementation(() => Promise.resolve({ data: {} }))
	}
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
})

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mount(component) {
	let vm
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(component, { ref: (r) => { vm = r } }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.mount(host)
	await flush()
	return vm
}

const conflict = (error) => ({ response: { status: 409, data: { error } } })

const LIBRARIES = [
	{
		what: 'logo',
		key: 'logos',
		path: '/apps/learning/api/settings/admin/cert-logos',
		prefix: 'cert-logo',
		file: 'logo',
		load: () => import('../../src/components/CertLogoLibrary.vue'),
	},
	{
		what: 'background',
		key: 'backgrounds',
		path: '/apps/learning/api/settings/admin/cert-backgrounds',
		prefix: 'cert-background',
		file: 'background',
		load: () => import('../../src/components/CertBackgroundLibrary.vue'),
	},
]

describe.each(LIBRARIES)('the $what library against changes by another administrator (GitHub #102)', ({ key, path, prefix, file, load }) => {
	const entry = (id, name) => ({ id, name, mime: 'image/png', size: 9, courses: 0 })
	const seen = { [key]: [entry(A, 'Primary'), entry(B, 'Second')], max: 20, revision: SEEN }
	const now = { [key]: [entry(A, 'Renamed elsewhere')], max: 20, revision: NOW }

	async function library() {
		axios.get.mockResolvedValue({ data: seen })
		const { default: component } = await load()
		return mount(component)
	}

	const status = () => host.querySelector(`[data-test="${prefix}-library-status"]`).textContent.trim()

	it('a rename sends the name and the revision of the list shown in the body', async () => {
		const vm = await library()
		axios.put.mockResolvedValueOnce({ data: { ...now, revision: NOW } })
		vm.names[A] = '  New name '
		await vm.rename(vm[key][0])
		await flush()

		expect(axios.put).toHaveBeenCalledWith(`${path}/${A}`, { name: 'New name', revision: SEEN })
		expect(vm.revision).toBe(NOW)
	})

	it('a delete sends the revision of the list shown as the query parameter revision', async () => {
		const vm = await library()
		axios.delete.mockResolvedValueOnce({ data: { ...now, courses_reset: 0 } })
		await vm.remove(vm[key][1])
		await flush()

		expect(axios.delete).toHaveBeenCalledWith(`${path}/${B}`, { params: { revision: SEEN } })
		expect(vm.revision).toBe(NOW)
	})

	it('an upload sends name and file, and no revision the server would not read', async () => {
		const vm = await library()
		axios.post.mockResolvedValueOnce({ data: { ...now, added: B } })
		vm.newName = 'Third'
		await vm.upload(new File(['png'], 'x.png', { type: 'image/png' }))
		await flush()

		const [, form] = axios.post.mock.calls.at(-1)
		expect([...form.keys()].sort()).toEqual([file, 'name'].sort())
	})

	it('a rename on a list changed since says so and shows the list as stored now', async () => {
		const vm = await library()
		axios.put.mockRejectedValueOnce(conflict('library_changed'))
		axios.get.mockResolvedValue({ data: now })
		vm.names[A] = 'Mine'
		await vm.rename(vm[key][0])
		await flush()

		expect(status()).toBe(CHANGED)
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(vm[key].map((e) => e.name)).toEqual(['Renamed elsewhere'])
		expect(host.querySelector(`[data-test="${prefix}-name-${A}"]`).value).toBe('Renamed elsewhere')
		expect(vm.revision).toBe(NOW)
	})

	it('a delete on a list changed since says so, closes the confirmation and loads the list again', async () => {
		const vm = await library()
		vm.confirming = B
		axios.delete.mockRejectedValueOnce(conflict('library_changed'))
		axios.get.mockResolvedValue({ data: now })
		await vm.remove(vm[key][1])
		await flush()

		expect(status()).toBe(CHANGED)
		expect(vm.confirming).toBe('')
		expect(axios.delete).toHaveBeenCalledTimes(1)
		expect(vm[key].map((e) => e.id)).toEqual([A])
	})

	it('an upload while another change holds the library says so in the field and loads the list again', async () => {
		const vm = await library()
		axios.post.mockRejectedValueOnce(conflict('library_busy'))
		axios.get.mockResolvedValue({ data: now })
		vm.newName = 'Third'
		await vm.upload(new File(['png'], 'x.png', { type: 'image/png' }))
		await flush()

		expect(host.querySelector(`[data-test="${prefix}-new-error"]`).textContent.trim()).toBe(BUSY)
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(vm.revision).toBe(NOW)
		expect(vm.newName).toBe('Third')
	})

	it('another failure of a delete keeps its own message', async () => {
		const vm = await library()
		vm.confirming = B
		axios.delete.mockRejectedValueOnce({ response: { status: 500, data: {} } })
		await vm.remove(vm[key][1])
		await flush()
		expect(status()).not.toBe(CHANGED)
		expect(vm.confirming).toBe(B)
	})
})
