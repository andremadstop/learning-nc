import { describe, it, expect } from 'vitest';
import { computeTimerState, formatTime } from '../../src/utils/timerEngine.js';

describe('computeTimerState', () => {
	const totalSeconds = 300;

	it('returns safe phase when >50% remaining', () => {
		const deadline = 1000;
		const now = 800; // 200 remaining out of 300 = 66%
		const result = computeTimerState(deadline, totalSeconds, now);
		expect(result.phase).toBe('safe');
		expect(result.color).toBe('var(--lnc-green)');
		expect(result.expired).toBe(false);
		expect(result.lastTenSeconds).toBe(false);
	});

	it('returns warning phase when 25-50% remaining', () => {
		const deadline = 1000;
		const now = 880; // 120 remaining out of 300 = 40%
		const result = computeTimerState(deadline, totalSeconds, now);
		expect(result.phase).toBe('warning');
		expect(result.color).toBe('var(--lnc-amber)');
	});

	it('returns danger phase when <25% remaining', () => {
		const deadline = 1000;
		const now = 950; // 50 remaining out of 300 = 16%
		const result = computeTimerState(deadline, totalSeconds, now);
		expect(result.phase).toBe('danger');
		expect(result.color).toBe('var(--lnc-danger)');
	});

	it('returns expired when remaining is 0', () => {
		const deadline = 1000;
		const now = 1000;
		const result = computeTimerState(deadline, totalSeconds, now);
		expect(result.expired).toBe(true);
		expect(result.remaining).toBe(0);
	});

	it('returns expired when now exceeds deadline', () => {
		const deadline = 1000;
		const now = 1050;
		const result = computeTimerState(deadline, totalSeconds, now);
		expect(result.expired).toBe(true);
		expect(result.remaining).toBe(0);
	});

	it('returns lastTenSeconds when remaining <= 10', () => {
		const deadline = 1000;
		const now = 992; // 8 remaining
		const result = computeTimerState(deadline, totalSeconds, now);
		expect(result.lastTenSeconds).toBe(true);
		expect(result.phase).toBe('danger');
	});

	it('includes formatted time', () => {
		const deadline = 1000;
		const now = 800; // 200 remaining
		const result = computeTimerState(deadline, totalSeconds, now);
		expect(result.formattedTime).toBe('3:20');
		expect(result.remaining).toBe(200);
	});

	it('returns fraction correctly', () => {
		const deadline = 1000;
		const now = 850; // 150 remaining out of 300 = 0.5
		const result = computeTimerState(deadline, totalSeconds, now);
		expect(result.fraction).toBe(0.5);
	});
});

describe('formatTime', () => {
	it('formats minutes and seconds with leading zero', () => {
		expect(formatTime(125)).toBe('2:05');
	});

	it('formats single-digit seconds with leading zero', () => {
		expect(formatTime(9)).toBe('0:09');
	});

	it('formats exact minute', () => {
		expect(formatTime(60)).toBe('1:00');
	});

	it('formats zero', () => {
		expect(formatTime(0)).toBe('0:00');
	});

	it('handles negative (clamps to 0)', () => {
		expect(formatTime(-5)).toBe('0:00');
	});
});
