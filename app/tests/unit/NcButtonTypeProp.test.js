/**
 * NcButton prop-contract regression tests (Codeberg issue: premature submit on "Upload image").
 *
 * @nextcloud/vue 9 swapped the meaning of the NcButton `type` prop:
 *   v8: type = styling ("primary" | "secondary" | ...), native-type = the <button type> attribute
 *   v9: variant = styling,                              type        = the <button type> attribute
 *
 * `type` is forwarded to the native element unvalidated, and HTML treats an unknown
 * button type as `submit` (invalid-value-default). So a leftover v8 `type="secondary"`
 * turns every button inside a <form> into a submit button -- the reported bug, where
 * "Upload image" saved the question before a file was even picked.
 *
 * Uses the project pattern (no @vue/test-utils): Vue's own createApp against happy-dom.
 */
import { describe, it, expect } from 'vitest'
import { createApp, h } from 'vue'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import NcButton from '@nextcloud/vue/components/NcButton'

const STYLING_VALUES = [
	'primary', 'secondary', 'tertiary', 'tertiary-no-background',
	'tertiary-on-primary', 'error', 'warning', 'success',
]

/** Render a real NcButton and hand back the native <button> it produced. */
function renderButton(props) {
	const host = document.createElement('div')
	document.body.appendChild(host)
	createApp({ render: () => h(NcButton, props, { default: () => 'Label' }) }).mount(host)
	return host.querySelector('button')
}

describe('NcButton prop contract (@nextcloud/vue 9)', () => {
	it('maps `variant` to styling and leaves the native type at "button"', () => {
		const button = renderButton({ variant: 'secondary' })
		expect(button.getAttribute('type')).toBe('button')
		expect(button.className).toContain('button-vue--secondary')
	})

	it('maps `type` to the NATIVE attribute -- a styling value there is invalid HTML', () => {
		const button = renderButton({ type: 'secondary' })
		// Not "button": the styling value leaked onto the native attribute, where the
		// HTML spec's invalid-value-default makes the browser treat it as `submit`.
		expect(button.getAttribute('type')).toBe('secondary')
	})

	it('supports an explicit native submit type', () => {
		const button = renderButton({ type: 'submit', variant: 'primary' })
		expect(button.getAttribute('type')).toBe('submit')
		expect(button.className).toContain('button-vue--primary')
	})
})

/** Collect every .vue file under src/. */
function vueFiles(dir, found = []) {
	for (const entry of readdirSync(dir)) {
		const full = join(dir, entry)
		if (statSync(full).isDirectory()) vueFiles(full, found)
		else if (entry.endsWith('.vue')) found.push(full)
	}
	return found
}

/**
 * Every <NcButton ...> opening tag in a source string, multi-line included.
 *
 * Scans quote-aware rather than with /<NcButton[^>]*>/: a '>' inside an attribute
 * value (`:disabled="form.answers.length >= 8"`) would truncate the tag and hide
 * any prop written after it.
 */
function ncButtonTags(source) {
	const tags = []
	let i = 0
	while ((i = source.indexOf('<NcButton', i)) !== -1) {
		let k = i + '<NcButton'.length
		if (/[\w-]/.test(source[k] || '')) { i = k; continue }
		let quote = null
		for (; k < source.length; k++) {
			const c = source[k]
			if (quote) { if (c === quote) quote = null }
			else if (c === '"' || c === "'") quote = c
			else if (c === '>') break
		}
		tags.push(source.slice(i, k + 1))
		i = k + 1
	}
	return tags
}

// happy-dom rewrites import.meta.url to an http:// base, so resolve from the cwd
// vitest runs in (app/). The file-count assertion below catches a wrong root.
const SRC = join(process.cwd(), 'src')
const FILES = vueFiles(SRC)
// Lookbehinds keep `native-type=` and dynamic `:type=` out of the match.
const STATIC_TYPE = /(?<!native-)(?<![\w:-])type="([^"]*)"/

describe('NcButton usage across src/', () => {
	it('finds .vue files to scan (guards against a silently empty sweep)', () => {
		expect(FILES.length).toBeGreaterThan(50)
		expect(FILES.some((f) => f.endsWith('QuestionForm.vue'))).toBe(true)
	})

	it('never passes a styling value to the native `type` prop', () => {
		const offenders = []
		for (const file of FILES) {
			for (const tag of ncButtonTags(readFileSync(file, 'utf8'))) {
				const match = STATIC_TYPE.exec(tag)
				if (match && STYLING_VALUES.includes(match[1])) {
					offenders.push(`${file.replace(SRC, 'src')}: type="${match[1]}" (should be variant=)`)
				}
			}
		}
		expect(offenders).toEqual([])
	})

	it('never passes a styling value through a DYNAMIC :type binding either', () => {
		// The static check below missed `:type="cond ? 'primary' : 'secondary'"` entirely
		// (found by an independent review after 5.4.5 shipped). A dynamic binding lands on
		// the native attribute exactly like a static one, so it needs the same rule.
		const offenders = []
		for (const file of FILES) {
			const src = readFileSync(file, 'utf8')
			for (const tag of ncButtonTags(src)) {
				const dynamic = /(?::|v-bind:)type="([^"]*)"/.exec(tag)
				if (!dynamic) continue
				const leaked = STYLING_VALUES.filter((v) => new RegExp(`['\`"]${v}['\`"]`).test(dynamic[1]))
				if (leaked.length) {
					offenders.push(`${file.replace(SRC, 'src')}: :type="${dynamic[1]}" → use :variant`)
				}
			}
		}
		expect(offenders).toEqual([])
	})

	it('no longer uses the v8-only `native-type` prop, which v9 silently ignores', () => {
		const offenders = FILES
			.filter((file) => ncButtonTags(readFileSync(file, 'utf8')).some((t) => t.includes('native-type')))
			.map((file) => file.replace(SRC, 'src'))
		expect(offenders).toEqual([])
	})
})

describe('buttons inside a <form> submit only when they mean to', () => {
	const formFiles = FILES.filter((f) => readFileSync(f, 'utf8').includes('<form'))

	it('covers the forms that triggered the report', () => {
		const names = formFiles.map((f) => f.split('/').pop())
		expect(names).toEqual(expect.arrayContaining(['QuestionForm.vue', 'PoolList.vue', 'ShareDialog.vue']))
	})

	it.each(formFiles.map((f) => [f.split('/').pop(), f]))(
		'%s: every NcButton has an explicit, valid native type',
		(_name, file) => {
			const bad = ncButtonTags(readFileSync(file, 'utf8')).filter((tag) => {
				const match = STATIC_TYPE.exec(tag)
				// Inside a form an implicit type is a trap: reviewers read the styling
				// value as the type. Demand submit/reset/button in writing.
				return !match || !['submit', 'reset', 'button'].includes(match[1])
			})
			expect(bad).toEqual([])
		},
	)
})
