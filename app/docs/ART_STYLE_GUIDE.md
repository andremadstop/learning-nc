# Art Style Guide — VirtuProf Archetypes (v4.4.0+)

> **Purpose:** Operational art-direction spec for all character SVG work in v4.4.0 and beyond.
> **Audience:** Illustrators and developers producing SVG assets (Phase 152 SCHOLAR-01/02/03).
> **Status:** Normative — Phase 152 SVG-Freeze is gated by Section 5 internal review.
> **Pairs with:** `.planning/CHARACTER_BIBLE.md` (tonality + behaviour for the 12 existing characters).

---

## 1. Style-Regime pro Category

Alle Charaktere teilen eine konsistente **Comic-Superhero-Ästhetik**. Wissenschaftler-Archetypen sind nicht semi-realistische Portraits, sondern stilisierte "Science Heroes" — inspiriert vom Empowerment-Narrativ, nicht von Mitleid oder dokumentarischer Treue.

| Category | Style | Rationale |
|----------|-------|-----------|
| **NOVA (hero)** | Futuristic chibi / particle-heavy — unchanged from v4.0.0 | AR-Entity, keine reale Referenz, bestehende User-Kontinuität |
| **Prof. Lern Classic** | Chibi / rounded / friendly — Restoration v2.6.1 | Fictional, keine reale Referenz, Nostalgie |
| **Archetype-Scholars** (Theoretiker / Kosmologe / Astrophysik-Popularisierer) | **Comic-Superhero / stylized-empowerment** | Heroic personas, nicht Portraits. Visual Language orientiert sich an Pixar / Into the Spider-Verse / Invincible — nicht an Wikipedia-Fotos. |

### Core Rule

> **Alle drei Scholar-Archetypen werden als Science-Heroes stilisiert: leicht exaggerierte Proportionen, dynamische Posen, Energy-Effekte, Palette mit Glow-Anteil. Keine semi-realistischen Portraits.**

Diese Regel löst die frühere Empfehlung „semi-realistic für identity-sensitive characters" ab. Begründung: Empowerment-Narrativ (besonders für den Kosmologen) ist stärker als dokumentarische Respekts-Darstellung. Ein cooler Rollstuhl mit Raketenantrieb kommuniziert „Held mit Super-Tool", nicht „Opfer einer Krankheit".

---

## 2. Per-Archetype Palette + Silhouette

Jeder Archetyp hat: Defining Features, Palette, Pose, Power-Element, konkrete No-Go-Liste. Archetype-Labels sind die einzigen erlaubten Identifier — reale Namen dürfen nirgends auftauchen (Code, Kommentare, Metadata, File-Names, Commit-Messages, Design-Tool-Layer-Names).

### 2.1 Der Theoretiker

**Defining Features:**
- Wildes, elektrostatisch abstehendes graues Haar (leichter Energie-Effekt)
- Buschiger Schnurrbart
- Cardigan mit leuchtendem Saum-Akzent

**Power-Element:**
- **Kreide-Energie**: schwebende Formel-Symbole um die Hände (abstrakte Glyphen, keine echten Formeln wie E=mc²)
- Optional: Haar-Glow wenn er "denkt"

**Palette:**
- Primär: warmes Bernstein / Braun (Cardigan-Base)
- Sekundär: Cream / Off-White (Shirt)
- Accent: elektrisches Gelb-Grün (Energie, Formel-Glyphen)

**Pose:**
- Stehend, leicht nach vorne gelehnt, Hände gestikulieren
- Gaze fokussiert-intensiv, nicht hunched-sad
- Energetische Ausstrahlung — "Aha-Moment in Aktion"

**No-Go (concrete):**
- ❌ Tongue-out Pose (leaks berühmtes Foto)
- ❌ `E=mc²` oder andere echte Formeln lesbar auf Kleidung/Tafel
- ❌ Tafelformeln die bekannte Lecture-Fotos imitieren
- ❌ Name-Cues: kein "Albert", keine Initialen, keine Jahreszahl-Referenzen
- ❌ Passive-introvertierte Hunched-Pose → muss energisch wirken

