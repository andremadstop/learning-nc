# Vue 3 Migration Evaluation

**Erstellt:** 2026-03-27
**Codebase:** 75 Vue-Komponenten, Vue 2.7.16, Options API, webpack + vue-loader 15
**Entscheidung:** Bedingtes Go — blockiert durch @nextcloud/vue 9.x Release

## Executive Summary

- **@nextcloud/vue 8.x ist inkompatibel mit Vue 3** — dies ist ein harter Blocker. Migration ist erst moeglich wenn @nextcloud/vue 9.x (Vue 3) stabil released ist und NC 31+ als Mindestversion akzeptabel ist.
- **Die Codebase ist migrationsfreundlich:** 70% der Komponenten (54/77) sind ohne Aenderungen kompatibel, 30% brauchen mechanische Anpassungen (Event Bus, $set, Mixins). Keine Komponente muss neu geschrieben werden.
- **Drei Hauptarbeiten:** Event Bus durch provide/inject ersetzen (11 Dateien, 43 Stellen), `this.$set()` entfernen (10 Dateien, 34 Stellen), 1 Mixin zu Composable umbauen.
- **Geschaetzter Gesamtaufwand:** L (2-3 Wochen Vollzeit) — davon 80% @nextcloud/vue Import-Migration und Testing, 20% eigener Code.
- **Empfehlung: Bedingtes Go** — Migration als eigenen Milestone planen, Startbedingung ist stabiles @nextcloud/vue 9.x Release. Bis dahin: Vue 2.7 LTS weiterverwenden, keine neuen Vue-2-only Patterns einfuehren.

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

### 2.1 Empfohlene Strategie: vue-compat (Migration Build)

**Empfehlung: Inkrementelle Migration mit `@vue/compat`** statt Big-Bang.

Vue 3 bietet einen offiziellen Kompatibilitaets-Modus (`@vue/compat`), der Vue-2-Code in einer Vue-3-Runtime ausfuehrt und Deprecation-Warnings fuer Breaking Changes ausgibt. Dies erlaubt schrittweise Migration statt eines riskanten Komplettumbaus.

**Warum nicht Big-Bang?** 77 Komponenten + 37 @nextcloud/vue Imports gleichzeitig umzubauen ist zu riskant. Ein einziger Fehler blockiert die gesamte App.

**Warum nicht rein inkrementell (Micro-Frontends)?** Nextcloud Apps laufen als monolithische Vue-Instanz. Zwei Vue-Versionen parallel sind nicht moeglich.

### 2.2 Migrationspfad — Schritte

```
Schritt 0: Voraussetzung pruefen                    [BLOCKER]
           @nextcloud/vue 9.x muss stabil released sein.
           Ohne diesen Schritt: STOP.

Schritt 1: Vorbereitungen (noch auf Vue 2)          [Voraussetzung: keiner]
           1a. Event Bus ersetzen: $root.$emit/$on → provide/inject Pattern
               (11 Dateien, 43 Stellen)
           1b. hintMixin.js → useHint() Composable
               (1 Mixin, 4 Konsumenten)
           1c. this.$set() durch direkte Zuweisung ersetzen
               (10 Dateien, 34 Stellen — Vue 2.7 unterstuetzt Proxy fuer
               neue Properties in data(), aber $set ist fuer dynamische Keys
               noch noetig. Testen ob direkte Zuweisung funktioniert.)
           → Diese Aenderungen sind rueckwaertskompatibel mit Vue 2.7!

Schritt 2: Build-Toolchain umstellen                 [Voraussetzung: Schritt 1]
           2a. vue-template-compiler → @vue/compiler-sfc
           2b. vue-loader 15 → vue-loader 17
           2c. webpack.config.js anpassen (vue$ Alias entfernen, Plugin-Config)
           2d. Optional: Vite-Migration (empfohlen, aber kann separater Schritt sein)

Schritt 3: @nextcloud/vue upgraden                   [Voraussetzung: Schritt 0 + 2]
           3a. @nextcloud/vue 8.x → 9.x
           3b. Import-Pfade anpassen (37 Komponenten):
               `@nextcloud/vue/dist/Components/NcButton.js`
               → voraussichtlich `@nextcloud/vue` (named exports)
           3c. @nextcloud/dialogs upgraden falls noetig

Schritt 4: Vue 3 mit @vue/compat aktivieren          [Voraussetzung: Schritt 1+2+3]
           4a. vue 2.7 → vue 3.x + @vue/compat
           4b. Entry Points umschreiben: new Vue() → createApp()
           4c. Vue.prototype.t/n → app.config.globalProperties.t/n
           4d. Vue.config.productionTip entfernen
           4e. App im compat-Modus starten, Warnings beheben

Schritt 5: Compat-Modus entfernen                    [Voraussetzung: Schritt 4]
           5a. Alle Deprecation-Warnings behoben
           5b. @vue/compat entfernen, auf reines Vue 3 umstellen
           5c. Vollstaendiger Regressionstest (471+ Vitest, 67 Playwright)

Schritt 6 (Optional): Composition API Migration      [Voraussetzung: Schritt 5]
           Options API funktioniert in Vue 3 weiter.
           Umstellung auf Composition API ist empfohlen aber nicht erzwungen.
           Empfehlung: Nur bei groesseren Refactorings einzelner Komponenten
           auf <script setup> umstellen — kein separater Migrationsdurchlauf.
```

