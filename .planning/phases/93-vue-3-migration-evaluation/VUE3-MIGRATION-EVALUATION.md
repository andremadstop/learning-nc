# Vue 3 Migration Evaluation

**Erstellt:** 2026-03-27
**Codebase:** 75 Vue-Komponenten, Vue 2.7.16, Options API, webpack + vue-loader 15
**Entscheidung:** [wird in Sektion Go/No-Go beantwortet]

## Executive Summary

[Platzhalter — wird in Task 2 ausgefuellt]

---

## 1. Kompatibilitaetsanalyse

### 1.1 Breaking-Change-Inventar

Folgende Vue 3 Breaking Changes wurden in der Codebase identifiziert:

| Breaking Change | Dateien | Vorkommen | Schwere |
|----------------|---------|-----------|---------|
| `$root.$emit` / `$root.$on` (Event Bus) | 11 | 43 | Hoch — komplett entfernt in Vue 3 |
| `this.$set()` (Reactivity) | 10 | 34 | Mittel — nicht mehr noetig mit Proxy, aber Code muss angepasst werden |
| `mixins: [...]` | 4 | 4 | Mittel — muessen zu Composables werden |
| `Vue.prototype.t/n` (Global Prototype) | 3 (Entry Points) | 6 | Mittel — wird zu `app.config.globalProperties` |
| `new Vue()` (App-Erstellung) | 3 (Entry Points) | 3 | Niedrig — mechanischer Umbau zu `createApp()` |
| `Vue.config.productionTip` | 3 (Entry Points) | 3 | Niedrig — existiert nicht mehr, einfach entfernen |
| `vue$` Alias in webpack resolve | 1 (webpack.config.js) | 1 | Niedrig — muss angepasst werden |
| `$forceUpdate()` | 1 (hintMixin.js) | 1 | Niedrig — existiert weiter, aber innerhalb Mixin |
| `$listeners` / `$children` | 0 | 0 | -- |
| `filters:` | 0 | 0 | -- |
| `::v-deep` / `/deep/` / `>>>` | 0 | 0 | -- |
| `render(h)` Funktionen | 0 | 0 | -- |
| `Vue.component()` / `Vue.directive()` / `Vue.filter()` | 0 | 0 | -- |
| `v-model` mit Custom Prop (Syntax-Aenderung) | 0 custom | 0 | -- |

**Zusammenfassung Breaking Changes:**
- **3 kritische Patterns**: Event Bus (43x), `$set` (34x), Mixins (4x)
- **0 Showstopper-Patterns**: Keine Filters, keine $listeners, keine $children, keine render-Funktionen
- **Mechanisch migrierbar**: Entry Points (new Vue, prototype, config) — 3 Dateien, trivial

### 1.2 Dependency-Analyse

#### @nextcloud/vue 8.x — K.O.-Kriterium

| Fakt | Detail |
|------|--------|
| Installierte Version | 8.36.0 (package.json fordert ^8.20.0) |
| Vue-Abhaengigkeit | `vue: ^2.7.16` (hartkodiert auf Vue 2) |
| Interne Dependencies | vue-frag, vue-color, vue2-datepicker, floating-vue 1.x, vue-router 3.x — alles Vue-2-only |
| Vue 3 Support | **@nextcloud/vue 9.x** ist in Entwicklung (seit NC 30+), aber noch kein stabiles Release |
| NC-Kompatibilitaet | learning-nc unterstuetzt NC 29-31; @nextcloud/vue 9.x wird voraussichtlich NC 31+ voraussetzen |
| Import-Muster | 37 Komponenten importieren aus `@nextcloud/vue/dist/Components/` — dieses Import-Pattern aendert sich in v9 |

**Ergebnis: @nextcloud/vue 8.x ist inkompatibel mit Vue 3. Dies ist ein harter Blocker.**

@nextcloud/vue 9.x (Vue-3-kompatibel) befindet sich in aktiver Entwicklung im Nextcloud-Oekosystem. Nextcloud Server selbst migriert schrittweise: NC 30 hat begonnen, NC 31+ wird voraussichtlich Vue 3 als Standard fuehren. Ein stabiles @nextcloud/vue 9.x Release haengt vom Fortschritt im Nextcloud-Core ab.

#### Weitere @nextcloud Packages

| Package | Version | Vue 3 Status |
|---------|---------|-------------|
| @nextcloud/axios | ^2.5.0 | Kompatibel — kein Vue-Bezug, reiner HTTP-Client |
| @nextcloud/dialogs | ^5.3.7 | v6.x wird Vue 3 brauchen; v5.x hat minimalen Vue-Bezug (showSuccess/showError sind vanilla JS) |
| @nextcloud/l10n | ^3.1.0 | Kompatibel — reines JS, kein Vue-Bezug |
| @nextcloud/router | ^3.0.1 | Kompatibel — reines JS, kein Vue-Bezug |

#### Build-Toolchain

