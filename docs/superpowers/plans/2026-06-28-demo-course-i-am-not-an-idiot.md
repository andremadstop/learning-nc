# Demo-Kurs „I am not an idiot test" — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a bilingual (DE+EN) 18-question MCQ demo course "I am not an idiot test" that doubles as the v5.0.0 certification live-activation vehicle.

**Architecture:** Two language-tagged question pools (`de`/`en`) authored as repo JSON artifacts in the existing import format (`app/examples/*.json`), imported into two parallel courses (DE + EN) with cert enabled. Content authoring + validation + a mandatory pre-publish multi-AI review happen first (no prod mutation). The actual live provisioning (pool/course creation on DevCloud, cert mint, gated 157 checks, release) is a SEPARATE user-gated runbook (Rule 15) — Task 4 is documented but executed ONLY under explicit per-step authorization.

**Tech Stack:** PHP 8.1 / NC App Framework (CourseController, PoolController, QuestionController), JSON pool-import format, PostgreSQL 16 (live), did:web issuer (existing key). Content: hand-authored MCQ.

## Global Constraints

- **Bilingual:** every question exists in BOTH `de` and `en`; the EN set is a faithful 1:1 translation of the DE set (same facts, same correct answer index), never independently invented.
- **18 questions per language**, balanced across 4 themes (~4–5 each): Phishing & Fake-Shops · Passwörter & 2FA · KI-Scams & Deepfakes · Geld-Betrug & Datenschutz.
- **Import format (verbatim):** JSON array of `{"text": str, "answers": [{"text": str, "is_correct": bool}], "explanation": str}` — see `app/examples/gdpr-basics.json`. Each question: 4 answers, ≥1 correct, a non-empty factual `explanation`.
- **Tone:** framing (titles/intro/result copy) playful; question stems + explanations factually correct and current as of 2026. A safety course that gives wrong advice is worse than none.
- **Cert config:** `cert_enabled=true`, `cert_pass_percent=70`, validity 365 days, existing did:web issuer (NO new crypto). Cert title DE: „Internet-Mündigkeit 2026 — I am not an idiot test"; EN: „Internet Street-Smarts 2026 — I am not an idiot test".
- **NO prod mutation in Tasks 1–3.** All DevCloud writes (pool/course create, `occ upgrade`, cert mint) live in Task 4, user-gated.
- **Pre-publish gate is mandatory** before ANY live/public step (Task 4): fabric → Gemini → grumpy Codex (`feedback_prelive_review_gate.md`).
- **Quality gates** (CLAUDE.md): if any app code is touched, PHPStan L5 + ESLint 0 + Vitest. Pure content (JSON only) needs schema validation, not PHP tests.

---

### Task 1: Author the German question set (18 MCQ)

**Files:**
- Create: `app/examples/i-am-not-an-idiot-de.json`
- Validate: `scripts/validate-pool-json.mjs` (create — reusable schema check)

**Interfaces:**
- Produces: `app/examples/i-am-not-an-idiot-de.json` — the canonical DE content the EN set (Task 2) translates and the pools (Task 4) import.
- Produces: `scripts/validate-pool-json.mjs` — `node scripts/validate-pool-json.mjs <file>` exits 0 iff the file matches the import schema; reused by Task 2.

**Content blueprint (write all 18; per theme):**
- **Phishing & Fake-Shops (5):** fake parcel/customs SMS; bank-login phishing mail tells; spotting a fake shop (no Impressum, too-cheap, prepayment-only, fake trust seals); lookalike domain / URL inspection; QR-code phishing ("quishing").
- **Passwörter & 2FA (4):** what makes a strong password (length > complexity); password manager value; why SMS-2FA < app/TOTP < passkey; password reuse / credential-stuffing.
- **KI-Scams & Deepfakes (5):** voice-clone grandparent/CEO scam; AI fake profiles (romance/investment); deepfake video of a "celebrity" promoting crypto; AI-written phishing has no more typos — old tells are dead; verifying via a second channel.
- **Geld-Betrug & Datenschutz (4):** crypto/investment "guaranteed return" scam; urgency/pressure ("act now or account closed") as the universal red flag; app over-permissions / data brokers; public Wi-Fi + why HTTPS/VPN.

Tone example (stem): „Eine SMS sagt, dein Paket hängt im Zoll — klick hier und zahl 2,99 € Gebühr. Was tust du?"

- [ ] **Step 1: Write the DE JSON**

Author 18 objects in the import format. Each: a playful-but-clear German stem, 4 plausible answers (distractors must be realistic, not silly), exactly the correct one(s) flagged `is_correct:true`, and a 1–2 sentence factual `explanation` that teaches the principle. Balance the 4 themes per the blueprint (5/4/5/4 = 18).

- [ ] **Step 2: Write the schema validator**

