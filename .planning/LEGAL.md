# Legal & Trademark Analysis — Learning-NC v4.4.0

**Version:** 1.0
**Created:** 2026-04-18
**Scope:** Character & Personality milestone (v4.4.0)
**Status:** Authoritative — this document is the single source of truth for the Archetype-Naming decision.

<!-- LEGAL-EXCEPTION: This document discusses trademarked names (Einstein, Hawking, Tyson, Cosmos, StarTalk)
     for legal analysis and audit purposes. These names must NOT appear in production code
     (app/src/, app/l10n/, CHANGELOG.md, appinfo/info.xml). The CI-guard
     scripts/check-forbidden-names.sh enforces this by excluding .planning/ from its scope
     and by filtering out any line containing the `LEGAL-EXCEPTION` marker. See Chapter 5. -->

---

## 1. Executive Summary / Decision

**Decision (locked, v4.4.0):** Learning-NC ships with three stylized **Archetype**-Presets that are explicitly NOT named after real persons — living or deceased:

1. **Der Theoretiker** — stilisierter Archetyp mit wildem Haar und Cardigan-Palette.
2. **Der Kosmologe** — stilisierter Archetyp mit Brille und Rollstuhl, blaue Palette.
3. **Der Astrophysik-Popularisierer** — stilisierter Archetyp mit Kinnbart, Weste und magenta-violettem Sternen-Glow.

Named-person presets (Einstein / Hawking / Tyson or any variant thereof) are **OUT OF SCOPE** for v4.4.0. Any future reintroduction of named presets would require:

- a formal legal review (documented in an updated version of this file), **AND**
- a signed license agreement with the relevant trademark holder / estate / living subject, **AND**
- a scope change approved at project-owner level before any art production begins.

The decision was driven by three specific legal hazards documented in detail in Chapter 2 (Einstein trademark / Hawking Estate trademark / Tyson right-of-publicity) plus the disability-representation risk covered by the separate Sensitivity-Review workflow (Phase 149-04 + Phase 152 gate). The Archetype-Naming decision eliminates all three legal exposures simultaneously while preserving the emotional payoff of "familiar physicist-type mentor figures" that motivated the milestone.

