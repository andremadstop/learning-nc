# Art Style Guide — VirtuProf Archetypes (v4.4.0+)

> **Purpose:** Operational art-direction spec for all character SVG work in v4.4.0 and beyond.
> **Audience:** Illustrators and developers producing SVG assets (Phase 152 SCHOLAR-01/02/03).
> **Status:** Normative — the Phase 152 SVG freeze is gated by the Section 5 internal review.
> **Pairs with:** `.planning/CHARACTER_BIBLE.md` (tonality and behaviour for the 12 existing characters).

---

## 1. Style regime per category

All characters share a consistent **comic-superhero aesthetic**. The scholar archetypes are not
semi-realistic portraits but stylised "science heroes" — drawn from an empowerment narrative,
not from pity or documentary likeness.

| Category | Style | Rationale |
|----------|-------|-----------|
| **NOVA (hero)** | Futuristic chibi, particle-heavy — unchanged since v4.0.0 | An AR entity with no real-world referent; preserves continuity for existing users |
| **Prof. Lern Classic** | Chibi, rounded, friendly — restored in v2.6.1 | Fictional, no real-world referent, nostalgia |
| **Archetype scholars** (theorist / cosmologist / astrophysics populariser) | **Comic superhero, stylised empowerment** | Heroic personas, not portraits. The visual language follows Pixar / Into the Spider-Verse / Invincible — never Wikipedia photographs. |

### Core rule

> **All three scholar archetypes are stylised as science heroes: slightly exaggerated
> proportions, dynamic poses, energy effects, a palette with a glow component. No
> semi-realistic portraits.**

This rule supersedes the earlier recommendation of "semi-realistic for identity-sensitive
characters". The reasoning: an empowerment narrative — particularly for the cosmologist — is
stronger than a respectful documentary depiction. A cool rocket-powered wheelchair communicates
"hero with a super-tool", not "victim of an illness".

---

## 2. Palette and silhouette per archetype

Every archetype has defining features, a palette, a pose, a power element and a concrete list of
things not to do. Archetype labels are the only permitted identifiers — real names must appear
nowhere: not in code, comments, metadata, file names, commit messages, or design-tool layer
names.

### 2.1 The theorist

**Defining features**

- Wild grey hair standing up as if electrostatically charged (light energy effect)
- Bushy moustache
- Cardigan with a glowing hem accent

**Power element**

- **Chalk energy**: formula symbols floating around the hands — abstract glyphs, never real
  formulae such as E=mc²
- Optional: the hair glows while he is "thinking"

**Palette**

- Primary: warm amber / brown (cardigan base)
- Secondary: cream / off-white (shirt)
- Accent: electric yellow-green (energy, formula glyphs)

**Pose**

- Standing, leaning slightly forward, hands gesturing
- Focused, intense gaze — not hunched and sad
- Energetic presence: an "aha moment" in progress

**Never (concrete)**

- ❌ Tongue-out pose — leaks the famous photograph
- ❌ `E=mc²` or any other real formula legible on clothing or a blackboard
- ❌ Blackboard formulae that imitate known lecture photographs
- ❌ Name cues: no "Albert", no initials, no year references
- ❌ A passive, introverted, hunched pose — the figure must read as energetic

### 2.2 The cosmologist

**Defining features**

- Glasses with a subtle blue glow
- **Rocket wheelchair**: a wheelchair with visible thrusters, energy trails, optionally hovering
- Upright, engaged posture
- Blue signature palette with cosmic accents

**Power element**

- **Rocket propulsion on the wheelchair**: thruster glow at the rear, optional hover particles
- An energy aura around the figure (light rim)
- Optional: a star-pattern projection from the hands

**Palette**

- Primary: deep cosmic blue (shirt, wheelchair frame)
- Secondary: silver-grey (thrusters, trousers)
- Accent: cyan glow (thruster flames, lens reflection, aura)

**Pose**

- Seated, upright, engaged — actively heroic, never passively suffering
- Arms open, gesturing or projecting energy
- Gaze forward, confident
- The wheelchair may be shown dynamically: slightly angled, "in flight", thrusters lit

**Power-first drawing order (mandatory)**

1. Character body and pose
2. Face and expression
3. Hair and glasses
4. Wheelchair including the rocket propulsion
5. Energy effects (aura, thruster glow, particles)