| Tool | Aktuell | Vue 3 Aequivalent | Migration |
|------|---------|-------------------|-----------|
| vue-loader | 15.11.1 | 17.x | Major-Upgrade, neue Config |
| vue-template-compiler | 2.7.16 | @vue/compiler-sfc | Package-Tausch |
| webpack | 5.97.1 | 5.x (bleibt) oder Vite | webpack 5 funktioniert mit vue-loader 17 |
| babel-loader | 9.2.1 | 9.x (bleibt) | Kein Umbau noetig |
| @vitejs/plugin-vue2 (devDep) | 2.3.4 | @vitejs/plugin-vue | Nur relevant falls Vite-Migration |
| eslint-plugin-vue | 9.33.0 | 9.x (bleibt, unterstuetzt Vue 3) | Nur Config-Anpassung |

#### Nicht-Vue Dependencies (kein Migrationsbedarf)

- d3-force, d3-selection, d3-shape, d3-transition, d3-zoom — Framework-agnostisch
- canvas-confetti — Vanilla JS
- linkifyjs — Vanilla JS
- happy-dom, vitest, playwright — Test-Tools, Vue-unabhaengig

### 1.3 Kompatibilitaetstabelle — Alle 75 Vue-Komponenten

Legende:
- **kompatibel**: Kein Vue-3-spezifischer Umbau noetig (Options API funktioniert weiter)
- **anpassbar**: Hat Breaking Changes die mechanisch migrierbar sind
- **neu schreiben**: Fundamentale Aenderungen noetig

#### App Shell & Navigation

| # | Komponente | Status | Breaking Changes | Aufwand |
|---|-----------|--------|-----------------|---------|
| 1 | App.vue | anpassbar | $root.$on (1x), $root.$emit (4x) | M |
| 2 | CourseList.vue | anpassbar | mixins: [hintMixin] | S |
| 3 | CourseDetail.vue | anpassbar | $root.$emit (8x), $set (3x) | L |
| 4 | ArenaSelector.vue | kompatibel | -- | S |
| 5 | ModeIdentityBanner.vue | kompatibel | -- | S |

#### Quiz-Modi

| # | Komponente | Status | Breaking Changes | Aufwand |
|---|-----------|--------|-----------------|---------|
| 6 | TrainingMode.vue | anpassbar | $root.$emit (4x) | M |
| 7 | LeitnerMode.vue | anpassbar | $root.$emit (3x), mixins: [hintMixin] | M |
| 8 | ExamMode.vue | anpassbar | $root.$emit (7x), $set (6x) | L |
| 9 | DuelMode.vue | anpassbar | $root.$emit (6x) | M |
| 10 | LernwuerfelMode.vue | anpassbar | $root.$emit (1x) | M |
| 11 | GameshowMode.vue | anpassbar | $root.$emit (1x) | M |
| 12 | WissensturmMode.vue | anpassbar | $root.$emit (1x) | M |
| 13 | SmartQueue.vue | anpassbar | mixins: [hintMixin] | S |
| 14 | AbenteuerMode.vue | kompatibel | -- | S |
| 15 | QuestionLanguageSwitcher.vue | kompatibel | -- | S |
| 16 | OldschoolSelector.vue | kompatibel | -- | S |

#### VirtuProf & NOVA

| # | Komponente | Status | Breaking Changes | Aufwand |
|---|-----------|--------|-----------------|---------|
| 17 | VirtuProf.vue | anpassbar | $root.$on (7x), $root.$off (7x), $set (3x) | L |
| 18 | VirtuProfBubble.vue | kompatibel | -- (nur $emit zu parent, kein $root) | S |
| 19 | VirtuProfAvatar.vue | kompatibel | -- | S |
| 20 | NovaAvatar.vue | kompatibel | -- | S |
| 21 | NovaBaseCore.vue | kompatibel | -- | S |
| 22 | NovaEyeDisplay.vue | kompatibel | -- | S |
| 23 | NovaBitOrbiters.vue | kompatibel | -- | S |
| 24 | NovaAccessorySlot.vue | kompatibel | -- | S |
| 25 | NovaPanel.vue | kompatibel | -- | S |
| 26 | NovaDock.vue | kompatibel | -- | S |
| 27 | DauBotDialog.vue | kompatibel | -- | S |
| 28 | CharacterAvatar.vue | kompatibel | -- | S |

#### Campaign & Quest

| # | Komponente | Status | Breaking Changes | Aufwand |
|---|-----------|--------|-----------------|---------|
| 29 | CampaignCard.vue | kompatibel | -- | S |
| 30 | CampaignIntro.vue | kompatibel | -- | S |
| 31 | DialogueStage.vue | kompatibel | -- | S |
| 32 | QuestMap.vue | kompatibel | -- | S |
| 33 | HackThroughTime.vue | anpassbar | $set (1x) | S |
| 34 | TerminalPuzzle.vue | kompatibel | -- | S |
| 35 | GameHud.vue | kompatibel | -- | S |
| 36 | GameTimer.vue | kompatibel | -- | S |

#### PBQ (Performance-Based Questions)