### 2.2 Der Kosmologe

**Defining Features:**
- Brille mit subtilem Blauglühen
- **Raketenrollstuhl**: Rollstuhl mit sichtbaren Thrustern, Energie-Spuren, optional Schwebe-Effekt
- Aufrechte, zugewandte Haltung
- Blaue Signature-Palette mit Kosmos-Akzenten

**Power-Element:**
- **Raketenantrieb am Rollstuhl**: Thruster-Glow am Heck, optional Schwebe-Partikel
- Energie-Aura um die Figur (leichter Saum)
- Optional: Stern-Muster-Projektion aus den Händen

**Palette:**
- Primär: tiefes Kosmos-Blau (Shirt, Rollstuhl-Frame)
- Sekundär: Silber-Grau (Thruster, Trousers)
- Accent: Cyan-Glow (Thruster-Flammen, Brillen-Reflexion, Aura)

**Pose:**
- Sitzend, upright, engaged — aktiv-heroisch, nie passiv-leidend
- Arms open, gestikulierend oder Energy-Projektion
- Gaze forward, confident
- Rollstuhl darf dynamisch gezeigt werden (leicht angewinkelt, "im Flug", Thruster an)

**Power-First Drawing Order (mandatory):**
1. Charakter-Körper + Pose
2. Gesicht + Ausdruck
3. Haare + Brille
4. Rollstuhl inkl. Raketenantrieb
5. Energie-Effekte (Aura, Thruster-Glow, Partikel)

Der Rollstuhl ist **heroisches Super-Tool, kein neutrales medizinisches Gerät**. Er soll als Teil der Identität sichtbar sein — aber als Empowerment-Element, nicht als Passiv-Symbol.

**No-Go (concrete):**
- ❌ **Sad-lonely-pose-Framing** — Figur muss aktiv/heroisch wirken
- ❌ Voice-Synthesizer-Visual-Cue (keine Sprach-Synthese-Box, keine robotische-Stimme-Metapher)
- ❌ Comic-Prop-Rollstuhl (Wheelie als Joke, Slapstick-Animation) — Raketen ja, Slapstick nein
- ❌ "Hawking-inspired" / "ALS-inspired" in Kommentaren, Layer-Namen, Commit-Messages, File-Names, Metadata
- ❌ Name-Cues: keine Initialen, keine Buchtitel, keine Lecture-Title-Referenzen
- ❌ Medical-Device-Ästhetik (kein "Hospital-Look") — der Raketenstuhl ist Sci-Fi-Gear, nicht Krankenhaus-Equipment

### 2.3 Der Astrophysik-Popularisierer

**Defining Features:**
- Stehend, confident, einladende Haltung
- Kinnbart (generisch, nicht spezifisches Real-Person-Cut)
- Weste mit Sternen-Muster oder Kosmos-Print
- Magenta-Violett Signature mit starkem Stern-Glow

**Power-Element:**
- **Kosmos-Projektion aus den Händen** — Mini-Galaxie oder Sternen-Nebel schwebt vor den Fingern
- Umhang-Artige Weste die leicht flattert / nebelartig verläuft
- Optional: konstellations-artige Linien im Hintergrund

**Palette:**
- Primär: Magenta (Weste)
- Sekundär: Violett (Background, Star-Glow, Umhang)
- Accent: warmes Gold / Weiß (Stern-Highlights, Nebel-Schein)
- Haut-Töne: stylized-saturated (Comic-Book-Stil), NICHT exaggerated-realistic

**Pose:**
- Stehend, Arme offen, erklärend-einladend
- Gaze forward, warm-charismatisch
- Stance weit genug für "Kosmos-Kommunikator", nicht "hinter Podium stehender Dozent"

