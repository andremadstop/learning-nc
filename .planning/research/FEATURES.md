# Feature Research

**Domain:** Mandatory compliance training / corporate LMS (Pflichtschulung)
**Milestone:** v5.2.0 — AWO-Readiness
**Researched:** 2026-07-01
**Confidence:** HIGH (video-gating platform limits), MEDIUM (recert workflow details), HIGH (DSGVO Nachweis requirements)

---

## Context: What v5.0.0 Already Delivers (Do Not Re-Research)

The following compliance primitives exist and are anchored in Block analysis below as dependencies:

- Signed OB3/VC certificate with `expiry_date` per course pass
- Compliance CSV export scoped to course-instructor
- Hard pass-criterion (score threshold) per course
- Public verify portal (Ed25519 signature check)
- DSGVO-safe: no PII in public cert, claim-binding on internal subject ID

The Nachweis question ("who passed what when, provably") is already structurally answered. v5.2.0 adds operational tooling for 2000-employee org scenarios: visibility delegation (team leads), lifecycle management (recert), content gating (video), and provisioning ergonomics (username-only + bulk).

---

## Feature Landscape

### BLOCK 1 — Video-/Material-Gating

**Question answered:** What does a compliance auditor accept as "learner has engaged with the material"?

In the SCORM/xAPI world (Forma LMS, Docebo, TalentLMS, Moodle), the standard behavior is:

- Completion status is a distinct event tracked separately from quiz score
- Content (video or SCORM module) must emit a `completed` status before the quiz becomes available
- Forward-seek prevention is standard only for self-hosted players the LMS controls
- Resume-where-left-off is expected for videos > ~5 min

**TABLE STAKES — Video-/Material-Gating**

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| 100% completion gating (NC-MP4) | Auditor expects "not just opened but watched". NC-hosted MP4 in a controlled player = full control. | MEDIUM | HTML5 `<video>` timeupdate event; track furthest-watched timestamp server-side per user/video. Unlock quiz when `max_watched / duration >= threshold` (recommend ≥ 0.95 to tolerate encoder tail silence). |
| Forward-seek prevention (NC-MP4 only) | Standard behavior in compliance LMS for owned players (TutorLMS, Absorb, Moodle Workplace all do this). | LOW-MEDIUM | CSS/JS: intercept `seeking` event; clamp to `max_watched`. Works only in our own `<video>` element. |
| Quiz locked until material complete | Table-stakes gating flow in every compliance LMS. | LOW | Gate on `material_completed = true` flag stored per enrollment. Quiz tab/button disabled with explanation text. |
| Progress persistence (resume) | Standard expectation for any video > 3 min. Users on slow connections or interrupted mid-training need this. | LOW | Store `last_position` in user's material progress row. Restore on re-open. |
| Visual completion indicator | Users must know what is blocking the quiz. | LOW | "Video not yet watched to completion" status badge on quiz section. |
| Multiple materials per course, ordered | Compliance courses often have multiple clips + a final quiz. Auditors expect sequential consumption. | MEDIUM | Material ordering already exists in course structure; gate needs to respect order: all materials above quiz position must be complete. |

**DIFFERENTIATORS — Video-/Material-Gating**

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| YouTube/Vimeo embed best-effort tracking | AWO may have existing Vimeo/YouTube training libraries. Supporting these widens adoption without forcing re-encode. | MEDIUM | IFrame API (YouTube) and Player SDK (Vimeo) provide progress events but cannot prevent seeking — users can always open video on the platform itself. Report as "watched X%" not "completed". Gating is best-effort: require e.g. 90% reported view, document limitation. |
| "Mark as read" for non-video material (PDF/link) | Some compliance material is a policy document, not a video. One-click acknowledgment ("I have read this document") is standard. | LOW | Separate material type `document` with explicit acknowledgment button. Counts as completion for gating purposes. |

