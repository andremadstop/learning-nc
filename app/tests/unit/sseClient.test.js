import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createSseClient } from '../../src/utils/sse-client.js';

class FakeEventSource {
	static instances = [];

	constructor(url, options) {
		this.url = url;
		this.options = options;
		this.listeners = new Map();
		this.closed = false;
		this.readyState = 0;
		this.onerror = null;
		this.onopen = null;
		FakeEventSource.instances.push(this);
	}

	addEventListener(type, callback) {
		if (!this.listeners.has(type)) {
			this.listeners.set(type, []);
		}
		this.listeners.get(type).push(callback);
	}

	close() {
		this.closed = true;
		this.readyState = 2;
	}

	emit(type, event = {}) {
		if (type === 'open' && typeof this.onopen === 'function') {
			this.onopen(event);
		}
		if (type === 'error' && typeof this.onerror === 'function') {
			this.onerror(event);
		}
		for (const callback of this.listeners.get(type) || []) {
			callback(event);
		}
	}
}

describe('createSseClient', () => {
	const originalEventSource = globalThis.window.EventSource;
	const originalOnLine = globalThis.navigator.onLine;

	beforeEach(() => {
		vi.useFakeTimers();
		FakeEventSource.instances = [];
		globalThis.window.EventSource = FakeEventSource;
		Object.defineProperty(globalThis.navigator, 'onLine', {
			value: true,
			configurable: true,
		});
	});

	afterEach(() => {
		vi.useRealTimers();
		globalThis.window.EventSource = originalEventSource;
		Object.defineProperty(globalThis.navigator, 'onLine', {
			value: originalOnLine,
			configurable: true,
		});
	});

	it('forwards parsed state events to onState', () => {
		const onState = vi.fn();
		const client = createSseClient('/apps/learning/api/sse/duel/ABC123', { onState });
		const source = FakeEventSource.instances[0];

		source.emit('state', { data: JSON.stringify({ status: 'waiting', code: 'ABC123' }) });

		expect(onState).toHaveBeenCalledWith({ status: 'waiting', code: 'ABC123' });
		client.close();
		expect(source.closed).toBe(true);
	});

	it('handles server close events without triggering fallback', () => {
		const onClose = vi.fn();
		const onFallback = vi.fn();
		createSseClient('/apps/learning/api/sse/gameshow/XYZ789', { onClose, onFallback });
		const source = FakeEventSource.instances[0];

		source.emit('close', { data: '{}' });

		expect(onClose).toHaveBeenCalledTimes(1);
		expect(onFallback).not.toHaveBeenCalled();
		expect(source.closed).toBe(true);
	});

	it('retries with backoff and falls back after repeated errors', () => {
		const onFallback = vi.fn(() => vi.fn());
		const client = createSseClient('/apps/learning/api/sse/duel/RETRY', { onFallback });

		FakeEventSource.instances[0].emit('error');
		vi.advanceTimersByTime(1000);
		expect(FakeEventSource.instances).toHaveLength(2);

		FakeEventSource.instances[1].emit('error');
		vi.advanceTimersByTime(2000);
		expect(FakeEventSource.instances).toHaveLength(3);

		FakeEventSource.instances[2].emit('error');
		vi.advanceTimersByTime(4000);
		expect(FakeEventSource.instances).toHaveLength(4);

		FakeEventSource.instances[3].emit('error');

		expect(onFallback).toHaveBeenCalledTimes(1);
		client.close();
	});

	it('waits for the browser to come back online before reconnecting', () => {
		Object.defineProperty(globalThis.navigator, 'onLine', {
			value: false,
			configurable: true,
		});

		createSseClient('/apps/learning/api/sse/duel/OFFLINE', {});
		FakeEventSource.instances[0].emit('error');

		vi.advanceTimersByTime(10000);
		expect(FakeEventSource.instances).toHaveLength(1);

		Object.defineProperty(globalThis.navigator, 'onLine', {
			value: true,
			configurable: true,
		});
		globalThis.window.dispatchEvent(new Event('online'));

		expect(FakeEventSource.instances).toHaveLength(2);
	});
});
