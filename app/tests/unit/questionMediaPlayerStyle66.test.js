/**
 * GitHub #66, Persona-Gate C4: a focused or hovered Play button of the media player showed white
 * text on a light background (axe: 1.3:1, #ffffff on #d9e3e8).
 *
 * Nextcloud core (server.css, NC 33) gives every plain button
 * `button:not(.button-vue,[class^=vs__]):hover/:focus { background-color:
 * var(--color-primary-element-light-hover) }` at specificity (0,2,1). The player's primary rule
 * `.qmedia__button--primary` is (0,2,0) once scoped, so on hover or focus the core background won
 * while the text stayed white. The same holds for the active language button.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const css = [...readFileSync(resolve(__dirname, '../../src/components/QuestionMediaPlayer.vue'), 'utf8')
	.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)]
	.map((m) => m[1])
	.join('\n')
	.replace(/\/\*[\s\S]*?\*\//g, '')

/** Specificity as a*100 + b, counting classes, pseudo-classes (not :not) and attributes; +100 for the scope attribute. */
function scopedSpec(sel) {
	const classes = (sel.match(/\.[\w-]+|:(?!not\()[\w-]+(?:\([^)]*\))?|\[[^\]]+\]/g) || []).length
	const types = (sel.replace(/\([^)]*\)/g, '').match(/(^|[\s>+~])[a-z]+/gi) || []).length
	return (classes + 1) * 100 + types
}

/** Rules whose selector list contains a selector matching `test`, with their declarations. */
function rules(test) {
	const out = []
	for (const m of css.matchAll(/([^{}@]+)\{([^{}]*)\}/g)) {
		const decls = {}
		for (const decl of m[2].split(';')) {
			const i = decl.indexOf(':')
			if (i >= 0) decls[decl.slice(0, i).trim()] = decl.slice(i + 1).trim()
		}
		for (const sel of m[1].split(',').map((s) => s.trim().replace(/\s+/g, ' '))) {
			if (test(sel)) out.push({ sel, decls })
		}
	}
	return out
}

const CORE_HOVER_FOCUS = 201

describe('#66 media player: primary buttons keep their colours on hover and focus', () => {
	for (const cls of ['.qmedia__button--primary', '.qmedia__lang--active']) {
		for (const state of [':hover', ':focus']) {
			it(`${cls}${state} outranks the core button rule and keeps a primary background`, () => {
				const hits = rules((sel) => sel.includes(cls) && sel.endsWith(state) && scopedSpec(sel) > CORE_HOVER_FOCUS)
				expect(hits.length, `${cls}${state}`).toBeGreaterThan(0)
				for (const { decls } of hits) {
					expect(decls.background || decls['background-color']).toMatch(/^var\(--color-primary-element(-hover)?\)$/)
					expect(decls.color).toBe('var(--color-primary-element-text)')
				}
			})
		}
	}
})

/**
 * Gesamt-Review C4: since NC 32/33 --color-error, --color-success and --color-warning are the pale
 * background tints (#FFE7E7 light, #552121 dark), about 1.1:1 on the stage or a hover background.
 * The wavy mark of the misconception keywords (aria-hidden, so the only cue) and the accent borders
 * of the feedback panel and the hint must use the element variants (at least 3:1).
 */
describe('Gesamt-Review C4: coloured cues use the element colours, not the pale tints', () => {
	const styleOf = (file) => [...readFileSync(resolve(__dirname, '../../src/components/' + file), 'utf8')
		.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1]).join('\n').replace(/\/\*[\s\S]*?\*\//g, '')
	const pale = /var\(--color-(error|success|warning)\)/

	it('the wavy mark of a doubtful keyword', () => {
		const hits = rules((sel) => sel.includes('qmedia__keyword--doubt'))
		expect(hits.length).toBeGreaterThan(0)
		for (const { decls } of hits) {
			expect(decls['text-decoration']).toMatch(/var\(--color-element-error/)
			expect(decls['text-decoration']).not.toMatch(pale)
		}
	})

	for (const [file, needed] of [['RichFeedbackPanel.vue', ['--color-element-success', '--color-element-error']], ['QuestionHint.vue', ['--color-element-warning']]]) {
		it(`the accent borders of ${file}`, () => {
			const css = styleOf(file)
			expect(css).not.toMatch(pale)
			for (const name of needed) {
				expect(css).toContain('var(' + name)
			}
		})
	}
})
