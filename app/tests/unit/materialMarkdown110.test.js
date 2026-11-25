// @vitest-environment jsdom
/**
 * GitHub #110: Markdown materials are rendered inside Learning. The file stays text/plain on the
 * server; these tests pin the two sanitizing layers (markdown-it html:false, then DOMPurify with
 * link and image hooks).
 *
 * jsdom, not the suite's happy-dom: under happy-dom DOMPurify drops a fragment's first element and
 * does not walk the children it keeps from a removed element, so a sanitizer test there would
 * not test what a browser does.
 */
import { describe, it, expect } from 'vitest'
import { renderMaterialMarkdown, tooLargeToRender, MAX_RENDER_BYTES } from '../../src/utils/materialMarkdown.js'

const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='

function dom(html) {
	const el = document.createElement('div')
	el.innerHTML = html
	return el
}

describe('renderMaterialMarkdown: what learners need', () => {
	it('renders headings, tables with alignment, lists and code', () => {
		const el = dom(renderMaterialMarkdown('# Title\n\n| a | b |\n|:--|--:|\n| 1 | 2 |\n\n- x\n- y\n\n```\ncode\n```'))
		expect(el.querySelector('h1').textContent).toBe('Title')
		expect(el.querySelectorAll('table td')).toHaveLength(2)
		expect(el.querySelector('td').getAttribute('style')).toBe('text-align:left')
		expect(el.querySelectorAll('li')).toHaveLength(2)
		expect(el.querySelector('pre code').textContent).toBe('code\n')
	})

	it('keeps an embedded base64 raster screenshot', () => {
		const el = dom(renderMaterialMarkdown(`![Screenshot](${PNG})`))
		expect(el.querySelector('img').getAttribute('src')).toBe(PNG)
		expect(el.querySelector('img').getAttribute('alt')).toBe('Screenshot')
	})

	it('opens http(s) and mailto links in a new tab without opener', () => {
		const el = dom(renderMaterialMarkdown('[a](https://example.org) [b](mailto:x@example.org) [c](#part)'))
		const [a, b, c] = el.querySelectorAll('a')
		expect(a.getAttribute('target')).toBe('_blank')
		expect(a.getAttribute('rel')).toBe('noopener noreferrer')
		expect(b.getAttribute('href')).toBe('mailto:x@example.org')
		expect(c.getAttribute('href')).toBe('#part')
		expect(c.hasAttribute('target')).toBe(false)
	})

	it('renders Chinese and Arabic text unchanged', () => {
		const el = dom(renderMaterialMarkdown('## 培训材料\n\nمرحبا'))
		expect(el.querySelector('h2').textContent).toBe('培训材料')
		expect(el.querySelector('p').textContent).toBe('مرحبا')
	})
})

describe('renderMaterialMarkdown: what never gets through', () => {
	it.each([
		['script tag', '<script>window.pwned=1</script>'],
		['img onerror', '<img src=x onerror="window.pwned=1">'],
		['iframe', '<iframe src="https://evil.example"></iframe>'],
		['inline event in raw html', '<div onclick="alert(1)">x</div>'],
	])('shows raw HTML (%s) as text, never as an element', (_name, source) => {
		const el = dom(renderMaterialMarkdown(source))
		expect(el.querySelector('script, iframe, div, img')).toBeNull()
		expect(el.innerHTML).not.toMatch(/<(script|iframe|img|div)\b/i)
		expect(el.textContent).toContain('<')
	})

	it.each([
		['javascript:', '[x](javascript:alert(1))'],
		['upper-case JAVASCRIPT:', '[x](JAVASCRIPT:alert(1))'],
		['entity-encoded', '[x](java&#115;cript:alert(1))'],
		['data:text/html', '[x](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)'],
		['vbscript:', '[x](vbscript:msgbox(1))'],
		['relative path', '[x](other.md)'],
		['protocol-relative', '[x](//evil.example/a)'],
	])('drops a %s link target and keeps the text', (_name, source) => {
		const el = dom(renderMaterialMarkdown(source))
		// markdown-it may refuse the link (then its source shows as text) or DOMPurify/the hook
		// strips the target; either way no element carries a URL
		for (const node of el.querySelectorAll('*')) {
			expect(node.hasAttribute('href')).toBe(false)
			expect(node.hasAttribute('src')).toBe(false)
		}
		expect(el.textContent).toContain('x')
	})

	it.each([
		['remote', '![logo](https://tracker.example/pixel.png)'],
		['relative', '![fig](images/fig.png)'],
		['svg data', '![s](data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+)'],
		['non-base64 data', '![d](data:image/png,abc)'],
	])('replaces a %s image by its alt text', (_name, source) => {
		const el = dom(renderMaterialMarkdown(source))
		expect(el.querySelector('img')).toBeNull()
		expect(el.querySelector('[src], [href]')).toBeNull()
		// remote/relative: the hook leaves the alt text; svg/non-base64 data: markdown-it already
		// refuses the image and shows its source as text
		expect(el.textContent.trim()).toMatch(/^\[(logo|fig)\]$|^!\[(s|d)\]\(data:/)
		expect(el.innerHTML).not.toMatch(/<img|="(https?:|images\/)/)
	})

	it('allows no style other than table alignment', () => {
		// markdown-it never writes a style on a paragraph; the hook would strip one anyway
		const el = dom(renderMaterialMarkdown('| a |\n|:-:|\n| 1 |'))
		expect(el.querySelector('th').getAttribute('style')).toBe('text-align:center')
	})

	it('keeps a file whose content is JSON as text', () => {
		const el = dom(renderMaterialMarkdown('{"a":1}'))
		expect(el.querySelector('p').textContent).toBe('{"a":1}')
	})
})

describe('tooLargeToRender', () => {
	it('renders normal files and refuses very large ones', () => {
		expect(tooLargeToRender('# small')).toBe(false)
		expect(tooLargeToRender('x'.repeat(MAX_RENDER_BYTES + 1))).toBe(true)
	})
})