**ANTI-FEATURES — Video-/Material-Gating**

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Forward-seek prevention for YouTube/Vimeo | "We want 100% enforcement everywhere" | IFrame API doesn't block seek; users can open youtube.com URL directly. Building a fake full-enforcement creates false auditor confidence. | Document that seek-prevention is reliable only for NC-hosted MP4. For external embeds, report watched-% and flag < 90% as incomplete. |
| Server-side video streaming / chunked token auth | Max security for video content | Full media server complexity; NC already serves files. Out of scope for a NC app. | NC file permissions control who can access the raw file. Player-side gating is sufficient. |
| SCORM runtime integration | "Can we import SCORM packages?" | Full SCORM engine is Forma LMS's domain; building it is months of work and maintenance. Not AWO's actual need. | AWO's actual content is likely MP4 clips. SCORM = anti-feature / scope creep. |
| Per-second granular server-side tracking | Maximum proof of watch time | Chatty, performance impact, privacy concern (DSGVO: is detailed engagement data "personal data"?). | Track furthest-watched position + final completion event only. That is sufficient for Nachweis. |

---

### BLOCK 2 — Teamleiter-RBAC-Reports

**Question answered:** What does a team-lead / manager compliance view actually show?

In enterprise LMS platforms (Docebo, Adobe Learning Manager, Totara, Absorb), the manager view is a scoped compliance dashboard:
- Direct reports only (no upward visibility)
- Completion status per person per assigned course
- Filter by status (all / completed / pending / overdue / never-started)
- Export as CSV or PDF
- No ability to manage the course itself (read-only view)

**TABLE STAKES — Teamleiter-RBAC**

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Team-lead role scoped to NC group | 2000 employees need delegation. Admins cannot watch every individual. Auditors expect "responsible persons" named. | MEDIUM | New role concept: `group_lead` per NC group. Assigned by NC admin. Does NOT require building a new RBAC framework — use NC groups as the scope boundary. |
| Compliance report filtered to own group | Team lead must see only their people. Privacy (DSGVO §26 BDSG): access to others' training records without business need is not permissible. | MEDIUM | Filter existing compliance report SQL by `group_id`. Team lead sees same columns as course admin but restricted to their NC group members. |
| Status columns: Passed / Pending / Not Started / Overdue | Standard compliance view. "Who still owes" is the primary use case. | LOW | Derived from cert table + enrollment table + expiry_date. Overdue = has cert with `expiry_date` < today AND no newer valid cert. |
| Export (CSV) for own group | Auditors and HR departments request CSV extracts. Team lead may need to present to HR. | LOW | Same CSV logic as existing course-admin export, restricted to group membership. |
| "Who still owes" list (default view) | Primary operational need: quickly identify incomplete employees before a deadline. | LOW | Sort by status: Not Started first, then Overdue, then Pending, then Passed. |

**Note:** Team-lead RBAC is an *operational* table-stake for managing 2000 employees, not strictly an *auditor* requirement. The auditor wants the admin-level compliance CSV (v5.0.0 already delivers this). Team-lead view reduces admin bottleneck and enables self-service status checks.

**DIFFERENTIATORS — Teamleiter-RBAC**

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Deadline field per course assignment | Team lead can see "training must be done by 2026-09-01" per course, not just expiry date. | LOW | Add `due_date` to course enrollment or group-course assignment. Shows as column in team view. |
| In-app notification to group members | Team lead can trigger a one-click "reminder to incomplete members" without leaving the app. | MEDIUM | Sends NC Notification to each non-completed group member. Uses existing NC Notifications API. Must use Notifications (not email) to also reach username-only users (Block 4 dependency). |

**ANTI-FEATURES — Teamleiter-RBAC**

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Multi-level org hierarchy (manager → team lead → employee) | Large orgs have reporting trees | Two-level hierarchy (admin → group lead → members) is sufficient for AWO. Multi-level is months of work and needs custom org-chart data model. | NC groups already model the hierarchy. One level of delegation covers AWO's case. |
| Building role management outside NC groups | "We want fine-grained permissions" | NC groups are the canonical tenancy primitive in Nextcloud. Re-inventing a permission system creates sync problems. | Map `group_lead` role onto an NC group membership (e.g., `group_leads` NC group with a group-ID metadata table). |
| Individual learner progress drill-down per quiz question | "We want to see where people got stuck" | Per-question analytics is learning analytics, not compliance. DSGVO concern: detailed per-question tracking is sensitive. | Show pass/fail + score only. Question-level analytics is out of scope. |

---

### BLOCK 3 — Re-Zertifizierung

**Question answered:** What is the expected lifecycle of a certification in a compliance LMS?

Standard lifecycle in Absorb LMS, TalentLMS, Moodle Workplace, Certifi, RenewOps:

