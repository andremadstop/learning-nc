# Art Style Guide — VirtuProf Archetypes (v4.4.0+)

> **Purpose:** Operational art-direction spec for all character SVG work in v4.4.0 and beyond.
> **Audience:** Illustrators and developers producing SVG assets (Phase 152 SCHOLAR-01/02/03).
> **Status:** Normative — Phase 152 SVG-Freeze is gated by Section 5 sign-off.
> **Pairs with:** `.planning/CHARACTER_BIBLE.md` (tonality + behaviour for the 12 existing characters).

---

## 1. Style-Regime pro Category

All characters in the app fall into exactly one of three categories. The style is not a preference — it is load-bearing for legal, a11y, and respectful-representation reasons.

| Category | Style | Rationale |
|----------|-------|-----------|
| **NOVA (hero)** | Futuristic chibi / particle-heavy — unchanged from v4.0.0 | Fictional AR entity, no real-world reference. Continuity with existing users. |
| **Prof. Lern Classic** | Chibi / rounded / friendly — restoration of v2.6.1 visual identity | Fictional character, no real-world reference. Nostalgic restoration. |
| **Archetype-Scholars** (Der Theoretiker, Der Kosmologe, Der Astrophysik-Popularisierer) | **Semi-realistic / illustrated** — NOT chibi | Identity-sensitive archetypes that reference real-world physicist typologies. Chibi would trivialize disability / race / historical gravitas. |

### Core Rule

> **Semi-realistic illustration style is mandatory for identity-sensitive characters. Chibi is reserved for fictional-only characters with no real-world reference.**

Chibi exaggerates single features for cuteness. When a single feature is a disability or a race marker, "cute chibi" becomes caricature (see Section 3). The archetype-scholars therefore use a proportionate, semi-realistic register — closer to a portrait-illustration than a mascot.

---

## 2. Per-Archetype Palette + Silhouette

Each archetype has: defining features, palette, pose, drawing order (when relevant), and a concrete No-Go list. Archetype names are the only permissible identifier — real-person names must not appear anywhere (code, comments, metadata, file names, commit messages, design-tool layer names).

### 2.1 Der Theoretiker

**Defining features:**
- Wild curly hair, bushy moustache
- Cardigan-era wardrobe: warm brown + off-white layered knitwear
- Thoughtful, slightly hunched stance

**Palette:**
- Primary: warm amber / brown (knitwear base)
- Secondary: muted cream (shirt, undertones)
- Accent: chalkboard-green (books, background elements)

**Pose:**
- Standing, slightly hunched, contemplative
- Gaze forward or slightly down — thinking posture
- Hands: near chin, holding a book, or resting on jacket pocket

**No-Go (concrete):**
- ❌ Tongue-out poses (leaks a specific famous photograph)
- ❌ `E=mc²` pattern anywhere on clothing, hat, pin, or background
- ❌ Chalkboard formulae that mimic the well-known lecture-hall photograph
- ❌ Any resemblance to a specific historical portrait or photograph
- ❌ Name cues anywhere — no "Albert", no initials on a blackboard

### 2.2 Der Kosmologe

**Defining features:**
- Glasses
- Seated posture, upright and engaged
- Wheelchair is present as **ONE of several details** — never the defining trait
- Blue-palette wardrobe

**Palette:**
- Primary: cool blue (sweater or shirt)
- Secondary: muted grey (trousers, wheelchair frame)
- Accent: subtle teal (background, highlights)

**Pose:**
- Seated, upright, engaged — intellectually active, NOT "sad lonely wheelchair user"
- Gaze forward or toward an imagined listener
- Hands visible, gesturing or resting naturally

**Character-first drawing order (mandatory):**
1. Clothing
2. Pose + body proportion
3. Face + expression
4. Hair
5. **Wheelchair — LAST**

Draw the character as a full person first. The wheelchair is equipment, not identity. This ordering is the single most important rule in this document (CHI 2024 research: "They only care to show us the wheelchair").

