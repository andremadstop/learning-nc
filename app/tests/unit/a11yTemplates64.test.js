/**
 * GitHub #64: accessibility patterns the persona gate's axe-core run found, checked in every
 * component's template and style so they cannot come back in a screen the sweep never opens.
 *
 * axe needs a rendered page (and colours), the sweep only reaches the screens it clicks. These
 * checks read the source of every .vue file instead:
 *  - aria-required-children: a role="tablist" holds only role="tab" elements (sub-tab bars,
 *    tool bars).
 *  - aria-allowed-attr: aria-sort only on a column header, never on role="button".
 *  - nested-interactive: a role="button" container holds no button, link, input or menu.
 *  - label: no text field (<input>, <select>, <textarea>) without a name; an NcSelect takes
 *    input-id (a plain id lands on its wrapper and leaves the combobox input unnamed).
 *  - color-contrast: since Nextcloud 31 --color-warning/-success/-error/-info are background
 *    tints (#FFEEC5 … in light, #3D3010 … in dark); text on them or on the page needs the
 *    --color-<status>-text variant. Plus the VirtuProf kicker cyan (#06b6d4, 2.42:1 on white).
 */
import { describe, expect, it } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { parse as parseSfc } from '@vue/compiler-sfc'

const SRC = join(__dirname, '../../src')

function vueFiles(dir) {
	return readdirSync(dir).flatMap((name) => {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) return vueFiles(path)
		return name.endsWith('.vue') ? [path] : []
	})
}

const FILES = vueFiles(SRC).map((path) => {
	const source = readFileSync(path, 'utf8')
	const { descriptor } = parseSfc(source, { filename: path })
	return { path, rel: relative(SRC, path), descriptor }
})

const ELEMENT = 1

/** Static value of an attribute (also a bound string literal), or `true` when it is bound to an expression. */
function attr(node, name) {
	for (const p of node.props || []) {
		if (p.type === 6 && p.name === name) return p.value ? p.value.content : ''
		if (p.type === 7 && p.name === 'bind' && p.arg && p.arg.content === name) {
			const literal = /^\s*'([^']*)'\s*$/.exec(p.exp?.content || '')
			return literal ? literal[1] : true
		}
	}
	return undefined
}

function walk(node, visit, ancestors = []) {
	if (node.type === ELEMENT) visit(node, ancestors)
	const next = node.type === ELEMENT ? [...ancestors, node] : ancestors
	for (const child of node.children || []) walk(child, visit, next)
}

/** Element children, looking through <template> wrappers (v-for / v-if). */
function elementChildren(node) {
	return (node.children || []).flatMap((c) => {
		if (c.type !== ELEMENT) return []
		return c.tag === 'template' ? elementChildren(c) : [c]
	})
}

function line(file, node) {
	return `${file.rel}:${node.loc.start.line} <${node.tag}>`
}

function eachTemplateNode(visit) {
	for (const file of FILES) {
		const ast = file.descriptor.template?.ast
		if (!ast) continue
		walk(ast, (node, ancestors) => visit(file, node, ancestors))
	}
}

/** Text fields: what axe's `label` and `select-name` rules look at. */
function isTextField(node) {
	if (node.tag === 'select' || node.tag === 'textarea') return true
	if (node.tag !== 'input') return false
	const type = attr(node, 'type')
	return !['hidden', 'checkbox', 'radio', 'button', 'submit', 'reset', 'file', 'image'].includes(typeof type === 'string' ? type : '')
}

function labelIds() {
	const ids = new Set()
	eachTemplateNode((file, node) => {
		const target = attr(node, 'for')
		if (node.tag === 'label' && typeof target === 'string') ids.add(file.rel + '#' + target)
	})
	return ids
}

