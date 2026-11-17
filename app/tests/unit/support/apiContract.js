/**
 * Contract scanner for GitHub #65: finds every request the frontend makes to this app and the
 * routes it targets, so apiContract.test.js can check them against app/openapi.json.
 *
 * Two kinds of call sites:
 *  - XHR: `axios.get|post|put|patch|delete(url, …)` — sends the request token, so any route that
 *    exists for that method is fine. Server-sent events count here too, as GET:
 *    `createSseClient(url, …)` (src/utils/sse-client.js) and `new EventSource(url)` send the token as
 *    the `requesttoken` query parameter, which Nextcloud's CSRF check accepts.
 *  - Navigation: `window.location(.href) = url`, `location.assign(url)`, `window.open(url)`,
 *    `<element>.href = url`, and `href`/`src` attributes and bindings in templates. The browser
 *    sends no request token, so the route must be GET and #[NoCSRFRequired] (in the spec: no
 *    required `OCS-APIRequest` header parameter). A download that needs the token fails with
 *    "CSRF check failed" for every user, and no unit test of the component notices.
 *
 * The URL argument is evaluated abstractly with the real parser (Babel via @vue/compiler-sfc):
 * string and template literals, `+`, `?:`, `||`/`??`, generateUrl(), constants and local
 * variables, imported constants and functions, and in components `this.x` computed properties and
 * methods (their return values). What cannot be evaluated becomes a wildcard segment; an argument
 * with no literal part at all is "unresolved" and has to be listed in the test's allowlist, so a
 * call the scanner cannot see is never counted as checked.
 */
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { babelParse, parse as parseSfc } from '@vue/compiler-sfc'

export const WILD = '\u0001'
const APP_PREFIX = '/apps/learning'
const MAX_CANDIDATES = 64
const MAX_DEPTH = 6

const AXIOS_METHODS = new Set(['get', 'post', 'put', 'patch', 'delete'])

/** All .js/.ts/.vue files below dir. */
export function listSources(dir) {
	const out = []
	for (const name of readdirSync(dir)) {
		const p = join(dir, name)
		if (statSync(p).isDirectory()) {
			out.push(...listSources(p))
		} else if (/\.(js|ts|vue)$/.test(name)) {
			out.push(p)
		}
	}
	return out.sort()
}

// ---------------------------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------------------------

