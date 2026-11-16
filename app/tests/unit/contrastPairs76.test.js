/**
 * GitHub #76: the "Reset profile" button in the personal settings showed
 * --color-primary-element-text on an almost transparent background. It was an NcButton with
 * variant="tertiary-on-primary", a variant meant for a button that sits ON a primary-coloured
 * surface; on the normal page background its text colour (white, or black under a light primary
 * colour) is unreadable, and the NcButton tertiary hover only adds 8 % primary.
 *
 * Nextcloud pairs its colours: --color-primary-element-text is readable only on
 * --color-primary-element (or its -hover), --color-primary-element-light-text only on
 * --color-primary-element-light. These checks read every stylesheet and template in src/ (and the
 * hand-written css/style.css), so a wrong pairing fails here instead of waiting for the persona
 * gate to open the one screen it sits on (axe only measures the state it sees, never :hover).
 *
 *  1. A rule that sets `color: var(--color-primary-element-text)` sets a solid primary background
 *     in the same rule, or is listed in PAIR_EXCEPTIONS with the reason why the background comes
 *     from elsewhere.
 *  2. variant="tertiary-on-primary" only inside a primary-coloured surface (TERTIARY_ON_PRIMARY_OK).
 *  3. A :hover, :focus or :active rule that reaches the same element and outranks the pair does
 *     not swap the background for a light one while the text stays --color-primary-element-text.
 *  4. A native <button> with that pair keeps it on :hover and :focus: Nextcloud core gives every
 *     plain button `button:not(.button-vue,[class^=vs__]):hover/:focus { background-color:
 *     var(--color-primary-element-light-hover) }` at specificity (0,2,1) (see
 *     questionMediaPlayerStyle66.test.js). A scoped `.x` rule is (0,2,0) and loses, so a hovered or
 *     focused button showed white on light blue (1.3:1).
 */
import { describe, expect, it } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import postcss from 'postcss'
import { parse as parseSfc } from '@vue/compiler-sfc'

const SRC = join(__dirname, '../../src')

function files(dir, ext) {
	return readdirSync(dir).flatMap((name) => {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) return files(path, ext)
		return name.endsWith(ext) ? [path] : []
	})
}

const VUE = files(SRC, '.vue').map((path) => {
	const { descriptor } = parseSfc(readFileSync(path, 'utf8'), { filename: path })
	return { rel: relative(SRC, path), descriptor }
})

/** Every stylesheet: { rel, scoped, root } */
const SHEETS = [
	...VUE.flatMap((f) => f.descriptor.styles.map((s) => ({ rel: f.rel, scoped: !!s.scoped, content: s.content }))),
	...files(join(SRC, 'styles'), '.css').map((path) => ({ rel: relative(SRC, path), scoped: false, content: readFileSync(path, 'utf8') })),
	{ rel: '../css/style.css', scoped: false, content: readFileSync(join(SRC, '../css/style.css'), 'utf8') },
].map((s) => ({ ...s, root: postcss.parse(s.content) }))

