import { describe, expect, it } from 'vitest'
import {
	buildRoutingDecision,
	createDefaultRoute,
	findMatchingRoutes,
	maskToPrefix,
	normalizeRoute,
	routeMatches,
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