1. **Issuance** — cert issued with expiry_date attached
2. **Active period** — cert valid, user shows as "compliant"
3. **Pre-expiry window** — reminders at defined intervals (30/7/0 days typical)
4. **Grace period** (optional) — short window where cert shows warning but not yet "expired" (14 days typical)
5. **Expiry** — cert status flips to "expired/overdue", user shows as non-compliant in reports
6. **Re-enrollment** — user must retake and pass course
7. **Re-issuance** — new cert issued with new expiry_date

**Rolling-vs-fixed decision:** v5.0.0 already stores per-cert `expiry_date` (set at issuance = pass_date + N months). Rolling-from-pass reuses this with zero new data model. Fixed-calendar-date (all recert by January 1) needs a separate scheduling layer. **Recommendation: rolling only for v1; fixed-date is v2+.**

**TABLE STAKES — Re-Zertifizierung**

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Cert status: Active / Expiring-Soon / Expired / Never-Certified | Standard compliance lifecycle states. Report consumers (team lead, auditor) need these. | LOW | Derived from `expiry_date` at query time. No new DB column needed. `Expiring-Soon` = expiry within 30 days. |
| Reminder notifications at 30 / 7 days before expiry | Universal standard in all compliance LMS (Absorb, Docebo, Certifi, RenewOps all use this cadence). Regulations require orgs to give "reasonable notice". | MEDIUM | Requires NC background job (cron, `occ` command or PHP cron). Must use NC Notifications API (not email) as primary channel to cover username-only users — email is fallback. |
| Status flip to "Overdue/Non-Compliant" at expiry_date | Auditors expect the system to automatically mark non-renewal as non-compliant. Manual status management is not acceptable for 2000 employees. | LOW | Computed field: `expiry_date < today AND no newer valid cert`. Cron or query-time computation. |
| Re-enrollment path: user can retake course after expiry | Without re-enrollment, the system is a dead end. | LOW | Remove "already passed" block on course enrollment when cert is expired. Allow re-take. |
| New cert issued on re-pass, new expiry_date computed | Standard lifecycle end: fresh cert replaces expired one. | LOW | Re-use existing cert issuance flow. New cert row, previous cert marked `superseded`. |
| Configurable expiry period per course (months) | Different compliance topics have different cycles: DSGVO annually, fire safety 2 years, first aid 3 years. | LOW | Add `cert_validity_months` field to course configuration. Default: 12. |

**DIFFERENTIATORS — Re-Zertifizierung**

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Grace period (14 days) | Learner on vacation when cert expires returns to "warning" not "hard expired". Reduces false-positive non-compliance. | LOW | Add configurable `grace_days` to course. During grace: cert shows "expiring" warning, user still shows as "compliant with warning" in report. After grace: "expired". |
| Team-lead dashboard shows upcoming expirations | Proactive management: "5 people in your group expire in the next 30 days". | LOW | Query on team-lead report page. Reuses Block 2 infrastructure. |

**ANTI-FEATURES — Re-Zertifizierung**

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Blocking system access on cert expiry | "Non-compliant employees shouldn't use the system" | This is an org policy decision, not an LMS decision. LMS blocking access to an entire Nextcloud instance would require NC-level hooks and is massively over-engineered and legally risky. | Flip status to "overdue", surface prominently in reports and dashboard. Let HR/manager act. |
| Fixed-calendar recert (everyone recerts January 1) | "We want synchronized annual cycles" | Requires separate scheduling data model orthogonal to per-cert `expiry_date`. Net-new complexity. | Defer to v2+. Rolling-from-pass covers AWO's actual need. |
| Email reminders built inside the app | "We want to control the email" | NC handles email delivery via its mail settings. Building a parallel email subsystem duplicates infrastructure and creates delivery reliability concerns. | Use NC Notifications API. NC's own mail-notification feature converts NC notifications to emails when user has email configured. |
| Different course content for re-certification | "We want a shorter refresher course for renewals" | Separate content authoring per recert cycle is deep LMS complexity (Docebo-level). AWO's need is "retake the course, get a new cert". | Same course, same pass criterion. Content variation is out of scope. |

---

### BLOCK 4 — Username-Politur (Username-Only + Bulk Enrollment)

**Question answered:** What does a 2000-employee org need to provision training without managing email accounts?

Typical compliance LMS behavior (Forma, Absorb, Moodle, corporate LDAP/AD-synced instances):
- Username is the canonical identifier; email is optional contact channel
- Bulk user import = CSV with username, name, group; email optional
- App assigns courses to groups, not individuals — so "enroll by group" is the primary path
- Notifications without email = in-app (dashboard badge, notification center)

