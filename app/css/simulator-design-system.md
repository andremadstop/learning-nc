# Simulator Design System — Learning-NC Werkzeuge

> Verbindliche Design-Spezifikation fuer alle 8 Netzwerk-Simulatoren.
> Aesthetik: **Terminal Noir** — Dark-First, monospace Akzente, subtile Glow-Effekte.
> Nicht generisch, nicht bunt, nicht "KI-Slop". Sondern: wie ein professionelles Netzwerk-Tool das Spass macht.

## 1. Design-Philosophie

**Leitidee**: Jeder Simulator fuehlt sich an wie ein echtes CLI/Dashboard-Tool das man in einem SOC oder NOC finden wuerde — aber mit der Zugaenglichkeit einer Lern-App.

**Ton**: Dunkel, technisch, praezise. Cyan als Akzent (Netzwerk-Assoziation). Kein Weiss-auf-Weiss, kein Pastell, kein "freundliches" Lernapp-Design. Stattdessen: kontrollierte Informationsdichte mit klarer Hierarchie.

**Differenzierung zwischen Simulatoren**: Nicht durch Farbe (alle nutzen dasselbe Palette), sondern durch **Icon + Untertitel + Tool-spezifische Visualisierungen**. Ein DNS-Resolver zeigt eine Lookup-Kette, ein Port-Scanner zeigt einen Scan-Fortschritt — aber beide nutzen dieselben Cards, Buttons und Tabellen.

## 2. Gemeinsamer BEM-Prefix

**ALLE Simulatoren nutzen `sim-tool__`** als BEM-Prefix. Kein `dns-tool__`, kein `subnet-tool__`.

```css
.sim-tool { }              /* Wrapper */
.sim-tool--embedded { }    /* Embedded in Kampagne */
.sim-tool__header { }      /* Header mit Eyebrow + Titel */
.sim-tool__eyebrow { }     /* "CompTIA Network+ N10-009" oder "Werkzeuge" */
.sim-tool__title { }       /* "DNS-Resolver" */
.sim-tool__subtitle { }    /* Beschreibung */
.sim-tool__tabs { }        /* Simulator | Uebung Tabs */
.sim-tool__tab { }         /* Einzelner Tab */
.sim-tool__tab--active { } /* Aktiver Tab */
.sim-tool__panel { }       /* Content-Bereich */
.sim-tool__section { }     /* Abschnitt innerhalb Panel */
.sim-tool__card { }        /* Ergebnis-Card */
.sim-tool__table { }       /* Datentabelle */
.sim-tool__row { }         /* Tabellenzeile */
.sim-tool__row--highlight { } /* Hervorgehobene Zeile */
.sim-tool__row--success { }   /* Gruen */
.sim-tool__row--danger { }    /* Rot */
.sim-tool__input { }       /* Eingabefeld */
.sim-tool__btn { }         /* Primaer-Button */
.sim-tool__btn--secondary { } /* Sekundaer-Button */
.sim-tool__badge { }       /* Status-Badge */
.sim-tool__status { }      /* Status-Indikator */
.sim-tool__status--pass { }
.sim-tool__status--fail { }
.sim-tool__status--warn { }
```

## 3. Farbpalette (nutzt bestehende --lnc-* Tokens)

```css
/* Simulator-spezifische Tokens (ergaenzen, nicht ersetzen) */
:root {
  /* Simulator-Flaechen */
  --sim-bg: var(--lnc-surface-dark);          /* #0D1117 */
  --sim-panel: var(--lnc-surface);            /* #161B22 */
  --sim-panel-elevated: #1C2333;              /* leicht heller fuer Cards */
  --sim-border: var(--lnc-border-hard);       /* #30363D */

  /* Simulator-Akzente */
  --sim-accent: var(--lnc-cyan);              /* #58A6FF — Primaer */
  --sim-accent-dim: rgba(88, 166, 255, 0.15); /* Glow, Hover-BG */
  --sim-success: var(--lnc-green);            /* #00E676 */
  --sim-danger: var(--lnc-danger);            /* #F85149 */
  --sim-warn: var(--lnc-amber);              /* #D29922 */

  /* Simulator-Text */
  --sim-text: var(--lnc-text-light);          /* #C9D1D9 */
  --sim-text-muted: var(--lnc-text-muted);    /* #8B949E */
  --sim-text-mono: 'JetBrains Mono', 'Fira Code', 'Cascadia Code', monospace;

  /* Status-Glow (fuer Pass/Fail Animationen) */
  --sim-glow-pass: 0 0 12px rgba(0, 230, 118, 0.3);
  --sim-glow-fail: 0 0 12px rgba(248, 81, 73, 0.3);
  --sim-glow-accent: 0 0 12px rgba(88, 166, 255, 0.2);
}
```

