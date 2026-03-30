# CODEX RESULT: Exam Presets

## Umsetzung

- `app/src/components/ExamMode.vue` von freier Zeit-/Fragenwahl auf zwei Presets umgestellt: `Full Exam` (90/90) und `Practice Exam` (45/45)
- Setup-UI auf Kartenlayout reduziert, Start je Preset direkt aus der Karte
- Ergebnisansicht auf CompTIA-nahe Darstellung umgebaut: skalierter Score `X/900`, Pass/Fail-Status und Bestehensgrenze `720/900`

## Shuffle / Reihenfolge

- Fragen-Shuffle war im Backend bereits aktiv und bleibt erhalten
- PBQs werden weiterhin zuerst ausgeliefert
- Antwortoptionen werden weiterhin session-basiert deterministisch gemischt

## Offene Punkte

- Keine separaten Lint-/Testläufe gestartet
- Verifikation erfolgt über den vorgeschriebenen JS-Deploy
