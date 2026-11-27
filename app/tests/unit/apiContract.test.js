// The request body the frontend sends must be one the backend reads.
//
// A key no controller reads gets a 200 and does silently the wrong thing; neither the PHP tests nor
// the component tests can see it, because each side is tested on its own (memory:
// feedback_request_payload_contracts). This test reads every axios.post/put/patch in src/ whose URL
// is a generateUrl() literal and whose body is an object literal, finds the operation in
// openapi.json, and checks each body key against what that controller can read:
//   - the operation's requestBody properties and parameters (the method arguments), and
//   - the keys the controller class reads with $this->request->getParam('…') or from getParams(),
//     which the OpenAPI extractor does not see.
// Calls with a computed URL or a body variable cannot be checked statically; their number is
// pinned below, so a refactoring that hides calls from this test shows up.
import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'fs'
import { join, relative } from 'path'
import { babelParse, parse as parseSfc } from '@vue/compiler-sfc'

const APP = join(__dirname, '../..')

function sourceFiles(dir) {
	return readdirSync(dir).flatMap((f) => {
		const p = join(dir, f)
		if (statSync(p).isDirectory()) return f === 'l10n' ? [] : sourceFiles(p)
		return /\.(js|ts|vue)$/.test(f) ? [p] : []
	})
}

function operations() {
	const spec = JSON.parse(readFileSync(join(APP, 'openapi.json'), 'utf8'))
	const ops = []
	for (const [path, item] of Object.entries(spec.paths)) {
		for (const [method, op] of Object.entries(item)) {
			if (!['post', 'put', 'patch'].includes(method)) continue
			const route = path.replace('/index.php/apps/learning', '')
			ops.push({
				method,
				route,
				segments: route.split('/'),
				controller: (op.tags?.[0] ?? '').split('_').map((w) => w[0].toUpperCase() + w.slice(1)).join('') + 'Controller',
				keys: new Set([
					...Object.keys(op.requestBody?.content?.['application/json']?.schema?.properties ?? {}),
					...(op.parameters ?? []).map((p) => p.name.replace(/\[\]$/, '')),
				]),
			})
		}
	}
	return ops
}