```js
// scripts/validate-pool-json.mjs — node scripts/validate-pool-json.mjs <file>
import { readFileSync } from 'node:fs'
const file = process.argv[2]
const data = JSON.parse(readFileSync(file, 'utf8'))
if (!Array.isArray(data)) { console.error('not an array'); process.exit(1) }
let ok = true
data.forEach((q, i) => {
  const where = `Q${i + 1}`
  if (typeof q.text !== 'string' || !q.text.trim()) { console.error(`${where}: empty text`); ok = false }
  if (typeof q.explanation !== 'string' || !q.explanation.trim()) { console.error(`${where}: empty explanation`); ok = false }
  if (!Array.isArray(q.answers) || q.answers.length !== 4) { console.error(`${where}: need exactly 4 answers`); ok = false }
  const correct = (q.answers || []).filter(a => a && a.is_correct === true).length
  if (correct < 1) { console.error(`${where}: need >=1 correct answer`); ok = false }
  ;(q.answers || []).forEach((a, j) => {
    if (typeof a.text !== 'string' || !a.text.trim()) { console.error(`${where}.A${j + 1}: empty answer`); ok = false }
    if (typeof a.is_correct !== 'boolean') { console.error(`${where}.A${j + 1}: is_correct must be bool`); ok = false }
  })
})
if (data.length !== 18) { console.error(`expected 18 questions, got ${data.length}`); ok = false }
console.log(ok ? `OK: ${file} — ${data.length} questions valid` : `FAIL: ${file}`)
process.exit(ok ? 0 : 1)
```

- [ ] **Step 3: Run the validator — expect PASS**

Run: `node scripts/validate-pool-json.mjs app/examples/i-am-not-an-idiot-de.json`
Expected: `OK: app/examples/i-am-not-an-idiot-de.json — 18 questions valid`

- [ ] **Step 4: Theme-balance self-check**

Manually confirm the 5/4/5/4 theme split and that each `explanation` states WHY (teaches a principle), not just "correct".

- [ ] **Step 5: Commit**

```bash
git add app/examples/i-am-not-an-idiot-de.json scripts/validate-pool-json.mjs
git commit -m "feat(demo-course): author 18 German MCQ for 'I am not an idiot test' + schema validator"
```

---

### Task 2: Author the English question set (faithful translation)

**Files:**
- Create: `app/examples/i-am-not-an-idiot-en.json`

**Interfaces:**
- Consumes: `app/examples/i-am-not-an-idiot-de.json` (Task 1), `scripts/validate-pool-json.mjs` (Task 1).
- Produces: `app/examples/i-am-not-an-idiot-en.json` — the EN pool content for Task 4.

- [ ] **Step 1: Translate the DE set 1:1**

For each of the 18 DE questions, write the EN equivalent in the SAME order: same scenario, same 4 answers in the SAME positions, the SAME answer flagged correct, explanation conveying the same fact. Natural idiomatic English, not literal word-for-word. Keep the playful tone.

- [ ] **Step 2: Run the validator — expect PASS**

Run: `node scripts/validate-pool-json.mjs app/examples/i-am-not-an-idiot-en.json`
Expected: `OK: app/examples/i-am-not-an-idiot-en.json — 18 questions valid`

- [ ] **Step 3: Parity check DE↔EN**

```bash
# Same question count AND same correct-answer index per question (faithful translation).
node -e '
const de=require("./app/examples/i-am-not-an-idiot-de.json");
const en=require("./app/examples/i-am-not-an-idiot-en.json");
if(de.length!==en.length){console.error("length mismatch");process.exit(1)}
const idx=a=>a.answers.map((x,i)=>x.is_correct?i:-1).filter(i=>i>=0).join(",");
let ok=true;
de.forEach((q,i)=>{ if(idx(q)!==idx(en[i])){console.error(`Q${i+1}: correct-index DE(${idx(q)}) != EN(${idx(en[i])})`);ok=false} });
console.log(ok?"OK: DE/EN parity (count + correct index)":"FAIL: parity");
process.exit(ok?0:1)'
```
Expected: `OK: DE/EN parity (count + correct index)`

- [ ] **Step 4: Commit**

```bash
git add app/examples/i-am-not-an-idiot-en.json
git commit -m "feat(demo-course): author English translation (faithful 1:1, DE/EN parity verified)"
```

---

### Task 3: Pre-publish multi-AI review gate

**Files:**
- Create: `docs/superpowers/plans/2026-06-28-demo-course-REVIEW.md` (review log + findings + resolutions)

**Interfaces:**
- Consumes: both JSON artifacts (Tasks 1–2) + the cert config (spec §3).
- Produces: a SHIP verdict logged in the review doc — the precondition for Task 4.

This is the established Pre-Live-Gate (`feedback_prelive_review_gate.md`), fixed order. Steering: Gemini-CLI is dead → drive via `fabric --model gemini-2.5-pro < input`; Codex via `codex exec --sandbox read-only "<prompt>" < /dev/null`.

- [ ] **Step 1: fabric first pass**

Bundle both JSONs + spec §2/§3 into one input; run fabric for a structural/quality first pass (clarity, distractor quality, theme balance, tone consistency). Log findings.

- [ ] **Step 2: Gemini — content & factual correctness**