function parseScript(code, file) {
	const plugins = file.endsWith('.ts') || /lang=["']ts["']/.test(code) ? ['typescript'] : []
	return babelParse(code, { sourceType: 'module', plugins, errorRecovery: false })
}

/**
 * A parsed module: Babel program, plus (for .vue) the template AST.
 * Created from a path, or from in-memory text for the self-tests.
 */
export class Module {
	constructor(file, text, loader) {
		this.file = file
		this.loader = loader
		this.template = null
		this.scriptLine = 0
		let code = text
		if (file.endsWith('.vue')) {
			const { descriptor, errors } = parseSfc(text, { filename: file })
			if (errors.length) {
				throw new Error(`${file}: ${errors[0].message}`)
			}
			const script = descriptor.script || descriptor.scriptSetup
			code = script ? script.content : ''
			this.scriptLang = script?.lang
			this.scriptLine = script ? script.loc.start.line - 1 : 0
			this.template = descriptor.template ? descriptor.template.ast : null
		}
		this.code = code
		this.ast = parseScript(code, this.scriptLang === 'ts' ? 'x.ts' : file)
		this.imports = new Map() // local name -> { source, imported }
		this.topBindings = new Map() // name -> [init nodes] (const/let/function)
		this.axiosNames = new Set()
		for (const node of this.ast.program.body) {
			this.collectTop(node)
		}
		this.component = this.findComponent()
	}

	collectTop(node) {
		if (node.type === 'ImportDeclaration') {
			for (const s of node.specifiers) {
				const imported = s.type === 'ImportDefaultSpecifier' ? 'default' : s.type === 'ImportNamespaceSpecifier' ? '*' : (s.imported.name ?? s.imported.value)
				this.imports.set(s.local.name, { source: node.source.value, imported })
				if (node.source.value === '@nextcloud/axios' && imported === 'default') {
					this.axiosNames.add(s.local.name)
				}
			}
			return
		}
		const decl = node.type === 'ExportNamedDeclaration' || node.type === 'ExportDefaultDeclaration' ? node.declaration : node
		if (!decl) {
			return
		}
		if (decl.type === 'VariableDeclaration') {
			for (const d of decl.declarations) {
				if (d.id.type === 'Identifier') {
					addBinding(this.topBindings, d.id.name, d.init)
				}
			}
		} else if (decl.type === 'FunctionDeclaration' && decl.id) {
			addBinding(this.topBindings, decl.id.name, decl)
		}
	}

	/** The Options-API object: `export default {…}` or `export default defineComponent({…})`. */
	findComponent() {
		for (const node of this.ast.program.body) {
			if (node.type !== 'ExportDefaultDeclaration') {
				continue
			}
			let d = node.declaration
			if (d.type === 'CallExpression' && d.arguments[0]?.type === 'ObjectExpression') {
				d = d.arguments[0]
			}
			if (d.type === 'Identifier') {
				d = (this.topBindings.get(d.name) || [])[0]
			}
			if (d && d.type === 'ObjectExpression') {
				return d
			}
		}
		return null
	}

	/** computed/methods member `name` of the component, as a function node. */
	componentMember(name) {
		if (!this.component) {
			return null
		}
		for (const section of ['computed', 'methods']) {
			const sec = objectProp(this.component, section)
			if (!sec || sec.type !== 'ObjectExpression') {
				continue
			}
			const member = objectProp(sec, name, true)
			if (!member) {
				continue
			}
			if (member.type === 'ObjectExpression') {
				const getter = objectProp(member, 'get', true)
				if (getter) {
					return getter
				}
			}
			return member
		}
		return null
	}
}

function addBinding(map, name, node) {
	if (!map.has(name)) {
		map.set(name, [])
	}
	map.get(name).push(node)
}

function keyName(p) {
	if (!p.key) {
		return null
	}
	return p.key.type === 'Identifier' ? p.key.name : p.key.value
}

/** Value of property `name` in an object literal (methods: the method node itself). */
function objectProp(obj, name, wantFunction = false) {
	for (const p of obj.properties) {
		if (p.computed || keyName(p) !== name) {
			continue
		}
		if (p.type === 'ObjectMethod') {
			return p
		}
		if (p.type === 'ObjectProperty') {
			return wantFunction || p.value ? p.value : null
		}
	}
	return null
}

export class Loader {
	constructor(srcRoot) {
		this.srcRoot = srcRoot
		this.cache = new Map()
	}

	load(file) {
		if (!this.cache.has(file)) {
			this.cache.set(file, new Module(file, readFileSync(file, 'utf8'), this))
		}
		return this.cache.get(file)
	}

	/** Register in-memory source (self-tests). */
	add(file, text) {
		const m = new Module(file, text, this)
		this.cache.set(file, m)
		return m
	}

	resolveImport(fromFile, source) {
		let base
		if (source.startsWith('@/')) {
			base = join(this.srcRoot, source.slice(2))
		} else if (source.startsWith('.')) {
			base = resolve(dirname(fromFile), source)
		} else {
			return null
		}
		for (const cand of [base, base + '.js', base + '.ts', join(base, 'index.js')]) {
			if (this.cache.has(cand) || (existsSync(cand) && statSync(cand).isFile())) {
				return cand
			}
		}
		return null
	}
}

// ---------------------------------------------------------------------------------------------
// Abstract evaluation: node -> list of strings, WILD for unknown parts. null = nothing known.
// ---------------------------------------------------------------------------------------------

const UNKNOWN = [WILD]

function concat(a, b) {
	const out = []
	for (const x of a) {
		for (const y of b) {
			out.push(x + y)
			if (out.length >= MAX_CANDIDATES) {
				return out
			}
		}
	}
	return out
}

function union(...lists) {
	return [...new Set(lists.flat())].slice(0, MAX_CANDIDATES)
}

/** Collapse runs of wildcards so "a" + x + y reads as one unknown part. */
function norm(list) {
	return union(list.map((s) => s.replace(new RegExp(WILD + '+', 'g'), WILD)))
}

const SKIP_KEYS = new Set(['loc', 'start', 'end', 'leadingComments', 'trailingComments', 'innerComments', 'extra'])
const isFunction = (n) => /Function|ObjectMethod|ClassMethod/.test(n.type)
const isScope = (n) => isFunction(n) || n.type === 'BlockStatement'

/**
 * Visit `root` and its descendants with the chain of enclosing scopes (functions and blocks,
 * outermost first, `root` included when it is one), without entering nested functions.
 */
function walkScoped(root, visit, outer = []) {
	const go = (n, scopes) => {
		if (!n || typeof n.type !== 'string') {
			return
		}
		if (n !== root && isFunction(n)) {
			return
		}
		const inner = isScope(n) ? [...scopes, n] : scopes
		if (visit(n, inner) === false) {
			return
		}
		for (const k of Object.keys(n)) {
			if (SKIP_KEYS.has(k)) {
				continue
			}
			const v = n[k]
			if (Array.isArray(v)) {
				v.forEach((c) => go(c, inner))
			} else if (v && typeof v.type === 'string') {
				go(v, inner)
			}
		}
	}
	go(root, outer)
}

/** Return-value expressions of a function node, each with its scope chain. */
function returnsOf(fn, outer = []) {
	if (!fn.body) {
		return []
	}
	if (fn.body.type !== 'BlockStatement') {
		return [{ node: fn.body, scopes: [...outer, fn] }]
	}
	const out = []
	walkScoped(fn, (n, scopes) => {
		if (n.type === 'ReturnStatement') {
			if (n.argument) {
				out.push({ node: n.argument, scopes })
			}
			return false
		}
	}, outer)
	return out
}

/**
 * Bindings of `name` declared directly in `scope` (a function's parameters, or a block's own
 * const/let/var/function statements), plus every assignment to it inside that scope.
 */
function localBindings(scope, name) {
	const out = []
	let declared = false
	let isParam = false
	if (isFunction(scope)) {
		for (const p of scope.params || []) {
			if (p.type === 'Identifier' && p.name === name) {
				declared = isParam = true
			}
			if (p.type === 'AssignmentPattern' && p.left.type === 'Identifier' && p.left.name === name) {
				declared = isParam = true
				out.push(p.right)
			}
			if (p.type === 'ObjectPattern' || p.type === 'ArrayPattern') {
				if (JSON.stringify(p, (k, v) => (SKIP_KEYS.has(k) ? undefined : v)).includes('"name":"' + name + '"')) {
					declared = isParam = true
				}
			}
		}
	} else {
		for (const st of scope.body) {
			const decl = st.type === 'ExportNamedDeclaration' ? st.declaration : st
			if (decl && decl.type === 'VariableDeclaration') {
				for (const d of decl.declarations) {
					if (d.id.type === 'Identifier' && d.id.name === name) {
						declared = true
						if (d.init) {
							out.push(d.init)
						}
					}
				}
			} else if (decl && decl.type === 'FunctionDeclaration' && decl.id?.name === name) {
				declared = true
				out.push(decl)
			}
		}
	}
	if (declared) {
		walkScoped(scope, (n) => {
			if (n.type === 'AssignmentExpression' && n.left.type === 'Identifier' && n.left.name === name) {
				out.push(n.operator === '=' ? n.right : { type: '__Unknown' })
			}
		})
	}
	return { nodes: out, isParam, declared }
}

export class Evaluator {
	constructor(loader) {
		this.loader = loader
	}

	/**
	 * @param {Module} mod
	 * @param {object} node Babel expression
	 * @param {object[]} fns enclosing function nodes, innermost last
	 * @param {boolean} templateScope identifiers are component members (template expressions)
	 */
	eval(mod, node, fns = [], templateScope = false, depth = 0) {
		if (!node || depth > MAX_DEPTH) {
			return UNKNOWN
		}
		const ev = (n, f = fns, d = depth) => this.eval(mod, n, f, templateScope, d)
		switch (node.type) {
		case 'StringLiteral':
			return [node.value]
		case 'NumericLiteral':
			return [String(node.value)]
		case 'TemplateLiteral': {
			let acc = ['']
			node.quasis.forEach((q, i) => {
				acc = concat(acc, [q.value.cooked ?? q.value.raw])
				if (i < node.expressions.length) {
					acc = concat(acc, ev(node.expressions[i]))
				}
			})
			return norm(acc)
		}
		case 'BinaryExpression':
			if (node.operator === '+') {
				return norm(concat(ev(node.left), ev(node.right)))
			}
			return UNKNOWN
		case 'ConditionalExpression':
			return union(ev(node.consequent), ev(node.alternate))
		case 'LogicalExpression':
			return union(ev(node.left), ev(node.right))
		case 'TSAsExpression':
		case 'TSNonNullExpression':
		case 'ParenthesizedExpression':
			return ev(node.expression)
		case '__Unknown':
			return UNKNOWN
		case 'Identifier':
			return this.evalIdentifier(mod, node.name, fns, templateScope, depth)
		case 'MemberExpression':
		case 'OptionalMemberExpression':
			if (node.object.type === 'ThisExpression' && !node.computed) {
				return this.evalMember(mod, node.property.name, depth)
			}
			return UNKNOWN
		case 'CallExpression':
		case 'OptionalCallExpression':
			return this.evalCall(mod, node, fns, templateScope, depth)
		default:
			return UNKNOWN
		}
	}

	evalIdentifier(mod, name, fns, templateScope, depth) {
		for (let i = fns.length - 1; i >= 0; i--) {
			const { nodes, isParam, declared } = localBindings(fns[i], name)
			if (declared) {
				const vals = nodes.map((n) => (n.type === 'FunctionDeclaration' ? UNKNOWN : this.eval(mod, n, fns.slice(0, i + 1), templateScope, depth + 1)))
				return union(...vals, isParam ? UNKNOWN : [])
			}
		}
		if (mod.topBindings.has(name)) {
			return union(...mod.topBindings.get(name).map((n) => (n && n.type === 'FunctionDeclaration' ? UNKNOWN : this.eval(mod, n, [], false, depth + 1))))
		}
		const imp = mod.imports.get(name)
		if (imp) {
			const target = this.importedModule(mod, imp)
			if (target && target.topBindings.has(imp.imported)) {
				return union(...target.topBindings.get(imp.imported).map((n) => (n && n.type === 'FunctionDeclaration' ? UNKNOWN : this.eval(target, n, [], false, depth + 1))))
			}
			return UNKNOWN
		}
		if (templateScope) {
			return this.evalMember(mod, name, depth)
		}
		return UNKNOWN
	}

	importedModule(mod, imp) {
		const file = this.loader.resolveImport(mod.file, imp.source)
		return file ? this.loader.load(file) : null
	}

	/** this.name: a computed property (its return values); anything else is unknown. */
	evalMember(mod, name, depth) {
		const member = mod.componentMember(name)
		if (member && /Function|ObjectMethod/.test(member.type)) {
			return union(...returnsOf(member).map((r) => this.eval(mod, r.node, r.scopes, false, depth + 1)))
		}
		return UNKNOWN
	}

	/** Return values of the function a call targets: generateUrl, local/imported function, this.method. */
	functionOf(mod, callee, fns, templateScope) {
		if (callee.type === 'Identifier') {
			for (let i = fns.length - 1; i >= 0; i--) {
				const { nodes, declared } = localBindings(fns[i], callee.name)
				if (declared) {
					const fn = nodes.find((n) => /Function/.test(n.type))
					return fn ? { mod, fn } : null
				}
			}
			const top = (mod.topBindings.get(callee.name) || []).find((n) => n && /Function/.test(n.type))
			if (top) {
				return { mod, fn: top }
			}
			const imp = mod.imports.get(callee.name)
			if (imp) {
				const target = this.importedModule(mod, imp)
				const fn = target && (target.topBindings.get(imp.imported) || []).find((n) => n && /Function/.test(n.type))
				return fn ? { mod: target, fn } : null
			}
			if (templateScope) {
				const m = mod.componentMember(callee.name)
				return m ? { mod, fn: m } : null
			}
			return null
		}
		if ((callee.type === 'MemberExpression' || callee.type === 'OptionalMemberExpression') && callee.object.type === 'ThisExpression' && !callee.computed) {
			const m = mod.componentMember(callee.property.name)
			return m ? { mod, fn: m } : null
		}
		return null
	}

	evalCall(mod, node, fns, templateScope, depth) {
		const callee = node.callee
		const calleeName = callee.type === 'Identifier' ? callee.name : null
		const importedName = calleeName && mod.imports.get(calleeName)
		if (importedName && importedName.source === '@nextcloud/router') {
			if (importedName.imported === 'generateUrl') {
				// generateUrl('/apps/learning/x/{id}', {id}) — NC placeholders become wildcards.
				return norm(this.eval(mod, node.arguments[0], fns, templateScope, depth + 1).map((s) => s.replace(/\{[^}/]+\}/g, WILD)))
			}
			return ['<foreign:' + importedName.imported + '>']
		}
		// String(x) / encodeURIComponent(x) / x.toString(): an unknown segment.
		const target = this.functionOf(mod, callee, fns, templateScope)
		if (target) {
			return union(...returnsOf(target.fn).map((r) => this.eval(target.mod, r.node, r.scopes, false, depth + 1)))
		}
		return UNKNOWN
	}
}