**No-Go (concrete):**
- ❌ "StarTalk"-artige Typografie irgendwo auf/bei der Figur
- ❌ "Cosmos" (die Show) Apparel-Referenzen, Logo, Iconografie
- ❌ Signature-Westen-Muster einer spezifischen realen Person (Streifen-Lapel, bestimmte Pocket-Platzierung)
- ❌ Racial Exaggeration (übertrieben-dunkler Hautton, over-sharp Kiefer/Wangenknochen, over-large Features)
- ❌ Reale Portrait-Fotos tracen oder kopieren — Referenz ist Comic-Ästhetik (Spider-Verse, Pixar), nicht Wikipedia
- ❌ Name-Cues: kein "Neil", kein "deGrasse", keine Podcast/Show-Titel im Background

---

## 3. Universal No-Gos

Gelten für ALLE Charaktere (NOVA, Prof. Lern Classic, alle 3 Scholars + zukünftige Archetypen).

- ❌ **Reale Namen irgendwo** — Layer-Names, File-Names, Commit-Messages, Code-Kommentare, SVG-Metadata, Design-Tool-Annotations. Archetype-Labels only.
- ❌ **Racial Exaggeration** — Haut-Ton-Saturierung im Comic-Stil OK, aber keine karikaturhaften Proportionen. Comic ≠ Karikatur.
- ❌ **Photographic Reference Copying** — keine tracebaren Posen, keine kopierten Kleidungs-Muster, keine nachgestellten Hairstylings spezifischer realer Personen. Reference = inspiration für Proportion, niemals Transfer.
- ❌ **Endorsement-Indicia** — keine Named-Books, Signature-Vest-Muster, Catchphrases, Buchrücken-Titel, oder visuelle Elemente die "X-Person-endorsed" triggern.
- ❌ **`scaleX(-1)` auf Charakter-Body in RTL-Layouts** — UI-Chrome (Pfeile, Sprechblasen-Pointer) spiegeln, nicht den Charakter. Charakter hat RTL-spezifische Pose-Variante falls nötig. (Pitfall #15)
- ❌ **`<title>`-Element im SVG-Root oder Child** — Screen-Reader spammen das. Designer-Tool-Exports müssen gestrippt werden. (Pitfall #9)
- ❌ **Inline `<script>`-Elemente in SVG** — Security-Vektor.
- ❌ **`<foreignObject>`-Elemente in SVG** — Security-Vektor (HTML-Injection-Surface).
- ❌ **Externe `xlink:href`-Referenzen** — Data-Exfiltration / Network-Fingerprint.
- ❌ **`onXXX=`-Event-Handler-Attribute** in SVG.

**Sanitization-Schritt (Phase 152 CI):** jede authored SVG läuft durch `svgo` mit Plugins die `<title>`, `<script>`, `<foreignObject>` und `on*`-Attribute strippen. Keine Ausnahmen.

---

## 4. Animation Constraints (foreshadows Phase 150)

Die Animation-Primitive aus Phase 150 erzwingt diese Constraints global. Scholar-SVGs in Phase 152 müssen bereits compliant sein.

- **Idle-Loops: `transform` und `opacity` only** — GPU-composited. Niemals `width`, `height`, `top`, `left`, `filter`, `box-shadow` oder andere paint-triggernde Props in Idle-Loops.
- **Respect `prefers-reduced-motion: reduce`** — statische Pose, keine Motion, keine Scale-Bobbing. CSS `@media (prefers-reduced-motion: reduce) { animation: none; }` pro Keyframe. JS-Animations gaten auf `window.matchMedia('(prefers-reduced-motion: reduce)').matches`.
  - **Besonders wichtig:** Thruster-Glow beim Kosmologen = animated → muss bei reduced-motion zu statischem Glow werden (Opacity-only, kein Flicker).
- **`IntersectionObserver`-Pause off-screen** — wenn Avatar nicht im Viewport, pausieren. `document.visibilityState === 'hidden'` → pausieren. `@vueuse/core` Helpers.
- **Avatar SVG Root: `role="img"` + statisches `aria-label`** — Label einmal pro Charakter, localized. Niemals ARIA-Announcements an Animation-State koppeln.
- **`aria-hidden="true"` + `focusable="false"`** bei rein dekorativen Platzierungen; `role="img"` + statisches Label wo Charakter den Speaker repräsentiert.
- **Animation-Count-Budget pro Archetyp: 3 Minimum** (idle/blink, wave, celebrate) — ANIM-05 in Phase 152. Power-Effekte (Thruster, Kosmos-Projektion, Energy-Aura) zählen als zusätzliche Animationen, müssen dieselben Constraints erfüllen.
- **Kein manuelles `setInterval` / `requestAnimationFrame`** — `@vueuse/core` Composables (`useIntervalFn`, `useRafFn`) mit Auto-Cleanup.
- **`will-change: transform`** Hint am animierten Root-Element.
- **Keine Animation-tied State-Transitions auf `aria-label`** — Screen-Reader hört in jedem Animation-State dasselbe Label.

---

## 5. Internal Review Gate

Phase 152 SVG-Freeze ist **geblockt** bis alle drei Bedingungen erfüllt und in `.planning/sensitivity-review/SIGNOFF.md` dokumentiert sind:

- ✅ **Internal Review durchgeführt** (Projekt-Owner + Co-Review). Fokus: Empowerment-Narrativ, Racial-Darstellung, Archetype-Konsistenz.
- ✅ **Alle Universal No-Gos (Section 3) confirmed absent** im Review-Draft. Checklist-Eintrag pro Archetyp.
- ✅ **Palette + Silhouette + Power-Element pro Archetyp matches Section 2** — bestätigt vom Phase 152 Executor vor Commit.

**Process:**

1. Illustrator produziert Concept-Art für alle 3 Archetypen im Comic-Superhero-Stil
2. Maintainer läuft Sanitization + No-Go-Self-Check gegen Section 3
3. Internal Review (Andre + Tools-Review): Empowerment-Narrativ-Check, Racial-Check, Stil-Konsistenz
4. Änderungen integriert. Bei non-trivial: loop zu Schritt 2
5. Finale SVGs committed erst nach `SIGNOFF.md`-Eintrag

**Review-Checklist (je Archetyp):**
- [ ] Archetype-Label only — keine realen Namen im File/Layer/Metadata
- [ ] Power-Element sichtbar und positiv konnotiert (nicht Mitleid/Comedy)
- [ ] Palette matched Section 2 Vorgabe
- [ ] Pose ist aktiv-heroisch, nicht passiv-leidend
- [ ] Alle Universal No-Gos absent
- [ ] SVG sanitized (svgo Pass 1)
- [ ] Reduced-Motion-Fallback definiert
- [ ] aria-label statisch + lokalisiert

**Hard Constraint:** keine SVG für einen Scholar-Archetyp shipt zu `main`, DevCloud, oder App Store ohne entsprechenden Eintrag in `.planning/sensitivity-review/SIGNOFF.md`.

**Budget-Entscheidung:** Projekt-Owner hat entschieden, kein externes Sensitivity-Review zu beauftragen. Review-Verantwortung liegt intern. Rationale: Die Empowerment-Comic-Ästhetik (Raketenrollstuhl, Kosmos-Projektion, Energy-Effekte) ist ein klarer Bruch zur realistisch-portraithaften Darstellung, die die ursprünglichen Sensitivity-Risiken produziert hätte. Comic-Superhero-Stil hat andere Risiko-Profile (Karikatur, Racial-Stereotype), die durch Internal Review + Universal-No-Gos + Comic-Book-Precedent abgedeckt sind.

---

## Cross-References

- **Character tonality and behaviour** (existing 12 characters): `.planning/CHARACTER_BIBLE.md`
- **Legal analysis and trademark rationale:** `.planning/LEGAL.md`
- **Pitfall research (Racial-Stereotypes, RTL, A11y, Animation-Budget):** `.planning/research/PITFALLS.md`
- **Phase 149 context and decisions:** `.planning/phases/149-legal-art-direction/149-CONTEXT.md`
- **CI guard for forbidden names:** `scripts/check-forbidden-names.sh`

---

*Art Style Guide v2.0 — updated in Phase 149 after Empowerment-Comic pivot — 2026-04-18*
*v1.0 (2026-04-18) was semi-realistic; v2.0 switched to Comic-Superhero nach User-Entscheidung*