```bash
cat app/examples/i-am-not-an-idiot-de.json app/examples/i-am-not-an-idiot-en.json > /tmp/idiot-review.txt
fabric --model gemini-2.5-pro < /tmp/idiot-review.txt   # prompt: fact-check each question against 2026 reality; verify DE↔EN translation fidelity; flag any wrong/outdated security advice
```
Log every factual flag. Wrong security advice = blocking.

- [ ] **Step 3: grumpy Codex — code/security/config**

```bash
codex exec --sandbox read-only "You are a very grumpy senior reviewer. Review app/examples/i-am-not-an-idiot-{de,en}.json and the demo-course spec docs/superpowers/specs/2026-06-28-demo-course-i-am-not-an-idiot-design.md. Hunt for: factually wrong or dangerous security advice, ambiguous questions with >1 defensible answer, weak distractors, cert-config risks, any way the import/JSON could break the pool importer or leak. Be harsh." < /dev/null
```
Log findings.

- [ ] **Step 2–3 loop: fix findings**

Apply fixes to the JSONs, re-run the validator + parity check (Task 1 Step 3 / Task 2 Step 3), re-review until SHIP (~3 rounds typical). Record each round in the review doc.

- [ ] **Step 4: Commit the review log + any content fixes**

```bash
git add app/examples/i-am-not-an-idiot-de.json app/examples/i-am-not-an-idiot-en.json docs/superpowers/plans/2026-06-28-demo-course-REVIEW.md
git commit -m "docs(demo-course): pre-publish multi-AI review (fabric/Gemini/grumpy Codex) -> SHIP + content fixes"
```

---

### Task 4: Live provisioning runbook (USER-GATED — Rule 15, NOT auto-run)

> ⛔ **This task mutates live production (DevCloud serving real learners). An autonomous plan executor MUST STOP here and hand control to the user. Execute each step only on explicit per-step authorization. This is the v5.0.0 provisioning pass + release.**

**Files:**
- Modify (release): `app/appinfo/info.xml` (version bump), `CHANGELOG.md`
- Modify (gated test activation): `app/tests/e2e/public-verify.spec.js` (LIVE_VID + recipient constants)

**Interfaces:**
- Consumes: the two SHIP'd JSON pools (Tasks 1–3), existing did:web issuer key, dormant migration Version009200.

- [ ] **Step 1: Decide cert-mint method** — real person sits the test (most authentic, dogfoods UX — recommended for ≥1 cert) vs seed the pass-gates (faster, like 155). User decides.
- [ ] **Step 2: Settle python3-cryptography** (INBOX) — present (43.0.0) now; choose (a) leave / (c) bake into image. Independent-verify needs it.
- [ ] **Step 3: Apply dormant migration** — bump `info.xml` + `occ upgrade` on DevCloud in a low-traffic window (brief maintenance page). Verify `revoked_at` column present on live PG16.
- [ ] **Step 4: Create pools** — import `i-am-not-an-idiot-de.json` + `-en.json` as two `de`/`en` pools via the regular import path. Record pool IDs.
- [ ] **Step 5: Create two courses** (DE + EN), owner = real instructor account, cert config per Global Constraints (70%, 365d, titles), `certRequiredPoolIds` = respective pool.
- [ ] **Step 6: Mint the cert(s)** — per Step 1 decision; verify independently (`scripts/verify-credential.py`, Ed25519).
- [ ] **Step 7: Run the gated 157 checks live** — VERIFY-03 (set LIVE_VID + recipient constants, un-skip the Playwright DOM gate → green); VERIFY-06 (429 curl-loop on unknown branch; bruteforce-reset 172.21.0.1 first); VERIFY-05 (credentialed revoke smoke); visual valid/withdrawn/expired banners + RTL.
- [ ] **Step 8: Visual CERT-07/08/13** — print, QR, LinkedIn-share on the real cert.
- [ ] **Step 9: Milestone close + release** — flip any remaining deferred VERIFY/CERT reqs to Complete, CHANGELOG, git tag v5.0.0, Codeberg store release, `/gsd:complete-milestone`.

---

## Self-Review

**Spec coverage:** §2 content → Task 1; §2a bilingual → Tasks 1–2 + parity; §3 cert config → Global Constraints + Task 4 Step 5; §4 build path → Task 4 Steps 4–5; §5 pre-publish gate → Task 3; §6 provisioning → Task 4; §7 open points → Task 4 Steps 1–2 + python3-crypto; §8 not-in-scope → respected (MCQ only, DE/EN only, existing crypto). All covered.

**Placeholder scan:** validator + parity code complete; review commands concrete; runbook steps are real actions (the only intentionally-open items — mint method, python3-crypto, exact pool/course IDs — are user decisions/runtime values, flagged as such, not plan gaps).

**Type consistency:** import-schema shape (`text`/`answers[{text,is_correct}]`/`explanation`) identical across Tasks 1, 2, 4; validator + parity check + importer all consume the same shape; `validate-pool-json.mjs` defined in Task 1, reused in Task 2.