// ---------------------------------------------------------------------------------------------
// Call sites
// ---------------------------------------------------------------------------------------------

/** Walk a Babel AST with the stack of enclosing functions. */
function walkWithFns(node, visit, fns = []) {
	if (!node || typeof node.type !== 'string') {
		return
	}
	const inner = isScope(node) ? [...fns, node] : fns
	visit(node, inner)
	for (const k of Object.keys(node)) {
		if (k === 'loc' || k === 'leadingComments' || k === 'trailingComments' || k === 'innerComments') {
			continue
		}
		const v = node[k]
		if (Array.isArray(v)) {
			v.forEach((c) => walkWithFns(c, visit, inner))
		} else if (v && typeof v.type === 'string') {
			walkWithFns(v, visit, inner)
		}
	}
}

function memberPath(n) {
	if (n.type === 'Identifier') {
		return n.name
	}
	if ((n.type === 'MemberExpression' || n.type === 'OptionalMemberExpression') && !n.computed) {
		const base = memberPath(n.object)
		return base ? base + '.' + n.property.name : null
	}
	if (n.type === 'ThisExpression') {
		return 'this'
	}
	return null
}

/**
 * @typedef {object} CallSite
 * @property {string} file  path relative to the scan root
 * @property {number} line
 * @property {'xhr'|'navigation'} kind
 * @property {string|null} method  HTTP method for xhr, 'GET' for navigation
 * @property {string} sink  e.g. "axios.post", "createSseClient", "window.location.href", ":href"
 * @property {string} arg   source text of the URL argument
 * @property {string[]} candidates  evaluated URL strings (WILD = unknown part)
 */

