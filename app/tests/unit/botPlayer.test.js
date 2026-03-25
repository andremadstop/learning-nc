import { describe, it, expect } from 'vitest';
import {
  botChooseAnswer,
  botResponseDelay,
  botPhrase,
  createBotPlayer,
  botChooseCategory,
  botRollDice,
  BOT_PROFILES,
} from '../../src/utils/botPlayer.js';

// ---------------------------------------------------------------------------
// botChooseAnswer
// ---------------------------------------------------------------------------

describe('botChooseAnswer', () => {
  const answers = [
    { id: 1, text: 'Richtig', is_correct: true },
    { id: 2, text: 'Falsch A', is_correct: false },
    { id: 3, text: 'Falsch B', is_correct: false },
  ];

  it('gibt bei rate=1.0 immer die richtige Antwort zurück', () => {
    // Überschreibe correctRate für diesen Test via direkten Wert
    // Wir simulieren easy mit correctRate 0.30 — also mocken wir Math.random
    // Stattdessen: patch correctRate direkt im Profil, dann restore
    const original = BOT_PROFILES.klaus.correctRate.easy;
    BOT_PROFILES.klaus.correctRate.easy = 1.0;

    for (let i = 0; i < 20; i++) {
      const result = botChooseAnswer(answers, 'easy');
      expect(result.is_correct).toBe(true);
    }

    BOT_PROFILES.klaus.correctRate.easy = original;
  });

  it('gibt bei rate=0.0 immer eine falsche Antwort zurück', () => {
    const original = BOT_PROFILES.klaus.correctRate.easy;
    BOT_PROFILES.klaus.correctRate.easy = 0.0;

    for (let i = 0; i < 20; i++) {
      const result = botChooseAnswer(answers, 'easy');
      expect(result.is_correct).toBe(false);
    }

    BOT_PROFILES.klaus.correctRate.easy = original;
  });

  it('gibt null zurück wenn answers leer ist', () => {
    expect(botChooseAnswer([], 'medium')).toBeNull();
  });

  it('gibt null zurück wenn answers null ist', () => {
    expect(botChooseAnswer(null, 'medium')).toBeNull();
  });

  it('akzeptiert alle Schwierigkeitsstufen ohne Fehler', () => {
    for (const diff of ['easy', 'medium', 'hard']) {
      expect(() => botChooseAnswer(answers, diff)).not.toThrow();
    }
  });

  it('fällt auf medium zurück bei unbekannter Schwierigkeit', () => {
    // Unbekannte Difficulty sollte nicht crashen
    expect(() => botChooseAnswer(answers, 'legendary')).not.toThrow();
  });

  it('gibt eine Antwort aus dem answers-Array zurück', () => {
    const result = botChooseAnswer(answers, 'medium');
    expect(answers).toContain(result);
  });

  it('wählt auch korrekt wenn es nur eine einzige Antwort gibt', () => {
    const single = [{ id: 99, text: 'Einzige', is_correct: true }];
    const result = botChooseAnswer(single, 'medium');
    expect(result).not.toBeNull();
    expect(result.id).toBe(99);
  });
});

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
// createBotPlayer
// ---------------------------------------------------------------------------

describe('createBotPlayer', () => {
  it('hat isBot=true', () => {
    const bot = createBotPlayer();
    expect(bot.isBot).toBe(true);
  });

  it('hat is_bot=true (Server-Feld-Kompatibilität)', () => {
    const bot = createBotPlayer();
    expect(bot.is_bot).toBe(true);
  });

  it('hat die richtige Standard-ID', () => {
    const bot = createBotPlayer();
    expect(bot.id).toBe('bot-klaus');
  });

  it('akzeptiert eine benutzerdefinierte ID', () => {
    const bot = createBotPlayer('mein-bot');
    expect(bot.id).toBe('mein-bot');
    expect(bot.user_id).toBe('mein-bot');
  });

  it('hat einen displayName', () => {
    const bot = createBotPlayer();
    expect(bot.displayName).toBeTruthy();
    expect(bot.display_name).toBeTruthy();
  });

  it('hat score=0 zu Beginn', () => {
    const bot = createBotPlayer();
    expect(bot.score).toBe(0);
  });

  it('hat einen Avatar-Emoji', () => {
    const bot = createBotPlayer();
    expect(typeof bot.avatar).toBe('string');
    expect(bot.avatar.length).toBeGreaterThan(0);
  });
});

// ---------------------------------------------------------------------------
// botChooseCategory
// ---------------------------------------------------------------------------

describe('botChooseCategory', () => {
  const cats = [
    { poolId: 1, name: 'Netzwerk' },
    { poolId: 2, name: 'Security' },
    { poolId: 3, name: 'Linux' },
  ];

  it('gibt eine Kategorie aus dem Array zurück', () => {
    const result = botChooseCategory(cats);
    expect(cats).toContain(result);
  });

  it('gibt null zurück bei leerem Array', () => {
    expect(botChooseCategory([])).toBeNull();
  });

  it('gibt null zurück bei null', () => {
    expect(botChooseCategory(null)).toBeNull();
  });
});

// ---------------------------------------------------------------------------
// botRollDice
// ---------------------------------------------------------------------------

describe('botRollDice', () => {
  it('gibt eine Zahl zwischen 1 und 6 zurück', () => {
    for (let i = 0; i < 50; i++) {
      const roll = botRollDice();
      expect(roll).toBeGreaterThanOrEqual(1);
      expect(roll).toBeLessThanOrEqual(6);
      expect(Number.isInteger(roll)).toBe(true);
    }
  });

  it('produziert unterschiedliche Ergebnisse (stochastisch)', () => {
    const rolls = new Set();
    for (let i = 0; i < 60; i++) {
      rolls.add(botRollDice());
    }
    expect(rolls.size).toBeGreaterThanOrEqual(4);
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
