/**
 * Frontend ↔ API contract (GitHub #65): every request the frontend makes to this app must target a
 * route in app/openapi.json (generated from the controllers, scripts/openapi-spec.sh), with a method
 * that route has; and every URL the browser opens without the request token (downloads, links,
 * images, `window.location`) must be a GET route that is #[NoCSRFRequired].
 *
 * A wrong path, a PUT where the controller only has POST, or a CSV download behind the CSRF check
 * all pass the component's unit tests (axios is mocked) and only break in the browser.
 */
import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { Loader, Evaluator, scanModule, scanSources, checkSites, siteKey, listSources } from './support/apiContract.js'

const SRC = resolve(__dirname, '../../src')
const spec = JSON.parse(readFileSync(resolve(__dirname, '../../openapi.json'), 'utf8'))

/**
 * Call sites whose URL the scanner cannot evaluate, with the reason it does not matter. This list
 * may only shrink: an entry that no longer occurs fails the test, so it is removed with its cause.
 */
const UNRESOLVED_ALLOWED = {
	// Images handed over as data URLs or as URLs from API responses, not built in the frontend.
	'components/ImageFileField.vue :src(imageUrl)': 'image handed in by the parent (cert layout: data URL, #72)',
	'components/CertificateCard.vue :src(logoSrc)': 'issuer logo: the certificate\'s own library logo (data URL, #78) or the Theming logo from the certificate data',
	'components/CertificateCard.vue :src(layout.stamp)': 'stamp image from the layout (data URL)',
	'components/CertificateCard.vue :src(layout.signature)': 'signature image from the layout (data URL)',
	'components/CertificateCard.vue :src(layout.background)': 'background image from the layout (PNG/JPEG/WebP data URL, #93)',
	'components/CertificateCard.vue :src(qrDataUrl)': 'QR code rendered locally as a data URL',
	'components/PbqPlacement.vue :src(scenarioImage)': 'prop, the parent passes the question image',
	'components/PbqReferenceGallery.vue :src(activeImage.src)': 'reference images from the PBQ config',
	// URLs the server builds (linkToRoute / IcsService::buildFeedUrl) and passes in.
	'components/CourseSummary.vue :href(icsSubscribeUrl)': 'webcal:// URL from GET /api/calendar/token',
	'components/ImpressumPage.vue :href(appUrl)': 'prop from the server-rendered page (linkToRoute)',
	'components/ImpressumPage.vue :href(privacyUrl)': 'prop from the server-rendered page (linkToRoute)',
	'components/PrivacyInfo.vue :href(appUrl)': 'prop from the server-rendered page (linkToRoute)',
	'components/PrivacyInfo.vue :href(imprintUrl)': 'prop from the server-rendered page (linkToRoute)',
	'components/PrivacyInfo.vue :href(privacyUrl)': 'prop from the server-rendered page (linkToRoute)',
	'components/PrivacyPage.vue :href(appUrl)': 'prop from the server-rendered page (linkToRoute)',
	'components/PrivacyPage.vue :href(imprintUrl)': 'prop from the server-rendered page (linkToRoute)',
	// The SSE wrapper: its URL is the caller's, checked at each createSseClient(url) call site.
	'utils/sse-client.js new window.EventSource(withRequestToken(url))': 'createSseClient(url) callers are checked',
	// Downloads of content generated in the browser.
	'components/InstructorDashboard.vue <el>.href(url)': 'URL.createObjectURL(blob)',
	// GitHub #110: the reader gets the material's download URL as a prop from its two callers
	// (CourseMaterials.downloadUrl(), requiredContent.requiredDocumentUrl()), both route-checked.
	'components/MaterialReader.vue :href(url)': 'documents/{documentId}/download (prop)',
	'components/MaterialReader.vue axios.get(url)': 'documents/{documentId}/download (prop)',
	'utils/summary-export.js <el>.href(url)': 'URL.createObjectURL(blob)',
	// GitHub #94: the certificate PDF, made in the browser.
	'utils/certificatePdf.js <el>.href(url)': 'URL.createObjectURL(blob) of the certificate PDF',
	'utils/certificatePdf.js pendingWindow.location.href(url)': 'URL.createObjectURL(blob) of the certificate PDF, in the tab opened at the click',
	'components/Certificate.vue window.open(\'\')': 'an empty tab opened at the click where links cannot download; it gets the PDF blob URL',
}