| # | Komponente | Status | Breaking Changes | Aufwand |
|---|-----------|--------|-----------------|---------|
| 37 | PbqRenderer.vue | anpassbar | $set (1x) | S |
| 38 | PbqCli.vue | anpassbar | $set (3x) | S |
| 39 | PbqCable.vue | kompatibel | -- | S |
| 40 | PbqSwitchConfig.vue | kompatibel | -- | S |
| 41 | PbqRoutingConfig.vue | kompatibel | -- | S |
| 42 | PbqDiagnostic.vue | kompatibel | -- | S |
| 43 | PbqDropdown.vue | kompatibel | -- | S |
| 44 | PbqMultiPanel.vue | kompatibel | -- | S |
| 45 | PbqPlacement.vue | kompatibel | -- | S |
| 46 | PbqReferenceGallery.vue | kompatibel | -- | S |
| 47 | PbqAuthorTool.vue | anpassbar | $set (7x) | M |
| 48 | NetworkTopologySvg.vue | kompatibel | -- | S |

#### Simulatoren / Tools

| # | Komponente | Status | Breaking Changes | Aufwand |
|---|-----------|--------|-----------------|---------|
| 49 | SimulatorShell.vue | kompatibel | -- | S |
| 50 | SubnetCalculator.vue | anpassbar | $set (2x) | S |
| 51 | PortScanner.vue | kompatibel | -- | S |
| 52 | RoutingTable.vue | kompatibel | -- | S |
| 53 | NatTable.vue | kompatibel | -- | S |
| 54 | WiresharkLite.vue | kompatibel | -- | S |
| 55 | AuthFlowSimulator.vue | kompatibel | -- | S |
| 56 | FirewallBuilder.vue | kompatibel | -- | S |
| 57 | DnsResolver.vue | kompatibel | -- | S |

#### Coop / Multiplayer

| # | Komponente | Status | Breaking Changes | Aufwand |
|---|-----------|--------|-----------------|---------|
| 58 | CoopLobby.vue | kompatibel | -- | S |
| 59 | CoopVoteOverlay.vue | kompatibel | -- | S |
| 60 | CoopWaiting.vue | kompatibel | -- | S |

#### Admin, Settings & Dialoge

| # | Komponente | Status | Breaking Changes | Aufwand |
|---|-----------|--------|-----------------|---------|
| 61 | AdminSettings.vue | anpassbar | $set (1x) | S |
| 62 | PersonalSettings.vue | anpassbar | $root.$emit (1x) | S |
| 63 | OnboardingIntro.vue | kompatibel | -- | S |
| 64 | QuestionForm.vue | kompatibel | -- | S |
| 65 | QuestionList.vue | kompatibel | -- | S |
| 66 | PoolList.vue | anpassbar | mixins: [hintMixin] | S |
| 67 | ImportDialog.vue | kompatibel | -- | S |
| 68 | TranslationDialog.vue | anpassbar | $set (2x) | S |
| 69 | ShareDialog.vue | kompatibel | -- | S |
| 70 | AIGenerator.vue | kompatibel | -- | S |

#### Gamification & UI

| # | Komponente | Status | Breaking Changes | Aufwand |
|---|-----------|--------|-----------------|---------|
| 71 | BadgeUnlock.vue | kompatibel | -- | S |
| 72 | LevelUpOverlay.vue | kompatibel | -- | S |
| 73 | LeagueTab.vue | kompatibel | -- | S |
| 74 | AnalyticsDashboard.vue | kompatibel | -- | S |
| 75 | StudentDetail.vue | kompatibel | -- | S |
| 76 | InstructorDashboard.vue | kompatibel | -- | S |
| 77 | CourseMaterials.vue | anpassbar | $set (4x) | S |

> **Hinweis:** Die Glob-Suche ergab 76 .vue-Dateien (75 Komponenten + App.vue als Root). Die Tabelle listet 77 Eintraege weil App.vue als #1 mitgezaehlt wird.

### 1.4 Kompatibilitaets-Zusammenfassung

| Status | Anzahl | Anteil |
|--------|--------|--------|
| **kompatibel** | 54 | 70% |
| **anpassbar** | 23 | 30% |
| **neu schreiben** | 0 | 0% |

**Keine einzige Komponente muss komplett neu geschrieben werden.** Alle Breaking Changes sind mechanisch migrierbar (Event Bus ersetzen, $set entfernen, Mixin zu Composable).

### 1.5 Nicht-Komponenten-Dateien mit Breaking Changes

| Datei | Typ | Breaking Change | Aufwand |
|-------|-----|----------------|---------|
| main.js | Entry Point | `new Vue()`, `Vue.prototype.t/n`, `Vue.config.productionTip` | S |
| admin-settings.js | Entry Point | `new Vue()`, `Vue.prototype.t/n`, `Vue.config.productionTip` | S |
| personal-settings.js | Entry Point | `new Vue()`, `Vue.prototype.t/n`, `Vue.config.productionTip` | S |
| hintMixin.js | Mixin | Muss zu Composable werden (`useHint()`) | S |
| webpack.config.js | Build | vue-loader Plugin, vue$ Alias | M |

---

## 2. Migrationspfad

[Platzhalter — wird in Task 2 ausgefuellt]

## 3. Risikobewertung

[Platzhalter — wird in Task 2 ausgefuellt]

## 4. Go/No-Go Empfehlung

[Platzhalter — wird in Task 2 ausgefuellt]
