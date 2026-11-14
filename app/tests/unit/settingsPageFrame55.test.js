/**
 * GitHub #55: /settings/admin/learning and /settings/user/learning had no left margin, and the
 * "Instructor group" field (like every plain input there) was 130 px wide.
 *
 * Causes: the mount points in templates/settings/*.php lacked Nextcloud's "section" class (core
 * .section gives the 30 px settings margin), and .nc-input set only a max-width, so Nextcloud
 * core's `input, select { width: 130px }` stayed in force. The margin belongs to the template,
 * not to the component: App.vue mounts the same components inside the app.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

function read(rel) {
	return readFileSync(resolve(__dirname, '../..', rel), 'utf8')
}

function styleOf(file) {
	const sfc = read('src/components/' + file)
	const blocks = [...sfc.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map(m => m[1])
	return blocks.join('\n').replace(/\/\*[\s\S]*?\*\//g, '')
}

function declsFor(css, selector) {
	const out = {}
	let hits = 0
	for (const m of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		const selectors = m[1].split(',').map(s => s.trim().replace(/\s+/g, ' '))
		if (!selectors.includes(selector)) continue
		hits++
		for (const decl of m[2].split(';')) {
			const i = decl.indexOf(':')
			if (i < 0) continue
			out[decl.slice(0, i).trim()] = decl.slice(i + 1).trim()
		}
	}
	return hits ? out : null
}

describe('#55 settings pages: Nextcloud frame and field width', () => {
	it.each([
		['templates/settings/admin.php', 'learning-admin-settings'],
		['templates/settings/personal.php', 'learning-personal-settings'],
	])('%s mounts into a Nextcloud settings section', (file, id) => {
		const tag = read(file).match(new RegExp(`<div[^>]*id="${id}"[^>]*>`))
		expect(tag).not.toBeNull()
		expect(tag[0]).toMatch(/class="[^"]*\bsection\b[^"]*"/)
	})

	it.each(['AdminSettings.vue', 'PersonalSettings.vue'])('%s gives .nc-input a width of its own', (file) => {
		const decls = declsFor(styleOf(file), '.nc-input')
		expect(decls).not.toBeNull()
		expect(decls.width).toBe('100%')
		expect(decls['max-width']).toBe('280px')
	})
})
