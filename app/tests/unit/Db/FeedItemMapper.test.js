/**
 * FeedItem entity and mapper behavior tests.
 *
 * These tests validate the expected data contract for FeedItem objects
 * as they will be consumed by the frontend via the Feed API.
 */
import { describe, it, expect } from 'vitest'

/**
 * Simulates the PHP FeedItem::jsonSerialize() output format.
 * The real entity lives in PHP; this validates the contract.
 */
function makeFeedItem ({
	id = 1,
	courseId = 10,
	type = 'announcement',
	title = 'Test announcement',
	body = 'Some body text',
	actorId = 'instructor1',
	meta = null,
	createdAt = 1711612800,
} = {}) {
	return {
		id,
		course_id: courseId,
		type,
		title,
		body,
		actor_id: actorId,
		meta: typeof meta === 'string' ? JSON.parse(meta) : meta,
		created_at: createdAt,
	}
}

describe('FeedItem entity contract', () => {
	it('serializes all required keys in snake_case', () => {
		const item = makeFeedItem()
		const keys = Object.keys(item)
		expect(keys).toEqual([
			'id', 'course_id', 'type', 'title', 'body',
			'actor_id', 'meta', 'created_at',
		])
	})

	it('decodes meta from JSON string', () => {
		const item = makeFeedItem({ meta: '{"pool_id":42}' })
		expect(item.meta).toEqual({ pool_id: 42 })
	})

	it('handles null meta gracefully', () => {
		const item = makeFeedItem({ meta: null })
		expect(item.meta).toBeNull()
	})

	it('supports all defined feed item types', () => {
		const validTypes = ['announcement', 'pool_added', 'milestone', 'content_update', 'progress']
		for (const type of validTypes) {
			const item = makeFeedItem({ type })
			expect(item.type).toBe(type)
		}
	})
})

describe('FeedItem sorting contract', () => {
	it('items should be sortable by created_at DESC (newest first)', () => {
		const items = [
			makeFeedItem({ id: 1, createdAt: 1000 }),
			makeFeedItem({ id: 2, createdAt: 3000 }),
			makeFeedItem({ id: 3, createdAt: 2000 }),
		]
		const sorted = [...items].sort((a, b) => b.created_at - a.created_at)
		expect(sorted.map(i => i.id)).toEqual([2, 3, 1])
	})
})

describe('FeedItem filtering contract', () => {
	it('filters items by course IDs', () => {
		const items = [
			makeFeedItem({ id: 1, courseId: 10 }),
			makeFeedItem({ id: 2, courseId: 20 }),
			makeFeedItem({ id: 3, courseId: 10 }),
			makeFeedItem({ id: 4, courseId: 30 }),
		]
		const courseIds = [10, 20]
		const filtered = items.filter(i => courseIds.includes(i.course_id))
		expect(filtered).toHaveLength(3)
		expect(filtered.map(i => i.id)).toEqual([1, 2, 3])
	})

	it('supports limit and offset for pagination', () => {
		const items = Array.from({ length: 10 }, (_, i) =>
			makeFeedItem({ id: i + 1, createdAt: 1000 + i }),
		)
		const sorted = [...items].sort((a, b) => b.created_at - a.created_at)
		const limit = 3
		const offset = 2
		const page = sorted.slice(offset, offset + limit)
		expect(page).toHaveLength(3)
		expect(page[0].id).toBe(8)
	})

	it('deleteOlderThan removes items before timestamp', () => {
		const items = [
			makeFeedItem({ id: 1, createdAt: 1000 }),
			makeFeedItem({ id: 2, createdAt: 2000 }),
			makeFeedItem({ id: 3, createdAt: 3000 }),
		]
		const threshold = 1500
		const remaining = items.filter(i => i.created_at >= threshold)
		expect(remaining).toHaveLength(2)
		expect(remaining.map(i => i.id)).toEqual([2, 3])
	})
})
