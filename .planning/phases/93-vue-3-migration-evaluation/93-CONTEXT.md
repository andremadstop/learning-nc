# Phase 93: Vue 3 Migration Evaluation - Context

**Gathered:** 2026-03-27
**Status:** Ready for planning

<domain>
## Phase Boundary

Fundierte Entscheidungsgrundlage ob und wie die Migration von Vue 2 auf Vue 3 durchgefuehrt werden soll. Kein Code-Umbau — nur Analyse, Tabellen, Risikobewertung. Output ist ein Evaluierungsdokument das Go/No-Go Entscheidung ermoeglicht.

</domain>

<decisions>
## Implementation Decisions

### Entscheidungskriterien
- Nextcloud-Kompatibilitaet ist K.O.-Kriterium: Wenn @nextcloud/vue Vue 3 nicht unterstuetzt, ist Migration blockiert
- Vue 2 EOL (31.12.2023) ist bekannt — Sicherheitsrisiko steigt, aber App laeuft in kontrollierter NC-Umgebung
- Migration muss inkrementell moeglich sein — Big-Bang bei 75 Komponenten ist zu riskant

### Analyse-Tiefe
- Pro-Komponente Tabelle: Kompatibilitaet (kompatibel / anpassbar / neu schreiben), Aufwand (S/M/L/XL), Risiko
- Fokus auf Breaking Changes: Mixins (4 Dateien), Event Bus $root.$emit (11 Dateien), vue-loader → vite
- Plugin/Dependency Check: @nextcloud/vue 8.x, vue-loader 15, vue-template-compiler, D3.js Integration

### Output-Format
- Markdown-Dokument in .planning/ (kein Code, kein Branch)
- 3 Sektionen passend zu VUE3-01/02/03: Kompatibilitaetstabelle, Migrationspfad, Risikobewertung
- Go/No-Go Empfehlung mit klarer Begruendung

### Claude's Discretion
- Analyse-Methodik und Detailtiefe pro Komponente
- Ob vue-compat (Migration Build) empfohlen wird
- Ob Vite-Migration als separater Schritt oder zusammen mit Vue 3 empfohlen wird
- Ob Composition API Umstellung vor oder nach Vue 3 empfohlen wird
- Priorisierung der Komponenten (welche zuerst migrieren)

</decisions>

<specifics>
## Specific Ideas

No specific requirements — user gave full autonomy. Key constraint: die 3 Requirements (VUE3-01, VUE3-02, VUE3-03) muessen erfuellt sein.

</specifics>

<code_context>
## Existing Code Insights

### Codebase Snapshot (2026-03-27)
- 75 Vue-Komponenten (Options API, 0 Composition API)
- Vue 2.7.16, webpack, vue-loader 15, vue-template-compiler 2.7.16
- @nextcloud/vue 8.20.0 (NC-Komponentenbibliothek)
- 5 @nextcloud packages: axios, dialogs, l10n, router, vue
- Kein Vuex/Pinia — State ist komponentenlokal oder Event Bus

### Vue 2 Breaking Change Patterns
- **Mixins**: 4 Dateien — muessen zu Composables werden
- **Filters**: 0 — kein Problem
- **Event Bus ($root.$emit/$on)**: 11 Dateien — muss durch mitt/tiny-emitter oder provide/inject ersetzt werden
- **Options API**: 75/75 Komponenten — funktioniert weiter in Vue 3, aber Composition API ist empfohlen

### Build-Toolchain
- webpack mit vue-loader 15 (Vue 2 spezifisch)
- NC Build-Integration: @nextcloud/webpack-vue-config vermutlich
- Migration zu Vite wuerde vue-loader, vue-template-compiler und webpack.config.js betreffen

### Integration Points
- @nextcloud/vue ist die kritischste Dependency — muss Vue 3 kompatible Version haben
- D3.js (questMapRenderer.js) ist framework-agnostisch — kein Problem
- Web Audio API (nova-audio-manager.js) — kein Vue-Bezug
- PHP Backend (OCS API, QBMapper) — komplett unabhaengig von Frontend-Framework

</code_context>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 93-vue-3-migration-evaluation*
*Context gathered: 2026-03-27*