/**
 * All call sites of one module.
 * @return {CallSite[]}
 */
export function scanModule(mod, evaluator, root) {
	const rel = relative(root, mod.file)
	const sites = []
	const text = (n) => mod.code.slice(n.start, n.end)
	const push = (kind, method, sink, argNode, fns, line, argText, templateScope = false) => {
		sites.push({
			file: rel,
			line,
			kind,
			method,
			sink,
			arg: (argText ?? (argNode ? text(argNode) : '')).replace(/\s+/g, ' ').trim(),
			candidates: argNode ? evaluator.eval(mod, argNode, fns, templateScope) : UNKNOWN,
		})
	}
	const lineOf = (n) => (n.loc ? n.loc.start.line + mod.scriptLine : 0)

	walkWithFns(mod.ast.program, (node, fns) => {
		if (node.type === 'CallExpression' || node.type === 'OptionalCallExpression') {
			const c = node.callee
			if ((c.type === 'MemberExpression' || c.type === 'OptionalMemberExpression') && !c.computed && c.object.type === 'Identifier'
				&& mod.axiosNames.has(c.object.name) && AXIOS_METHODS.has(c.property.name)) {
				push('xhr', c.property.name.toUpperCase(), 'axios.' + c.property.name, node.arguments[0], fns, lineOf(node))
			} else if (mod.axiosNames.has(memberPath(c) ?? '') || ['axios.request', 'fetch', 'window.fetch'].includes(memberPath(c) ?? '')) {
				push('xhr', null, memberPath(c), node.arguments[0], fns, lineOf(node))
			} else {
				const p = memberPath(c)
				if (p === 'window.open' || p === 'window.location.assign' || p === 'location.assign' || p === 'window.location.replace' || p === 'location.replace') {
					push('navigation', 'GET', p, node.arguments[0], fns, lineOf(node))
				} else if (c.type === 'Identifier' && mod.imports.get(c.name)?.imported === 'createSseClient') {
					push('xhr', 'GET', 'createSseClient', node.arguments[0], fns, lineOf(node))
				}
			}
		}
		if (node.type === 'NewExpression' && ['EventSource', 'window.EventSource'].includes(memberPath(node.callee) ?? '')) {
			push('xhr', 'GET', 'new ' + memberPath(node.callee), node.arguments[0], fns, lineOf(node))
		}
		if (node.type === 'AssignmentExpression' && node.operator === '=') {
			const p = memberPath(node.left)
			if (p && (p === 'window.location' || p === 'location' || p.endsWith('.href'))) {
				push('navigation', 'GET', p.endsWith('.href') && !p.includes('location') ? '<el>.href' : p, node.right, fns, lineOf(node))
			}
		}
	})

	if (mod.template) {
		const visit = (el) => {
			for (const prop of el.props || []) {
				// static attribute: href="/apps/learning/…"
				if (prop.type === 6 && (prop.name === 'href' || prop.name === 'src') && prop.value) {
					if (prop.value.content.includes(APP_PREFIX)) {
						sites.push({ file: rel, line: prop.loc.start.line, kind: 'navigation', method: 'GET', sink: prop.name, arg: prop.value.content, candidates: [prop.value.content] })
					}
				}
				// :href / v-bind:href / :src
				if (prop.type === 7 && prop.name === 'bind' && prop.arg && prop.arg.type === 4 && (prop.arg.content === 'href' || prop.arg.content === 'src') && prop.exp) {
					const src = prop.exp.content
					let expr
					try {
						expr = babelParse('(' + src + ')', { sourceType: 'module' }).program.body[0].expression
					} catch {
						expr = null
					}
					sites.push({
						file: rel,
						line: prop.loc.start.line,
						kind: 'navigation',
						method: 'GET',
						sink: ':' + prop.arg.content,
						arg: src.replace(/\s+/g, ' ').trim(),
						candidates: expr ? evalTemplate(evaluator, mod, expr, src) : UNKNOWN,
					})
				}
			}
			for (const child of el.children || []) {
				if (child.type === 1) {
					visit(child)
				}
			}
		}
		for (const child of mod.template.children || []) {
			if (child.type === 1) {
				visit(child)
			}
		}
	}
	return sites
}

