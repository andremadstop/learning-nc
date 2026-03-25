/**
 * botPlayer.js — Bot-Gegner "Klaus der Praktikant"
 *
 * Deterministisch, kein Gemini — reine Logik.
 * Wird in allen Multiplayer-Modi als lokaler Gegner eingesetzt.
 */

export const BOT_PROFILES = {
  klaus: {
    name: 'Klaus',
    title: 'Der Praktikant',
    avatar: '🤓',
    difficulty: 'medium',
    // Trefferquote pro Schwierigkeit (0.0–1.0)
    correctRate: { easy: 0.30, medium: 0.55, hard: 0.75 },
    // Antwortzeit in ms [min, max]
    responseTime: { easy: [4000, 8000], medium: [2000, 5000], hard: [1000, 3000] },
    phrases: {
      correct: [
        'Ha! Das wusste sogar ich!',
        'Mein YouTube-Tutorial hat sich gelohnt!',
        'Moment... war das richtig? JA!',
        'Selbst ein Praktikant kann das!',
        'Ich hab das letzte Woche zufällig gegoogelt.',
      ],
      wrong: [
        'Äh... das stand nicht im Handbuch...',
        'Das hab ich anders in Erinnerung.',
        'Warte, ich google das kurz... oh.',
        'Der Senior hat mir was anderes gesagt!',
        'Mist, wieder daneben.',
        'Ich war kurz abgelenkt, ehrlich!',
      ],
      taunt: [
        'Bist du sicher? Ich hätte was anderes genommen...',
        'Hm, interessante Wahl.',
        'Okay, wenn du meinst...',
        'Na mal sehen, ob das stimmt.',
      ],
      join: [
        'Klaus meldet sich zum Dienst! 🫡',
        'Praktikant Klaus ist bereit!',
        'Ich hab heute eigentlich frei, aber okay...',
        'Für die Erfahrung mache ich das gratis.',
      ],
      lose: [
        'Nächstes Mal! Ich übe noch.',
        'Das lag an der Tastatur.',
        'Unfair — du hattest mehr Kaffee!',
        'Ich beantrage eine Neuauszählung.',
      ],
      win: [
        'HA! Der Praktikant gewinnt!',
        'Sagt das bloß nicht dem Chef...',
        'Anfängerglück? Ich nenne es Talent!',
        'Wer braucht schon Berufserfahrung?',
      ],
      thinking: [
        '🤔 Klaus überlegt...',
        '🤓 Klaus durchforstet das Handbuch...',
        '⏳ Klaus googelt still...',
      ],
    },
  },
};

/**
 * Wählt eine zufällige Antwort basierend auf Schwierigkeit.
 *
 * @param {Array} answers  Array von Antwortobjekten mit { id, is_correct }
 * @param {string} difficulty  'easy' | 'medium' | 'hard'
 * @returns {object|null} Gewählte Antwort oder null wenn answers leer
 */
export function botChooseAnswer(answers, difficulty = 'medium') {
  if (!answers || answers.length === 0) return null;

  const profile = BOT_PROFILES.klaus;
  const rate = profile.correctRate[difficulty] ?? 0.55;
  const isCorrect = Math.random() < rate;

  if (isCorrect) {
    const correct = answers.find(a => a.is_correct);
    return correct || answers[0];
  } else {
    const wrong = answers.filter(a => !a.is_correct);
    if (wrong.length === 0) return answers[0];
    return wrong[Math.floor(Math.random() * wrong.length)];
  }
}

/**
 * Berechnet realistische Wartezeit vor der Antwort.
 *
 * @param {string} difficulty  'easy' | 'medium' | 'hard'
 * @returns {number} Delay in Millisekunden
 */
export function botResponseDelay(difficulty = 'medium') {
  const profile = BOT_PROFILES.klaus;
  const [min, max] = profile.responseTime[difficulty] || [2000, 5000];
  return min + Math.random() * (max - min);
}

/**
 * Zufälliger Spruch für ein Ereignis.
 *
 * @param {string} event  Schlüssel aus BOT_PROFILES.klaus.phrases
 * @returns {string}
 */
export function botPhrase(event) {
  const phrases = BOT_PROFILES.klaus.phrases[event] || [];
  if (phrases.length === 0) return '';
  return phrases[Math.floor(Math.random() * phrases.length)];
}

/**
 * Erstellt ein Bot-Spieler-Objekt, das wie ein echter Spieler aussieht.
 *
 * @param {string} id  Spieler-ID (default: 'bot-klaus')
 * @returns {object}
 */
export function createBotPlayer(id = 'bot-klaus') {
  return {
    id,
    user_id: id,
    name: 'Klaus',
    displayName: 'Klaus (Praktikant)',
    display_name: 'Klaus (Praktikant)',
    isBot: true,
    is_bot: true,
    avatar: '🤓',
    score: 0,
    streak: 0,
    lives: 3,
    slot: 1,          // Bot bekommt Slot 1 (User hat Slot 0)
    is_ready: false,
  };
}

/**
 * Wählt eine zufällige Kategorie aus einer Liste.
 *
 * @param {Array} categories  Array von Kategorie-Objekten { poolId, name }
 * @returns {object|null}
 */
export function botChooseCategory(categories) {
  if (!categories || categories.length === 0) return null;
  return categories[Math.floor(Math.random() * categories.length)];
}

/**
 * Würfelt für den Bot (gibt eine Zahl 1–6 zurück).
 *
 * @returns {number}
 */
export function botRollDice() {
  return Math.floor(Math.random() * 6) + 1;
}
