---
phase: 158-akt-1-k3-core
plan: "04"
subsystem: campaign-content
tags: [lpic, ghostline, content-qa, fact-check, exam-traps]
dependency_graph:
  requires: [158-02, 158-03]
  provides: [CONT-01, CONT-02]
  affects: [ghostline_act1.json]
tech_stack:
  added: []
  patterns: [LPIC-103-vault-cross-check, Noir-educational-content]
key_files:
  modified:
    - app/data/campaigns/ghostline_act1.json
decisions:
  - "History dating corrected: grep is early-1970s Bell Labs, not 1969/PDP-7 (Unix birth vs grep birth)"
  - "sort|uniq woven into history node as Bell Labs-era lore, not a dry textbook insert"
  - "Signal numbers placed in quiz_wrong narrative so the ghost's 'second lesson' feels natural"
metrics:
  duration: "~20min"
  completed: "2026-06-30T17:36:00Z"
  tasks_completed: 2
  files_modified: 1
---

# Phase 158 Plan 04: CONT-01 / CONT-02 Fact-Check Summary

**One-liner:** All quiz/terminal content verified against LPIC-103 vault; history dating corrected (1969/PDP-7 → early-1970s Bell Labs); all 6 exam traps added as Noir-style authored content.

---

## CONT-01 Sign-Off: Factual Cross-Check Audit

Each item verified against the primary vault source listed. Corrections noted.

| # | Content Element | Vault Source | Shell Verified | Verdict |
|---|----------------|--------------|----------------|---------|
| 1 | `grep ^T ghost_journal.txt` — command syntax | 103.7 (grep BRE, `^` = Zeilenanfang) | YES — ran against fixture; 8 lines match | CORRECT |
| 2 | `grep ^T` objective: "alle Zeilen die mit T beginnen" | 103.7 (`^` Anker = Zeilenanfang) | YES | CORRECT |
| 3 | `grep ^T` hint: "das Caret ^ verankert am Zeilenanfang" | 103.7 Anker-Tabelle | YES | CORRECT |
| 4 | `grep ^T` output (8 lines, all T-starting) | Shell re-run confirmed identical | YES | CORRECT, no re-capture needed |
| 5 | Quiz question: BRE 'a+' vs ERE -E 'a+' framing | 103.7 BRE/ERE Schlüsselbegriffe | — | CORRECT |
| 6 | Quiz correct answer: "-E aktiviert + als ERE-Quantifier" | 103.7: `+` = "1 oder mehr (ERE)" | — | CORRECT |
| 7 | Quiz wrong answer: "Kein Unterschied" | 103.7 confirms BRE treats + as literal | — | CORRECT (is genuinely wrong) |
| 8 | Quiz explanation: BRE=literal, ERE=quantifier | 103.7 Quantifizierer + BRE/ERE sections | — | CORRECT; **IMPROVED** — added concrete example (see below) |
| 9 | `sed 's/ERROR/WARN/g'` — syntax + `/g` global per line | 103.7 sed section; 103.2 sed table | YES — ran against fixture; 3 substitutions on 15 lines | CORRECT |
| 10 | `sed 's/ERROR/WARN/g'` objective: "alle Vorkommen … global" | 103.7: `s/alt/neu/g` = global | YES | CORRECT |
| 11 | `sed` hint: "/g-Flag ersetzt alle Vorkommen pro Zeile" | 103.7 sed `s/alt/neu/g` | YES | CORRECT |
| 12 | `sed` output (15 lines, 3 ERROR→WARN substitutions) | Shell re-run confirmed identical | YES | CORRECT, no re-capture needed |
| 13 | History: "Ken Thompson" + "g/re/p aus ed" + "Bell Labs" | Ken Thompson created grep; g/re/p from ed — well-documented | — | CORRECT |
| 14 | History: "1969 / PDP-7" for grep | Unix=1969/PDP-7 CORRECT; grep=early 1970s/PDP-11 — **WRONG** | — | **CORRECTED** |
| 15 | History: NPC dialog "1969. Ken Thompson." | Same dating error | — | **CORRECTED** |

### Corrections Made (Task 1)