**Light Mode**: Simulatoren bleiben IMMER dunkel. Das ist bewusst — ein Terminal/Dashboard sieht in Dark besser aus. NC Light Mode aendert nur den Rahmen drumherum, nicht die Simulatoren selbst. Falls NC Dark Mode an ist, fuegen sich die Simulatoren nahtlos ein.

## 4. Typografie

```css
.sim-tool__title {
  font-family: var(--sim-text-mono);
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--sim-text);
  letter-spacing: -0.02em;
}

.sim-tool__eyebrow {
  font-family: var(--sim-text-mono);
  font-size: 0.7rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--sim-accent);
  margin-bottom: 4px;
}

.sim-tool__subtitle {
  font-size: 0.875rem;
  color: var(--sim-text-muted);
  max-width: 600px;
}

/* Tabellen und Daten: immer Monospace */
.sim-tool__table td,
.sim-tool__card code,
.sim-tool__input {
  font-family: var(--sim-text-mono);
  font-size: 0.8125rem;
}
```

## 5. Komponenten-Spezifikation

## 5.0 SubnetCalculator Ausnahme

Der `SubnetCalculator.vue` behaelt vorerst seinen bestehenden `subnet-tool__` Klassen-Prefix, nutzt aber fuer Farben, Text-Hierarchie und Hintergruende dieselben `--sim-*` Tokens wie die uebrigen Simulatoren. So bleibt die bestehende Markup-Struktur stabil, waehrend das visuelle Erscheinungsbild mit dem Terminal-Noir-System konsistent bleibt.

### 5.1 Header (jeder Simulator)

```
┌──────────────────────────────────────────────────────┐
│  COMPTIA NETWORK+ N10-009        ← Eyebrow (cyan)   │
│  DNS-Resolver                    ← Title (mono)      │
│  Verfolge die Lookup-Kette...    ← Subtitle (muted)  │
│                                                       │
│  [Simulator]  [Uebung]          ← Tabs               │
└──────────────────────────────────────────────────────┘
```

- Eyebrow: Immer "CompTIA Network+ N10-009" oder "CompTIA Security+ SY0-701"
- Kein "Phase 64" Badge — das ist internes GSD, nicht User-relevant
- Kein Erklaer-Modus Toggle im Header (nur beim Subnetzrechner relevant, dort belassen)

### 5.2 Tabs (Simulator | Uebung)

```css
.sim-tool__tabs {
  display: flex;
  gap: 2px;
  background: var(--sim-border);
  border-radius: var(--lnc-radius-sm);
  padding: 2px;
  width: fit-content;
  margin-bottom: var(--lnc-space-xl);
}

.sim-tool__tab {
  padding: 8px 20px;
  border: none;
  background: transparent;
  color: var(--sim-text-muted);
  font-family: var(--sim-text-mono);
  font-size: 0.8125rem;
  font-weight: 500;
  cursor: pointer;
  border-radius: 6px;
  transition: all 0.15s ease;
}

.sim-tool__tab--active {
  background: var(--sim-accent-dim);
  color: var(--sim-accent);
  box-shadow: var(--sim-glow-accent);
}
```

### 5.3 Cards (Ergebnisse, Informationen)

```css
.sim-tool__card {
  background: var(--sim-panel-elevated);
  border: 1px solid var(--sim-border);
  border-radius: var(--lnc-radius-md);
  padding: var(--lnc-space-lg) var(--lnc-space-xl);
  margin-bottom: var(--lnc-space-lg);
}

.sim-tool__card--glow {
  border-color: var(--sim-accent);
  box-shadow: var(--sim-glow-accent);
}

.sim-tool__card--success {
  border-left: 3px solid var(--sim-success);
}

.sim-tool__card--danger {
  border-left: 3px solid var(--sim-danger);
}
```

### 5.4 Tabellen (Firewall, Routing, NAT, Ports)

```css
.sim-tool__table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-family: var(--sim-text-mono);
  font-size: 0.8125rem;
}

.sim-tool__table thead th {
  background: var(--sim-panel);
  color: var(--sim-text-muted);
  font-weight: 600;
  text-transform: uppercase;
  font-size: 0.6875rem;
  letter-spacing: 0.08em;
  padding: 10px 12px;
  border-bottom: 1px solid var(--sim-border);
  text-align: left;
  position: sticky;
  top: 0;
}

.sim-tool__table tbody td {
  padding: 8px 12px;
  border-bottom: 1px solid rgba(48, 54, 61, 0.5);
  color: var(--sim-text);
}

.sim-tool__table tbody tr:hover {
  background: var(--sim-accent-dim);
}

/* Firewall: Allow = gruen, Deny = rot */
.sim-tool__row--allow td:nth-child(2) { color: var(--sim-success); }
.sim-tool__row--deny td:nth-child(2) { color: var(--sim-danger); }
.sim-tool__row--implicit {
  opacity: 0.5;
  font-style: italic;
}

/* Matched Rule Highlight */
.sim-tool__row--matched {
  background: var(--sim-accent-dim) !important;
  border-left: 3px solid var(--sim-accent);
}
```