/** A template expression: its code is not in mod.code, so evaluate on a private module view. */
function evalTemplate(evaluator, mod, expr) {
	return evaluator.eval(mod, expr, [], true)
}

/** Every call site below srcDir. */
export function scanSources(srcDir, loader = new Loader(srcDir)) {
	const evaluator = new Evaluator(loader)
	const sites = []
	for (const file of listSources(srcDir)) {
		sites.push(...scanModule(loader.load(file), evaluator, srcDir))
	}
	return sites
}

// ---------------------------------------------------------------------------------------------
// Classification against the spec
// ---------------------------------------------------------------------------------------------

/** An app URL candidate (path below /apps/learning, query and hash removed) or null. */
export function appPath(candidate) {
	// A leading unknown part is a base the scanner cannot see (for example window.location.origin).
	let s = candidate.replace(new RegExp('^' + WILD + '+'), '').replace(/^\/index\.php(?=\/)/, '')
	if (!(s === APP_PREFIX || s.startsWith(APP_PREFIX + '/') || s.startsWith(APP_PREFIX + '?') || s.startsWith(APP_PREFIX + '#'))) {
		return null
	}
	s = s.slice(APP_PREFIX.length).split('?')[0].split('#')[0]
	return s === '' ? '/' : s
}

/** Operations of the spec: [{ method, regex, path, csrf }]. */
export function specOperations(spec) {
	const ops = []
	for (const [path, methods] of Object.entries(spec.paths)) {
		const local = path.replace(/^\/index\.php\/apps\/learning/, '') || '/'
		const regex = new RegExp('^' + local.split(/\{[^}]+\}/).map((s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('[^/]+') + '$')
		for (const [method, op] of Object.entries(methods)) {
			const csrf = (op.parameters || []).some((p) => p.in === 'header' && p.name === 'OCS-APIRequest' && p.required)
			ops.push({ method: method.toUpperCase(), regex, path: local, csrf, operationId: op.operationId })
		}
	}
	return ops
}

/** Spec operations a path pattern (WILD = any segment text) can reach. */
export function matchingOperations(ops, pattern) {
	const tries = [pattern.split(WILD).join('x1'), pattern.replace(new RegExp(WILD + '$'), '').split(WILD).join('x1')]
	const hit = ops.filter((o) => tries.some((t) => o.regex.test(t)))
	return hit
}

/**
 * Does a candidate say anything about the path? Not when it is all unknown, nor when its first
 * literal part is a query or fragment appended to an unknown base (`url + '?requesttoken=' + t`):
 * such a call site is unresolved, not a request to some foreign host.
 */
function hasLiteralPath(candidate) {
	const rest = candidate.replace(new RegExp('^' + WILD + '+'), '')
	return rest.replace(new RegExp(WILD, 'g'), '') !== '' && !/^[?&#]/.test(rest)
}

/**
 * Check call sites against the spec.
 * @return {{ checked: number, unresolved: CallSite[], foreign: number, problems: string[] }}
 */
export function checkSites(sites, spec) {
	const ops = specOperations(spec)
	const problems = []
	const unresolved = []
	let checked = 0
	let foreign = 0
	for (const site of sites) {
		const where = `${site.file}:${site.line} ${site.sink}(${site.arg})`
		const known = site.candidates.filter(hasLiteralPath)
		if (known.length === 0) {
			unresolved.push(site)
			continue
		}
		const appCandidates = known.map(appPath).filter((p) => p !== null)
		if (appCandidates.length === 0) {
			foreign++
			continue
		}
		checked++
		for (const path of new Set(appCandidates)) {
			const pretty = path.split(WILD).join('{…}')
			const reach = matchingOperations(ops, path)
			if (reach.length === 0) {
				problems.push(`${where}: no route ${pretty} in openapi.json (a new route? regenerate the spec: scripts/openapi-spec.sh --write)`)
				continue
			}
			if (site.kind === 'xhr') {
				if (site.method && !reach.some((o) => o.method === site.method)) {
					problems.push(`${where}: ${pretty} has no ${site.method} route (only ${[...new Set(reach.map((o) => o.method))].join(', ')})`)
				}
			} else {
				const get = reach.filter((o) => o.method === 'GET')
				if (get.length === 0) {
					problems.push(`${where}: navigation to ${pretty}, which has no GET route`)
				} else if (get.every((o) => o.csrf)) {
					problems.push(`${where}: navigation to ${pretty} sends no request token, but the route is not #[NoCSRFRequired] (${get.map((o) => o.operationId).join(', ')})`)
				}
			}
		}
	}
	return { checked, unresolved, foreign, problems }
}

/** Stable key of an unresolved call site for the allowlist (no line numbers). */
export function siteKey(site) {
	return `${site.file} ${site.sink}(${site.arg})`
}
