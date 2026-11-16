/**
 * Frontend ↔ API contract (GitHub #65): every request the frontend makes to this app must target a
 * route in app/openapi.json (generated from the controllers, scripts/openapi-spec.sh), with a method
 * that route has; and every URL the browser opens without the request token (downloads, links,
 * images, `window.location`) must be a GET route that is @NoCSRFRequired.
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
	'components/CertLayoutSettings.vue :src(images[kind.key])': 'layout images loaded as data URLs',
	'components/CertificateCard.vue :src(fields.issuerLogo)': 'issuer logo from the certificate data',
	'components/CertificateCard.vue :src(layout.stamp)': 'stamp image from the layout (data URL)',
	'components/CertificateCard.vue :src(layout.signature)': 'signature image from the layout (data URL)',
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
	// Downloads of content generated in the browser.
	'components/InstructorDashboard.vue <el>.href(url)': 'URL.createObjectURL(blob)',
	'utils/summary-export.js <el>.href(url)': 'URL.createObjectURL(blob)',
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
			expect(p).toContain('not @NoCSRFRequired')
		}
	})

	it('reports a call whose URL it cannot evaluate as unresolved, not as checked', () => {
		const r = scan({ 'd.js': header + 'export const f = (url) => axios.get(url)' })
		expect(r.checked).toBe(0)
		expect(r.unresolved.map(siteKey)).toEqual(['__probe__/d.js axios.get(url)'])
	})
})