The wheelchair is a **heroic super-tool, not a neutral medical device**. It should be visible as
part of the identity — as an element of empowerment, never as a symbol of passivity.

**Never (concrete)**

- ❌ **A sad, lonely framing** — the figure must read as active and heroic
- ❌ A voice-synthesiser visual cue: no speech-synthesis box, no robotic-voice metaphor
- ❌ A comedy-prop wheelchair (wheelies as a joke, slapstick animation). Rockets yes, slapstick no
- ❌ "Hawking-inspired" or "ALS-inspired" in comments, layer names, commit messages, file names
  or metadata
- ❌ Name cues: no initials, no book titles, no lecture-title references
- ❌ Medical-device aesthetics — no "hospital look". The rocket chair is science-fiction gear,
  not hospital equipment

### 2.3 The astrophysics populariser

**Defining features**

- Standing, confident, inviting posture
- A chin beard — generic, not a specific real person's cut
- A waistcoat with a star pattern or cosmic print
- Magenta-violet signature with a strong star glow

**Power element**

- **A cosmos projection from the hands** — a miniature galaxy or star nebula floating in front of
  the fingers
- A cape-like waistcoat that flutters slightly or dissolves into nebula
- Optional: constellation-like lines in the background

**Palette**

- Primary: magenta (waistcoat)
- Secondary: violet (background, star glow, cape)
- Accent: warm gold / white (star highlights, nebula sheen)
- Skin tones: stylised-saturated in the comic-book manner, NOT exaggerated-realistic

**Pose**

- Standing, arms open, explaining and inviting
- Gaze forward, warm and charismatic
- A stance wide enough for a "communicator of the cosmos", not a lecturer behind a podium

**Never (concrete)**

- ❌ "StarTalk"-style typography anywhere on or near the figure
- ❌ References to *Cosmos* (the show): apparel, logo, iconography
- ❌ The signature waistcoat pattern of a specific real person (striped lapel, particular pocket
  placement)
- ❌ Racial exaggeration: an overly dark skin tone, over-sharp jaw or cheekbones, oversized features
- ❌ Tracing or copying real portrait photographs — the reference is comic aesthetics
  (Spider-Verse, Pixar), not Wikipedia
- ❌ Name cues: no "Neil", no "deGrasse", no podcast or show titles in the background

---

## 3. Universal prohibitions

These apply to EVERY character — NOVA, Prof. Lern Classic, all three scholars, and any future
archetype.

- ❌ **Real names anywhere** — layer names, file names, commit messages, code comments, SVG
  metadata, design-tool annotations. Archetype labels only.
- ❌ **Racial exaggeration** — saturated skin tones in the comic style are fine, caricatured
  proportions are not. Comic ≠ caricature.
- ❌ **Copying photographic references** — no traceable poses, no copied clothing patterns, no
  recreated hairstyles of specific real people. A reference informs proportion; it is never
  transferred.
- ❌ **Endorsement indicia** — no named books, signature waistcoat patterns, catchphrases, book
  spine titles, or any visual element that suggests "endorsed by person X".
