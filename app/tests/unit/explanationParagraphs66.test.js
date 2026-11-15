/**
 * Explanations keep their paragraphs (GitHub #66).
 *
 * A question's explanation is plain text, often authored with paragraphs separated by "\n"
 * (answer, reasoning, background, mnemonic). Every view rendered it with {{ }} into an element
 * with the default `white-space: normal`, so the browser folded all paragraphs into one block.
 * The fix keeps rendering it as text (no v-html) and gives every explanation container
 * `white-space: pre-line` (or the pre-existing `pre-wrap` in the exam review and cheat sheet).
 *
 * Two checks:
 *  1. A real render: DailyChallengeCard is mounted with a multi-line explanation, its compiled
 *     scoped CSS is put into the document, and the explanation element must keep the newlines
 *     in its text AND compute to a white-space value that shows them.
 *  2. A contract over every component: each template interpolation that ends in `.explanation`
 *     must sit in an element whose class has a white-space rule that keeps line breaks, so a new
 *     view cannot fold explanations again unnoticed.
 */
import fs from 'fs'
import path from 'path'
import { describe, it, expect, vi, afterEach } from 'vitest'
import { createApp, nextTick } from 'vue'
import { parse, compileStyle } from '@vue/compiler-sfc'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn(() => Promise.resolve({ data: {} })) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('../../src/components/QuestionScenario.vue', () => ({ default: { name: 'QuestionScenario', template: '<div />' } }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))

globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)

import DailyChallengeCard from '../../src/components/DailyChallengeCard.vue'

const COMPONENTS_DIR = path.resolve(__dirname, '../../src/components')
const KEEPS_BREAKS = /white-space\s*:\s*(pre-line|pre-wrap|pre|break-spaces)\b/

const MULTI_LINE = 'The second option is right.\nIt names the step that comes first.\n\nRemember: first the scope, then the scan.'

// Not question explanations: a practicum step's context line (authored inline in step JSON).
const NOT_QUESTION_EXPLANATIONS = new Set(['PracticumRunner.vue'])

function styleRules(descriptor) {
	const rules = new Map()
	for (const block of descriptor.styles) {
		const css = block.content.replace(/\/\*[\s\S]*?\*\//g, '')
		const re = /([^{}]+)\{([^{}]*)\}/g
		let m
		while ((m = re.exec(css)) !== null) {
			for (const selector of m[1].split(',')) {
				const classes = selector.match(/\.[A-Za-z0-9_-]+/g) || []
				const last = classes[classes.length - 1]
				if (!last) continue
				const name = last.slice(1)
				rules.set(name, (rules.get(name) || '') + m[2])
			}
		}
	}
	return rules
}

function staticClasses(node) {
	const attr = (node.props || []).find((p) => p.type === 6 && p.name === 'class')
	return attr && attr.value ? attr.value.content.split(/\s+/).filter(Boolean) : []
}

function explanationSites(ast) {
	const sites = []
	const walk = (node, ancestors) => {
		if (node.type === 5 && /(^|\.)explanation\s*$/.test(node.content.content.trim())) {
			sites.push({ expr: node.content.content.trim(), line: node.loc.start.line, ancestors })
		}
		const next = node.type === 1 ? [node, ...ancestors] : ancestors
		for (const child of node.children || []) walk(child, next)
	}
	walk(ast, [])
	return sites
}

describe('explanations keep their paragraphs (#66)', () => {
	afterEach(() => {
		document.head.querySelectorAll('style[data-test66]').forEach((el) => el.remove())
	})

	it('DailyChallengeCard renders a multi-line explanation with its line breaks visible', async () => {
		const file = path.join(COMPONENTS_DIR, 'DailyChallengeCard.vue')
		const { descriptor } = parse(fs.readFileSync(file, 'utf8'), { filename: file })
		const scopeId = DailyChallengeCard.__scopeId
		expect(scopeId).toBeTruthy()
		const css = descriptor.styles.map((s) => compileStyle({
			source: s.content, filename: file, id: scopeId, scoped: s.scoped,
		}).code).join('\n')
		const style = document.createElement('style')
		style.setAttribute('data-test66', '')
		style.textContent = css
		document.head.appendChild(style)

		const host = document.createElement('div')
		document.body.appendChild(host)
		const app = createApp(DailyChallengeCard)
		app.config.globalProperties.t = globalThis.t
		const vm = app.mount(host)
		vm.dailyChallenge = {
			available: true,
			completed: true,
			was_correct: false,
			pool_name: 'Pool',
			question: { id: 1, text: 'Which step comes first?', answers: [], explanation: MULTI_LINE },
		}
		await nextTick()
		const el = host.querySelector('.dc-explanation')
		expect(el).not.toBeNull()
		expect(el.textContent).toContain('first.\n\nRemember')
		expect(getComputedStyle(el).whiteSpace).toMatch(/^(pre-line|pre-wrap)$/)
		app.unmount()
		host.remove()
	})

	it('every view that shows a question explanation keeps line breaks', () => {
		const files = fs.readdirSync(COMPONENTS_DIR, { recursive: true })
			.filter((f) => f.endsWith('.vue') && !NOT_QUESTION_EXPLANATIONS.has(path.basename(f)))
		const folded = []
		let total = 0
		for (const f of files) {
			const file = path.join(COMPONENTS_DIR, f)
			const { descriptor } = parse(fs.readFileSync(file, 'utf8'), { filename: file })
			if (!descriptor.template) continue
			const sites = explanationSites(descriptor.template.ast)
			if (sites.length === 0) continue
			const rules = styleRules(descriptor)
			for (const site of sites) {
				total++
				const ok = site.ancestors.some((el) => staticClasses(el).some((c) => KEEPS_BREAKS.test(rules.get(c) || '')))
				if (!ok) folded.push(`${f}:${site.line} {{ ${site.expr} }}`)
			}
		}
		// Sanity: the scan must actually find the known views (Training, Leitner, SmartQueue, ...).
		expect(total).toBeGreaterThanOrEqual(15)
		expect(folded).toEqual([])
	})
})
