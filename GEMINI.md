# Learning-NC — Gemini Instructions

> Nextcloud App für Lernkarteien. PHP 8.1+, Vue 2.7, PostgreSQL 16, NC 29–31.
> Deine Rolle: **Analyse und Planung only** — kein Code schreiben, keine Dateien ändern.
> Ergebnisse als Markdown-Dokument in `GEMINI_PLAN_[THEMA].md` im Repo-Root ablegen.

---

## Projekt-Struktur

```
app/                   ← alles hier, das ist die NC-App
app/lib/Controller/    ← PHP Controller
app/lib/Service/       ← Business-Logik
app/lib/Db/            ← Entities + Mapper
app/lib/Migration/     ← DB-Migrationen
app/src/components/    ← Vue 2.7 SFCs
app/l10n/              ← UI-Übersetzungen (de.json, de.js)
app/appinfo/routes.php ← alle API-Routen
```

---

## PHP-Standards (für Planungszwecke)

- Namespace: `OCA\Learning\...`
- DB: `IQueryBuilder`, Tabellennamen ohne `oc_`-Prefix
- Neue Migrationen: `VersionXXXXDate{YYYYMMDD}000000.php`
- Controller brauchen `@NoAdminRequired` + `#[UserRateLimit]`

## Vue-Standards (für Planungszwecke)

- Vue **2.7** — kein Vue 3
- `t('learning', 'Text')` für UI-Strings
- NC-Komponenten: NcButton, NcNoteCard, NcModal, NcLoadingIcon

---

## Wichtige Rahmenbedingungen

1. **Keine Datenmutation** — Gemini ändert keine bestehenden Daten oder Übersetzungen
2. **Plan muss Codex-ausführbar sein** — konkrete Dateilisten, Methodennamen, SQL
3. **Fallstricke beachten**: PBQ-Fragen (`pbq_subtype IS NOT NULL`) haben keine `oc_learning_answers`-Einträge
4. **Bestehende Translation-Infrastruktur nutzen**: `TranslationService`, `oc_learning_question_translations`, `oc_learning_answer_translations` existieren bereits

---

## Aktuelle Aufgabe → GEMINI_BRIEF_LANGUAGE.md
