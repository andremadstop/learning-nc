/**
 * Weak questions (persona sweep C9b, WCAG 1.4.3): a wrong rate between 50 and 69 % was set in
 * `.rate-yellow { color: #e6a817 }`, about 2.1:1 on white. It now takes Nextcloud's warning text
 * colour, like `.rate-red` takes the error text colour; `.rate-ok` keeps the body text colour.
 * The rendered contrast in both themes is measured by the persona station "Participants › Weak
 * questions: the wrong rates keep 4.5:1, light and dark"; here the rules and the thresholds.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const source = readFileSync(resolve(__dirname, '../../src/components/CourseTabTeilnehmer.vue'), 'utf8')

function ownRules() {
	const style = source.slice(source.indexOf('<style'))
	const rules = {}
	for (const m of style.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^}]*)\}/g)) {
		for (const selector of m[1].split(',').map((s) => s.trim())) rules[selector] = (rules[selector] || '') + m[2]
	}
	return rules
}

function luminance(hex) {
	const [r, g, b] = hex.replace('#', '').match(/../g).map((c) => {
		const v = parseInt(c, 16) / 255
		return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
	})
	return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
function contrast(a, b) {
	const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
	return (hi + 0.05) / (lo + 0.05)
}

describe('Weak questions: the wrong rate keeps its contrast', () => {
	const rules = ownRules()

	it('the yellow rate takes the theme warning text colour, no fixed light amber', () => {
		const rule = rules['.rate-yellow'] || ''
		expect(rule).toMatch(/color:\s*var\(--color-warning-text,\s*#[0-9a-f]{6}\)/i)
		expect(rule).not.toMatch(/#e6a817/i)
		expect(rules['.rate-red'] || '').toMatch(/color:\s*var\(--color-error-text\)/)
		expect(rules['.rate-ok']).toBeUndefined()
	})

	it('the fallback holds 4.5:1 on white and on the table header grey', () => {
		const fallback = /var\(--color-warning-text,\s*(#[0-9a-f]{6})\)/i.exec(rules['.rate-yellow'])[1]
		expect(contrast(fallback, '#ffffff')).toBeGreaterThanOrEqual(4.5)
		expect(contrast(fallback, '#ededed')).toBeGreaterThanOrEqual(4.5)
	})

	// Values from apps/theming/lib/Themes/{Default,Dark}Theme.php of the pinned nextcloud:33 image.
	it.each([
		['light', '#664700', '#ffffff'],
		['dark', '#FFEEC5', '#171717'],
	])('Nextcloud\'s warning text colour holds 4.5:1 on the %s main background', (_theme, text, background) => {
		expect(contrast(text, background)).toBeGreaterThanOrEqual(4.5)
	})

	it('the thresholds are unchanged: from 70 % red, from 50 % yellow, else ok', () => {
		expect(source).toContain(":class=\"q.wrong_rate >= 70 ? 'rate-red' : q.wrong_rate >= 50 ? 'rate-yellow' : 'rate-ok'\"")
	})
})
