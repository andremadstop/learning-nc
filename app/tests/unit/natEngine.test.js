import { describe, expect, it } from 'vitest'
import {
	allocateDynamicMapping,
	buildNatVisualization,
	createNatConfig,
	findHairpinTranslation,
	simulateNat,
	translateDynamicNat,
	translatePat,
	translateStaticNat,
} from '../../src/utils/natEngine.js'

const PACKET = {
	sourceIp: '10.0.0.10',
	sourcePort: 51515,
	destinationIp: '198.51.100.20',
	destinationPort: 443,
	protocol: 'tcp',
}

describe('createNatConfig', () => {
	it('creates defaults for PAT', () => {
		expect(createNatConfig('pat').publicIp).toBe('203.0.113.10')
	})
})

describe('translateStaticNat', () => {
	it('maps inside local to inside global', () => {
		const result = translateStaticNat(PACKET, [{ insideLocal: '10.0.0.10', insideGlobal: '203.0.113.10' }])
		expect(result.status).toBe('translated')
		expect(result.packet.sourceIp).toBe('203.0.113.10')
	})

	it('reports missing mappings', () => {
		expect(translateStaticNat(PACKET, []).status).toBe('no-mapping')
	})
})

describe('allocateDynamicMapping', () => {
	it('reuses existing mappings', () => {
		const mapping = allocateDynamicMapping('10.0.0.10', ['203.0.113.10'], [{ insideLocal: '10.0.0.10', insideGlobal: '203.0.113.10' }])
		expect(mapping.insideGlobal).toBe('203.0.113.10')
	})

	it('allocates the first free public IP', () => {
		const mapping = allocateDynamicMapping('10.0.0.11', ['203.0.113.10', '203.0.113.11'], [{ insideLocal: '10.0.0.10', insideGlobal: '203.0.113.10' }])
		expect(mapping.insideGlobal).toBe('203.0.113.11')
	})

	it('returns null when the pool is exhausted', () => {
		expect(allocateDynamicMapping('10.0.0.11', ['203.0.113.10'], [{ insideLocal: '10.0.0.10', insideGlobal: '203.0.113.10' }])).toBeNull()
	})
})

describe('translateDynamicNat', () => {
	it('translates a packet and extends the table', () => {
		const result = translateDynamicNat(PACKET, ['203.0.113.10'], [])
		expect(result.status).toBe('translated')
		expect(result.table).toHaveLength(1)
	})

	it('reports pool exhaustion when no public IP is free', () => {
		const result = translateDynamicNat(PACKET, ['203.0.113.10'], [{ insideLocal: '10.0.0.20', insideGlobal: '203.0.113.10' }])
		expect(result.status).toBe('pool-exhausted')
	})
})

describe('translatePat', () => {
	it('allocates a translated source port', () => {
		const result = translatePat(PACKET, '203.0.113.10', [])
		expect(result.packet.sourceIp).toBe('203.0.113.10')
		expect(result.packet.sourcePort).toBe(40000)
	})

	it('reuses an existing PAT mapping', () => {
		const result = translatePat(PACKET, '203.0.113.10', [{ insideLocal: '10.0.0.10', insidePort: 51515, insideGlobal: '203.0.113.10', insideGlobalPort: 40010 }])
		expect(result.packet.sourcePort).toBe(40010)
	})
})

describe('hairpin NAT', () => {
	it('rewrites the destination to the internal host', () => {
		const translated = findHairpinTranslation({
			sourceIp: '10.0.0.40',
			sourcePort: 52000,
			destinationIp: '203.0.113.30',
			destinationPort: 443,
			protocol: 'tcp',
		}, [{ insideLocal: '10.0.0.30', insideGlobal: '203.0.113.30' }])
		expect(translated.destinationIp).toBe('10.0.0.30')
	})
})

describe('simulateNat and visualization', () => {
	it('routes through the correct NAT mode', () => {
		const result = simulateNat(PACKET, {
			type: 'pat',
			publicIp: '203.0.113.10',
			table: [],
		})
		expect(result.status).toBe('translated')
	})

	it('builds a three-hop visualization string set', () => {
		const translation = translatePat(PACKET, '203.0.113.10', [])
		const visualization = buildNatVisualization(PACKET, translation)
		expect(visualization.device).toContain('translation')
		expect(visualization.outside).toContain('203.0.113.10')
	})
})
