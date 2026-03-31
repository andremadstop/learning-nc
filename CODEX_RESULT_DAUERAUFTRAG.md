# Codex Dauerauftrag — Ergebnis

| Block | Aufgabe | Status | Commit | Anmerkungen |
|-------|---------|--------|--------|-------------|
| A1 | Exam Presets Verify | ✓ | `2901e07` | Zwei Presets, Score-Labels und Answer-Shuffle per Vitest-Smoke verifiziert; Browser-Login auf dev blieb in `/login` hängen. |
| A2 | Terminal Verify | ✓ | `af9e7fc` | Terminal-Smoke auf aktuelle Help-/Response-/Duplicate-Command-Logik angehoben; direkte DOM-Verifikation ebenfalls durch NC-Login blockiert. |
| A3 | Kampagne Encoding | ✓ | `34d5176` | Gemini-Pipeline mit UTF-8-/Mojibake-Normalisierung gehärtet und auf learning-dev per PHP-Lint plus Remote-Smoke geprüft. |
| B1 | Sec+ Fragen Clean | ✓ | `c8b968a` | Konverter für `00-Buchfragen.json` erstellt; 143 saubere Fragen nach `00-Buchfragen-clean.json` geschrieben. |
| B2 | N+ Erklärungen Review | ✓ | `0ce414f` | 10er Stichprobe geprüft; 4 generische Erklärungen neu generiert, 3 defekte Mehrfachfragen aus Quell-JSON rekonstruiert und Pool 124 bereinigt. |
| C1 | VirtuProf X-Button | ✓ | `37c369d` | Panel-Header um direkten Close-Button ergänzt, auf bestehende `dismiss()`-Logik verdrahtet und JS auf learning-dev ausgerollt. |
| C2 | Audit-Log Schwarm | ✓ | `4861352` | Moderation schreibt jetzt `swarm_moderation` mit Statuswechsel/Quelle/Beitragendem; interner API-Smoke mit Test-Chunk erfolgreich, Daten danach bereinigt. |
| C3 | Dozenten Upload UI | ✓ | `696599b` | Bestehenden Knowledge-Upload vervollständigt: `source_type`-Trennung zwischen Dozenten-Importen und studentischen Beiträgen, UI-Feedback verbessert, Scope-Smoke erfolgreich. |
| D1 | Worktree Merge | ✓ | `n/a` | `sim-content` existiert lokal nicht, daher kein Merge und kein Cleanup möglich. |
| D2 | Sec+ Vault Import | ✓ | `n/a` | 16 `Lesson-*.md` per `learning:import-vault` in Kurs 21 importiert; Dry-Run und Live-Import ergaben 18 neue Chunks. |
| D3 | Umlaut Cleanup | ✓ | `40775d8` | Sichtbare `ae/oe/ue`-Reste in Kursmaterialien, UI-Strings und `de.json` auf echte Umlaute umgestellt; Bundle plus `data/`/`l10n/` auf learning-dev deployt. |

## Offene Punkte
- A1 und A2 konnten auf `learning-dev` nicht per Browser-DOM verifiziert werden, weil der automatisierte Login trotz gültiger Formdaten auf der Login-Seite hängen blieb; deshalb wurden reproduzierbare Smoke-Tests auf Code-/Komponentenebene genutzt.
- D1 und D2 sind operative Schritte ohne Repo-Diff; deshalb gibt es dafür bewusst keinen separaten Code-Commit.
- Der Remote-`npm run build`-Pfad auf `learning-dev` hing mehrfach in einer stillen SSH-Hülle. Für D3 wurde deshalb lokal gebaut und das fertige Bundle direkt deployt.
