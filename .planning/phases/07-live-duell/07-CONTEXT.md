# Phase 7: Live-Duell - Context

**Gathered:** 2026-03-18
**Status:** Ready for planning
**Source:** User decisions (direct input)

<domain>
## Phase Boundary

Live-Duell-Modus: Zwei eingeloggte Nextcloud-User spielen ein synchronisiertes Wahr/Falsch-Quiz gegeneinander. 10 Fragen aus dem aktiven Pool, Steal-Mechanik, eigener Nav-Eintrag. Kein WebSocket — Short Polling (500ms Intervall).

Abgrenzung: Nur Wahr/Falsch-Fragen (Ja/Nein-Antworten), kein Multi-Choice PBQ. Nur für authenticated NC-Benutzer innerhalb derselben Nextcloud-Instanz.

</domain>

<decisions>
## Implementation Decisions

### Spielmechanik (LOCKED)
- **Scoring Option C (Steal-Mechanik)**:
  - Korrekt + Zuerst geantwortet: +3 Punkte
  - Beide korrekt: Schnellerer +3, Langsamerer +2
  - Falsch geantwortet, Gegner korrekt: Gegner bekommt +1 Steal-Bonus (zusätzlich zu seinen normalen Punkten)
  - Beide falsch: -1 Punkt je Spieler (kein Unterschied wer zuerst)
- **10 Fragen** pro Duell, feste Anzahl
- **Fragequelle**: Aktiver Pool des Erstellers (bei Erstellung gewählt)
- **Rematch**: Neue Zufallsauswahl aus demselben Pool, gleiche Spieler

### Echtzeit-Mechanismus (LOCKED)
- **Short Polling**: Beide Clients pollen alle 500ms den Server-Zustand
- Kein WebSocket, kein Server-Sent Events — einfach halten
- Server ist Single Source of Truth für alle Zustände

### UX-Flow (LOCKED)
- Spieler A erstellt Duell → erhält Duell-Code/Link
- Spieler B tritt per Code bei
- Lobby-Phase: beide müssen "Bereit" klicken
- Fragen werden simultan angezeigt — Antwort-Timestamp bestimmt "wer zuerst"
- Nach 10 Fragen: Ergebnis-Screen mit Punkte + Rematch-Button
- **Eigener Nav-Eintrag**: "⚔️ Duell" in der App-Navigation

### Technische Umsetzung (LOCKED)
- **Backend**: PHP Controller + Service, 2 neue DB-Tabellen (duel_sessions, duel_answers)
- **Frontend**: DuelMode.vue neue Komponente
- **Routing**: App.vue erweitern
- NC-Authentifizierung für beide Spieler vorausgesetzt

### Claude's Discretion
- Timeout-Logik: Wenn ein Spieler die App schließt (z.B. 30s ohne Poll → Duell abbrechen/Gegner gewinnt automatisch)
- Fragen-Subset: Bei Rematch neue Zufallsauswahl (darf Fragen der letzten Runde wiederholen)
- Duell-Code Format: kurzer alphanumerischer Code (6 Zeichen), nicht kollidierend
- Lobby-Timeout: nach z.B. 5 Minuten verfällt ein offenes Duell
- Antwort-Fenster: z.B. 15 Sekunden pro Frage, dann automatisch nächste (oder beide behalten -1 Malus)
- Duell-Historie: optional — ob vergangene Duelle gespeichert werden

</decisions>

<specifics>
## Specific Ideas

### Scoring-Matrix (exakt)

| Spieler A | Spieler B | A bekommt | B bekommt |
|-----------|-----------|-----------|-----------|
| ✓ zuerst  | ✓ später  | +3        | +2        |
| ✓ zuerst  | ✗         | +3 + 1 steal = +4 (nein: steal geht an den RICHTIGEN, also A: +3, B: 0 — B verliert nichts extra) |  |
| Korrektur: | | | |
| ✓ zuerst  | ✗         | +3        | 0 (kein Abzug, nur kein Gewinn) — aber A bekommt kein extra Steal |
| ✗         | ✓         | 0         | +3 + 1 (Steal) = +4 |
| ✗         | ✗         | -1        | -1        |
| ✓ gleichzeitig | ✓ gleichzeitig | +3 | +3 (beide gleich schnell → beide +3) |

**Klarstellung Steal**: Wenn B falsch antwortet und A korrekt: B verliert nichts extra, A gewinnt normal (+3). Steal-Bonus gilt nur wenn der Gegner FALSCH liegt — korrekter Spieler bekommt +1 extra.

Daher konkret:
- A korrekt + B falsch: A = +3+1 = **+4**, B = **0**
- A falsch + B korrekt: A = **0**, B = +3+1 = **+4**
- Beide korrekt (A zuerst): A = **+3**, B = **+2**
- Beide korrekt (gleich schnell): A = **+3**, B = **+3**
- Beide falsch: A = **-1**, B = **-1**

### DB-Schema Vorschlag

```sql
-- Duell-Sitzungen
CREATE TABLE oc_learning_duel_sessions (
  id SERIAL PRIMARY KEY,
  code VARCHAR(6) NOT NULL UNIQUE,
  creator_uid VARCHAR(64) NOT NULL,
  opponent_uid VARCHAR(64),
  pool_id INT NOT NULL,
  question_ids JSONB NOT NULL, -- Array mit 10 Frage-IDs
  status VARCHAR(20) NOT NULL DEFAULT 'waiting', -- waiting|ready|active|finished|expired
  current_question_index INT DEFAULT 0,
  creator_score INT DEFAULT 0,
  opponent_score INT DEFAULT 0,
  creator_ready BOOLEAN DEFAULT FALSE,
  opponent_ready BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT NOW(),
  updated_at TIMESTAMP DEFAULT NOW()
);

-- Antworten je Frage
CREATE TABLE oc_learning_duel_answers (
  id SERIAL PRIMARY KEY,
  duel_id INT NOT NULL REFERENCES oc_learning_duel_sessions(id),
  question_id INT NOT NULL,
  player_uid VARCHAR(64) NOT NULL,
  answer_correct BOOLEAN NOT NULL,
  answered_at TIMESTAMP NOT NULL DEFAULT NOW(),
  points_earned INT NOT NULL DEFAULT 0
);
```

### API-Endpoints

- `POST /api/duels` — Duell erstellen (Creator wählt Pool)
- `POST /api/duels/{code}/join` — Duell beitreten
- `POST /api/duels/{code}/ready` — Bereit-Signal
- `GET /api/duels/{code}/state` — Aktueller Zustand (Polling-Endpoint)
- `POST /api/duels/{code}/answer` — Antwort einreichen (true/false + timestamp)
- `POST /api/duels/{code}/rematch` — Rematch anfordern

</specifics>

<deferred>
## Deferred Ideas

- Spectator-Modus (Zuschauer können zuschauen)
- Duell-Statistiken / Bestenliste (Wer hat gegen wen wie oft gewonnen)
- Multi-Runden-Turnier
- Async-Duell (nicht gleichzeitig online sein müssen)
- Emoji-Reaktionen während des Spiels
- Kursbasiertes Duell (aus einem Kurs statt einzelnem Pool)

</deferred>

---

*Phase: 07-live-duell*
*Context gathered: 2026-03-18 via User decisions (direct input)*
