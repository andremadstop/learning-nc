# Simulator design system — the Learning tools

> The binding design specification for all eight network simulators.
> Aesthetic: **terminal noir** — dark first, monospace accents, subtle glow effects.
> Not generic, not colourful, not AI slop. The target is a professional network tool that
> happens to be fun to use.

## 1. Design philosophy

**Guiding idea**: every simulator should feel like a real CLI or dashboard tool you would find
in a SOC or NOC — with the approachability of a learning app.

**Tone**: dark, technical, precise. Cyan as the accent, for the network association. No
white-on-white, no pastels, no "friendly learning app" styling. Controlled information density
with a clear hierarchy instead.

**Differentiating the simulators**: not by colour — they all share one palette — but through
**icon, subtitle and tool-specific visualisations**. A DNS resolver shows a lookup chain, a port
scanner shows scan progress, but both use the same cards, buttons and tables.

## 2. The shared BEM prefix

**Every simulator uses `sim-tool__`** as its BEM prefix. Not `dns-tool__`, not `subnet-tool__`.

```css
.sim-tool { }              /* wrapper */
.sim-tool--embedded { }    /* embedded in a campaign */
.sim-tool__header { }      /* header: eyebrow plus title */
.sim-tool__eyebrow { }     /* "CompTIA Network+ N10-009" or "Tools" */
.sim-tool__title { }       /* "DNS-Resolver" */
.sim-tool__subtitle { }    /* description */
.sim-tool__tabs { }        /* Simulator | Practice tabs */
.sim-tool__tab { }         /* a single tab */
.sim-tool__tab--active { } /* the active tab */
.sim-tool__panel { }       /* content area */
.sim-tool__section { }     /* a section inside a panel */
.sim-tool__card { }        /* result card */
.sim-tool__table { }       /* data table */
.sim-tool__row { }         /* table row */
.sim-tool__row--highlight { } /* highlighted row */
.sim-tool__row--success { }   /* green */
.sim-tool__row--danger { }    /* red */
.sim-tool__input { }       /* input field */
.sim-tool__btn { }         /* primary button */
.sim-tool__btn--secondary { } /* secondary button */
.sim-tool__badge { }       /* status badge */
.sim-tool__status { }      /* status indicator */
.sim-tool__status--pass { }
.sim-tool__status--fail { }
.sim-tool__status--warn { }
```

## 3. Colour palette (built on the existing --lnc-* tokens)

```css
/* Simulator-specific tokens — these extend the base set, they do not replace it */
:root {
  /* Simulator-Flaechen */
  --sim-bg: var(--lnc-surface-dark);          /* #0D1117 */
  --sim-panel: var(--lnc-surface);            /* #161B22 */
  --sim-panel-elevated: #1C2333;              /* slightly lighter, for cards */
  --sim-border: var(--lnc-border-hard);       /* #30363D */

  /* Simulator-Akzente */
  --sim-accent: var(--lnc-cyan);              /* #58A6FF — primary */
  --sim-accent-dim: rgba(88, 166, 255, 0.15); /* Glow, Hover-BG */
  --sim-success: var(--lnc-green);            /* #00E676 */
  --sim-danger: var(--lnc-danger);            /* #F85149 */
  --sim-warn: var(--lnc-amber);              /* #D29922 */

  /* Simulator-Text */
  --sim-text: var(--lnc-text-light);          /* #C9D1D9 */
  --sim-text-muted: var(--lnc-text-muted);    /* #8B949E */
  --sim-text-mono: 'JetBrains Mono', 'Fira Code', 'Cascadia Code', monospace;

  /* status glow, for the pass/fail animations */
  --sim-glow-pass: 0 0 12px rgba(0, 230, 118, 0.3);
  --sim-glow-fail: 0 0 12px rgba(248, 81, 73, 0.3);
  --sim-glow-accent: 0 0 12px rgba(88, 166, 255, 0.2);
}
```

**Light mode**: simulators stay dark ALWAYS. That is deliberate — a terminal or dashboard reads better dark. Nextcloud's light mode
only changes the frame around them, never the simulators themselves. When Nextcloud is in dark
mode, the simulators blend straight in.

## 4. Typography

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