**1. [CONT-01 - History] grep dated to 1969/PDP-7 — corrected**
- Found during: Task 1 Step 4
- Issue: a1_k3_history narrative said "seit 1969" and "ein PDP-7" for grep's creation. 1969/PDP-7 is Unix's birth (Thompson's early Unix). grep was extracted from ed's g/re/p in the early 1970s (commonly 1973/1974, on PDP-11).
- Fix: Changed narrative to "Bell Labs, frühe 1970er Jahre" and "ein Terminal" (removing wrong machine). Changed NPC dialog from "1969. Ken Thompson." to "Bell Labs. Ken Thompson."
- Files modified: `app/data/campaigns/ghostline_act1.json`
- Commit: `76feb5a`

**2. [CONT-01 - Quiz] BRE/ERE explanation lacked concrete example**
- Found during: Task 1 Step 3
- Issue: Explanation had 2 sentences but no example of what `grep 'a+'` actually matches vs doesn't match.
- Fix: Added "Konkret: grep 'a+' sucht den Literal-String 'a+' und matcht nicht 'aaa' — grep -E 'a+' dagegen matcht jede Folge von einem oder mehr 'a'."
- Files modified: `app/data/campaigns/ghostline_act1.json`
- Commit: `76feb5a`

---

## CONT-02 Sign-Off: Exam Trap Coverage

All 6 prüfungskritische 103-Fallen confirmed present as authored content.

| Trap | Node | Quote (first sentence) | Status |
|------|------|------------------------|--------|
| **1. umask** | `a1_k3_grep2_gate` | "Zwischen den Zeilen eine Notiz: umask 022 — neue Dateien erhalten 644, Verzeichnisse 755." | ADDED (was missing) |
| **2. BRE vs ERE** | `a1_k3_quiz` (quiz.explanation) + `a1_k3_quiz_wrong` (narrative) | "BRE behandelt + literal; ERE (grep -E oder egrep) interpretiert + als 'ein oder mehr'. Konkret: grep 'a+' sucht den Literal-String 'a+' und matcht nicht 'aaa'…" | PRE-EXISTING; explanation IMPROVED |
| **3. redirect order (2>&1)** | `a1_k3_grep1_gate` | "cmd > file 2>&1 ist korrekt — erst stdout in die Datei, dann stderr dazu. Umgekehrt, 2>&1 > file, bleibt stderr auf dem Terminal." | ADDED (was missing) |
| **4. sort\|uniq** | `a1_k3_history` | "Sein Bruder im Geiste: uniq — aber uniq allein kennt nur benachbarte Zeilen. Erst sort, dann uniq: so zählt man Duplikate wirklich." | ADDED (was missing) |
| **5. vi modes** | `a1_k3_end` | "vi öffnet immer im Normal-Modus — wer zu tippen beginnt ohne i, schreibt Befehle statt Text. Esc bringt zurück in den Normal-Modus." | ADDED (was missing) |
| **6. signal numbers** | `a1_k3_quiz_wrong` | "Signale haben Nummern. 1=SIGHUP, 2=SIGINT, 9=SIGKILL (nicht abfangbar, kein Entkommen), 15=SIGTERM (Standard für kill ohne Flag)." | ADDED (was missing) |

### CONT-02 grep audit result

```
umask:   1
BRE:     2
ERE:    13
2>&1:    1
sort:    1
uniq:    1
vi :     1
SIGKILL: 1
SIGTERM: 1
```

All CONT-02 required patterns present (≥1 match each).

---

## Terminal Command Re-Capture

No re-capture was needed. Both commands were verified against the real shell:

**grep ^T ghost_journal.txt** (real output):
```
Tagebuch des Geists — Version 0
Timestamp: 1969-01-01T00:00:00Z — Initialisierung
Tue Mar 10 03:14:07 UTC 1970 — Systemstart
Traceroute endet im Nichts. Paket verloren.
Terminierung ohne Rückmeldung.
The pattern persists. The signal remains.
Tue, 14 Apr 1970 — letzter bekannter Eintrag
Terminal: Keine weiteren Daten verfügbar.
```
Matches JSON output field exactly. No correction needed.

**sed 's/ERROR/WARN/g' ghost_journal.txt** (real output):
```
Tagebuch des Geists — Version 0
Alles begann mit einem Signal, das niemand senden wollte.
Timestamp: 1969-01-01T00:00:00Z — Initialisierung
Tue Mar 10 03:14:07 UTC 1970 — Systemstart
WARN: Speicher nicht initialisiert — Daten verloren
Das Netz kennt keine Namen, nur Adressen.
Traceroute endet im Nichts. Paket verloren.
WARN: Verbindung unterbrochen — Neustart erforderlich
Suchmaschinen lügen. grep lügt nicht.
Terminierung ohne Rückmeldung.
The pattern persists. The signal remains.
WARN: Zeitstempel außerhalb des gültigen Bereichs
Nur Muster bleiben. Texte vergehen.
Tue, 14 Apr 1970 — letzter bekannter Eintrag
Terminal: Keine weiteren Daten verfügbar.
```
Matches JSON output field exactly. No correction needed.

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] History node dated grep to wrong year and wrong machine**
- Found during: Task 1 Step 4
- Issue: Narrative said "seit 1969" and "ein PDP-7" for grep's creation. LPIC-relevant fact: grep was created at Bell Labs in the early 1970s (not 1969). PDP-7 = early Unix platform; grep was written on PDP-11. The error would not harm LPIC exam performance directly, but as an authoring quality gate it constitutes a factual inaccuracy in an LPIC training campaign.
- Fix: Changed to "Bell Labs, frühe 1970er Jahre" in narrative; "Bell Labs. Ken Thompson." in NPC dialog; "ein Terminal" instead of "ein PDP-7".
- Files modified: `app/data/campaigns/ghostline_act1.json`
- Commit: `76feb5a`

---

## Self-Check

```
ghostline_act1.json: FOUND
Commit 76feb5a: FOUND
Commit 36914cd: FOUND
```

Validator: 12/12 GREEN (both after Task 1 and Task 2 commits)

## Self-Check: PASSED