**Critical dependency to verify (see Feature Dependencies):** v5.0.0 cert claim-binding must accept username as subject identifier when no email is present. If the current cert subject-binding uses `email` as the identifier, username-only users will fail cert issuance. **This must be verified against `CertificateService.php` before implementation.**

**TABLE STAKES — Username-Politur**

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Username-only users can enroll, pass, get cert | Without this, ~20% of enterprise orgs with service/kiosk accounts cannot use the app. AWO specifically named this. | LOW-MEDIUM | Most flows likely already work. Primary risk is cert subject-binding (see Dependencies). Audit trail: username is sufficient identifier for DSGVO Nachweis (name + employee-ID is the standard). |
| Username-only users appear correctly in compliance reports | Report must not show blank rows or crash when `email = null`. | LOW | NULL-safe display: show username where email would normally appear. Compliance CSV must include username column as primary identifier. |
| Bulk enrollment helper (CSV: username → course) | Admin with 2000 employees cannot enroll individually. | MEDIUM | CSV format: `username,course_id,group_id`. App processes enrollment rows. This is *enrollment* not *account creation* — NC admin tools handle account creation (`occ user:add`, admin UI). Explicitly separate these two concerns. |
| Group-based course assignment (enroll all group members) | Standard compliance deployment model: "All employees in group AWO-Pflege must complete Training X". | MEDIUM | Admin assigns course to NC group; all current group members are enrolled. New group members added later get auto-enrolled. Cron refreshes group membership. |

**DIFFERENTIATORS — Username-Politur**

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| NC Notifications for username-only users | These users have no email, so all reminders (recert, new assignment) must reach them via NC dashboard notification center. | LOW | NC Notifications API works without email. Must be primary reminder channel for Block 3 reminders. |
| CSV preview/dry-run before import | Admin uploads CSV, sees "12 users found, 3 not recognized, 1 already enrolled" before committing. | LOW-MEDIUM | Reduces import errors for large batches. |

**ANTI-FEATURES — Username-Politur**

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Building account creation within the app | "We want one CSV to create users AND enroll them" | NC provides `occ user:add`, LDAP sync, and admin UI for account creation. Duplicating this is massive scope and creates sync problems. | Document that account creation is NC-admin responsibility. App handles enrollment only. Provide CSV format spec for the enrollment step. |
| LDAP/AD synchronization within the app | "We want to sync from our directory" | NC already has LDAP integration at the platform level. Re-building it in the app is months of work with no advantage. | Rely on NC's LDAP/AD sync. Once users exist in NC, our enrollment layer works. |
| Email-less password reset within the app | "Users without email can't reset passwords" | This is NC account management, not LMS. | NC admin resets passwords. Not in scope. |

---

## Feature Dependencies

```
[Video Gating — NC-MP4]
    └──requires──> [NC-hosted file serving] (already exists)
    └──requires──> [Material progress table] (NEW: user_id, material_id, watched_seconds, max_watched, completed)
    └──gates──> [Quiz access] (existing quiz unlock flow)

[Video Gating — YouTube/Vimeo]
    └──degrades-to──> [Best-effort progress, no seek-prevention]
    └──requires──> [IFrame/Player API integration] (NEW, limited)

[Teamleiter-RBAC]
    └──requires──> [NC Groups] (already exists)
    └──requires──> [group_lead role table] (NEW: user_id, group_id)
    └──scopes──> [existing Compliance Report] (filter by group)
    └──enhances──> [Re-Zertifizierung overdue status] (team lead sees who is overdue)

[Re-Zertifizierung reminders]
    └──requires──> [NC Cron / background job] (NEW occ command)
    └──requires──> [NC Notifications API] (already exists in NC)
    └──CONFLICTS WITH email-as-primary──> [Username-only users] (see below)

[Re-Zertifizierung]
    └──builds-on──> [v5.0.0 expiry_date field] (already exists on cert)
    └──builds-on──> [v5.0.0 cert issuance flow] (re-use for re-issuance)
    └──requires──> [status computation layer] (NEW: Active/Expiring-Soon/Expired derived)

[Username-only users]
    └──CONFLICTS WITH──> [email-based reminders] (must use NC Notifications as primary)
    └──VERIFY──> [v5.0.0 cert claim-binding subject field] (does it accept username when email=null?)
    └──requires──> [NULL-safe display in all report columns] (LOW effort)

[Bulk enrollment CSV]
    └──requires──> [Group-based enrollment] (if group_id in CSV)
    └──does-NOT-require──> [Account creation] (NC-admin responsibility, out of scope)
```