/* tables and data: always monospace */
.sim-tool__table td,
.sim-tool__card code,
.sim-tool__input {
  font-family: var(--sim-text-mono);
  font-size: 0.8125rem;
}
```

## 5. Component specification

## 5.0 The SubnetCalculator exception

`SubnetCalculator.vue` keeps its existing `subnet-tool__` class prefix for now, but draws its
colours, text hierarchy and backgrounds from the same `--sim-*` tokens as the other simulators.
The existing markup stays stable while the appearance stays consistent with terminal noir.

### 5.1 Header (every simulator)

```
┌──────────────────────────────────────────────────────┐
│  COMPTIA NETWORK+ N10-009        ← Eyebrow (cyan)   │
│  DNS-Resolver                    ← Title (mono)      │
│  Follow the lookup chain...      ← Subtitle (muted)  │
│                                                       │
│  [Simulator]  [Practice]        ← Tabs               │
└──────────────────────────────────────────────────────┘
```

- Eyebrow: always "CompTIA Network+ N10-009" or "CompTIA Security+ SY0-701"
- No "Phase 64" badge — that is internal planning, of no interest to the user
- No explain-mode toggle in the header; it is relevant only to the subnet calculator, where it stays

### 5.2 Tabs (Simulator | Practice)

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

### 5.3 Cards (results, information)

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

### 5.4 Tables (firewall, routing, NAT, ports)

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

### 5.6 Input fields

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

### 5.7 Status indicators

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

### 5.8 Animations

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

## 6. Tools-tab navigation (App.vue)

The current `course-sub-nav` with eight text buttons is too cramped. The new design:

```
┌──────────────────────────────────────────────────────────┐
│  🔢 Subnetz  🌐 DNS  🛡️ Firewall  🔍 Ports            │
│  🗺️ Routing  🔄 NAT  📡 Wireshark  🔐 802.1X          │
└──────────────────────────────────────────────────────────┘
```

- Icons plus a short label
- A two-row grid (4×2) instead of a single flex row
- Active tab: cyan glow plus a bottom border
- Inactive tabs: muted text, no background

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
    /* stays 4x2 on mobile too, just with tighter padding */
  }
  .sim-nav__item {
    padding: 8px 4px;
    font-size: 0.6875rem;
  }
}
```

## 7. Simulator-specific elements

These differ per simulator but are built from the shared token system:

| Simulator | Distinctive element | Design |
|-----------|--------------------|--------|
| DNS | Lookup chain (arrow animation) | Horizontal boxes joined by arrows, cyan glow on the active step |
| Firewall | Rule table plus packet test | Table with a drag handle (≡ icon on the left), no up/down buttons |
| Port scanner | Scan animation | Progress bar, results fading in row by row |
| Routing | Longest prefix match | Matching routes highlighted green, the winning route glowing |
| NAT | Packet transformation | A left/right diagram with the address rewrite in the middle |
| Wireshark | Packet layers | Nested collapsible boxes, colour-coded per layer |
| 802.1X | Sequence diagram | A vertical timeline with three actors and animated arrows |

## 8. Migration checklist

For each simulator:

- [ ] Switch the BEM prefix to `sim-tool__`
- [ ] Replace every inline style with a CSS class
- [ ] Replace every hard-coded colour with a `--sim-*` token
- [ ] Header: eyebrow, title and subtitle, consistently
- [ ] Tabs: the `sim-tool__tabs` pattern
- [ ] Tables: the `sim-tool__table` pattern
- [ ] Buttons: the `sim-tool__btn` pattern — no Nextcloud `NcButton` inside a simulator
- [ ] Inputs: the `sim-tool__input` pattern
- [ ] Status: the `sim-tool__status--pass/fail/warn` pattern
- [ ] Animations: respect `prefers-reduced-motion`
- [ ] Dark mode: the simulator always stays dark
- [ ] Mobile: tables scroll horizontally, inputs go full width
- [ ] Remove any "Phase XX" badge — internal planning, of no interest to the user

## 9. Do not change

- The SubnetCalculator explain-mode toggle, which is specific to that simulator
- The SubnetCalculator toggle presets (all / beginner / advanced)
- The practice-mode logic — the `practiceEngine` pattern stays
- The embedded-mode API (`:scenario` plus `$emit`)