**No-Go (concrete):**
- ❌ **Animated wheelchair** — no rolling, no wheelie, no "rolling-comedy" frame. This is the most important single No-Go in the entire document.
- ❌ Sad-lonely-pose framing
- ❌ Voice-synthesizer visual cue (no speech-synth box, no robotic-voice metaphor)
- ❌ Any animation that treats the wheelchair as a comic prop
- ❌ "Hawking-inspired" / "ALS-inspired" / any real-person reference in comments, layer names, commit messages, file names, or metadata
- ❌ Wheelchair as the visual anchor (largest / brightest / most detailed element)
- ❌ Name cues anywhere — no initials, no book titles, no lecture-title references

### 2.3 Der Astrophysik-Popularisierer

**Defining features:**
- Standing, confident posture
- Goatee (generic style — NOT a specific real-person's cut)
- Vest (generic — NOT a specific real-person's signature pattern or lapel)
- Star-glow background accent (magenta-violet nebula)

**Palette:**
- Primary: magenta (vest or shirt accent)
- Secondary: violet (background, star-glow)
- Accent: warm star-glow — purple-to-magenta gradient
- Skin tones rendered from reference photos for proportion; semi-realistic (NEVER exaggerated)

**Pose:**
- Standing, confident, arms-open or teaching gesture
- Gaze forward, engaged, warm
- Stance wide enough to read as "communicator", not "lecturer behind podium"

**No-Go (concrete):**
- ❌ StarTalk-style typography anywhere near or on the character
- ❌ Cosmos (the show) apparel references or iconography
- ❌ Signature vest-pattern that identifies a specific real person (striped lapels, specific pocket placement)
- ❌ Exaggerated racial features — over-dark skin tone, over-sharp jaw / cheekbones, over-large facial features
- ❌ Reference actual portrait photos for **proportion**, then render semi-realistically — never copy or trace
- ❌ Name cues anywhere — no "Neil", no "deGrasse", no podcast/show titles in background

---

## 3. Universal No-Gos

These apply to ALL archetype-scholar characters and to any future character referencing a real-world identity type.

- ❌ **Chibi style for any scholar-category character** — chibi is restricted to NOVA and Prof. Lern Classic.
- ❌ **Racial exaggeration** in any character — reference real photos for proportion, render semi-realistically, no single-feature hyperbole.
- ❌ **Photographic reference copying** — no traceable poses, no copied clothing patterns, no recreated hairstyling of a specific real person. Reference = inspiration-for-proportion, never transfer.
- ❌ **Endorsement-indicia** — no named books, signature vest patterns, catchphrases on background, book-spine titles, or any visual element that triggers "this is X-person-endorsed".
- ❌ **Real-person names anywhere** — layer names, file names, commit messages, code comments, SVG metadata, design-tool annotations. Archetype labels only.
- ❌ **`scaleX(-1)` on the character body in RTL layouts** — mirror the UI chrome (arrows, speech-bubble pointers), never the character itself. Character faces forward or has an RTL-specific pose variant. (Pitfall #15)
- ❌ **`<title>` element inside the SVG root or any child** — screen-readers dutifully announce every `<title>`, causing audio spam on state changes. Designer-tool exports must be stripped. (Pitfall #9)
- ❌ **Inline `<script>` elements inside any SVG** — security vector, out-of-scope for custom-upload v5.x discussions.
- ❌ **`<foreignObject>` elements inside any SVG** — security vector (HTML/script injection surface).
- ❌ **External `xlink:href` references** in SVG — data-exfiltration / network-fingerprint vector.
- ❌ **`onXXX=` event-handler attributes** inside any SVG.

**Sanitization step (Phase 152 CI):** every authored SVG runs through `svgo` with plugins that strip `<title>`, `<script>`, `<foreignObject>`, and `on*` attributes. No exceptions.

---

## 4. Animation Constraints (foreshadows Phase 150)

The animation primitive developed in Phase 150 will enforce these constraints globally. Archetype-scholar SVGs authored in Phase 152 must already comply so the primitive does not need defensive logic.

- **Idle loops: `transform` and `opacity` only** — GPU-composited, off main thread. Never animate `width`, `height`, `top`, `left`, `filter`, `box-shadow`, or any paint-triggering property inside idle loops.
- **Respect `prefers-reduced-motion: reduce`** — render static pose, no motion, no scale bobbing. CSS `@media (prefers-reduced-motion: reduce) { animation: none; }` for every keyframe. JS-driven animations gate on `window.matchMedia('(prefers-reduced-motion: reduce)').matches`.
- **`IntersectionObserver`-pause off-screen** — when the avatar is not intersecting the viewport, pause animations. When `document.visibilityState === 'hidden'`, pause. Use `@vueuse/core` helpers (`useIntersectionObserver`, `useDocumentVisibility`) — already a project dep.
- **Avatar SVG root: `role="img"` + static `aria-label`** — label set once per character, localized. Never tie ARIA announcements to animation state. Do not announce "blinking" / "thinking" per frame.
- **`aria-hidden="true"` + `focusable="false"`** on purely decorative header/corner placements; `role="img"` + static label only where the character *represents* the speaker (chat interface, VirtuProf fullscreen).
- **Animation-count budget per archetype: 3 minimum** (idle/blink, wave, celebrate) — reference ANIM-05 in Phase 152 requirements. Additional animations may be authored but must pass the same constraints.
- **No manual `setInterval` / `requestAnimationFrame`** — use `@vueuse/core` composables (`useIntervalFn`, `useRafFn`) that auto-cleanup on unmount. (Pitfall #12)
- **`will-change: transform`** hint on the animated root element — prevents paint thrash on first animation trigger.
- **No animation-tied state transitions on `aria-label`** — screen readers must hear the same label in every animation state.

---

## 5. Sensitivity-Review-Gate

Phase 152 SVG-Freeze is **blocked** until all three conditions below are satisfied and documented in `.planning/sensitivity-review/SIGNOFF.md`:

- ✅ **External sensitivity-review signed off** by a DE-market reviewer with disability-representation expertise (via Leidmedien.de / Sozialhelden e.V. or equivalent). Sign-off covers both Der Kosmologe (disability representation) and Der Astrophysik-Popularisierer (racial representation).
- ✅ **All Universal No-Gos (Section 3) confirmed absent** in the reviewed draft. Reviewer-checklist or explicit written statement required.
- ✅ **Palette + silhouette per archetype matches Section 2 spec** — confirmed by the Phase 152 executor before commit, and by the reviewer before sign-off.

**Process:**

1. Illustrator produces concept-art for all three archetypes.
2. Author / maintainer runs sanitization + No-Go self-check against Section 3.
3. Concept-art submitted to sensitivity-reviewer (`REQUEST.md` template).
4. Reviewer returns signed-off versions or annotated change requests.
5. Changes integrated. If non-trivial, loop back to step 2.
6. Final SVGs committed only after `SIGNOFF.md` is in place.

**Hard constraint:** no SVG for Der Kosmologe or Der Astrophysik-Popularisierer ships to `main`, DevCloud, or the App Store without an entry in `.planning/sensitivity-review/SIGNOFF.md` for that archetype.

---

## Cross-References

- **Character tonality and behaviour** (existing 12 characters): `.planning/CHARACTER_BIBLE.md`
- **Legal analysis and trademark rationale:** `.planning/LEGAL.md`
- **Pitfall research (disability caricature #7, RTL #15, a11y #9, animation budget #4):** `.planning/research/PITFALLS.md`
- **Phase 149 context and decisions:** `.planning/phases/149-legal-art-direction/149-CONTEXT.md`
- **CI guard for forbidden names:** `scripts/check-forbidden-names.sh`

---

*Art Style Guide v1.0 — created in Phase 149 Plan 03 — 2026-04-18*