const PRIMARY_TEXT = /var\(--color-primary-element-text\b(?!-)/
const SOLID_PRIMARY_BG = /^var\(--color-primary-element(-hover)?\b(?!-)/

function declsOf(rule) {
	const decls = {}
	rule.each((n) => { if (n.type === 'decl') decls[n.prop] = n.value })
	return decls
}

function background(decls) {
	return decls.background ?? decls['background-color']
}

/** All rules of all sheets: { sheet, rule, selectors, decls } */
const RULES = SHEETS.flatMap((sheet) => {
	const out = []
	sheet.root.walkRules((rule) => {
		if (rule.parent?.type === 'atrule' && /keyframes/.test(rule.parent.name)) return
		out.push({ sheet, rule, selectors: rule.selectors.map((s) => s.trim().replace(/\s+/g, ' ')), decls: declsOf(rule) })
	})
	return out
})

function where(r) {
	return `${r.sheet.rel}:${r.rule.source.start.line} ${r.selectors.join(', ')}`
}

/**
 * Rules whose colour pairs with a background set elsewhere. Key: "<file> <selector list>".
 * Every entry says where the solid primary background comes from.
 */
const PAIR_EXCEPTIONS = {
	'components/AnalyticsDashboard.vue .xp-hero-level': 'the level number sits inside .xp-hero-badge, a 72px circle with background var(--color-primary-element)',
}

/** NcButton variant="tertiary-on-primary" is allowed only where the parent is primary-coloured. */
const TERTIARY_ON_PRIMARY_OK = {
	// '<file>:<trimmed template line>': 'where the primary surface comes from'
}

describe('#76 --color-primary-element-text only on a solid primary background', () => {
	it('every rule with the primary element text colour sets the primary element background', () => {
		const offenders = []
		for (const r of RULES) {
			const color = r.decls.color
			if (!color || !PRIMARY_TEXT.test(color)) continue
			const bg = background(r.decls)
			if (bg && SOLID_PRIMARY_BG.test(bg)) continue
			const key = `${r.sheet.rel} ${r.selectors.join(', ')}`
			if (PAIR_EXCEPTIONS[key]) continue
			offenders.push(where(r) + `  (background: ${bg ?? 'none'})`)
		}
		expect(offenders).toEqual([])
	})

	it('the colour patterns tell the solid pair from the light one and from a tint', () => {
		expect(PRIMARY_TEXT.test('var(--color-primary-element-text)')).toBe(true)
		expect(PRIMARY_TEXT.test('var(--color-primary-element-text, #fff)')).toBe(true)
		expect(PRIMARY_TEXT.test('var(--color-primary-element-light-text)')).toBe(false)
		expect(SOLID_PRIMARY_BG.test('var(--color-primary-element)')).toBe(true)
		expect(SOLID_PRIMARY_BG.test('var(--color-primary-element-hover)')).toBe(true)
		expect(SOLID_PRIMARY_BG.test('var(--color-primary-element, #00679e)')).toBe(true)
		expect(SOLID_PRIMARY_BG.test('var(--color-primary-element-light)')).toBe(false)
		expect(SOLID_PRIMARY_BG.test('color-mix(in srgb, var(--color-primary-element) 8%, transparent)')).toBe(false)
		expect(specificity('.x', true)).toBe(200)
		expect(specificity('.x:hover', true)).toBe(300)
		expect(specificity('button:not(.button-vue,[class^=vs__]):hover', false)).toBe(CORE_HOVER_FOCUS)
	})

	it('no exception is left over after its rule changed', () => {
		const keys = new Set(RULES.map((r) => `${r.sheet.rel} ${r.selectors.join(', ')}`))
		expect(Object.keys(PAIR_EXCEPTIONS).filter((k) => !keys.has(k))).toEqual([])
	})

	it('variant="tertiary-on-primary" is used only on a primary-coloured surface', () => {
		const offenders = []
		for (const f of VUE) {
			const src = f.descriptor.template?.content || ''
			src.split('\n').forEach((text, i) => {
				if (!/tertiary-on-primary/.test(text)) return
				const key = `${f.rel}:${text.trim()}`
				if (!TERTIARY_ON_PRIMARY_OK[key]) offenders.push(`${f.rel}:${i + 1 + (f.descriptor.template.loc.start.line - 1)} ${text.trim()}`)
			})
		}
		expect(offenders).toEqual([])
	})

	it('the reset-profile button in the personal settings uses the light primary pair (secondary)', () => {
		const settings = VUE.find((f) => f.rel === 'components/PersonalSettings.vue')
		const line = settings.descriptor.template.content.split('\n').find((l) => /confirmResetProfile/.test(l))
		expect(line).toMatch(/variant="secondary"/)
	})
})

/* ------------------------------------------------------------- hover / focus / active states */

const ELEMENT = 1

/** Classes an element may carry: the static class plus the quoted names and object keys of :class. */
function classesOf(node) {
	const out = new Set()
	for (const p of node.props || []) {
		if (p.type === 6 && p.name === 'class' && p.value) p.value.content.split(/\s+/).forEach((c) => c && out.add(c))
		if (p.type === 7 && p.name === 'bind' && p.arg?.content === 'class' && p.exp) {
			const exp = p.exp.content
			for (const m of exp.matchAll(/['"`]([\w-]+(?:\s+[\w-]+)*)['"`]/g)) m[1].split(/\s+/).forEach((c) => out.add(c))
			for (const m of exp.matchAll(/[{,]\s*([A-Za-z_][\w]*)\s*:/g)) out.add(m[1])
		}
	}
	return out
}

function isNativeButton(node) {
	if (node.tag === 'button') return true
	if (node.tag !== 'input') return false
	const type = (node.props || []).find((p) => p.type === 6 && p.name === 'type')?.value?.content
	return ['button', 'submit', 'reset'].includes(type)
}

/** Every template element with classes: { rel, tag, button, classes } */
const ELEMENTS = []
for (const f of VUE) {
	const walk = (node) => {
		if (node.type === ELEMENT) {
			const classes = classesOf(node)
			if (classes.size) ELEMENTS.push({ rel: f.rel, tag: node.tag, button: isNativeButton(node), classes, line: node.loc.start.line })
		}
		for (const child of node.children || []) walk(child)
	}
	if (f.descriptor.template?.ast) walk(f.descriptor.template.ast)
}

/** (ids * 10000) + (classes + pseudo-classes + attributes) * 100 + types; :not() counts its argument; the scope attribute adds one class. */
function specificity(sel, scoped) {
	const bare = sel.replace(/::[\w-]+/g, '')
	const notArgs = [...bare.matchAll(/:not\(([^)]*)\)/g)].map((m) => m[1].split(',')[0])
	const outer = bare.replace(/:not\(([^)]*)\)/g, ' ')
	const count = (text) => ({
		ids: (text.match(/#[\w-]+/g) || []).length,
		classes: (text.match(/\.[\w-]+|:[\w-]+(?:\([^)]*\))?|\[[^\]]+\]/g) || []).length,
		types: (text.match(/(^|[\s>+~])[a-z][\w-]*/gi) || []).length,
	})
	const parts = [count(outer), ...notArgs.map(count)]
	const sum = (k) => parts.reduce((n, p) => n + p[k], 0)
	return sum('ids') * 10000 + (sum('classes') + (scoped ? 1 : 0)) * 100 + sum('types')
}

/** Nextcloud core: button:not(.button-vue,[class^=vs__]):hover / :focus → (0,2,1). */
const CORE_HOVER_FOCUS = 201
const STATE = /:(hover|focus|focus-visible|focus-within|active)\b/

/** Classes of the selector's last compound, without the state pseudo-classes and :not(). */
function targetClasses(sel) {
	const last = sel.replace(/:not\([^)]*\)/g, '').split(/[\s>+~]+/).pop()
	return [...last.matchAll(/\.([\w-]+)/g)].map((m) => m[1])
}

/** Classes a selector excludes with :not(.x) on its last compound. */
function excludedClasses(sel) {
	const last = sel.split(/[\s>+~]+(?![^(]*\))/).pop()
	return [...last.matchAll(/:not\(([^)]*)\)/g)].flatMap((m) => [...m[1].matchAll(/\.([\w-]+)/g)].map((x) => x[1]))
}

function reaches(sel, element) {
	const tc = targetClasses(sel)
	if (!tc.length || !tc.every((c) => element.classes.has(c))) return false
	return !excludedClasses(sel).some((c) => element.classes.has(c))
}

/** Rules with the primary pair and a selector without a state: { r, sel, elements } */
function pairTargets() {
	const out = []
	for (const r of RULES) {
		const color = r.decls.color
		const bg = background(r.decls)
		if (!color || !PRIMARY_TEXT.test(color) || !bg || !SOLID_PRIMARY_BG.test(bg)) continue
		for (const sel of r.selectors) {
			if (STATE.test(sel) || /:disabled/.test(sel)) continue
			const elements = ELEMENTS.filter((e) => (!r.sheet.scoped || e.rel === r.sheet.rel) && reaches(sel, e))
			if (elements.length) out.push({ r, sel, elements })
		}
	}
	return out
}

function keepsPair(o) {
	const obg = background(o.decls)
	return obg !== undefined && SOLID_PRIMARY_BG.test(obg) && (!o.decls.color || PRIMARY_TEXT.test(o.decls.color))
}

describe('#76 the primary pair survives :hover, :focus and :active', () => {
	it('no own state rule lays a light background under the primary element text', () => {
		const offenders = new Set()
		for (const { r, sel, elements } of pairTargets()) {
			const spec = specificity(sel, r.sheet.scoped)
			for (const o of RULES) {
				if (o.sheet.rel !== r.sheet.rel || o === r) continue
				const obg = background(o.decls)
				if (obg === undefined || SOLID_PRIMARY_BG.test(obg)) continue
				if (o.decls.color && !PRIMARY_TEXT.test(o.decls.color)) continue
				for (const s of o.selectors) {
					const ospec = specificity(s, o.sheet.scoped)
					if (!STATE.test(s) || ospec < spec) continue
					const state = s.match(STATE)[0]
					for (const e of elements.filter((x) => reaches(s, x))) {
						// put right again by a rule for the same state that wins against it
						const repaired = RULES.some((k) => k.sheet === o.sheet && keepsPair(k) && k.selectors.some((ks) => {
							if (!ks.includes(state) || !reaches(ks, e)) return false
							const kspec = specificity(ks, k.sheet.scoped)
							return kspec > ospec || (kspec === ospec && k.rule.source.start.offset > o.rule.source.start.offset)
						}))
						if (!repaired) offenders.add(`${where(o)} overrides ${r.sheet.rel}:${r.rule.source.start.line} ${sel}`)
					}
				}
			}
		}
		expect([...offenders]).toEqual([])
	})

	it('a native button keeps the pair against the core :hover/:focus rule', () => {
		const offenders = new Set()
		for (const { r, sel, elements } of pairTargets()) {
			if (specificity(sel, r.sheet.scoped) > CORE_HOVER_FOCUS) continue
			for (const e of elements.filter((x) => x.button)) {
				for (const state of [':hover', ':focus']) {
					const ok = RULES.some((o) => o.sheet.rel === r.sheet.rel && keepsPair(o) && o.selectors.some((s) =>
						new RegExp(state + '(?![\\w-])').test(s) && specificity(s, o.sheet.scoped) > CORE_HOVER_FOCUS && reaches(s, e)))
					if (!ok) offenders.add(`${r.sheet.rel}:${r.rule.source.start.line} ${sel}${state} (<${e.tag}> ${e.rel}:${e.line})`)
				}
			}
		}
		expect([...offenders]).toEqual([])
	})
})
