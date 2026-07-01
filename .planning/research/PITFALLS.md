# Pitfalls Research

**Domain:** Compliance-training features added to a DSGVO-heavy native Nextcloud app (PHP/Vue 3 Options API, PG16+MariaDB, German welfare-org context)
**Milestone:** v5.2.0 Pflichtschulung — AWO-Readiness
**Researched:** 2026-07-01
**Confidence:** HIGH for code-grounded pitfalls (primary source); MEDIUM for legal pitfalls (cite statutes directionally; confirm with counsel)

---

## Critical Pitfalls

### Pitfall 1: Server-side Completion Gate — Plausibility Enforcement, Not Unspoofability

**What goes wrong:**
The video gate is implemented by listening to the `ended` event and POSTing `{completed: true}`. A developer can fire `videoEl.dispatchEvent(new Event('ended'))` in DevTools, or POST directly to the unlock endpoint with curl. The gate is bypassed in seconds.

**Why it happens:**
Developers trust browser events because they work fine in demos. The quiz unlock is tested by pressing play and waiting — the adversarial case (synthetic event) is never tried.

**How to avoid:**
Track covered time server-side via periodic heartbeats (e.g., every 5 seconds of actual playback the client POSTs a `{content_id, position_ms}` heartbeat). The server accumulates covered wall-clock time monotonically. Completion fires when `server_accumulated_seconds / declared_duration_seconds >= 0.95` AND the elapsed real time since the first heartbeat is plausible (e.g., >= 85% of declared duration). This defeats casual skipping and JavaScript event spoofing.

**Important framing:** This is "raises the bar + produces an audit trail," not tamper-proof. A scripted attacker who replays heartbeats at realtime cadence and mutes the browser tab still passes. For a 2000-employee AWO compliance context, plausibility enforcement is legally sufficient — the Nachweis is the cert, not the anti-skip mechanism itself. Do not oversell server-side enforcement as cryptographic proof.

**Critical sub-decision — segment data must be transient:**
The heartbeat state (which seconds were covered) is behavioral data. See Pitfall 2 below. Keep heartbeat state in a `video_progress` table during active sessions; delete the granular rows as soon as `completed_at` is set. The only record persisted permanently is `(user_id, content_id, completed_at)`. This satisfies both the anti-skip requirement and DSGVO data-minimization.

**Warning signs:**
- Completion is recorded via a single POST with no supporting state (no heartbeat table)
- Server reads `completed: true` from the client request body without independent verification
- PHPStan shows the unlock endpoint takes no server-validated arguments beyond the content ID

**Phase to address:** Video-Gating phase (first feature block)

---

### Pitfall 2: DSGVO + Betriebsrat — Watch-Tracking as Behavioral Monitoring

**What goes wrong:**
Storing granular viewing behavior (which segments a user watched, when they paused, how many attempts, time-of-day) constitutes behavioral monitoring (Verhaltens-/Leistungskontrolle) under German labor law. For a 2000-employee organization like AWO, the works council (Betriebsrat) has co-determination rights over any technical system enabling performance monitoring (commonly cited basis: § 87 Abs. 1 Nr. 6 BetrVG — confirm exact provision with labor counsel). Deploying without works-council sign-off exposes AWO to a formal objection that halts the rollout.

**Why it happens:**
The developer builds robust anti-skip tracking (legitimate technical goal), stores it all in the DB, and ships. No one on the dev side is thinking about § 87. The organization deploys. The Betriebsrat finds out and objects.

**How to avoid:**
- Design explicitly for data-minimization from day one: persist only `(user_id, content_id, completed_at TIMESTAMP)` permanently
- Heartbeat/segment data is session-transient: written to `video_progress`, deleted on `completed_at` write
- Never persist: play count, rewatch attempts, pause count, segment heatmap, time-of-day, device type
- Document the retention policy in `privacy-info.json` under a new category for compliance-training data
- Inform AWO that they need a Betriebsvereinbarung (works-council agreement) before production deployment — this is not the app developer's responsibility to obtain, but must be communicated as a prerequisite
- If AWO wants "who watched what and when" audit logs for their own HR purposes, that is a separate scope item requiring explicit Betriebsrat sign-off — do not build it by default

**Warning signs:**
- Any query like `SELECT user_id, segment_start, segment_end, paused_at FROM video_progress` in an endpoint accessible to team-leads or admins
- The team-lead report including "first accessed" or "number of attempts" fields beyond completion timestamp
- Missing entry for compliance-training data in `privacy-info.json`