### Dependency Notes

- **Re-Zertifizierung reminders conflict with Username-only users:** Email is unavailable for these users. The reminder system must use NC Notifications API as its *primary* channel, not email. Email becomes an optional secondary channel when the NC user has an email address configured. This is a cross-block design constraint.

- **Bulk enrollment requires Group-based enrollment:** These two features share the "course → group member" enrollment model. Implement group-based enrollment first; bulk CSV becomes a bulk group-assignment helper on top of it.

- **Video gating seek-prevention is source-dependent:** Seek prevention is implementable for NC-hosted MP4 (app controls the player). For YouTube and Vimeo embeds, the platform's own player controls seek — we cannot prevent it. This shapes the implementation contract: never promise 100% enforcement for external sources, only for NC-hosted MP4.

- **v5.0.0 cert subject-binding dependency (VERIFY BEFORE BUILD):** If `CertificateService.php` uses the NC user's `email` as the VC subject/claimant identifier, username-only users will produce certs with a null subject — breaking claim-binding and revocation. This must be audited and fixed (use NC `uid` as fallback subject) before any Block 4 work.

---

## MVP Definition

### Launch With (v5.2.0 — AWO contract-critical)

These are the minimum features Jan Knizek named explicitly. Without these, learning-nc loses the AWO account to Forma LMS.

- [ ] Video gating (NC-MP4): 100% watched → quiz unlocks, forward-seek prevention, resume
- [ ] Group-based course assignment (enroll NC group members)
- [ ] Teamleiter-RBAC: `group_lead` role, compliance report filtered to own group, CSV export
- [ ] Re-Zertifizierung: expiry status states (Active/Expiring-Soon/Expired), 30+7-day reminders via NC Notifications, re-enrollment path
- [ ] Username-only users: cert issuance and report display work without email (after claim-binding verification)
- [ ] Bulk enrollment CSV: username+course_id import

### Add After Validation (v5.2.x)

- [ ] YouTube/Vimeo best-effort progress tracking
- [ ] "Mark as read" for document materials (PDF acknowledgment)
- [ ] Grace period (14 days) on cert expiry
- [ ] Team-lead triggered in-app reminders to incomplete members
- [ ] Upcoming expirations panel on team-lead dashboard
- [ ] Configurable `cert_validity_months` per course (default 12)
- [ ] CSV bulk import dry-run / preview

### Future Consideration (v5.3+)

- [ ] Fixed-calendar recert (all-renew-by-date scheduling)
- [ ] Deadline (`due_date`) field per group-course assignment
- [ ] Multi-level manager hierarchy (two-level delegation)
- [ ] Automated email notifications (secondary channel, relies on NC mail config)

---

## Feature Prioritization Matrix

| Feature | Auditor Value | Operational Value | Implementation Cost | Priority |
|---------|---------------|-------------------|---------------------|----------|
| Video gating (NC-MP4) | HIGH (material consumption proof) | HIGH | MEDIUM | P1 |
| Group-based enrollment | MEDIUM | HIGH | MEDIUM | P1 |
| Teamleiter-RBAC report | LOW (auditor uses admin CSV) | HIGH (AWO ops) | MEDIUM | P1 |
| Re-Zertifizierung status + reminders | HIGH (expiry Nachweis) | HIGH | MEDIUM | P1 |
| Re-enrollment + new cert issuance | HIGH | HIGH | LOW | P1 |
| Username-only cert + report | MEDIUM | HIGH | LOW-MEDIUM | P1 |
| Bulk enrollment CSV | LOW | HIGH | MEDIUM | P1 |
| YouTube/Vimeo best-effort tracking | LOW | MEDIUM | MEDIUM | P2 |
| Document "mark as read" | MEDIUM | MEDIUM | LOW | P2 |
| Grace period on expiry | LOW | MEDIUM | LOW | P2 |
| CSV dry-run preview | LOW | MEDIUM | LOW-MEDIUM | P2 |
| Fixed-calendar recert | LOW | MEDIUM | HIGH | P3 |
| Two-level hierarchy | LOW | LOW | HIGH | P3 |