### 2.3 Event Bus Ersatz — Empfehlung

**Empfehlung: provide/inject + eventEmitter-Utility**

| Option | Pro | Contra | Empfehlung |
|--------|-----|--------|------------|
| provide/inject | Vue-nativ, kein Extra-Package | Nur Top-Down, nicht fuer Siblings | Ja, fuer VirtuProf-Kommunikation |
| mitt | Tiny (200B), bekannt, battle-tested | Externe Dependency | Fallback fuer komplexe Cross-Component Events |
| tiny-emitter | Noch kleiner | Weniger verbreitet | Nein |
| Pinia/Vuex Store | Zentraler State | Overkill fuer Event-Kommunikation | Nein (kein State Management noetig) |

**Konkreter Plan:** Die 43 Event-Bus-Stellen betreffen fast ausschliesslich die VirtuProf-Kommunikation (App.vue ↔ VirtuProf.vue ↔ Quiz-Modi). Ein `provide`/`inject`-Pattern mit einer zentralen VirtuProf-Bridge-Funktion ersetzt alle $root.$emit/$on Aufrufe sauber.

### 2.4 Build-Toolchain — Empfehlung

**webpack 5 + vue-loader 17 beibehalten**, nicht zu Vite migrieren.

Begruendung:
- Vite-Migration ist ein eigenstaendiges Projekt (Konfiguration, HMR, Proxy-Setup, NC-Integration)
- webpack 5 funktioniert einwandfrei mit Vue 3 + vue-loader 17
- Nextcloud-Oekosystem bewegt sich Richtung Vite, aber webpack ist noch vollstaendig unterstuetzt
- Vite kann spaeter als separater Optimierungs-Schritt erfolgen

### 2.5 Composition API — Empfehlung

**Nicht als eigenen Migrationsschritt planen.**

Options API wird in Vue 3 vollstaendig unterstuetzt und ist kein Deprecation-Kandidat. Die 75 Options-API-Komponenten funktionieren nach der Migration ohne Aenderung. Composition API/`<script setup>` bei natuerlichen Refactoring-Gelegenheiten einfuehren, nicht als Bulk-Migration.

---

## 3. Risikobewertung

### 3.1 Aufwand pro Komponenten-Gruppe