This decision is enforced in code by `scripts/check-forbidden-names.sh` (Gate #6 in `.git/hooks/pre-push`, delivered in Plan 149-01). See Chapter 5.

---

## 2. Trademark-Analyse

This chapter documents the three specific trademark / publicity-rights hazards that drove the Archetype-Naming decision. All three must be understood together; each alone would be sufficient grounds to avoid the respective name.

### 2.1 Einstein — USPTO Reg. #3591305 (Hebrew University of Jerusalem, ACTIVE)

**Primary holder:** The Hebrew University of Jerusalem holds the active U.S. trademark registration **USPTO Reg. #3591305** for the mark "ALBERT EINSTEIN" in multiple classes covering educational goods and services. This trademark is actively enforced by the Einstein Foundation and GreenLight Brand Management, who jointly operate the commercial licensing program (reported ~$10-12.5M/year in licensing revenue).

**Publicity-rights status (separate from trademark):** In a 2022 California federal court ruling (Hebrew University of Jerusalem v. General Press Publishing — see Chapter 3 for detail), post-mortem right of publicity for Einstein was held to have **expired in 2005** (50 years after his 1955 death, under California's retrospective application of its statutory limit). **Critical caveat:** this ruling addressed right-of-publicity only. It did **NOT** extinguish the trademark registration, which remains in force today.

**German / EU angle:**
- **KUG §22 (Kunsturheberrechtsgesetz)** governs post-mortem `Recht am eigenen Bild` in Germany and extends 10 years after death. Einstein died in 1955 → image-right expired in DE in 1965.
- However, the USPTO trademark has EU-equivalent registrations through the Einstein Foundation's global licensing footprint.
- Learning-NC is distributed via the Nextcloud App Store (global audience) and runs on Hetzner VPS in Germany with international users (DevCloud Security+ cohort includes FR/RU/AR speakers). **A conservative, globally-defensible posture is required.**

**Enforcement reality:** The Einstein Foundation + GreenLight Brand Management are documented as aggressive enforcers. They send cease-and-desist letters even in cases where the target has a defensible legal position, because the cost of defending (€10-50k+ legal fees) often exceeds the cost of compliance. For an open-source AGPL-3.0 project with no legal budget, even a successfully-defended claim would be project-fatal.

**Granddaughter claim:** Evelyn Einstein (granddaughter) has publicly disputed the Hebrew University's licensing authority, adding a potential second claimant pool. This is not a reason to assume "no one holds the rights" — it is a reason to assume **multiple parties might claim the rights**.

**Verdict:** Named "Einstein" preset would require a signed license from the Einstein Foundation (budget + paperwork + 4-8 weeks minimum response time) before any art production. Out of scope for v4.4.0.

### 2.2 Hawking — USPTO Reg. #5980163 (Stephen Hawking Estate, ACTIVE)

**Primary holder:** The Stephen Hawking Estate (represented by United Agents LLP) holds the active U.S. trademark registration **USPTO Reg. #5980163** for the mark "STEPHEN HAWKING". Estate contact: **info@stephenhawking.org.uk**.

**Estate posture — explicit and published:** The Stephen Hawking Foundation publishes a trademark policy that requires **prior written contact for ANY use of the name, likeness, or reputation — commercial OR non-commercial**. The estate has publicly stated that "educational use" does NOT exempt third parties from this requirement.

**Sound-mark:** Hawking's synthesized speech voice is separately registered as a sound-mark. Even voice-mimicry or TTS voice-matching would trigger an additional trademark hazard.

**Commercial status of Learning-NC:** The app is listed on **apps.nextcloud.com/apps/learning** (v4.2.2 live as of 2026-04-13). App Store distribution is unambiguously commercial for trademark-law purposes, regardless of the AGPL-3.0 license or $0 price tag. Any "non-commercial use" defense is not available.

**Enforcement reality:** United Agents LLP handles the IP portfolio. A takedown letter from them would force an emergency App Store unlisting (cert revocation possible), emergency code rollback, and public-facing incident disclosure. For an open-source project distributed through Nextcloud's App Store, this is a reputational catastrophe scenario.

**Contact budget (informational only, NOT a planned path):** Should a future milestone ever revisit a named Hawking preset, the estimated minimum cost is: 4-8 weeks response time, written permission required, no guarantee of approval. Out of scope for v4.4.0.

**Verdict:** Named "Hawking" preset is not viable without written Estate permission secured in writing before art production. Out of scope for v4.4.0.

### 2.3 Tyson — Living Public Figure (Right-of-Publicity, no registration possible)

**Subject:** Neil deGrasse Tyson is a living public figure. Right-of-publicity protection is in **full force today** (unlike deceased persons where the right fades or expires). Right of publicity is strongest in **California** (Civil Code §3344) and **New York** (Civil Rights Law §50-51), both jurisdictions relevant to Tyson's business operations (StarTalk productions, Hayden Planetarium / American Museum of Natural History).

**Scope of right-of-publicity:** Covers commercial use of name, likeness, voice, and **identifiable persona indicia**. "Identifiable persona indicia" is a doctrine that extends protection beyond literal likeness to signature visual markers — e.g. the combination of vest, bald head, goatee, and galactic/astronomy-themed styling that reads unambiguously as "Tyson" to a reasonable consumer.

**Existing litigation history:** Tyson has existing IP litigation history (notably the StarTalk portrait case) establishing that his agents actively enforce persona rights.

**No parody defense available:** In Learning-NC, the mentor avatar is used as a **sincere** learning guide — not parody, not criticism, not commentary. First Amendment parody defenses require the use to be transformative commentary ON the subject. "Cute cartoon mentor in the style of Tyson" is the textbook case where parody defense fails.

**Trademark layer:** Tyson and his businesses (StarTalk) hold additional trademark registrations in entertainment / education classes. Even without the right-of-publicity claim, trademark confusion doctrine would cover "StarTalk"-like branding in an educational app.

**Verdict:** No named "Tyson" preset is legally viable without a signed license agreement with Tyson or his agents. Realistic success probability of obtaining such a license for an open-source IT-certification app: near zero. Out of scope for v4.4.0.

**Rule for all living public figures (locked):** Learning-NC does not ship named presets referencing living persons. Period. This rule is broader than Tyson-specific and covers all future hypothetical named presets (Marie Curie — deceased, but estate + name in trademark; Jane Goodall — living, right-of-publicity active; Katherine Johnson — deceased 2020, estate rights active). All would require the same license-or-anonymize evaluation.

---

## 3. Right-of-Publicity-Ruling 2022

**Case:** Hebrew University of Jerusalem v. General Press Publishing (C.D. Cal., 2022).

**Holding:** The California federal court held that post-mortem right of publicity for Albert Einstein had **expired in 2005** — 50 years after his death in 1955 — applying California Civil Code §3344.1's 50-year post-mortem window retrospectively. The ruling denied Hebrew University's publicity-rights claim against a commercial publisher using Einstein's image on book covers.

**Critical caveat #1 — Trademark survives separately:** The court's ruling addressed ONLY right of publicity. The Hebrew University's active trademark registration **USPTO Reg. #3591305** (ALBERT EINSTEIN) was not affected and remains enforceable. Trademark protection does not expire on a fixed schedule; it lasts as long as the mark is used in commerce. A would-be user of Einstein's name in Learning-NC would still face a trademark-confusion claim even though the publicity claim is gone in California.

**Critical caveat #2 — CA-specific:** The ruling binds only courts applying California law. Other U.S. states (notably New York, which has different post-mortem rules) and non-U.S. jurisdictions (Germany's KUG §22, EU member states' varied regimes) are unaffected. The ruling does NOT create a global "Einstein is public domain" safe harbor.

**Critical caveat #3 — Enforcement realism:** Even where legally defensible, defending a rights claim costs €10-50k+ in legal fees. The Einstein Foundation has a documented pattern of sending demand letters to uses that would likely prevail in court, relying on the asymmetric defense cost to secure compliance. For Learning-NC (open-source, no legal budget, solo maintainer), even a winnable claim is a project-ending scenario.

**Application to Learning-NC:**
- App distributed globally via Nextcloud App Store → exposed to all jurisdictions, not just California.
- German / EU framework (KUG §22 post-mortem 10 years + separate trademark regime) diverges from California's 50-year post-mortem.
- Conservative posture required: assume trademark is enforceable globally, assume defense costs exceed project resources, assume demand letters will be sent regardless of legal merit.
- Therefore: Archetype-Naming is not a "defensible edge case" — it is the **only globally-safe path**.

**Citation:** 2022 ruling summarized at CDAS (Cowan, DeBaets, Abrahams & Sheppard LLP): https://cdas.com/einstein-publicity-rights-deemed-expired-by-california-federal-court/

---

## 4. Archetype-Naming Decision + Rationale

**Decision:** v4.4.0 ships three stylized **Archetype**-Presets. No named-person presets. No named-person references in code, UI, i18n strings, alt-text, aria-labels, CHANGELOG.md, App Store description, or marketing screenshots.

**Archetypes (locked names):**
1. **Der Theoretiker**
2. **Der Kosmologe**
3. **Der Astrophysik-Popularisierer**

**Rationale (six points):**

1. **App-Store-safety.** Archetype-Naming has zero trademark exposure and zero right-of-publicity exposure. Any of the three named-person alternatives would require active legal-risk management during App Store review and across the product's lifetime. Learning-NC does not have the legal budget or bandwidth for that posture.

2. **Hawking-Estate trademark active → would require written Estate permission.** Estate contact at `info@stephenhawking.org.uk` is published but requires 4-8 weeks response time, written permission, no guarantee of approval. Even if approved, it creates ongoing reporting obligations. Out of proportion for a single preset.

3. **Tyson right-of-publicity alive → no legal path forward without signed license.** Living public figure with active enforcement. Parody defense unavailable for sincere-mentor usage. Realistic licensing probability for an open-source IT-certification app: near zero.

4. **Einstein Hebrew-University trademark #3591305 → would require Einstein Foundation license.** Active enforcement documented (~$10-12.5M/year licensing revenue). Budget, paperwork, contractual drag. Contested by Einstein heirs adds second-claimant risk. Even the 2022 California publicity-rights ruling does not remove trademark exposure.

5. **EU/DE audience respects `Recht am eigenen Bild` even post-mortem in spirit.** The German / EU legal framework differs from California's. More importantly, the DE user base (primary audience, including DevCloud Security+ Kurs 21 cohort) carries cultural expectations about respectful treatment of deceased persons' images that exceed the strict legal minimum. Reputational risk beyond law matters for a trust-driven learning product.

6. **Archetype-Naming produces the same emotional payoff without any of the hazards.** The design goal behind named-presets was "familiar physicist-type mentor figures" — an emotional / aspirational payoff, not a literal likeness. Archetypes deliver the same feeling (wild-haired theoretician, wheelchair-using cosmologist, bearded popularizer) with zero legal exposure. Product intent is preserved; legal surface area is eliminated.

**Deferred path (informational only, NOT v4.4.0 scope):**

Should a future project owner ever revisit the named-preset direction, the required preconditions are:
- **Einstein:** License from the Einstein Foundation via einstein.biz. Budget: legal review + license fees + paperwork + 4-8 weeks minimum. Not a practical path for solo/open-source.
- **Hawking:** Written permission from Stephen Hawking Estate via `info@stephenhawking.org.uk`. Budget: 4-8 weeks response time, no guarantee of approval, Estate may decline.
- **Tyson:** Written license from Tyson or his agents. Realistic probability: near zero for open-source IT-education app. Not a practical path.

Any future reopening of this decision MUST update this LEGAL.md with a new version number, documented legal review, and signed license(s) attached before any art production starts. Do not reintroduce named presets via back-channel (marketing copy, alt-text, preset-internal-ID comments) — the CI-guard (Chapter 5) is the last line of defense, but architectural discipline is the first.

**Copy guidelines (reinforcement for Chapter 5):**
- Do NOT use phrases like "Einstein-inspired", "Hawking-like", "Tyson-style" anywhere in the codebase. The archetypes stand on their own merits.
- Do NOT cite real persons as inspiration in user-facing text. LEGAL.md is the ONLY document that names them, and LEGAL.md is internal.
- Do NOT use identifiable persona indicia in marketing screenshots (StarTalk-like typography, Cosmos-show apparel, Tyson-signature-vest-pattern).
- Marketing copy MUST avoid endorsement cues: "Lerne mit den größten Denkern" + a clearly-Einstein-like avatar = implied endorsement = trademark confusion risk returns through the back door.
- Safe phrasings: "Wähle deinen Lernbegleiter" / "Archetype-Presets inspiriert von Persönlichkeitstypen" (not persons).

---

## 5. CI-Guard + Exception-Policy

### 5.1 CI-Guard: `scripts/check-forbidden-names.sh`

The decision locked in Chapter 4 is enforced in code by `scripts/check-forbidden-names.sh` (delivered in Plan 149-01, integrated as Gate #6 in `.git/hooks/pre-push` and mirrored in `.githooks/pre-push`).

**Forbidden names (case-insensitive):**
- `Einstein`
- `Hawking`
- `Tyson`
- `Neil deGrasse` (Tyson's full-name fragment; catches non-"Tyson" references)
- `Cosmos` (Tyson's show-title; the generic word "cosmology" / "cosmological" is whitelisted — see 5.3)
- `StarTalk` (Tyson's media brand)

**Scope (strict — do NOT widen without updating this document):**
- `app/src/**` (Vue, JS, TS source files)
- `app/l10n/**` (all 5 language files: de.json, en.json, fr.json, ru.json, ar.json)
- `CHANGELOG.md`
- `appinfo/info.xml`

**Out of scope (explicitly excluded):**
- `.planning/**` — internal planning + legal documentation (this file lives here for a reason)
- `memory/**` — Claude memory files
- `node_modules/**`, `vendor/**`, `dist/**`, `certs/**` — build / dependency artifacts
- `.git/**` — version control internals

**Verification command:** `./scripts/check-forbidden-names.sh` must exit 0 on every pre-push. See Gate #6 integration in `.git/hooks/pre-push` (documents the enforcement mechanism for check-forbidden-names).

### 5.2 `LEGAL-EXCEPTION` Marker (Defence-in-Depth)

Even though `.planning/` is excluded from the grep scope, `scripts/check-forbidden-names.sh` additionally filters out any line containing the literal string `LEGAL-EXCEPTION`. This is defence-in-depth:

- **Primary defense:** Scope exclusion (`.planning/` not scanned).
- **Secondary defense:** `LEGAL-EXCEPTION` line-filter catches any legitimate forbidden-name use that might occur in a file that IS in scope (e.g. a future test fixture or a structured code comment quoting this document).

**Permitted uses of `LEGAL-EXCEPTION`:**
- This file (LEGAL.md) — legal documentation of the decision.
- Test fixtures that explicitly verify the CI-guard catches forbidden names (e.g. a test that greps for "Einstein" as input, then asserts the guard flags it).
- Structured code comments that quote this document for audit-trail purposes.

**Forbidden uses of `LEGAL-EXCEPTION`:**
- As a bypass mechanism for actual production copy (aria-label, i18n string, marketing text, UI label, onboarding slide, CHANGELOG entry).
- To smuggle named-person references into `app/src/` via a comment above the production string.

**Review requirement:** Any PR that adds a `LEGAL-EXCEPTION` marker inside `app/src/**`, `app/l10n/**`, `CHANGELOG.md`, or `appinfo/info.xml` MUST be reviewed at project-owner level. The default stance is reject; the only valid approvals are documented test fixtures.

### 5.3 Whitelist Detail — "Cosmos" vs "Cosmology"

The word `Cosmos` is forbidden because it's the title of Tyson's PBS/FOX show and falls under the identifiable-persona-indicia doctrine (§2.3). However, the scientific terms `cosmology`, `cosmological`, `cosmological constant`, `cosmological principle`, etc. are legitimate IT-certification / physics vocabulary and must be usable in learning content.

**Implementation:** The CI-guard searches for `Cosmos` as a word-boundary match (`\bCosmos\b`), then filters out lines that ALSO contain `cosmology`, `cosmological`, or `cosmolog` (any prefix). See `scripts/check-forbidden-names.sh` for the exact regex.

**Failure mode to watch for:** If a lesson file mentions "Tyson's show Cosmos" in educational content, the word "Tyson" will be caught regardless of whether "Cosmos" escapes. Do not attempt to add such content; rewrite to avoid the person-reference entirely.

### 5.4 Future Amendments to This Document

This LEGAL.md is versioned (see header). Any substantive change — widening scope, narrowing the forbidden list, approving a named-preset license, updating Chapter 2 trademark status — MUST:

1. Increment the version number in the header.
2. Add a changelog entry in a new section "## 6. Amendments" (to be added on first amendment).
3. Be committed with a clear commit message describing the legal rationale.
4. Be re-reviewed by the project owner before the next App Store release.

The current version (1.0, 2026-04-18) represents the v4.4.0 Archetype-Naming decision. It is the authoritative record for that decision.

---

## Sources

### Primary (HIGH confidence)
- **USPTO Trademark #3591305** — ALBERT EINSTEIN (Hebrew University of Jerusalem, active): https://trademarks.justia.com/789/77/albert-78977440.html
- **USPTO Trademark #5980163** — STEPHEN HAWKING (Stephen Hawking Estate, active): https://trademarks.justia.com/792/49/stephen-79249543.html
- **Stephen Hawking Foundation — Trademark Policy:** https://stephenhawkingfoundation.org/stephen-hawking-trademark-2/
- **Einstein Foundation licensing (einstein.biz):** https://einstein.biz/
- **2022 Einstein publicity-rights ruling (CDAS summary):** https://cdas.com/einstein-publicity-rights-deemed-expired-by-california-federal-court/
- **Tyson right-of-publicity analysis (Game Developer):** https://www.gamedeveloper.com/business/right-of-publicity-in-video-games---how-you-can-legally-include-a-celebrity-in-your-game
- **AI avatars + likeness legal risks (ArentFox Schiff):** https://www.afslaw.com/perspectives/alerts/the-business-ai-avatars-key-legal-risks-and-best-practices

### Secondary (MEDIUM confidence)
- **CHI 2024 — disability representation research:** "They only care to show us the wheelchair" — disability-representation pitfalls in avatar systems: https://dl.acm.org/doi/10.1145/3613904.3642166
- **CHI 2023 — Towards Inclusive Avatars:** https://dl.acm.org/doi/10.1145/3544548.3581481
- **CHI 2025 — Inclusive Avatar Guidelines:** https://dl.acm.org/doi/full/10.1145/3706598.3714230

### Internal (project-local)
- `.planning/research/PITFALLS.md` §1 (Hawking), §2 (Tyson), §3 (Einstein), §7 (Disability-Caricature), §17 (Celebrity-Endorsement)
- `.planning/phases/149-legal-art-direction/149-CONTEXT.md` — Archetype-Naming decision locked at v4.4.0 product level
- `.planning/phases/149-legal-art-direction/149-RESEARCH.md` — 5-chapter skeleton + sourcing recommendations
- `scripts/check-forbidden-names.sh` — CI enforcement (Plan 149-01)
- `.git/hooks/pre-push` Gate #6 — integration point (Plan 149-01)

---

*Document: `.planning/LEGAL.md`*
*Version: 1.0*
*Created: 2026-04-18*
*Authoritative for: v4.4.0 Archetype-Naming decision*
*Next review: before v4.4.0 App Store submission (Phase 153 exit)*