**Priority key:**
- P1: Must have for AWO contract / v5.2.0 launch
- P2: Quality-of-life, add in v5.2.x
- P3: Nice to have, future milestone

---

## Competitor Feature Analysis

| Feature | Forma LMS (SCORM) | Docebo / TalentLMS | learning-nc (v5.2.0 target) |
|---------|-------------------|--------------------|-------------------------------|
| Video gating | Via SCORM completion event (content author must build it in) | Built-in for hosted video; embed = author's problem | Built-in for NC-MP4; best-effort for YouTube/Vimeo |
| Seek prevention | Depends on SCORM content | Platform player: yes. External embed: no. | NC-MP4 player: yes. External: no (same limitation) |
| Manager/team-lead report | Yes (org chart model) | Yes (direct reports view) | Yes (NC group scoped, v5.2.0) |
| Recert / expiry lifecycle | Yes (standard SCORM cert lifecycle) | Yes (automated renewal workflows) | Yes (built on v5.0.0 `expiry_date`) |
| NC-native data residency | No (separate system) | No (SaaS) | YES — core differentiator |
| Signed verifiable credentials | No (PDF cert, no cryptographic proof) | No standard offering | YES (OB3/VC, Ed25519, v5.0.0) |
| Username-only users | Depends on deployment | Usually requires email | Yes (v5.2.0 target) |
| No second system | No (Forma = separate platform) | No (SaaS = separate platform) | YES — core differentiator |

**Our differentiation vs Forma LMS:** Data stays in the org's Nextcloud instance. No SCORM authoring required. Certs are cryptographically signed and machine-verifiable. Compliance report is already DSGVO-scoped. The v5.2.0 features close the operational gaps that Forma LMS covers, without requiring a second system.

---

## DSGVO / Nachweis Anchor

What a German compliance auditor (and the AWO Betriebsrat) actually needs as Nachweis:

1. **Who** completed training — username/name is sufficient (not email required)
2. **What** training — course name, course version
3. **When** — completion timestamp (immutable, stored in cert)
4. **Result** — pass/fail, score (optional but common)
5. **Authenticity** — cannot be backdated or forged (v5.0.0 Ed25519 signature covers this)
6. **Exportable** — as CSV for auditor's own records (v5.0.0 compliance CSV covers this)
7. **Current status** — who is overdue right now (v5.2.0 recert status layer)

**What auditors do NOT require (and we should not scope):**
- SCORM ADL-conformant data packages
- xAPI/LRS statement stores
- Per-minute video engagement analytics
- Proctored exam features
- Access blocking on expiry (org policy, not LMS)

---

## Sources

- [Absorb LMS: Compliance training reporting 101](https://www.absorblms.com/resources/articles/compliance-training-reporting-101-what-it-is-and-why-it-matters) — manager view and audit-ready reports
- [Certification Renewal Management — RenewOps](https://renewops.app/guides/recertification-reminder-workflow) — reminder cadence and grace period patterns
- [Moodle Workplace: Compliance training automation](https://moodle.com/news/simplify-automate-and-track-compliance-training-with-moodle-workplace/) — group assignment and recert
- [TutorLMS v2.2.4: Seek prevention feature](https://tutorlms.com/blog/tutor-lms-update-v2-2-4/) — forward-seek disable behavior
- [LernCampus24 — Compliance LMS für Compliance-Beauftragte](https://lerncampus24.de/fuer/compliance-beauftragte/) — German Nachweispflicht requirements
- [KI-Schulungspflicht 2026 — reteach.com](https://www.reteach.com/ki-schulungspflicht/) — statutory mandatory training scope 2026 in Germany
- [Valamis: Best Compliance Training Software 2026](https://www.valamis.com/blog/best-compliance-training-software) — feature landscape overview
- [Docebo: How to automate compliance training](https://www.docebo.com/learning-network/blog/how-to-automate-compliance-training/) — role-based reporting patterns
- [Forma LMS user management](https://formalms.org/index.php?id=29&option=com_content&view=category) — CSV bulk import behavior
- [WorkRamp: GDPR-compliant LMS platforms in Europe 2025](https://www.workramp.com/blog/best-lms-platforms-in-europe-for-gdpr-compliant-training-(2025)) — EU-specific requirements

---

*Feature research for: v5.2.0 Pflichtschulung / mandatory compliance training domain*
*Researched: 2026-07-01*