| Gruppe | Komponenten | Status-Mix | Gesamt-Aufwand | Begruendung |
|--------|------------|------------|----------------|-------------|
| App Shell & Navigation | 5 | 3 anpassbar, 2 kompatibel | M | CourseDetail ist gross (2500 LOC) mit 8x $root.$emit + 3x $set |
| Quiz-Modi | 11 | 7 anpassbar, 4 kompatibel | L | ExamMode (L) und DuelMode (M) sind komplex; viele Event-Bus-Stellen |
| VirtuProf & NOVA | 12 | 1 anpassbar, 11 kompatibel | M | VirtuProf.vue ist der Event-Bus-Hub (7x $on, 7x $off, 3x $set) |
| Campaign & Quest | 8 | 1 anpassbar, 7 kompatibel | S | Nur HackThroughTime hat 1x $set |
| PBQ | 12 | 4 anpassbar, 8 kompatibel | S | Nur $set-Ersetzungen, mechanisch |
| Simulatoren/Tools | 9 | 1 anpassbar, 8 kompatibel | S | Nur SubnetCalculator hat 2x $set |
| Coop/Multiplayer | 3 | 0 anpassbar, 3 kompatibel | S | Keine Aenderungen noetig |
| Admin/Settings/Dialoge | 10 | 5 anpassbar, 5 kompatibel | S | Kleine $set- und $emit-Aenderungen |
| Gamification & UI | 7 | 1 anpassbar, 6 kompatibel | S | Nur CourseMaterials hat $set |
| Entry Points + Build | 5 Dateien | 5 anpassbar | M | webpack.config.js Umbau + 3 Entry Points + Mixin |

**Gesamtaufwand eigener Code: M-L** (schaetzungsweise 5-8 Personentage)

### 3.2 Risiko-Matrix

| Migrationsschritt | Wahrscheinlichkeit Probleme | Auswirkung | Risiko | Mitigierung |
|-------------------|---------------------------|------------|--------|-------------|
| Schritt 0: @nextcloud/vue 9.x warten | Hoch (unklarer Zeitplan) | Blockiert alles | **Kritisch** | Beobachten, NC-Roadmap verfolgen |
| Schritt 1: Event Bus ersetzen | Niedrig | Mittel | **Niedrig** | Rueckwaertskompatibel, testbar auf Vue 2.7 |
| Schritt 1: $set entfernen | Mittel (dynamische Keys) | Niedrig | **Niedrig** | Vue 2.7 Proxy-Reaktivitaet testen |
| Schritt 1: Mixin → Composable | Niedrig | Niedrig | **Minimal** | Trivial, 1 Mixin mit 2 Methoden |
| Schritt 2: Build-Toolchain | Mittel | Hoch (App baut nicht) | **Mittel** | vue-loader 17 Doku folgen, CI-Pipeline |
| Schritt 3: @nextcloud/vue 9.x | Hoch (API-Aenderungen) | Hoch (37 Komponenten) | **Hoch** | Groesster Einzelposten, Import-Pfade + API |
| Schritt 4: vue-compat | Niedrig-Mittel | Mittel | **Mittel** | Offizielles Migration-Tool, Warnings |
| Schritt 5: compat entfernen | Niedrig | Niedrig | **Niedrig** | Schrittweise, mit Tests |

### 3.3 Blocker

| Blocker | Typ | Status | Umgehung |
|---------|-----|--------|----------|
| @nextcloud/vue 9.x nicht released | Extern, harter Blocker | Blockiert | Keine — Migration ohne NC-Vue-Library unmoeglich |
| NC 29 Kompatibilitaet aufgeben | Geschaeftsentscheidung | Offen | @nextcloud/vue 9.x wird NC 29 vermutlich nicht unterstuetzen |
| 37 Komponenten mit NC-Vue Imports | Interner Aufwand | Planbar | Import-Pfad-Migration ist mechanisch (Search & Replace) |

### 3.4 Regressionsrisiko

**Am anfaelligsten:**
1. **VirtuProf-Kommunikation** — Event Bus ist das Nervensystem zwischen Quiz-Modi und VirtuProf. Fehlerhafte Migration bricht alle VirtuProf-Trigger.
2. **ExamMode** — Komplexeste Komponente (1000+ LOC), 7x $root.$emit, 6x $set, Zeitdruck-Logik.
3. **CourseDetail** — Groesste Komponente (2500+ LOC), 8x $root.$emit, 3x $set, viele Tab-Interaktionen.
4. **PbqAuthorTool** — 7x $set in verschachtelten Formulardaten, Reaktivitaets-Fallen moeglich.

### 3.5 Test-Strategie fuer Migration

