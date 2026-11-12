/**
 * The operator of this installation (imprint provider, privacy controller) as an admin entered it.
 * Mirrors OperatorInfo::publicView() on the server: name, address and email are required; anything
 * else means "no operator" and the pages show a neutral hint instead (audit 2026-11).
 */
export const OPERATOR_FIELDS = ['name', 'address', 'register', 'email', 'content_responsible']
const REQUIRED = ['name', 'address', 'email']

/**
 * @param {unknown} raw operator object from the server (data attribute or API)
 * @return {{name: string, address: string, register: string, email: string, content_responsible: string}|null}
 */
export function parseOperator(raw) {
	if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
		return null
	}
	const operator = {}
	for (const field of OPERATOR_FIELDS) {
		operator[field] = typeof raw[field] === 'string' ? raw[field].trim() : ''
	}
	return REQUIRED.every(field => operator[field] !== '') ? operator : null
}

/**
 * @param {string|undefined} json the data-operator attribute
 * @return {ReturnType<typeof parseOperator>}
 */
export function parseOperatorAttribute(json) {
	try {
		return parseOperator(JSON.parse(json || 'null'))
	} catch (e) {
		return null
	}
}
