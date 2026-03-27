# Collaboration Guardrails

## Ziel

Diese Regeln verhindern, dass Claude, Gemini und Codex sich gegenseitig Dateien, Hypothesen oder Handoffs kaputt machen.

## Rollen

- Claude: Problemklaerung, Scope, Review, Architektur, Abnahme.
- Gemini: Recherche, Alternativen, Pro/Contra, keine parallelen Code-Edits im selben Scope.
- Codex: Implementierung, lokale Verifikation, Commit.

## Ownership-Regel

- Pro Datei oder Pfad gibt es genau 1 Code-Owner pro aktivem Task.
- Nur der Code-Owner darf diesen Scope editieren.
- Andere Agents duerfen beraten, Alternativen liefern oder reviewen, aber nicht parallel dieselben Dateien aendern.
- Wenn der Scope waehrend der Arbeit waechst, muss die Ownership vor dem naechsten Edit schriftlich neu festgelegt werden.

## Status pro Ansatz

- `hypothesis`: Idee wird gerade getestet. Nicht committen. Nicht an andere Agents als "fertig" weitergeben.
- `verified`: Ansatz ist lokal belegt und darf staged oder committed werden.
- `rejected`: Ansatz ist verworfen. Darf nicht im Haupt-Worktree liegen bleiben.

Nur `verified` darf committed werden.

## Handoff-Pflichtfelder

Jeder Handoff muss mindestens enthalten:

- Ziel
- Scope: erlaubte Dateien oder Pfade
- Verbotener Scope, falls relevant
- Code-Owner fuer den Scope
- Verifikation: `build`, `lint`, `tests` mit `passed` oder `failed`
- Abbruchregeln
- Status des aktuellen Ansatzes: `hypothesis`, `verified` oder `rejected`

## Failed Experiments

- Gescheiterte oder ersetzte Ansaetze nie als halbfertige Aenderung im Haupt-Worktree liegen lassen.
- Entweder auf separatem Branch parken oder vor dem Handoff verwerfen.
- Wenn ein Experiment ausnahmsweise sichtbar bleiben muss, muss der Handoff exakt nennen:
  - welche Dateien betroffen sind
  - warum der Stand nicht merge-faehig ist
  - welcher Agent die Aufraeum-Verantwortung traegt

## Standard-Ablauf

1. Claude klaert Ziel, Scope, Risiken und Owner.
2. Gemini liefert Alternativen oder Recherche ohne denselben Code-Scope zu editieren.
3. Codex setzt um.
4. Codex verifiziert lokal.
5. Claude reviewt nur `verified`-Aenderungen.

## Gemini-Integration

Gemini wird als Reviewer und Pre-Codex Gate eingesetzt. Prompts: `.planning/GEMINI_REVIEW_PROMPT.md`

- **Code-Review (Variante A):** Nach jedem Codex-Commit Diff + REVIEW-CONTEXT.md an Gemini → SHIP/FIX/BLOCKER
- **Pre-Codex Gate (Variante B):** Vor jedem Handoff pruefen lassen → APPROVE/IMPROVE/REJECT
- **Recherche (Variante C):** Bei Architektur-Fragen 2-3 Loesungswege analysieren lassen

Kontext-Datei: `.planning/REVIEW-CONTEXT.md` — enthält die Top-Steering-Rules und Known Patterns. Wird bei jedem Review mitgegeben.

## Merge-Regel

- Kein Commit mit unbekanntem Scope.
- Kein Commit mit `failed` Build, Lint oder Tests.
- Kein Commit auf Basis eines `rejected`-Ansatzes.
