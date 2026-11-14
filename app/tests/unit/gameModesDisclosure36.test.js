/**
 * Review of GitHub #36: the collapsed "Spielmodi" area is a <details>. Its <summary> had
 * display:flex, which removes the native disclosure triangle (it only draws while the summary is
 * display:list-item), so nothing showed that the line can be expanded.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const sfc = readFileSync(resolve(__dirname, '../../src/components/CourseTabVerwaltung.vue'), 'utf8')
const css = [...sfc.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1]).join('\n').replace(/\/\*[\s\S]*?\*\//g, '')

function decls(selector) {
	const out = {}
	for (const m of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		if (!m[1].split(',').map((s) => s.trim()).includes(selector)) continue
		for (const d of m[2].split(';')) {
			const i = d.indexOf(':')
			if (i > 0) out[d.slice(0, i).trim()] = d.slice(i + 1).trim()
		}
	}
	return out
}

describe('Spielmodi disclosure keeps its marker (GitHub #36)', () => {
	it('the summary stays a list item', () => {
		const display = decls('.game-modes__summary').display
		expect(display === undefined || display === 'list-item', `display: ${display}`).toBe(true)
		expect(decls('.game-modes__summary')['list-style']).not.toBe('none')
	})

	it('no rule hides the marker', () => {
		expect(css).not.toMatch(/game-modes__summary::-webkit-details-marker\s*\{[^}]*display:\s*none/)
		expect(css).not.toMatch(/game-modes__summary::marker\s*\{[^}]*content:\s*(''|"")/)
	})
})