describe('#64 template accessibility', () => {
	it('every role="tablist" holds only role="tab" elements', () => {
		const offenders = []
		eachTemplateNode((file, node) => {
			if (attr(node, 'role') !== 'tablist') return
			for (const child of elementChildren(node)) {
				if (attr(child, 'role') !== 'tab') offenders.push(line(file, child))
			}
		})
		expect(offenders).toEqual([])
	})

	it('every role="tablist" moves the focus with the arrow keys (a keydown handler)', () => {
		const offenders = []
		eachTemplateNode((file, node) => {
			if (attr(node, 'role') !== 'tablist') return
			const keydown = (node.props || []).some((p) => p.type === 7 && p.name === 'on' && p.arg && p.arg.content === 'keydown')
			if (!keydown) offenders.push(line(file, node))
		})
		expect(offenders).toEqual([])
	})

	it('aria-sort sits on a plain column header, never on role="button"', () => {
		const offenders = []
		eachTemplateNode((file, node) => {
			if (attr(node, 'aria-sort') === undefined) return
			if (node.tag !== 'th' || attr(node, 'role') !== undefined) offenders.push(line(file, node))
		})
		expect(offenders).toEqual([])
	})

	it('a role="button" container holds no other control', () => {
		const controls = /^(button|a|input|select|textarea|NcButton|NcActions|NcActionButton|NcCheckboxRadioSwitch|NcSelect)$/
		const offenders = []
		eachTemplateNode((file, node, ancestors) => {
			if (!controls.test(node.tag)) return
			// a file input behind a drop zone is display:none, the zone itself is the control
			if (node.tag === 'input' && attr(node, 'type') === 'file') return
			const container = ancestors.find((a) => attr(a, 'role') === 'button')
			if (container) offenders.push(`${line(file, node)} inside ${line(file, container)}`)
		})
		expect(offenders).toEqual([])
	})

	it('every text field has a name (label, aria-label, aria-labelledby or placeholder)', () => {
		const labelled = labelIds()
		const offenders = []
		eachTemplateNode((file, node, ancestors) => {
			if (!isTextField(node)) return
			if (attr(node, 'aria-label') !== undefined || attr(node, 'aria-labelledby') !== undefined) return
			if (node.tag !== 'select' && attr(node, 'placeholder') !== undefined) return
			if (ancestors.some((a) => a.tag === 'label')) return
			const id = attr(node, 'id')
			if (typeof id === 'string' && labelled.has(file.rel + '#' + id)) return
			if (id === true) return // bound id: its label is bound too, checked by the persona gate
			offenders.push(line(file, node))
		})
		expect(offenders).toEqual([])
	})

	it('an NcSelect names its input with input-id, not with id (the id lands on the wrapper)', () => {
		const offenders = []
		eachTemplateNode((file, node) => {
			if (node.tag === 'NcSelect' && attr(node, 'id') !== undefined) offenders.push(line(file, node))
		})
		expect(offenders).toEqual([])
	})

	it('text colours use the --color-<status>-text variants, not the background tints', () => {
		const tint = /(^|[\s;{])color:\s*var\(--color-(warning|success|error|info)\s*[,)]/
		const offenders = []
		for (const file of FILES) {
			for (const style of file.descriptor.styles) {
				style.content.split('\n').forEach((text, i) => {
					if (tint.test(text)) offenders.push(`${file.rel}:${style.loc.start.line + i} ${text.trim()}`)
				})
			}
		}
		// the hand-written stylesheet next to the bundles (templates/index.php: style('learning', 'style'))
		readFileSync(join(SRC, '../css/style.css'), 'utf8').split('\n').forEach((text, i) => {
			if (tint.test(text)) offenders.push(`css/style.css:${i + 1} ${text.trim()}`)
		})
		expect(offenders).toEqual([])
	})

	it('the due-cards count on the Smart Queue button lays no white veil over the button colour', () => {
		const style = FILES.find((f) => f.rel === 'components/PoolList.vue').descriptor.styles.map((st) => st.content).join('\n')
		const rule = style.match(/\.sq-count\s*\{[^}]*\}/)
		expect(rule).not.toBeNull()
		expect(rule[0]).not.toMatch(/background:\s*rgba\(255,\s*255,\s*255/)
	})

	it('the VirtuProf panel and dock kickers use a text colour with enough contrast', () => {
		const offenders = []
		for (const file of FILES) {
			for (const style of file.descriptor.styles) {
				const rule = style.content.match(/\.virtuprof-(panel|dock)-kicker\s*\{[^}]*\}/)
				if (rule && /#06b6d4|--nova-accent-cyan/i.test(rule[0])) offenders.push(file.rel)
			}
		}
		expect(offenders).toEqual([])
	})
})