- ❌ **`scaleX(-1)` on a character body in RTL layouts** — mirror the UI chrome (arrows, speech
  bubble pointers), never the character. Give the character an RTL-specific pose variant if one
  is needed. (Pitfall #15)
- ❌ **A `<title>` element in the SVG root or any child** — screen readers announce it
  repeatedly. Exports from design tools must be stripped. (Pitfall #9)
- ❌ **Inline `<script>` elements in SVG** — a security vector.
- ❌ **`<foreignObject>` elements in SVG** — a security vector (HTML injection surface).
- ❌ **External `xlink:href` references** — data exfiltration and network fingerprinting.
- ❌ **`onXXX=` event-handler attributes** in SVG.

**Sanitisation step (Phase 152 CI):** every authored SVG passes through `svgo` with plugins that
strip `<title>`, `<script>`, `<foreignObject>` and `on*` attributes. No exceptions.

---

## 4. Animation constraints (anticipating Phase 150)

The animation primitive from Phase 150 enforces these constraints globally. Scholar SVGs in
Phase 152 must already comply.

- **Idle loops: `transform` and `opacity` only** — GPU composited. Never `width`, `height`,
  `top`, `left`, `filter`, `box-shadow` or any other paint-triggering property in an idle loop.
- **Respect `prefers-reduced-motion: reduce`** — a static pose, no motion, no scale bobbing. Add
  `@media (prefers-reduced-motion: reduce) { animation: none; }` per keyframe set, and gate JS
  animations on `window.matchMedia('(prefers-reduced-motion: reduce)').matches`.
  - **Particularly important:** the cosmologist's thruster glow is animated and must degrade to a
    static glow under reduced motion — opacity only, no flicker.
- **Pause off-screen with `IntersectionObserver`** — pause when the avatar leaves the viewport,
  and when `document.visibilityState === 'hidden'`. Use the `@vueuse/core` helpers.
- **Avatar SVG root: `role="img"` plus a static `aria-label`** — one localised label per
  character. Never tie ARIA announcements to animation state.
- **`aria-hidden="true"` plus `focusable="false"`** for purely decorative placements;
  `role="img"` plus a static label wherever the character represents the speaker.
- **Animation budget per archetype: three minimum** (idle/blink, wave, celebrate) — ANIM-05 in
  Phase 152. Power effects (thrusters, cosmos projection, energy aura) count as additional
  animations and must satisfy the same constraints.
- **No hand-rolled `setInterval` or `requestAnimationFrame`** — use the `@vueuse/core`
  composables (`useIntervalFn`, `useRafFn`), which clean up after themselves.
- **`will-change: transform`** as a hint on the animated root element.
- **No animation-tied state transitions on `aria-label`** — a screen reader must hear the same
  label in every animation state.

---

## 5. Internal review gate

The Phase 152 SVG freeze is **blocked** until all three conditions are met and recorded in
`.planning/sensitivity-review/SIGNOFF.md`:

- ✅ **Internal review completed** (project owner plus a second reviewer), focused on the
  empowerment narrative, racial depiction, and archetype consistency.
- ✅ **Every universal prohibition in Section 3 confirmed absent** in the review draft, with a
  checklist entry per archetype.
- ✅ **Palette, silhouette and power element match Section 2** for each archetype, confirmed by
  the Phase 152 executor before committing.

**Process**

1. The illustrator produces concept art for all three archetypes in the comic-superhero style.
2. The maintainer runs sanitisation and the Section 3 self-check.
3. Internal review (owner plus tooling review): empowerment narrative, racial depiction, style
   consistency.
4. Changes are integrated. If they are non-trivial, return to step 2.
5. Final SVGs are committed only after the entry in `SIGNOFF.md` exists.

**Review checklist (per archetype)**

- [ ] Archetype label only — no real names in the file, layers or metadata
- [ ] Power element visible and positively framed (not pity, not comedy)
- [ ] Palette matches the Section 2 specification
- [ ] Pose is actively heroic, not passively suffering
- [ ] All universal prohibitions absent
- [ ] SVG sanitised (svgo pass 1)
- [ ] Reduced-motion fallback defined
- [ ] `aria-label` static and localised

**Hard constraint:** no SVG for a scholar archetype ships to `main`, to a live instance, or to
the App Store without a corresponding entry in `.planning/sensitivity-review/SIGNOFF.md`.

**Budget decision:** the project owner decided against commissioning an external sensitivity
review; responsibility rests with the internal review. The reasoning: the empowerment-comic
aesthetic (rocket wheelchair, cosmos projection, energy effects) is a clean break from the
realistic portrait depiction that would have produced the original sensitivity risks. The
comic-superhero style carries a different risk profile — caricature and racial stereotype —
which the internal review, the universal prohibitions and established comic-book precedent
cover.

---

## Cross-references

- **Character tonality and behaviour** (the existing 12 characters): `.planning/CHARACTER_BIBLE.md`
- **Legal analysis and trademark rationale:** `.planning/LEGAL.md`
- **Pitfall research** (racial stereotypes, RTL, accessibility, animation budget): `.planning/research/PITFALLS.md`
- **Phase 149 context and decisions:** `.planning/phases/149-legal-art-direction/149-CONTEXT.md`
- **CI guard for forbidden names:** `scripts/check-forbidden-names.sh`

---

*Art Style Guide v2.0 — updated in Phase 149 after the empowerment-comic pivot — 2026-04-18*
*v1.0 (2026-04-18) was semi-realistic; v2.0 switched to comic-superhero after the owner's decision*