| Phase | Tests | Wann |
|-------|-------|------|
| **Vor Migration (Schritt 1)** | Vitest-Tests fuer VirtuProf-Bridge schreiben, $set-Ersetzungen Unit-testen | Pflicht |
| **Waehrend Build-Umbau (Schritt 2-3)** | Build-Smoke-Test: `npm run build` muss erfolgreich sein | Pflicht |
| **Nach vue-compat (Schritt 4)** | Alle 471+ Vitest, alle 67 Playwright Checks, manueller Smoke-Test aller Quiz-Modi | Pflicht |
| **Nach compat-Entfernung (Schritt 5)** | Vollstaendiger Regressionstest inkl. TESTPROTOKOLL.md (62 manuelle Checks) | Pflicht |

### 3.6 Zeitschaetzung Gesamtmigration

| Posten | Aufwand |
|--------|---------|
| Schritt 1: Vue-2-Vorbereitungen (Event Bus, $set, Mixin) | S-M (2-3 Tage) |
| Schritt 2: Build-Toolchain | S (1 Tag) |
| Schritt 3: @nextcloud/vue 9.x Migration | L (5-7 Tage) — groesster Posten |
| Schritt 4+5: vue-compat + Cleanup | M (3-4 Tage) |
| Testing + Bugfixing | M (3-5 Tage) |
| **Gesamt** | **L-XL (14-20 Personentage, ~3 Wochen)** |

---

## 4. Go/No-Go Empfehlung

### Empfehlung: Bedingtes Go

**Die Vue 3 Migration ist technisch machbar und strategisch sinnvoll, aber aktuell durch eine externe Abhaengigkeit blockiert.**

### Bedingungen fuer Go

1. **Muss:** @nextcloud/vue 9.x (Vue-3-kompatibel) ist als stabiles Release verfuegbar
2. **Muss:** NC-Mindestversion fuer learning-nc kann auf 31+ angehoben werden (aktuell: 29-31)
3. **Soll:** Kein anderer grosser Feature-Milestone laeuft parallel (Migration braucht ~3 Wochen Fokus)
4. **Soll:** Vitest-Coverage fuer VirtuProf-Kommunikation und ExamMode existiert

### Empfohlener Zeitpunkt

- **Nicht jetzt** — @nextcloud/vue 9.x ist nicht released
- **Fruehestens:** Wenn NC 31 stable + @nextcloud/vue 9.x stable verfuegbar sind
- **Realistisch:** Q3-Q4 2026 (basierend auf NC Release-Zyklen)
- **Als eigener Milestone** — nicht als Teil eines Feature-Milestones

### Sofort-Massnahmen (noch auf Vue 2.7)

Diese Vorbereitungen koennen schon jetzt erfolgen und reduzieren den spaeterern Migrationsaufwand:

1. **Event Bus durch provide/inject ersetzen** (Schritt 1a) — rueckwaertskompatibel
2. **hintMixin zu useHint() Composable umbauen** (Schritt 1b) — rueckwaertskompatibel mit Vue 2.7
3. **Keine neuen `$set()`, `$root.$emit`, oder Mixin-Patterns einfuehren** — Coding Guidelines anpassen
4. **NC-Vue-3-Roadmap beobachten** — GitHub nextcloud/vue Repository + NC Server Releases verfolgen

### Alternative bei No-Go (Vue 2.7 LTS Strategie)

Falls @nextcloud/vue 9.x sich weiter verzoegert oder NC die Vue 2 Unterstuetzung laenger beibehaelt:

- Vue 2.7 ist die letzte Vue-2-Version mit Backported Vue-3-Features (Composition API, `<script setup>`, defineComponent)
- Vue 2 EOL war 31.12.2023, aber Sicherheitsrisiko ist gering da die App in einer kontrollierten NC-Umgebung laeuft (kein oeffentliches Internet)
- Options API Code bleibt wartbar und funktional
- Hauptrisiko: Veraltende Ecosystem-Packages (vue-loader 15, eslint-plugin-vue Support fuer Vue 2)

### Fazit

Die Codebase ist in gutem Zustand fuer eine Vue 3 Migration: keine Showstopper-Patterns, 70% kompatibel ohne Aenderungen, mechanisch migrierbare Breaking Changes. Der einzige echte Blocker ist @nextcloud/vue 9.x. Sobald dieser verfuegbar ist, kann die Migration in ~3 Wochen durchgefuehrt werden — vorausgesetzt die Sofort-Massnahmen (Event Bus, Mixin) werden vorher umgesetzt.
