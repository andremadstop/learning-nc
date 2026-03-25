import { describe, expect, it } from 'vitest'
import {
	buildRoutingDecision,
	createDefaultRoute,
	detectRoutingLoop,
	findMatchingRoutes,
	maskToPrefix,
	normalizeRoute,
	routeMatches,
	selectBestRoute,
} from '../../src/utils/routingEngine.js'

describe('maskToPrefix', () => {
	it.each([
		['255.255.255.0', 24],
		['255.255.0.0', 16],
		['0.0.0.0', 0],
		['255.255.255.252', 30],
	])('converts %s to /%i', (mask, prefix) => {
		expect(maskToPrefix(mask)).toBe(prefix)
	})
})

describe('normalizeRoute', () => {
	it('adds a prefix value and lowercases the type', () => {
		const route = normalizeRoute({
			destination: '10.0.2.0',
			mask: '255.255.255.0',
			gateway: '10.0.1.1',
			interface: 'Gi0/1',
			type: 'Static',
		})
		expect(route.prefix).toBe(24)
		expect(route.type).toBe('static')
	})
})

describe('routeMatches', () => {
	it.each([
		[{ destination: '10.0.0.0', mask: '255.255.0.0' }, '10.0.5.20', true],
		[{ destination: '10.0.1.0', mask: '255.255.255.0' }, '10.0.5.20', false],
		[{ destination: '0.0.0.0', mask: '0.0.0.0' }, '203.0.113.1', true],
	])('evaluates destination matches', (route, ip, expected) => {
		expect(routeMatches(route, ip)).toBe(expected)
	})
})

describe('findMatchingRoutes', () => {
	it('sorts matches by longest prefix and then metric', () => {
		const routes = [
			{ destination: '192.168.0.0', mask: '255.255.0.0', gateway: '10.0.0.1', metric: 5 },
			{ destination: '192.168.1.0', mask: '255.255.255.0', gateway: '10.0.1.1', metric: 20 },
			createDefaultRoute(),
		]
		const matches = findMatchingRoutes(routes, '192.168.1.25')
		expect(matches[0].gateway).toBe('10.0.1.1')
		expect(matches[1].gateway).toBe('10.0.0.1')
		expect(matches[2].type).toBe('default')
	})
})

describe('selectBestRoute', () => {
	it('returns the best route when one exists', () => {
		const result = selectBestRoute([
			{ destination: '203.0.113.0', mask: '255.255.255.0', gateway: '172.16.0.1', metric: 5 },
			createDefaultRoute({ gateway: '192.168.1.1' }),
		], '203.0.113.10')
		expect(result.bestRoute.gateway).toBe('172.16.0.1')
	})

	it('returns null when no route matches', () => {
		const result = selectBestRoute([
			{ destination: '10.0.0.0', mask: '255.255.255.0', gateway: 'direct', metric: 0 },
		], '172.16.0.10')
		expect(result.bestRoute).toBeNull()
	})
})

describe('buildRoutingDecision', () => {
	it('marks matching and non-matching routes', () => {
		const result = buildRoutingDecision([
			{ destination: '10.0.0.0', mask: '255.255.255.0', gateway: 'direct', metric: 0, type: 'connected' },
			createDefaultRoute({ gateway: '192.168.1.1' }),
		], '10.0.0.44')
		expect(result.status).toBe('forward')
		expect(result.evaluatedRoutes.filter((route) => route.matched)).toHaveLength(2)
	})

	it('returns unreachable without a default route', () => {
		const result = buildRoutingDecision([
			{ destination: '10.0.0.0', mask: '255.255.255.0', gateway: 'direct', metric: 0, type: 'connected' },
		], '172.20.0.4')
		expect(result.status).toBe('unreachable')
	})
})

describe('detectRoutingLoop', () => {
	it('detects a simple two-route loop', () => {
		expect(detectRoutingLoop([
			{ destination: '10.0.20.0', mask: '255.255.255.0', gateway: '10.0.30.1', metric: 10 },
			{ destination: '10.0.30.0', mask: '255.255.255.0', gateway: '10.0.20.1', metric: 10 },
		])).toBe(true)
	})

	it('returns false for ordinary routes', () => {
		expect(detectRoutingLoop([
			{ destination: '10.0.20.0', mask: '255.255.255.0', gateway: '10.0.1.1', metric: 10 },
			createDefaultRoute({ gateway: '192.168.1.1' }),
		])).toBe(false)
	})
})