describe('API contract (GitHub #65)', () => {
	const sites = scanSources(SRC)
	const result = checkSites(sites, spec)

	it('finds every axios call the source contains (the scanner does not lose call sites)', () => {
		// Independent count by text: the parser must see exactly as many axios.<method>( calls.
		let byText = 0
		for (const file of listSources(SRC)) {
			byText += (readFileSync(file, 'utf8').match(/\baxios\.(get|post|put|patch|delete)\(/g) || []).length
		}
		expect(byText).toBeGreaterThan(250)
		expect(sites.filter((s) => s.sink.startsWith('axios.')).length).toBe(byText)
		expect(sites.filter((s) => s.kind === 'navigation').length).toBeGreaterThan(30)
	})

	it('the spec covers the app (openapi.json is not empty or truncated)', () => {
		const ops = Object.values(spec.paths).reduce((n, m) => n + Object.keys(m).length, 0)
		expect(ops).toBeGreaterThan(300)
	})

	it('every app request targets a route of openapi.json with that method; downloads need no CSRF token', () => {
		expect(result.problems).toEqual([])
		expect(result.checked).toBeGreaterThan(300)
	})

	it('call sites the scanner cannot evaluate are exactly the allowlisted ones', () => {
		const unresolved = [...new Set(result.unresolved.map(siteKey))].sort()
		expect(unresolved).toEqual(Object.keys(UNRESOLVED_ALLOWED).sort())
	})
})

describe('API contract scanner bites (planted call sites)', () => {
	/** Scan in-memory modules placed inside src/ (so relative imports resolve). */
	function scan(files) {
		const loader = new Loader(SRC)
		const mods = Object.entries(files).map(([name, text]) => loader.add(join(SRC, '__probe__', name), text))
		const evaluator = new Evaluator(loader)
		return checkSites(mods.flatMap((m) => scanModule(m, evaluator, SRC)), spec)
	}
	const header = "import axios from '@nextcloud/axios'\nimport { generateUrl } from '@nextcloud/router'\n"

	it('accepts a correct request, a block-scoped URL per branch and a NoCSRFRequired download', () => {
		const r = scan({
			'ok.js': header + `
export async function save(edit, id, poolId) {
	if (edit) {
		const url = generateUrl('/apps/learning/api/courses/{id}', { id })
		await axios.put(url, {})
	} else {
		const url = generateUrl('/apps/learning/api/courses')
		await axios.post(url, {})
	}
	window.location.href = generateUrl('/apps/learning/api/pools/' + poolId + '/export/csv')
}`,
		})
		expect(r.problems).toEqual([])
		expect(r.checked).toBe(3)
	})

	it('reports a route that does not exist', () => {
		const r = scan({ 'a.js': header + "export const f = () => axios.get(generateUrl('/apps/learning/api/no-such-route'))" })
		expect(r.problems).toHaveLength(1)
		expect(r.problems[0]).toContain('no route /api/no-such-route')
	})

	it('reports a method the route does not have', () => {
		const r = scan({ 'b.js': header + "export const f = (id) => axios.post(generateUrl('/apps/learning/api/pools/{id}', { id }))" })
		expect(r.problems).toHaveLength(1)
		expect(r.problems[0]).toContain('has no POST route')
	})

	it('reports a download of a route that needs the request token, also through an imported URL builder', () => {
		const r = scan({
			'urls.js': "import { generateUrl } from '@nextcloud/router'\nexport function poolUrl(id) { return generateUrl('/apps/learning/api/pools/' + id) }",
			'c.vue': `<template><a :href="poolHref">x</a></template>
<script>
import { poolUrl } from './urls.js'
export default {
	computed: { poolHref() { return poolUrl(this.id) } },
	methods: { open() { window.location.href = this.poolHref } },
}
</script>`,
		})
		expect(r.problems).toHaveLength(2)
		for (const p of r.problems) {
			expect(p).toContain('not #[NoCSRFRequired]')
		}
	})

	it('checks server-sent event streams: createSseClient(url) and new EventSource(url) as GET', () => {
		const sse = "import { createSseClient } from '../utils/sse-client.js'\n"
		const ok = scan({ 'e.js': header + sse + "export const f = (code) => createSseClient(generateUrl('/apps/learning/api/sse/duel/' + code), {})" })
		expect(ok.problems).toEqual([])
		expect(ok.checked).toBe(1)
		const r = scan({
			'f.js': header + sse + `
export const f = (code) => createSseClient(generateUrl('/apps/learning/api/sse/duels/' + code), {})
export const g = (code) => new window.EventSource(generateUrl('/apps/learning/api/sse/quiz/' + code))
export const h = () => new EventSource(generateUrl('/apps/learning/api/courses'))`,
		})
		expect(r.problems).toHaveLength(2)
		expect(r.problems[0]).toContain('createSseClient(')
		expect(r.problems[0]).toContain('no route /api/sse/duels/{…}')
		expect(r.problems[1]).toContain('new window.EventSource(')
		expect(r.problems[1]).toContain('no route /api/sse/quiz/{…}')
		expect(r.checked).toBe(3)
	})

	it('a renamed SSE route fails at the duel and gameshow call sites (the real sources)', () => {
		const renamed = structuredClone(spec)
		for (const path of Object.keys(renamed.paths)) {
			if (path.includes('/api/sse/')) {
				renamed.paths[path.replace('/api/sse/', '/api/stream/')] = renamed.paths[path]
				delete renamed.paths[path]
			}
		}
		const problems = checkSites(scanSources(SRC), renamed).problems
		expect(problems.filter((p) => p.includes('no route /api/sse/')).map((p) => p.split(':')[0]).sort())
			.toEqual(['components/DuelMode.vue', 'components/GameshowMode.vue'])
	})

	it('a URL whose only literal part is a query on an unknown base is unresolved, not foreign', () => {
		const r = scan({ 'g.js': header + "export const f = (url, t) => axios.get(url + '?requesttoken=' + t)" })
		expect(r.checked).toBe(0)
		expect(r.foreign).toBe(0)
		expect(r.unresolved.map(siteKey)).toEqual(["__probe__/g.js axios.get(url + '?requesttoken=' + t)"])
	})

	it('reports a call whose URL it cannot evaluate as unresolved, not as checked', () => {
		const r = scan({ 'd.js': header + 'export const f = (url) => axios.get(url)' })
		expect(r.checked).toBe(0)
		expect(r.unresolved.map(siteKey)).toEqual(['__probe__/d.js axios.get(url)'])
	})
})
