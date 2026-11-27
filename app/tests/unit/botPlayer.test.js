import { describe, it, expect, vi, afterEach } from 'vitest';
import {
  botAnswersCorrectly,
  botResponseDelay,
  botPhrase,
  BOT_PROFILES,
} from '../../src/utils/botPlayer.js';

// ---------------------------------------------------------------------------
// botResponseDelay
// ---------------------------------------------------------------------------

describe('botResponseDelay', () => {
  it('liegt im erwarteten Bereich für easy', () => {
    for (let i = 0; i < 30; i++) {
      const delay = botResponseDelay('easy');
      expect(delay).toBeGreaterThanOrEqual(4000);
      expect(delay).toBeLessThanOrEqual(8000);
    }
  });

  it('liegt im erwarteten Bereich für medium', () => {
    for (let i = 0; i < 30; i++) {
      const delay = botResponseDelay('medium');
      expect(delay).toBeGreaterThanOrEqual(2000);
      expect(delay).toBeLessThanOrEqual(5000);
    }
  });

  it('liegt im erwarteten Bereich für hard', () => {
    for (let i = 0; i < 30; i++) {
      const delay = botResponseDelay('hard');
      expect(delay).toBeGreaterThanOrEqual(1000);
      expect(delay).toBeLessThanOrEqual(3000);
    }
  });

  it('gibt einen numerischen Wert zurück', () => {
    expect(typeof botResponseDelay('medium')).toBe('number');
  });

  it('fällt auf medium zurück bei unbekannter Schwierigkeit', () => {
    expect(() => botResponseDelay('godmode')).not.toThrow();
    const delay = botResponseDelay('godmode');
    expect(typeof delay).toBe('number');
    expect(delay).toBeGreaterThan(0);
  });
});

// ---------------------------------------------------------------------------
// botPhrase
// ---------------------------------------------------------------------------

describe('botPhrase', () => {
  const eventTypes = ['correct', 'wrong', 'taunt', 'join', 'lose', 'win', 'thinking'];

  it('gibt einen nicht-leeren String für jeden Event-Typ zurück', () => {
    for (const event of eventTypes) {
      const phrase = botPhrase(event);
      expect(typeof phrase).toBe('string');
      expect(phrase.length).toBeGreaterThan(0);
    }
  });

  it('gibt leeren String für unbekannten Event zurück', () => {
    expect(botPhrase('nonexistent_event')).toBe('');
  });

  it('gibt String zurück, kein Array', () => {
    const phrase = botPhrase('correct');
    expect(typeof phrase).toBe('string');
  });

  it('variiert die Antworten (stochastisch — mindestens 2 verschiedene in 50 Versuchen)', () => {
    const seen = new Set();
    for (let i = 0; i < 50; i++) {
      seen.add(botPhrase('correct'));
    }
    // Bei 5 Phrasen und 50 Versuchen sollten mind. 2 verschiedene vorkommen
    expect(seen.size).toBeGreaterThanOrEqual(2);
  });
});

// ---------------------------------------------------------------------------
// BOT_PROFILES Struktur
// ---------------------------------------------------------------------------

describe('BOT_PROFILES', () => {
  it('enthält das Klaus-Profil', () => {
    expect(BOT_PROFILES.klaus).toBeDefined();
  });

  it('hat alle Pflichtfelder', () => {
    const { name, title, avatar, correctRate, responseTime, phrases } = BOT_PROFILES.klaus;
    expect(name).toBeTruthy();
    expect(title).toBeTruthy();
    expect(avatar).toBeTruthy();
    expect(correctRate).toBeDefined();
    expect(responseTime).toBeDefined();
    expect(phrases).toBeDefined();
  });

  it('hat correctRate für alle Schwierigkeiten', () => {
    const { correctRate } = BOT_PROFILES.klaus;
    expect(correctRate.easy).toBeGreaterThanOrEqual(0);
    expect(correctRate.medium).toBeGreaterThanOrEqual(0);
    expect(correctRate.hard).toBeGreaterThanOrEqual(0);
  });

  it('hat correctRate-Werte zwischen 0 und 1', () => {
    const { correctRate } = BOT_PROFILES.klaus;
    for (const val of Object.values(correctRate)) {
      expect(val).toBeGreaterThanOrEqual(0);
      expect(val).toBeLessThanOrEqual(1);
    }
  });
});

// ---------------------------------------------------------------------------
// botAnswersCorrectly — Klaus without the answer key (2026-11 audit)
// ---------------------------------------------------------------------------
describe('botAnswersCorrectly', () => {
  afterEach(() => vi.restoreAllMocks());

  it('is right below the difficulty rate and wrong at or above it', () => {
    const rate = BOT_PROFILES.klaus.correctRate.medium;
    vi.spyOn(Math, 'random').mockReturnValue(rate - 0.01);
    expect(botAnswersCorrectly('medium')).toBe(true);
    Math.random.mockReturnValue(rate);
    expect(botAnswersCorrectly('medium')).toBe(false);
  });

  it('falls back to the medium rate for an unknown difficulty', () => {
    vi.spyOn(Math, 'random').mockReturnValue(0.54);
    expect(botAnswersCorrectly('legendary')).toBe(true);
    Math.random.mockReturnValue(0.56);
    expect(botAnswersCorrectly('legendary')).toBe(false);
  });
});