### 5.5 Buttons

```css
.sim-tool__btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  font-family: var(--sim-text-mono);
  font-size: 0.8125rem;
  font-weight: 600;
  border: 1px solid var(--sim-accent);
  border-radius: var(--lnc-radius-sm);
  background: var(--sim-accent-dim);
  color: var(--sim-accent);
  cursor: pointer;
  transition: all 0.15s ease;
}

.sim-tool__btn:hover {
  background: var(--sim-accent);
  color: var(--sim-bg);
  box-shadow: var(--sim-glow-accent);
}

.sim-tool__btn--secondary {
  border-color: var(--sim-border);
  background: transparent;
  color: var(--sim-text-muted);
}

.sim-tool__btn--secondary:hover {
  border-color: var(--sim-text-muted);
  color: var(--sim-text);
}

.sim-tool__btn--danger {
  border-color: var(--sim-danger);
  color: var(--sim-danger);
  background: rgba(248, 81, 73, 0.1);
}

/* Firewall Up/Down: Compact Icon-Buttons statt runde Kreise */
.sim-tool__btn--icon {
  padding: 4px 8px;
  font-size: 0.75rem;
  border-radius: 4px;
}
```

### 5.6 Eingabefelder

```css
.sim-tool__input {
  padding: 8px 12px;
  background: var(--sim-bg);
  border: 1px solid var(--sim-border);
  border-radius: var(--lnc-radius-sm);
  color: var(--sim-text);
  font-family: var(--sim-text-mono);
  font-size: 0.8125rem;
  transition: border-color 0.15s ease;
}

.sim-tool__input:focus {
  border-color: var(--sim-accent);
  outline: none;
  box-shadow: var(--sim-glow-accent);
}

.sim-tool__input--error {
  border-color: var(--sim-danger);
}

.sim-tool__input--valid {
  border-color: var(--sim-success);
}

.sim-tool__select {
  /* Gleich wie input, plus custom dropdown arrow */
  appearance: none;
  padding-right: 32px;
  background-image: url("data:image/svg+xml,...chevron...");
  background-repeat: no-repeat;
  background-position: right 10px center;
}
```

### 5.7 Status-Indikatoren

```css
.sim-tool__status {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 999px;
  font-family: var(--sim-text-mono);
  font-size: 0.75rem;
  font-weight: 600;
}

.sim-tool__status--pass {
  background: rgba(0, 230, 118, 0.12);
  color: var(--sim-success);
}

.sim-tool__status--fail {
  background: rgba(248, 81, 73, 0.12);
  color: var(--sim-danger);
}

.sim-tool__status--warn {
  background: rgba(210, 153, 34, 0.12);
  color: var(--sim-warn);
}

.sim-tool__status--info {
  background: var(--sim-accent-dim);
  color: var(--sim-accent);
}

/* Dot-Indikator (vor Port-Status, Scan-Ergebnis etc.) */
.sim-tool__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  display: inline-block;
}
.sim-tool__dot--open { background: var(--sim-success); box-shadow: var(--sim-glow-pass); }
.sim-tool__dot--closed { background: var(--sim-danger); }
.sim-tool__dot--filtered { background: var(--sim-warn); }
```

### 5.8 Animationen

```css
/* Scan-Fortschritt (Port-Scanner) */
@keyframes sim-scan-line {
  from { width: 0%; }
  to { width: 100%; }
}

.sim-tool__progress {
  height: 3px;
  background: var(--sim-border);
  border-radius: 2px;
  overflow: hidden;
  margin: var(--lnc-space-md) 0;
}

.sim-tool__progress-bar {
  height: 100%;
  background: linear-gradient(90deg, var(--sim-accent), var(--sim-success));
  border-radius: 2px;
  animation: sim-scan-line 2s ease-out;
}

/* Paket-Flow (Firewall, Routing, NAT) */
@keyframes sim-packet-flow {
  0% { opacity: 0; transform: translateX(-20px); }
  20% { opacity: 1; }
  80% { opacity: 1; }
  100% { opacity: 0; transform: translateX(20px); }
}

/* Zeile einblenden (Tabellen-Ergebnis) */
@keyframes sim-row-in {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}

.sim-tool__table tbody tr {
  animation: sim-row-in 0.2s ease;
}

/* Reduced Motion */
@media (prefers-reduced-motion: reduce) {
  .sim-tool__progress-bar { animation: none; }
  .sim-tool__table tbody tr { animation: none; }
}
```

