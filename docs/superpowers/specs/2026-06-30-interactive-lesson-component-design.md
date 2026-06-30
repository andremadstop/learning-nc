# Spec — Interaktive Lern-Häppchen („Lesson") · teach-first für Ghostline-Kampagnen

> Status: DESIGN (Brainstorm abgeschlossen, vom User freigegeben 2026-06-30)
> Treiber: Andrés Durchspielen von `ghostline_act1` (K3) ergab: man wird ins **Abfragen** geworfen
> (Terminal/Quiz), ohne dass vorher etwas **vermittelt** wird → „das geht zu schnell, ich kapier
> nicht, was ich machen soll". Lösung: erst Wissen in kleinen Happen vermitteln, **dann** abfragen.
> Verwandt: `2026-06-30-ghostline-interactive-course-design.md` (Akt 1). NotebookLM-Materialien
> (LPIC-1 Lernvault `3bf916d0-7984-4c58-a542-a3277698263c`) decken den **akuten** Prüfungs-Lernbedarf
> (03.07.) separat ab; **diese Spec ist die nachhaltige Engine-Erweiterung — Bau nach der Prüfung.**

---

## 1. Problem

Die Kampagne ist als **Retrieval-Übung** gebaut (Terminal erwartet `grep ^T …`, Quiz fragt BRE vs ERE).
Das setzt voraus, dass der Spieler grep/sed/Regex **schon kann**. Ein Lerner, der den Stoff zum ersten
Mal sieht, hat keine Lehr-Schicht davor → Frust statt Lernen. Es fehlt das „Ich → Wir" vor dem „Du".

## 2. Vision & Pädagogik