**Phase to address:** Video-Gating phase (architecture decision) AND RBAC-Reports phase (confirm team report DTO doesn't expose behavioral detail)

---

### Pitfall 3: IDOR in Team-Lead Compliance Report

**What goes wrong:**
A team-lead POSTs `{group_id: 5}` to the new team-scoped report endpoint. The server looks up group 5's cert data without first verifying that the requesting user is actually a team-lead for group 5. By changing `group_id` to 6, the team-lead sees another department's compliance status.

**Why it happens:**
The existing `CertificateReportService` has the gate-before-read pattern (`assertInstructorOfCourse()` runs FIRST, before any mapper call). A new team-lead report path is built quickly and the gate is forgotten or placed after the data fetch for convenience.

**How to avoid:**
Follow the identical pattern from `CertificateReportService::getCourseReport()`:
```php
// Gate BEFORE any read — must run first, always
$this->teamLeadService->assertTeamLeadForGroup($requestingUserId, $groupId);
// Only after gate: read certs
$certs = $this->certificateMapper->findByGroupAndCourse($groupId, $courseId, ...);
```
The group membership check must query NC's actual group table (via `IGroupManager`), not trust a group_id parameter alone.

**Additional sub-trap — NC subadmin semantics:**
NC's native "subadmin" role lets a user manage members of a group. Do not conflate subadmin with the app's team-lead role. Subadmin access lets users add/remove group members — a subadmin who adds themselves to another group would gain access to that group's report if authorization is based solely on NC subadmin. Build a separate `learning_team_leads` table with explicit `(user_id, group_id, course_id)` tuples, or use a dedicated NC group with a scoped prefix (e.g., `learning-teamlead-{group_id}`), and only grant report access based on that mapping.

**Warning signs:**
- Controller reads `$groupId` from request before calling any authorization check
- Tests only cover "happy path: team-lead queries own group" — no test for cross-group probe
- NC subadmin flag used as the sole authorization signal

**Phase to address:** RBAC-Reports phase

---

### Pitfall 4: PII Leakage in Team-Lead Report DTO

**What goes wrong:**
The team report endpoint returns `user_id` or a raw NC username in the JSON/CSV, exposing identity data to team-leads who should see aggregate compliance status, not internal system identifiers.

**Why it happens:**
The existing `CertificateReportService` protects against this via strict 5-field DTO: `{display_name, passed_at, score, expires_at, verification_id}`. The `display_name` is recovered from the frozen VC-JWT payload, not from the current NC user record — and any email-shaped name is replaced with `'Teilnehmer:in'` (defense-in-depth via `looksLikeEmail()`). A new team-lead path built from scratch or from a different mapper method may not replicate these protections.

**How to avoid:**
- Reuse `CertificateReportService::getCourseReport()` as the data source for team-lead reports — add a group filter inside the service, not in a parallel code path
- Never add `user_id`, `nc_username`, or any account identifier to the DTO
- Apply the same `looksLikeEmail()` guard when projecting display names on the team path
- If the team-lead needs to "find" a specific person, they can cross-reference the frozen `display_name` from the cert — they cannot get the system identifier

**Warning signs:**
- Any `SELECT user_id` alongside cert columns in a team-report query
- A `user_id` or `username` field appearing in the JSON response or CSV export

**Phase to address:** RBAC-Reports phase

---

### Pitfall 5: DST-Unsafe Annual Expiry Calculation

**What goes wrong:**
Re-certification adds one year to the cert's `issued_at` timestamp using integer arithmetic (`+365 * 86400`). In Europe (CET/CEST), the DST transition makes one day 23 hours and another 25 hours. The expiry date lands a day early or late, and is wrong by one hour in the stored UTC timestamp even if the date appears correct.

**Why it happens:**
Integer arithmetic is the obvious approach and works fine for short durations. No test exercises a cert issued on a DST boundary.

**How to avoid:**
Always use `DateTimeImmutable::modify('+1 year')` for annual expiry, never `+365*86400`. Store expiry as a `DATE` column (`YYYY-MM-DD`) alongside any Unix timestamp column, so the intent is clear and DB queries can use date comparisons without DST confusion. Example:
```php
$expiresAt = (new \DateTimeImmutable())->setTimestamp($issuedAt)->modify('+1 year');
$expiryDate = $expiresAt->format('Y-m-d');
$expiryTimestamp = $expiresAt->getTimestamp();
```

**Warning signs:**
- `expiry_date = issued_at + 365 * 86400` in any migration or service
- No test with a cert issued on 2026-03-29 (CET→CEST transition) or 2026-10-25 (CEST→CET)

**Phase to address:** Re-Zertifizierung phase

---

### Pitfall 6: Re-Cert Reminder Storm — Missing Per-Cert-Per-Threshold Idempotency

**What goes wrong:**
The existing `SendRemindersJob` runs hourly. Re-cert reminders ("your cert expires in 30/14/7/1 days") fire for every matching cert on every hourly run, sending dozens of duplicate notifications on the same day.

**Why it happens:**
The existing exam-reminder guard keys on `$examDate . ':' . $daysUntilExam` per course. A new re-cert path may guard only on "sent today" per user, missing the per-cert-per-threshold dimension. A user enrolled in 10 courses with expiring certs gets 10 notifications in quick succession.

**How to avoid:**
Use the same composite-key idempotency pattern as `sendExamReminders`:
```php
$configKey = 'last_recert_reminder_' . $certId;
$marker     = $expiryDate . ':' . $daysUntilExpiry;
if ($this->config->getUserValue($userId, 'learning', $configKey, '') === $marker) {
    continue;
}
```
Key per `(certId, threshold)` — not per user, not per course alone, not per day alone. Alternatively, introduce a `sent_recert_reminders` DB table with unique constraint `(cert_id, threshold_days)` — cleaner than per-user config proliferation for 2000 users × many certs.

**Warning signs:**
- Re-cert reminder guard uses only a per-user-per-day key without including cert ID and threshold
- No test that verifies a cert fires exactly once per threshold level

**Phase to address:** Re-Zertifizierung phase

---

### Pitfall 7: Re-Enrollment Corrupting Cert History or Breaking Verify URLs

**What goes wrong:**
Re-enrollment for the same course modifies the existing `learning_cert` row, superseding the old VC-JWT. The old `verification_id` URL now returns "not found" or "revoked." An auditor who stored the old cert URL finds it broken.

**Why it happens:**
Treating re-certification as an update to the existing cert record is the natural DB-normalizer instinct: one cert per user per course, replace it when re-certified.

**How to avoid:**
Each certification cycle creates a NEW cert row with a new `verification_id`. The old cert row is never deleted or modified (immutable VC-JWTs). Mark old certs as `superseded_by: <new_cert_verification_id>` in the JWT payload at issuance time if desired — but the old row and its verify URL must remain permanently valid. The compliance report should show the most recent cert, with a "history" view for the full chain.

**Warning signs:**
- Any `UPDATE learning_certs SET ... WHERE user_id = ? AND course_id = ?` in the re-enrollment path
- Verify endpoint returning 404 for a verification_id that was previously valid

**Phase to address:** Re-Zertifizierung phase

---

### Pitfall 8: No-Email User — Cert and Welcome Notification Silently Dropped

**What goes wrong:**
NC's `INotificationManager` (what the existing `ReminderService` uses) works fine without a user email address. However, the CSV upload feature creates new NC accounts, and NC's native account-creation flow may attempt to send a welcome email. If the email field is blank, the mail send fails silently — the user doesn't know their account exists and can't log in. Additionally, if any future re-cert reminder is implemented via NC's mail-notification channel (distinct from in-app bell), users without email silently don't get it.

**Why it happens:**
The distinction between NC's in-app notification (bell, via `INotificationManager`) and NC's email notification (via `IMailer` or NC's built-in "send password link by mail") is easy to blur. CSV user creation typically needs to communicate credentials to the new user somehow.

**How to avoid:**
- Use ONLY `INotificationManager` for all reminder and re-cert notification paths — never introduce `IMailer` into the app's own notification flow
- For CSV user creation: do not rely on NC's built-in welcome email. Instead, generate a one-time password-reset token via `IToken` or have an admin distribute credentials manually. Document this in the CSV upload UI: "Users without email will not receive an automated welcome — distribute credentials manually."
- Before sending ANY notification, check if the user has an email via `$user->getEMailAddress()`. If null/empty AND the notification is email-dependent, fall back to in-app bell only, and log `[no-email user: {userId}, notification {type} skipped for mail channel]`
- Screen at CSV import time: warn if a row has no email column, don't silently ignore it

**Warning signs:**
- Any `IMailer` dependency in a service that handles re-cert reminders
- CSV import that creates users without displaying which ones have no email
- No test with a no-email user in the re-cert reminder suite

**Phase to address:** Username-Politur + CSV phase AND Re-Zertifizierung phase (must coordinate)

---

### Pitfall 9: CSV Injection in User Export / Import

**What goes wrong:**
A user's display name containing `=CMD(...)`, `+HYPERLINK(...)`, or `@SUM(...)` is written directly into a CSV cell. A team-lead opens the compliance-report CSV export in Excel — the formula executes.

**Why it happens:**
PHP's `fputcsv()` correctly escapes CSV delimiters but does not sanitize spreadsheet formula starters. The existing export tools (v4.2.0) may already handle this for known data, but new CSV paths (user upload, team report CSV) may not.

**How to avoid:**
Sanitize any cell that begins with `=`, `+`, `-`, or `@` by prefixing with a tab or space character before writing:
```php
$safe = preg_replace('/^([=+\-@])/', "\t$1", $displayName);
```
Also output a UTF-8 BOM (`\xEF\xBB\xBF`) at the start of CSV downloads for correct German Umlaut rendering in Excel on Windows.

**Warning signs:**
- `fputcsv()` called on user-supplied display names without a sanitization step
- No test with a display name starting with `=`

**Phase to address:** Username-Politur + CSV phase

---

### Pitfall 10: YouTube / Vimeo IFrame API — DSGVO Consent + Event Reliability

**What goes wrong:**
Embedding a YouTube video loads `www.youtube.com/iframe_api` — a Google tracking script — on page load, without user consent. This is a DSGVO violation under Art. 6 DSGVO in Germany for German organizations. Separately, the `YT.Player.onStateChange` ENDED event does not fire if the YouTube API script is blocked by an ad-blocker or browser privacy setting — the user appears stuck at the gate even after genuinely watching.

**Why it happens:**
The IFrame API is the documented way to detect `ended` events on YouTube. The consent requirement is often overlooked when embedding for internal training use ("it's not a public site").

**How to avoid:**
- Use `youtube-nocookie.com` as the iframe domain to prevent cookies on initial load. This reduces but does not eliminate the consent obligation — external JS is still loaded
- Implement a two-click consent pattern: first click shows a privacy notice ("clicking loads YouTube and may transfer data to Google"), second click loads the iframe. NC's own consent infrastructure (`consentService`) or a simple overlay div is sufficient
- For the API-blocked case: implement a fallback "I confirm I have watched this video" manual acknowledgment button that appears after the video duration has elapsed in wall-clock time. This is less robust but prevents a hard gate block
- Vimeo has better EU-hosting options but still requires consent for its tracking scripts
- NC-hosted MP4 (the first source type) avoids all third-party consent issues — recommend it as the default for German compliance use cases

**Warning signs:**
- `<iframe src="https://www.youtube.com/embed/...">` in the component without a consent overlay
- Video gate tests run only with NC-MP4, never with the YT/Vimeo embed path
- `ytReady` state tracked only on the IFrame API `onReady` event with no fallback for blocked-API case

**Phase to address:** Video-Gating phase

---

### Pitfall 11: NC-Hosted MP4 — Range-Request Seeking Defeats Covered-Time Tracking

**What goes wrong:**
NC serves MP4 files via HTTP with `Content-Range` support. The browser's `<video>` element uses range requests to seek to arbitrary positions. A user can drag the playhead to 99% and trigger `ended` without watching the middle 80 minutes. Client-side `seeked` event interception is easily bypassed (set `event.preventDefault()` and the browser ignores it for buffered media).

**Why it happens:**
Developer tests playback from start to finish, `ended` fires, quiz unlocks. The seek-to-end case is not tested because it feels obvious that users wouldn't do that.

**How to avoid:**
This is resolved by the heartbeat approach (Pitfall 1): the server accumulates heartbeats representing actual playback positions; seeking forward produces a gap in the covered-time map; the completion threshold (`covered_seconds / declared_duration >= 0.95`) is not met. The server's `declared_duration` must be validated at content-item creation time (not trusted from client), either by reading MP4 duration via `ffprobe` at upload or by requiring the admin to input it.

If ffprobe is not available on the relay container: require the admin to enter video duration in seconds when creating the content item — reject a zero or missing duration, because without it the completion threshold cannot be computed.

**Warning signs:**
- Completion logic uses only `ended` event, no heartbeat table
- `declared_duration` column can be null or zero in the DB
- No integration test that seeks to 99% and verifies the gate does NOT open

**Phase to address:** Video-Gating phase

---

### Pitfall 12: CSV Bulk User Creation — Synchronous Timeout for 2000 Users

**What goes wrong:**
Uploading a CSV of 2000 users triggers `IUserManager->createUser()` 2000 times synchronously in an HTTP request. PHP-FPM / Apache times out at 60–120 seconds. The controller returns a 504 or 500 mid-way through. Partial user sets are created; the admin doesn't know which ones succeeded.

**Why it happens:**
The happy path (small CSV, 10–50 users) works fine in testing. Scale is not tested.

**How to avoid:**
Process CSV in a BackgroundJob (queue the rows, return a job-ID immediately). The admin polls a status endpoint (`GET /api/csv-import/{jobId}/status`) that returns `{processed, total, errors: [{row, reason}]}`. Alternatively, process synchronously but cap the import at a configurable maximum (e.g., 200 rows per request) with a clear error message.

Before calling `createUser`, always call `IUserManager->userExists($uid)` to skip duplicates — and add a uniqueness check on `uid` vs `displayName` from the CSV, since NC UIDs must be unique.

**Warning signs:**
- Controller calls `createUser` in a foreach loop over all rows without chunking or queuing
- No test with a 500-row CSV
- Import endpoint has no timeout protection (no `set_time_limit`)

**Phase to address:** Username-Politur + CSV phase

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| `ended` event as completion signal | Zero server infrastructure | Trivially bypassed, no audit trail | Never — a compliance Nachweis requires server-side evidence |
| Store all heartbeat segments permanently | Simplifies query (no delete step) | DSGVO violation; potential Betriebsrat veto | Never for behavioral segments; always delete on completion |
| NC subadmin flag as team-lead gate | No new DB table | Subadmin can manipulate group membership to escalate access | Never — maintain a separate `learning_team_leads` mapping |
| `issued_at + 365*86400` for annual expiry | One line vs DateTimeImmutable | DST-wrong dates; off by one in spring/autumn | Never for calendar-aligned annual dates |
| Synchronous CSV import (all rows, one request) | Simpler controller | 504 timeout at scale; partial state on failure | Only for < 50 rows with documented limit in UI |
| Flat per-user reminder guard (not per-cert) | Simple key | Duplicate notifications on multi-cert users | Never — use per-cert-per-threshold composite key |

---

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| YouTube IFrame API | Embed `youtube.com` without consent; rely on `onStateChange` without fallback | Use `youtube-nocookie.com` + consent overlay; implement manual-confirm fallback for API-blocked case |
| Vimeo embed | Assume `postMessage` events always fire | Same consent requirement as YT; `postMessage` blocked by sandboxed iframe or strict CSP — add fallback |
| NC-served MP4 | Assume `ended` means "watched" | Use heartbeat API + covered-seconds threshold; get `declared_duration` at content-item creation |
| NC `IUserManager->createUser()` | Call in tight loop for bulk import | Queue via BackgroundJob; return job-ID; poll status; pre-check `userExists()` |
| NC `IGroupManager` for team-lead gate | Trust `group_id` from request body | Call `$groupManager->isInGroup($teamLeadId, $groupId)` server-side, not from param |
| MariaDB date arithmetic | `DATE_ADD(col, INTERVAL 1 YEAR)` in SQL | Use PHP `DateTimeImmutable::modify('+1 year')` — portable across PG and MariaDB |

---

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Heartbeat endpoint called every 5s × N concurrent users | Database write spike; `video_progress` table hot spot | Batch heartbeats client-side (buffer 3 positions, flush every 15s); or use an in-memory upsert with `ON CONFLICT DO UPDATE` | 50+ concurrent video watchers |
| Team-lead report with no index on `(course_id, group_id)` in cert table | Slow report for large orgs | Add a composite index on `(course_id, issued_at)` on `learning_certs`; team filtering is a JOIN on group members | 500+ certs in a course |
| CSV import loading all 2000 rows into memory at once | PHP memory exhaustion (128MB limit) | Use `SplFileObject` line-by-line iteration, not `file_get_contents` + `str_getcsv` on the whole file | CSV > ~5000 rows |
| Reminder job scanning all users for expiring certs hourly | O(users × certs) query every hour for 2000 users | Index `learning_certs.expires_at`; narrow the scan window with `WHERE expires_at BETWEEN now() AND now() + 31 days` | 2000 users × 10 certs = 20000 rows per hourly scan |

---

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| Team-lead report reads cert data before authorization gate | IDOR — exposes another department's compliance status | Gate-before-read: call `assertTeamLeadForGroup()` as first line of service method, mirror `CertificateReportService` pattern |
| `user_id` or `nc_username` in team report DTO | PII leakage to team-leads | Strict 5-field DTO (display_name, passed_at, score, expires_at, verification_id); no system identifier |
| Video completion accepted from client-side flag | Bypass of training gate; invalid Nachweis | Server accumulates heartbeats; completion only from server-side threshold check |
| CSV upload without row sanitization | CSV injection (formula execution in Excel) | Prefix formula-starting chars (`=+−@`) with tab before `fputcsv` |
| YouTube embed loaded without consent | DSGVO Art. 6 violation | Two-click consent overlay before iframe loads |
| Re-cert reminder reads expiry from request param | Attackers can claim early expiry, trigger re-enrollment | Read expiry exclusively from `learning_certs.expires_at` in DB |

---

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| Hard lockout from course on cert expiry | User who hasn't re-enrolled yet can't access training content | Show warning banner ("Ihr Zertifikat ist abgelaufen — bitte neu anmelden"), but keep content accessible |
| Video player autoplay on mobile | Player silently fails; user sees frozen frame, doesn't know to press play | Never set `autoplay`; show a prominent play button overlay |
| Gate blocks quiz because IFrame API was blocked by adblock | User watched genuinely but can't proceed | Show "Ich habe das Video vollständig gesehen" confirm button after declared_duration elapses in wall-clock time |
| No-email user sees "check your email" after CSV onboarding | User confused, can't log in | Upload UI must show per-row email status; for no-email rows, show "Zugangsdaten bitte manuell übermitteln" |
| Re-cert reminder in-app notification stack of 10 (one per expiring cert) | Notification center flooded; users dismiss all without reading | Group multiple expiring certs into a single digest notification: "3 Ihrer Zertifikate laufen in 7 Tagen ab" |

---

## "Looks Done But Isn't" Checklist

- [ ] **Video gate works in demo:** Tested with `ended` event → untested against range-request seek-to-end; verify the heartbeat server-side threshold fires and the gate does NOT open after a seek
- [ ] **Team report filters by group:** Response payload inspected by eye → still contains `user_id` in JSON; verify strict 5-field DTO with no system identifier
- [ ] **No-email user can enroll:** User creates and enrolls → welcome/cert email silently fails; verify no `IMailer` call on the critical path and that the UI surfaces the "no email" warning
- [ ] **Re-cert reminder sends once:** Reminder fired in dev with one cert → fires N times for a user with N expiring certs; verify per-cert-per-threshold idempotency key
- [ ] **Annual expiry looks right in UI:** Date appears correct in UI → stored as `issued_at + 365*86400`, wrong on DST boundary; verify via `DateTimeImmutable::modify('+1 year')`
- [ ] **CSV import succeeds:** 10-user test CSV imports cleanly → 2000-user CSV times out at 90s; verify via BackgroundJob path or row-cap with error message
- [ ] **Old cert verify URL still works after re-enrollment:** New cert issued → old `verification_id` returns 404; verify old cert row is unchanged and old URL still returns valid
- [ ] **YouTube embed blocked by adblock:** Gate works with NC-MP4 → stuck on YT embed with blocked API; verify fallback "confirmed watched" button appears after elapsed time

---

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Client-side gate shipped, discovery that it's spoofable | HIGH | Add heartbeat table via migration; gate endpoint checks new table; re-issue affected certs (new verification_id); notify affected course instructors |
| IDOR in team report discovered after release | HIGH | Emergency: disable team-report endpoint; add gate; re-enable; audit access logs for unauthorized queries; notify DPO |
| PII leakage (user_id in team DTO) discovered | HIGH | Pull the endpoint; apply DTO fix; check if any team-lead CSV exports exist (request deletion from team-leads); DPO notification likely required |
| DST-wrong expiry for a batch of certs | MEDIUM | Migration: `UPDATE learning_certs SET expires_at = ... WHERE expires_at BETWEEN ...` recalculating via PHP; notify affected users of corrected date |
| Re-cert reminder storm (duplicates sent) | MEDIUM | Disable `SendRemindersJob` temporarily; add idempotency guard; re-enable; NC notification duplicates can be marked-read but not unsent |
| CSV import partial failure at 2000 users | MEDIUM | Query `learning_certs` or NC user table for successful rows; resume from last successful UID; BackgroundJob pattern prevents this |
| Betriebsrat veto after behavioral data already stored | VERY HIGH | Data deletion required under BDSG/DSGVO; potential co-determination violation; retroactive works-council negotiation. Prevention is the only acceptable strategy |

---

## Pitfall-to-Phase Mapping

| Pitfall | Feature Block / Phase | Verification |
|---------|-----------------------|--------------|
| Client-side gate spoofable | Video-Gating | Integration test: POST fake `ended` event → gate must NOT open without server-side heartbeat threshold |
| DSGVO/Betriebsrat watch-tracking | Video-Gating (architecture) | Code review: `video_progress` rows deleted on `completed_at` write; `privacy-info.json` updated |
| Range-request seeking defeats covered-time | Video-Gating | Test: seek to 99% via JS → gate must NOT open; `declared_duration` NOT NULL constraint in migration |
| YT/Vimeo IFrame consent + reliability | Video-Gating | Manual test: block YT API via DevTools → fallback button appears; consent overlay shown on first embed |
| IDOR in team-lead report | RBAC-Reports | Security test: team-lead queries a different group_id → 403; gate runs before any mapper call |
| PII leakage in team DTO | RBAC-Reports | Unit test: DTO fields enumerated, assert no `user_id` or `username` key; `looksLikeEmail()` guard present |
| NC subadmin conflation | RBAC-Reports | Code review: authorization reads from `learning_team_leads` table, not NC subadmin flag |
| DST-unsafe annual expiry | Re-Zertifizierung | Unit test with cert issued on 2026-03-29 (CET→CEST); assert expiry is exactly 2027-03-29 |
| Reminder storm (duplicate notifications) | Re-Zertifizierung | Test: run reminder job 3× with same cert expiring in 7 days → assert 1 notification, not 3 |
| Re-enrollment corrupts cert history | Re-Zertifizierung | Test: re-enroll user; assert old `verification_id` still resolves; old cert row unchanged |
| No-email user — notification path | Re-Zertifizierung + CSV | Test: create no-email user; trigger re-cert reminder → NC bell notification fires; no `IMailer` call |
| CSV injection | CSV phase | Unit test: display_name starting with `=SUM(...)` → exported cell begins with `\t=SUM` |
| Bulk CSV timeout | CSV phase | Test: 500-row CSV import returns job-ID immediately, status endpoint shows progress |

---

## Sources

- Primary source: `app/lib/Service/CertificateReportService.php` — gate-before-read + DTO contract pattern (HIGH confidence)
- Primary source: `app/lib/Service/ReminderService.php` + `app/lib/BackgroundJob/SendRemindersJob.php` — per-marker idempotency pattern, NC-notification-only path, no IMailer (HIGH confidence)
- Primary source: `app/lib/BackgroundJob/SendRemindersJob.php` — hourly run with hour-gated time-of-day routing (HIGH confidence)
- `.planning/PROJECT.md` — v5.2.0 scope, AWO context, existing DSGVO infrastructure (HIGH confidence)
- BetrVG § 87 Abs. 1 Nr. 6 — co-determination for technical monitoring systems; cited directionally, confirm exact provision with labor counsel (MEDIUM confidence)
- DSGVO Art. 5 data minimization, Art. 6 lawful basis for third-party script loading — well-established in German DPA guidance (HIGH confidence)
- YouTube IFrame API `onStateChange` reliability with ad-blockers — widely documented in web dev community (MEDIUM confidence, no single authoritative source)
- MariaDB/PG portability: DBAL abstraction, PHP-side date arithmetic — established from prior codebase patterns (`feedback_mysql_testing.md`) (HIGH confidence)

---
*Pitfalls research for: v5.2.0 Pflichtschulung compliance-training features on learning-nc*
*Researched: 2026-07-01*