## 6. Werkzeuge-Tab Navigation (App.vue)

Die aktuelle `course-sub-nav` mit 8 Text-Buttons ist zu eng. Neues Design:

```
┌──────────────────────────────────────────────────────────┐
│  🔢 Subnetz  🌐 DNS  🛡️ Firewall  🔍 Ports            │
│  🗺️ Routing  🔄 NAT  📡 Wireshark  🔐 802.1X          │
└──────────────────────────────────────────────────────────┘
```

- Icons + Kurzlabel
- 2 Zeilen Grid (4x2) statt 1 Zeile Flex
- Aktiver Tab: Cyan-Glow + Border-Bottom
- Inaktive Tabs: Muted Text, kein Background

```css
.sim-nav {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 2px;
  background: var(--sim-panel);
  border-radius: var(--lnc-radius-md);
  padding: 4px;
  margin-bottom: var(--lnc-space-xl);
}

.sim-nav__item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 10px 8px;
  border: none;
  background: transparent;
  color: var(--sim-text-muted);
  font-size: 0.75rem;
  font-weight: 500;
  cursor: pointer;
  border-radius: var(--lnc-radius-sm);
  transition: all 0.15s ease;
}

.sim-nav__item--active {
  background: var(--sim-accent-dim);
  color: var(--sim-accent);
}

.sim-nav__icon {
  font-size: 1.25rem;
}

@media (max-width: 600px) {
  .sim-nav {
    grid-template-columns: repeat(4, 1fr);
    /* Bleibt 4x2 auch mobil, nur kleinere Padding */
  }
  .sim-nav__item {
    padding: 8px 4px;
    font-size: 0.6875rem;
  }
}
```

## 7. Simulator-spezifische Elemente

Diese Elemente sind per Simulator unterschiedlich, nutzen aber das gemeinsame Token-System:

| Simulator | Spezial-Element | Design |
|-----------|----------------|--------|
| DNS | Lookup-Kette (Pfeil-Animation) | Horizontale Boxen mit Pfeilen, Cyan-Glow bei aktivem Schritt |
| Firewall | Regeltabelle + Paket-Test | Tabelle mit Drag-Handle (≡ Icon links), kein Up/Down Button |
| Port-Scanner | Scan-Animation | Progress-Bar + zeilenweises Einblenden der Ergebnisse |
| Routing | Longest Prefix Match | Matching-Routen gruen hervorgehoben, Gewinner-Route mit Glow |
| NAT | Paket-Transformation | Links/Rechts-Diagramm mit Adress-Aenderung in der Mitte |
| Wireshark | Paket-Schichten | Verschachtelte aufklappbare Boxen, Farbkodiert pro Layer |
| 802.1X | Sequenzdiagramm | Vertikale Timeline mit 3 Akteuren, animierte Pfeile |

## 8. Migration-Checkliste

Fuer jeden Simulator:

- [ ] BEM-Prefix auf `sim-tool__` umstellen
- [ ] Alle Inline-Styles durch CSS-Klassen ersetzen
- [ ] Alle Hardcoded-Farben durch `--sim-*` Tokens ersetzen
- [ ] Header: Eyebrow + Titel + Subtitle (einheitlich)
- [ ] Tabs: `sim-tool__tabs` Pattern
- [ ] Tabellen: `sim-tool__table` Pattern
- [ ] Buttons: `sim-tool__btn` Pattern (keine NC NcButton in Simulatoren)
- [ ] Inputs: `sim-tool__input` Pattern
- [ ] Status: `sim-tool__status--pass/fail/warn` Pattern
- [ ] Animationen: `prefers-reduced-motion` respektieren
- [ ] Dark-Mode: Simulator bleibt immer dunkel
- [ ] Mobile: Tabelle horizontal scrollbar, Inputs full-width
- [ ] "Phase XX" Badge entfernen (internes GSD, nicht User-relevant)

## 9. Nicht aendern

- SubnetCalculator Erklaer-Modus Toggle (spezifisch fuer diesen Simulator)
- SubnetCalculator Toggle-Presets (Alle/Anfaenger/Fortgeschritten)
- Uebungsmodus-Logik (practiceEngine Pattern bleibt)
- Embedded-Mode API (:scenario + $emit)