**Gradual Release of Responsibility („Ich → Wir → Du")** + **Segmenting** (kleine Happen) +
**interleaved Retrieval** (Zwischen-Check mitten im Lernen) + **Immediate Feedback**. Diese Kombination
ist lernwissenschaftlich der stärkste Hebel für genau diese Situation (Anfänger, kurzer Horizont) und
deckt sich mit `.planning/research/SUMMARY.md` (Retrieval Practice + Immediate Feedback; Worked Example
→ Faded Practice). Narrative-Transportation bleibt erhalten: VirtuProf/„der Geist" als durchgehender
Erzähl-Ton, kein Bruch in den „Lehr-Modus".

## 3. Flow (pro Befehl, Beispiel grep)

```
LESSON  (neue Komponente, selbst-getaktet, kleine Slides)        ← "Ich" + "Wir"
  1. Story-Hook:     "Das Journal ist zerstückelt…"            [Weiter]
  2. Konzept:        "grep = Zeilen nach einem Muster suchen"  [Weiter]
  3. Befehl zerlegt (anklickbar):   grep  ^T  journal.txt
        grep → "durchsucht Zeilen"   ^ → "Anker: Zeilenanfang"
        ^T   → "Zeile beginnt mit großem T"                    [Weiter]
  4. Worked Example (Klick-zum-Aufdecken):
        "Was findet  grep ^T journal.txt ?"  [Lösung] → Ausgabe [Weiter]
  5. Zwischen-Check (1 Frage, Sofort-Feedback, NICHT bewertet):
        "Welches matcht Zeilen die mit 'log' ENDEN?"
        ○ grep ^log   ● grep log$  → ✓ "$ = Zeilenende"        [Weiter]
        ↓  "Jetzt du:"
TERMINAL (bestehend) → selbst tippen                            ← "Du"
        ↓
QUIZ (bestehend) → Transfer-Check
```

## 4. Architektur

Saubere, abgegrenzte neue Einheit; fügt sich in die vorhandene Story-Engine ein wie die Simulatoren.

| Bedarf | Lösung | Pfad |
|--------|--------|------|
| Lehr-Sequenz im Graph | **Neuer Node-Typ `lesson`** mit `slides[]` | `data/campaigns/*.json`, `campaign-schema.json` |
| Rendering + Interaktion | **`LessonPlayer.vue`** (emit `complete`) | `app/src/components/LessonPlayer.vue` |
| Einbindung in Spielfluss | `AbenteuerMode` rendert LessonPlayer bei `node.type==='lesson'`, bei `complete` → `graph-traverse` (gleiches Muster wie `onSimulatorComplete`) | `app/src/components/AbenteuerMode.vue` |
| Engine akzeptiert Node-Typ | `lesson` zu `graph.node_type_enum` in `campaign-schema.json` + StoryEngine-Validierung | `app/lib/Service/StoryEngineService.php` |

- **Inhalt app-nativ** in der Kampagnen-JSON — **kein** NotebookLM-Embed zur Laufzeit. Begründung:
  selbst-enthalten/offline, unit-testbar, versioniert mit der Kampagne, volle A11y-Kontrolle, und die
  Zwischen-Checks sind nur app-nativ möglich. NotebookLM-Filme/Audios bleiben als **optionaler**
  „Trainingsband des Geists"-Material-Link (Vertiefung) — kein Laufzeit-Abhang.
- **Wiederverwendung:** der `check`-Slide nutzt dieselbe Shape wie das bestehende inline-`quiz`
  (`question/options[{id,text}]/correct/explanation`).

## 5. Schema-Erweiterung

Neuer Node-Typ (alle bestehenden node_required-Felder `id,title,narrative,act` gelten weiter):

```json
{
  "id": "a1_k3_lesson_grep",
  "type": "lesson",
  "title": "Lektion: grep",
  "narrative": "Kurzer Erzähl-Einstieg (optional, Noir-Ton).",
  "act": 1,
  "pass_flag": "lesson_grep_seen",
  "slides": [
    { "kind": "text", "body": "grep durchsucht Zeilen nach einem Muster — ein Muster pro Zeile." },
    { "kind": "annotated_command", "command": "grep ^T journal.txt",
      "parts": [
        { "label": "grep", "explain": "durchsucht Zeilen nach einem Muster" },
        { "label": "^",    "explain": "Anker: Zeilen-Anfang" },
        { "label": "^T",   "explain": "Zeile beginnt mit großem T" },
        { "label": "journal.txt", "explain": "die zu durchsuchende Datei" }
      ] },
    { "kind": "reveal", "prompt": "Was findet  grep ^T journal.txt ?",
      "reveal": "Alle Zeilen, die mit einem großen T beginnen." },
    { "kind": "check", "question": "Welches matcht Zeilen, die mit 'log' ENDEN?",
      "options": [ {"id":"a","text":"grep ^log datei"}, {"id":"b","text":"grep log$ datei"} ],
      "correct": "b", "explanation": "$ verankert am Zeilen-ENDE; ^ wäre der Anfang." }
  ]
}
```

**Slide-Typen (`kind`):**
- `text` — `{ body }` (eine Idee, kurz). (Bild-Support später, additiv.)
- `annotated_command` — `{ command, parts:[{label, explain}] }`; Player hebt anklickbare Teile hervor.
- `reveal` — `{ prompt, reveal }`; Lösung erst nach Klick (kurzer eigener Denkmoment).
- `check` — `{ question, options:[{id,text}], correct, explanation }`; Sofort-Feedback, **formativ** (zählt nicht als Gate, blockt nicht — bei Falsch: Feedback + weiter).

**Validierungs-Regeln (PHP + JS-Validator):** `lesson`-Node braucht nicht-leeres `slides[]`; jeder Slide
hat ein bekanntes `kind` mit dessen Pflichtfeldern; `check`-Slides folgen der Quiz-Shape.

## 6. Gate-Integrität

Die **Lesson ist KEIN Tor.** Das echte Kapitel-Gate (Anti-Skip, K3-04) hängt weiter am **Terminal/Quiz**
(`conditions.requires_flag` auf der Terminal-`pass_flag`). Eine Lesson darf höchstens ein
informatives „gesehen"-Flag setzen (`pass_flag: lesson_*_seen`), das NICHT für Kapitel-Fortschritt
verwendet wird. So bleibt „Durchklicken ohne Verstehen" wirkungslos für den Fortschritt — gelernt wird
am Terminal nachgewiesen.

## 7. A11y / i18n / Motion

- Tastatur-Navigation (Weiter/Zurück/Aufdecken/Antwort) mit sichtbarem `focus-visible`; `aria`-Labels.
- Animationen (Slide-Übergänge, Reveal) hinter `prefers-reduced-motion` (vorhandene Primitive nutzen).
- Inhalt folgt den bestehenden Kampagnen-Content-Konventionen (Primärsprache des Kampagnen-Texts).

## 8. Validierungs-Strategie

- **JS-Validator** (`app/tests/unit/ghostlineGraph.test.js`-Stil) erweitern: `lesson`-Node-Regeln +
  Slide-Kind-Shapes — spiegelt die PHP-Validierung, fängt Fehler bei Gate 1.
- **Vitest** für `LessonPlayer.vue`: rendert jede Slide-Art; `complete`-Event nach letzter Slide;
  `check` zeigt Sofort-Feedback und blockt NICHT; `reveal` versteckt Lösung bis Klick; reduced-motion-Pfad.
- **PHPUnit/Schema:** StoryEngine akzeptiert `lesson` (node_type_enum) und lehnt invalide slides ab.
- **Manuell:** ein Lerner ohne Vorwissen spielt eine Lektion und kann danach die Terminal-Aufgabe lösen.

## 9. Scope v1 / Non-Goals

**v1:** Node-Typ `lesson`; `LessonPlayer.vue`; Slide-Kinds `text`/`annotated_command`/`reveal`/`check`;
Einbindung in `AbenteuerMode`; Schema+Validator+Tests; Pilot-Lektionen für K3 (grep, sed) als teach-first
vor den bestehenden Terminals.

**Bewusst NICHT (YAGNI):** eingebettetes Video/Audio in der Lesson; NotebookLM-Embed zur Laufzeit;
Bild-Slides; verzweigte/adaptive Lehrpfade; Lesson als Gate; Autorentool-UI (Authoring bleibt JSON).
Sprecher-Audio und Bild-Slides sind spätere additive Erweiterungen.

## 10. Bau-Einheiten (abgegrenzt)

1. Schema + StoryEngine: `lesson` Node-Typ + slide-Validierung (PHP).
2. `LessonPlayer.vue` + Slide-Sub-Renderer (text/annotated_command/reveal/check).
3. `AbenteuerMode`-Verdrahtung (`node.type==='lesson'` → Player → `complete` → graph-traverse).
4. JS-Validator-Erweiterung + Vitest.
5. Pilot-Content: K3-Lektionen (grep, sed) vor die bestehenden Terminals einsetzen; NotebookLM-Film als „Trainingsband"-Link.

## 11. Offene Punkte

- Tokenisierung der `annotated_command`-`parts` (überlappende Teile wie `^` vs `^T`): Implementierungs-Detail im Plan (z. B. geordnete Highlights statt String-Match).
- Ob `lesson_*_seen`-Flags überhaupt gespeichert werden (nur wenn ein späterer „schon gelernt"-Skip gewünscht ist) — v1 optional.
- Verhältnis zu Phase 159: die K3-Pilot-Lektionen könnten dort mitlaufen oder ein eigener Milestone werden (Roadmap-Entscheidung beim Planen).

## 12. Erfolgskriterien (binär, testbar)

1. [ ] Kampagne mit `type:"lesson"`-Node lädt fehlerfrei (StoryEngine akzeptiert den Node-Typ).
2. [ ] `LessonPlayer.vue` rendert alle vier Slide-Kinds; `Weiter`/`Zurück` funktionieren tastaturbedienbar.
3. [ ] `reveal` zeigt die Lösung erst nach Klick; `check` gibt Sofort-Feedback und blockt den Fortschritt NICHT.
4. [ ] Nach der letzten Slide feuert `complete` → `AbenteuerMode` traversiert zur nächsten Node (Terminal).
5. [ ] Das Kapitel-Gate hängt weiter am Terminal/Quiz, nicht an der Lesson (Anti-Skip unverändert).
6. [ ] JS-Validator + Vitest grün; reduced-motion stoppt Lesson-Animationen.
7. [ ] Pilot: grep- und sed-Lektion stehen VOR den bestehenden K3-Terminals; ein Test-Lerner kann nach der Lektion die Aufgabe lösen.

---

*Spec erstellt 2026-06-30. Implementierung nach der LPIC-Prüfung (03.07.). Nächster Schritt nach User-Review: writing-plans.*