/** Keys a controller reads outside its method signature: getParam('x') and $params['x'] from getParams(). */
function requestKeysByController() {
	const dir = join(APP, 'lib/Controller')
	const byClass = {}
	for (const f of readdirSync(dir).filter((f) => f.endsWith('.php'))) {
		const php = readFileSync(join(dir, f), 'utf8')
		const keys = new Set([...php.matchAll(/getParam\(\s*['"]([^'"]+)['"]/g)].map((m) => m[1]))
		if (php.includes('getParams()')) {
			for (const m of php.matchAll(/\$params\[\s*['"]([^'"]+)['"]\s*\]/g)) keys.add(m[1])
			for (const m of php.matchAll(/array_key_exists\(\s*['"]([^'"]+)['"]\s*,\s*\$(?:params|this->request->getParams\(\))/g)) keys.add(m[1])
		}
		// getParam($key) with a variable (CoopController): the key lists are string arrays passed to
		// readNullableBool([...]) or looped over in `foreach ([[...], ...] as $keyPair)`. Another
		// helper like that makes this test report its keys; add its pattern here.
		if (/getParam\(\s*\$/.test(php)) {
			for (const m of php.matchAll(/readNullableBool\(\s*\[([^\]]*)\]|foreach\s*\(\s*\[([\s\S]*?)\]\s*as\s*\$keyPair\)/g)) {
				for (const k of (m[1] ?? m[2]).matchAll(/['"]([^'"]+)['"]/g)) keys.add(k[1])
			}
		}
		byClass[f.replace(/\.php$/, '')] = keys
	}
	return byClass
}

/** The last declarator of `name` before `pos` in the enclosing function (two `const url` in two branches). */
function declaratorInScope(fn, name, pos) {
	let found = null
	const visit = (node) => {
		if (!node || typeof node !== 'object') return
		if (Array.isArray(node)) return node.forEach(visit)
		if (node.type === 'VariableDeclarator' && node.id.name === name && node.start < pos && (!found || node.start > found.start)) found = node
		for (const k in node) if (k !== 'loc') visit(node[k])
	}
	visit(fn)
	return found
}

// Keys of an object built in the enclosing function: `const body = { a, b }` plus `body.c = …`.
function objectKeysInScope(fn, name, pos) {
	const decl = declaratorInScope(fn, name, pos)
	if (decl?.init?.type !== 'ObjectExpression') return null
	const keys = literalKeys(decl.init)
	const visit = (node) => {
		if (!node || typeof node !== 'object') return
		if (Array.isArray(node)) return node.forEach(visit)
		if (node.type === 'AssignmentExpression' && node.start > decl.start && node.start < pos
			&& node.left.type === 'MemberExpression' && node.left.object.name === name) {
			const k = node.left.computed ? node.left.property.value : node.left.property.name
			if (typeof k === 'string') keys.push(k)
		}
		for (const k in node) if (k !== 'loc') visit(node[k])
	}
	visit(fn)
	return keys
}

/** The literal keys of an object expression; spread and computed keys cannot be checked and are skipped. */
function literalKeys(obj) {
	return obj.properties.filter((p) => p.type === 'ObjectProperty' && !p.computed).map((p) => p.key.name ?? p.key.value)
}

/** The path a generateUrl() argument stands for, with {x} for every part that is not a constant. */
function pathOf(arg, consts) {
	if (!arg) return null
	if (arg.type === 'StringLiteral') return arg.value
	if (arg.type === 'Identifier') return consts[arg.name] ?? null
	if (arg.type === 'TemplateLiteral') {
		return arg.quasis.map((q, i) => q.value.cooked + (arg.expressions[i] ? (pathOf(arg.expressions[i], consts) ?? '{x}') : '')).join('')
	}
	if (arg.type === 'BinaryExpression' && arg.operator === '+') {
		return (pathOf(arg.left, consts) ?? '{x}') + (pathOf(arg.right, consts) ?? '{x}')
	}
	return null
}

/** The path of an axios URL argument: generateUrl(…) directly or through a variable of the enclosing function. */
function urlOf(arg, fn, consts, pos) {
	if (arg?.type === 'Identifier' && fn) arg = declaratorInScope(fn, arg.name, pos)?.init
	if (arg?.type !== 'CallExpression' || arg.callee.name !== 'generateUrl') return null
	const path = pathOf(arg.arguments[0], consts)
	return path && path.startsWith('/') ? path : null
}

/** A call path matches a route segment by segment; a {param} in the route or an x (a computed part) in the call matches any one segment. */
function matches(op, method, url) {
	const parts = url.split('/')
	return op.method === method && parts.length === op.segments.length
		&& parts.every((p, i) => p === 'x' || p === op.segments[i] || /^\{[^}]+\}$/.test(op.segments[i]))
}

function writeCalls() {
	const calls = []
	const blind = { computedUrl: 0, bodyVariable: 0 }
	for (const file of sourceFiles(join(APP, 'src'))) {
		let code = readFileSync(file, 'utf8')
		if (file.endsWith('.vue')) {
			const { descriptor } = parseSfc(code)
			code = (descriptor.script ?? descriptor.scriptSetup)?.content ?? ''
		}
		if (!/axios\.(post|put|patch)\(/.test(code)) continue
		const ast = babelParse(code, { sourceType: 'module', plugins: ['typescript'] })
		// module-level string constants, e.g. const COOP_BASE_URL = '/apps/learning/api/v1/coop/sessions'
		const consts = {}
		for (const st of ast.program.body) {
			const decl = st.type === 'ExportNamedDeclaration' ? st.declaration : st
			if (decl?.type !== 'VariableDeclaration' || decl.kind !== 'const') continue
			for (const d of decl.declarations) if (d.init?.type === 'StringLiteral') consts[d.id.name] = d.init.value
		}
		const visit = (node, fn) => {
			if (!node || typeof node !== 'object') return
			if (Array.isArray(node)) return node.forEach((n) => visit(n, fn))
			if (/Function|ObjectMethod|ClassMethod/.test(node.type)) fn = node
			const callee = node.type === 'CallExpression' ? node.callee : null
			if (callee?.type === 'MemberExpression' && callee.object.name === 'axios' && ['post', 'put', 'patch'].includes(callee.property.name)) {
				const [urlArg, body] = node.arguments
				const url = urlOf(urlArg, fn, consts, node.start)
				const keys = body === undefined ? []
					: body.type === 'ObjectExpression' ? literalKeys(body)
						: body.type === 'Identifier' && fn ? objectKeysInScope(fn, body.name, node.start) : null
				if (url === null) blind.computedUrl++
				else if (keys === null) blind.bodyVariable++
				else {
					calls.push({
						method: callee.property.name,
						url: url.replace(/^\/apps\/learning/, '').split('?')[0].replace(/\{[^}]+\}/g, 'x'),
						keys,
						where: `${relative(APP, file)}:${node.loc.start.line}`,
					})
				}
			}
			for (const k in node) if (k !== 'loc') visit(node[k], fn)
		}
		visit(ast.program, null)
	}
	return { calls, blind }
}

const ops = operations()
const readByController = requestKeysByController()
const { calls, blind } = writeCalls()

describe('API contract: request bodies (frontend ↔ openapi.json)', () => {
	it('finds the operation of every checkable write call', () => {
		const missing = calls.filter((c) => !ops.some((o) => matches(o, c.method, c.url)))
		expect(missing.map((c) => `${c.method} ${c.url} (${c.where})`)).toEqual([])
	})

	it('sends no body key the controller does not read', () => {
		const unread = []
		for (const c of calls) {
			const op = ops.find((o) => matches(o, c.method, c.url))
			if (!op) continue
			const extra = readByController[op.controller] ?? new Set()
			const known = KNOWN_UNREAD[`${c.method} ${op.route}`] ?? []
			const bad = c.keys.filter((k) => !op.keys.has(k) && !extra.has(k) && !known.includes(k))
			if (bad.length) unread.push(`${c.method} ${op.route}: ${bad.join(', ')} (${c.where})`)
		}
		expect(unread).toEqual([])
	})

	it('still sees the write calls it can check', () => {
		// Counted 27.11.2026. More checkable calls: raise the floor. Fewer: a call moved behind a
		// variable URL or body and is no longer checked; make it a literal again or lower on purpose.
		expect(calls.length).toBeGreaterThanOrEqual(CHECKED_FLOOR)
		expect(blind).toEqual(BLIND)
	})
})

const CHECKED_FLOOR = 127
const BLIND = { computedUrl: 2, bodyVariable: 32 }

// Keys the static scan reports although they are never sent to that route. May only shrink.
const KNOWN_UNREAD = {
	// CourseList.vue sets payload.profile only for a new course (!this.editingCourse); the scan does
	// not see the condition and also counts it for the update.
	'put /api/courses/{courseId}': ['profile'],
}
