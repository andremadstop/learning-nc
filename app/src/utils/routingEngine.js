function ipToInt(ip) {
	return String(ip || '')
		.trim()
		.split('.')
		.map((part) => Number(part))
		.reduce((total, part) => ((total << 8) >>> 0) + part, 0) >>> 0
}

export function maskToPrefix(mask) {
	const bits = String(mask || '')
		.trim()
		.split('.')
		.map((part) => Number(part).toString(2).padStart(8, '0'))
		.join('')
	return bits.split('').filter((bit) => bit === '1').length
}

export function normalizeRoute(route) {
	const destination = String(route.destination || '0.0.0.0').trim()
	const mask = String(route.mask || '0.0.0.0').trim()
	return {
		destination,
		mask,
		gateway: String(route.gateway || 'direct').trim(),
		interface: String(route.interface || 'Gi0/0').trim(),
		metric: Number(route.metric || 1),
		type: String(route.type || 'static').trim().toLowerCase(),
		prefix: maskToPrefix(mask),
	}
}

export function createDefaultRoute(overrides = {}) {
	return normalizeRoute({
		destination: '0.0.0.0',
		mask: '0.0.0.0',
		gateway: '192.168.1.1',
		interface: 'Gi0/0',
		type: 'default',
		metric: 100,
		...overrides,
	})
}

export function routeMatches(route, destinationIp) {
	const normalizedRoute = normalizeRoute(route)
	const prefix = normalizedRoute.prefix
	if (prefix === 0) {
		return true
	}
	const mask = prefix === 0 ? 0 : ((0xffffffff << (32 - prefix)) >>> 0)
	return (ipToInt(destinationIp) & mask) === (ipToInt(normalizedRoute.destination) & mask)
}

export function findMatchingRoutes(routes, destinationIp) {
	return (routes || [])
		.map(normalizeRoute)
		.filter((route) => routeMatches(route, destinationIp))
		.sort((left, right) => {
			if (right.prefix !== left.prefix) {
				return right.prefix - left.prefix
			}
			if (left.metric !== right.metric) {
				return left.metric - right.metric
			}
			return left.destination.localeCompare(right.destination)
		})
}

export function selectBestRoute(routes, destinationIp) {
	const matches = findMatchingRoutes(routes, destinationIp)
	return {
		matches,
		bestRoute: matches[0] || null,
	}
}

export function buildRoutingDecision(routes, destinationIp) {
	const normalizedRoutes = (routes || []).map(normalizeRoute)
	const matches = findMatchingRoutes(normalizedRoutes, destinationIp)
	const bestRoute = matches[0] || null
	return {
		destinationIp,
		evaluatedRoutes: normalizedRoutes.map((route) => ({
			...route,
			matched: routeMatches(route, destinationIp),
		})),
		matches,
		bestRoute,
		status: bestRoute ? 'forward' : 'unreachable',
	}
}

export function detectRoutingLoop(routes) {
	const normalizedRoutes = (routes || []).map(normalizeRoute)
	return normalizedRoutes.some((route) => {
		const nextHopRoute = normalizedRoutes.find((candidate) => candidate !== route && routeMatches(candidate, route.gateway))
		if (!nextHopRoute) {
			return false
		}
		return routeMatches(route, nextHopRoute.gateway)
	})
}
