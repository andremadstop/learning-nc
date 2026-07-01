# Stack Research — v5.2.0 Pflichtschulung

**Domain:** Compliance-training feature additions to existing Nextcloud native app
**Researched:** 2026-07-01
**Confidence:** HIGH for PHP/NC API choices; MEDIUM for NC Range-request behavior under varied server configs (endpoint verified reachable; byte-range serving with valid credentials not live-tested)

---

## 1. Video Gating — Technology Choices per Source

### MP4 Access Model — Critical Architecture Point

Course materials (including MP4 files) live in the **instructor's** NC namespace. `DocumentService` uses `IRootFolder->getUserFolder($instructorId)->get($path)` — students have no direct access to `/remote.php/dav/files/{instructorId}/...` (would return 403). `IShareManager` is not used anywhere in the existing app (confirmed: zero imports).

Two viable paths:

**Option A — App Streaming Controller (recommended)**

A new `VideoStreamController` endpoint:
1. Verifies student enrollment in the course via existing `CourseService`.
2. Opens the file server-side: `$this->rootFolder->getUserFolder($instructorId)->get($filePath)->fopen('r')`.
3. Parses the incoming `Range:` header and implements partial content (206) via `fseek()` + chunked `fread()`.
4. Returns correct `Content-Range`, `Accept-Ranges: bytes`, `Content-Length` headers.

Why recommended: enrollment authorization stays in the app layer; no dependency on NC's sharing configuration; the access path is fully controlled. `OCP\Files\File::fopen()` returns a PHP stream resource on which `fseek()` is reliable for local-disk and S3 object storage backends alike.

**Option B — NC Share-based**

When a course is published, use `IShareManager` to create a group share of the material folder to the enrolled NC group. Students can then access via `/remote.php/dav/files/{studentId}/{sharedFolderAlias}/video.mp4`. SabreDAV handles Range natively.

Why not recommended for v5.2.0: `IShareManager` is currently unused in the app; adds new NC API surface area, share lifecycle management (enrollment/unenrollment must update shares), and share-permission changes are harder to audit than an enrollment-gated controller.

**Range request status:** SabreDAV supports `Range:` for file downloads. NC uses `X-Accel-Redirect` / `X-Sendfile` in the DAV path, so Range is handled by the web server (nginx/Apache), not PHP, for direct WebDAV GET requests. For the App Controller (Option A), PHP-level Range serving is required. This is well-established in PHP (`fseek` + `Content-Range` response pattern), but must be implemented explicitly. MEDIUM confidence on behavior under all NC storage backends — verify with `curl -I -H "Range: bytes=0-1023" -u user:apppassword https://devcloud.andrestiebitz.de/remote.php/dav/files/...` during Phase implementation.

---

### (a) NC-hosted MP4 — Native HTML5 `<video>`

**Zero new deps. No library needed.**

The `<video src>` attribute points to the new `VideoStreamController` endpoint:

```
/apps/learning/api/v1/video-stream?course_id=X&file=encryptedRef
```

The browser sends the NC session cookie automatically. The controller streams bytes with proper `206 Partial Content` responses.

**Anti-skip algorithm (vanilla JS, no dep):**

Track covered intervals using a merge-on-insert approach:

1. On `timeupdate` (~4 Hz): append `[prevTime, currentTime]` to a local `coveredIntervals` array, then merge overlapping intervals.
2. On `seeking`: record `seekFromTime = currentTime` but do NOT add the gap to covered set.
3. On `seeked`: if new `currentTime > seekFromTime + skipThreshold` (recommend 5 s), the skipped span is never added — coverage cannot be faked by seeking to end.
4. Gate opens when `totalCoveredSeconds / duration >= 0.95` (95% allows last-frame buffer gap).
5. `ended` alone is NOT sufficient — user can seek to last second before it fires.

**Event surface (standard HTML5, no library):**

| Event | When | Key property |
|-------|------|-------------|
| `timeupdate` | ~4 Hz during play | `video.currentTime` |
| `seeking` | scrub starts | `video.currentTime` (seek target) |
| `seeked` | scrub complete | `video.currentTime` |
| `ended` | natural end | — |

---

### (b) Vimeo Embed — Vimeo Player SDK

**Load from CDN at runtime. Do NOT add `@vimeo/player` to package.json.**

```html
<script src="https://player.vimeo.com/api/player.js"></script>
```

Vimeo's own recommended integration path. The global `Vimeo.Player` constructor becomes available after load. Zero bundle-size impact — loaded lazily only when a Vimeo video is present on the page.

**Current npm version: 2.30.4** (April 2026, verified from npm registry and official GitHub). Only relevant if bundling is reconsidered; the CDN URL always serves the latest release.

**Available events for anti-skip:**

| Event | Returns | Use for |
|-------|---------|---------|
| `timeupdate` | `{duration, percent, seconds}` — fires ~every 250 ms | Interval tracking |
| `seeking` | `{duration, percent, seconds}` | Mark seek start |
| `seeked` | `{duration, percent, seconds}` | Detect forward jump |
| `ended` | `{duration, percent: 1, seconds: [total]}` | Supplemental check |

Same 95% coverage algorithm as native; use `seeking`/`seeked` events instead of DOM events.

**Trust limitation (document in implementation):** The SDK runs inside a cross-origin iframe. The server can only trust what the SDK reports via `postMessage`. A determined actor can forge POST payloads. This is an accepted limitation — identical to all SaaS LMS platforms using Vimeo embeds. Document in the compliance data model so AWO understands the boundary.

**DSGVO — MUST use `player.vimeo.com` with `dnt=1` parameter and consent gate.** Vimeo loads tracking on embed. For AWO (mandatory training for 2000 employees), the embed must not fire before explicit consent, or use the privacy-enhanced mode:

```
https://player.vimeo.com/video/{id}?dnt=1
```

The app already has a consent system — the Vimeo embed must be consent-gated.

---

### (c) YouTube Embed — YouTube IFrame Player API

**Load from CDN at runtime. No npm dep, no version number (living API).**

```html
<script src="https://www.youtube.com/iframe_api"></script>
```

After load, `YT.Player` constructor is available. Google maintains backward compatibility at a fixed URL; no semantic version exists.

**There is no `timeupdate` equivalent.** Must poll `getCurrentTime()`:

```js
setInterval(() => {
  const current = player.getCurrentTime();
  trackInterval(prevTime, current); // same merge logic
  prevTime = current;
}, 250);
```

**Player state constants:**

| Constant | Value | Meaning |
|----------|-------|---------|
| `YT.PlayerState.ENDED` | 0 | Natural end |
| `YT.PlayerState.PLAYING` | 1 | Playback active |
| `YT.PlayerState.PAUSED` | 2 | Paused |
| `YT.PlayerState.BUFFERING` | 3 | Buffering |

**Known bug:** `onStateChange` sometimes does not fire `ENDED` (0) on replay within the same player instance. Mitigation: check `getCurrentTime() >= getDuration() * 0.98` in the poll interval as a parallel completion signal.

Same cross-origin trust limitation as Vimeo applies.

**DSGVO — MUST use `youtube-nocookie.com` domain and consent gate.** YouTube embeds via `youtube.com` load Google tracking on page load. Use the privacy-enhanced domain:

```
https://www.youtube-nocookie.com/embed/{videoId}
```

Consent gate applies before the iframe is injected. The existing consent system (DSGVO layer, v3.5.0) is the correct hook point.

---

### Server-Side Validation — Critical Architecture Point

This is a compliance product for 2000 employees with Nachweispflicht. **Client-side tracking alone is legally insufficient and trivially spoofable.** The architecture must be:

1. **Client tracks intervals locally** (the algorithms above).
2. **Client POSTs progress checkpoints** to `POST /apps/learning/api/v1/video-progress` every N seconds or on meaningful events (play-pause, interval merge, completion). No WebSocket needed — POST polling fits the existing no-WebSocket constraint (same pattern as `CoopService`).
3. **Server merges and stores intervals** in a new `learning_video_progress` table.
4. **Server makes the unlock decision** — quiz gate queries `is_complete = TRUE` from the server-side record, never trusts a client-passed parameter.

**New table `learning_video_progress`** (migration Version009300, suggested schema):

```sql
id            BIGINT AUTOINCREMENT NOT NULL  -- surrogate PK, NOT composite on video_ref
user_id       VARCHAR(64)  NOT NULL
course_id     INTEGER      NOT NULL
video_ref_hash CHAR(64)    NOT NULL           -- SHA-256 of the canonical video reference
video_ref     TEXT         NOT NULL           -- full NC path or embed URL (non-indexed)
intervals_json TEXT        NOT NULL DEFAULT '[]'
covered_pct   FLOAT        NOT NULL DEFAULT 0.0
is_complete   BOOLEAN      NOT NULL DEFAULT false
updated_at    BIGINT       NOT NULL
UNIQUE (user_id, course_id, video_ref_hash)
```

Migration constraints (mirror existing style):

- Table name WITHOUT `oc_` prefix (NC auto-prepends).
- **Do NOT use `VARCHAR(512)` as part of a composite primary key** — utf8mb4 encoding pushes 512-char columns to ~2 KB of index key, uncomfortably close to MariaDB 11.4's InnoDB row-format-dependent limits and using a URL as PK is fragile. Use a surrogate BIGINT PK + `CHAR(64)` hash column in the unique index.
- `Types::TEXT` for `intervals_json` — NOT JSONB (PG16-only) or MEDIUMTEXT (MariaDB-specific). `TEXT` is portable.
- `Types::FLOAT` for `covered_pct`.
- `Types::BOOLEAN` with `['notnull' => true, 'default' => false]`.
- Must pass on both PostgreSQL 16 and MariaDB 11.4 — validate with `composer test` on both targets before merge.

---

## 2. RBAC — Team Lead Role

**No new deps. Extend `RoleService` and `CertificateReportService`.**

### Extension points in existing code

`RoleService` (already uses `IGroupManager` + `IConfig` + `IDBConnection`) is the correct seeding point. Add:

- New table `learning_teamlead_assignments (id BIGINT PK, user_id VARCHAR(64), group_id VARCHAR(64), created_at BIGINT)` — maps a NC user to the NC group they lead within this app.
- New method `RoleService::isTeamLeadForGroup(string $userId, string $groupId): bool`
- New method `RoleService::getTeamLeadGroups(string $userId): array` — returns `string[]` of NC group IDs

`CertificateReportService.getCourseReport()` gates on `assertInstructorOfCourse` — a team lead is NOT an instructor. Add a new method:

```php
public function getGroupReport(int $courseId, string $userId, string $groupId, ...): array
```

Authorization flow: assert `isTeamLeadForGroup($userId, $groupId)` FIRST (throws ForbiddenException on failure, same IDOR-safe pattern as the instructor gate). Then fetch member UIDs via `IGroupManager->get($groupId)->getUsers()`. Filter certificate rows to those UIDs **internally** — the group→member mapping stays server-side only. Apply the existing `projectRow()` pseudonymization before returning. No account identifiers appear in the output DTO (DSGVO: `CertificateReportService` already scrubs email-shaped names — keep that behavior for group reports).

**NC APIs to use:**

| Interface | Method | Purpose |
|-----------|--------|---------|
| `OCP\IGroupManager` | `->get($groupId)` | Resolve group → `IGroup` |
| `OCP\IGroup` | `->getUsers()` → `IUser[]` | Get members for filter |
| `OCP\IGroupManager` | `->isInGroup($userId, $groupId)` | Quick membership check |

Already imported in `RoleService` and `CourseService` — no new `use` declarations needed at the service layer.

---

## 3. Re-Certification

**No new deps. Extend existing `TimedJob`, `INotificationManager`, and `IMailer` patterns.**

### What already exists

| Component | File | Reuse path |
|-----------|------|-----------|
| `TimedJob` subclass pattern | `SendRemindersJob.php`, `NotificationJob.php`, `WeeklyLernplanJob.php` | Copy scheduling boilerplate |
| `INotificationManager` | `NotificationJob.php`, `ReminderService.php` | Already wired in DI |
| `certificates.expires_at` | `learning_certificates` table (v5.0.0) | Already a nullable BIGINT |
| `Notifier.php` | `app/lib/Notification/` | Register new subject types |

### Reminder channel: BOTH, not notification-only

For a mandatory annual recertification:

- **`INotificationManager->notify()`** — always, for all users. This sends to the NC notification bell. It is the only channel available for username-only users (no email). It does NOT send email reliably on its own — whether it triggers an email digest depends on the user's NC notification preferences, which are not guaranteed.
- **`IMailer`** — conditionally, when `$user->getEMailAddress()` returns a non-empty string. This is the reliable channel for users who have email and expect email-based compliance reminders.

Pattern: check email existence first, send `IMailer` if non-null, always send `INotificationManager` notification. Both can be added to the existing `SendRemindersJob` as a new method `sendRecertReminders()` — same `setInterval(3600)`, hour-check for timing.

### New component: `RecertReminderJob` or extend `SendRemindersJob`

Logic:

1. Find all certs where `expires_at IS NOT NULL AND expires_at > now AND expires_at < now + 30_days`.
2. Check `re_cert_reminder_sent_at IS NULL OR < now - 7_days`.
3. Send via `INotificationManager` (all users) + `IMailer` (email-having users).
4. Set `re_cert_reminder_sent_at = now`.

Add `re_cert_reminder_sent_at BIGINT NULL` to `learning_certificates` via migration Version009500.

### Re-enrollment gate

When a cert is expired, the quiz gate must re-open. Implementation: in the quiz-unlock check, query `certificates WHERE user_id=? AND course_id=? AND (expires_at IS NULL OR expires_at > now)`. If no valid cert, the gate treats the user as uncertified regardless of prior pass state. No new table needed — the existing `certificates.expires_at` column is the single source of truth.

---

## 4. Username Politur (No-Email Users)

**No new external deps. Extend existing patterns.**

### What already handles email-less users

`IssuanceService` already handles email-shaped display names and null display names via `FALLBACK_RECIPIENT = 'Teilnehmer:in'` and `looksLikeEmail()`. The cert issuance path is already email-safe.

### Audit required before implementation

Grep the codebase for `getEMailAddress()` calls — any code path that treats a null return as an error or formats a null into a required field (email recipient, display string with `@`) is a latent bug for username-only users. These must be patched null-safe before new features. Focus on: `IssuanceService`, `ReminderService`, any controller that renders user profile data.

### CSV User Upload Helper — occ command, not in-app form

```bash
php occ learning:import-users <csv-file> --group=<nc-group>
```

`ImportUsersCommand` extends `Symfony\Component\Console\Command\Command` (same base as all seven existing `app/lib/Command/` files). Takes CSV with columns `username,display_name,password` (password optional; auto-generates if empty and prints generated passwords to stdout). Uses:

| NC API | Method | Purpose |
|--------|--------|---------|
| `OCP\IUserManager` | `->createUser($uid, $password)` | Create user |
| `OCP\IUserManager` | `->userExists($uid)` | Guard duplicate |
| `OCP\IGroupManager` | `->get($group)->addUser($user)` | Assign to group |

Already imported in `ClassbookService`, `DataMobilityService`, `CourseService` — no new use declarations at the app level.

Do NOT build an in-app CSV upload form for the initial milestone. The occ command matches NC's `occ user:add` paradigm, is operationally simpler for bulk onboarding of 2000 AWO users, and requires zero new HTTP surface area.

---

## Recommended Stack — Summary Table

### Core Technologies (unchanged)

| Technology | Version | Purpose | Status |
|------------|---------|---------|--------|
| PHP | 8.1+ | Backend (NC native) | Existing |
| Vue 3 + Options API | 3.5.32 | Frontend | Existing |
| PostgreSQL 16 | 16 | Primary DB | Existing |
| MariaDB 11.4 | 11.4 | Secondary compat target | Existing |
| `OCP\BackgroundJob\TimedJob` | NC 33 | Scheduled jobs | Existing — extend |
| `OCP\Notification\IManager` | NC 33 | Notifications (email-less safe) | Existing — extend |
| `OCP\IMailer` | NC 33 | Email for email-having users | Existing — add to re-cert |
| `OCP\IGroupManager` / `IUserManager` | NC 33 | Group/user resolution | Existing — extend |
| `OCP\Files\IRootFolder` | NC 33 | Video file access | Existing in `DocumentService` |

### New Runtime Integrations (CDN-loaded, zero npm/composer deps)

| Integration | Load URL | Version | Bundle Impact |
|-------------|----------|---------|--------------|
| Vimeo Player SDK | `https://player.vimeo.com/api/player.js` | 2.30.4 (pinless CDN, always latest) | 0 — lazy-loaded only when Vimeo video present |
| YouTube IFrame API | `https://www.youtube.com/iframe_api` | unversioned (living Google API) | 0 — lazy-loaded only when YT video present |

### New DB Migrations

| Migration | Table / Change | Compat |
|-----------|---------------|--------|
| Version009300 | CREATE `learning_video_progress` | PG16 + MariaDB 11.4 |
| Version009400 | CREATE `learning_teamlead_assignments` | PG16 + MariaDB 11.4 |
| Version009500 | ALTER `learning_certificates` ADD `re_cert_reminder_sent_at BIGINT NULL` | PG16 + MariaDB 11.4 |

### New App Components

| Component | Type | Extends / Uses |
|-----------|------|---------------|
| `VideoStreamController` | Controller | `IRootFolder`, `CourseService` enrollment gate, Range HTTP response |
| `VideoProgressController` | Controller | `VideoProgressService`, enrollment gate |
| `VideoProgressService` | Service | Interval merge algorithm, `learning_video_progress` mapper |
| `RecertReminderJob` | TimedJob | `CertificateMapper`, `INotificationManager`, `IMailer` |
| `RoleService` extension | Existing service | Add `isTeamLeadForGroup()`, `getTeamLeadGroups()` |
| `CertificateReportService` extension | Existing service | Add `getGroupReport()` alongside existing `getCourseReport()` |
| `ImportUsersCommand` | occ Command | `IUserManager`, `IGroupManager` |

---

## What NOT to Add

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| `@vimeo/player` in package.json | ~50 KB bundle; CDN load is Vimeo's own recommended path with zero bundle cost | `<script src="https://player.vimeo.com/api/player.js">` lazy-loaded |
| Any YouTube npm wrapper | YT IFrame API has no npm-native version; all wrappers are thin CDN loaders | `<script src="https://www.youtube.com/iframe_api">` lazy-loaded |
| `video.js` / `plyr.js` / any video player lib | NC-hosted MP4 streaming via native `<video>` needs no player lib; these add 150–400 KB | Native `<video>` element |
| `INotificationManager` as the ONLY re-cert channel | `notify()` delivery to email depends on user prefs + digest settings, not guaranteed; email-having users may miss bell-only reminders for a mandatory annual compliance event | `INotificationManager` (always) + `IMailer` (where email non-null) |
| `IShareManager` for video access | Not currently used in app; share lifecycle must mirror enrollment; harder to audit | App streaming controller (`VideoStreamController`) with enrollment gate |
| WebSocket library | NC has no WS server; violates existing no-WS constraint | POST polling (same pattern as existing `CoopService`) |
| Server-trust of client-reported completion | Spoofable; legally insufficient for Nachweispflicht | Server-side `learning_video_progress.is_complete` column as the only gate |
| In-app CSV upload form for users | Adds HTTP surface area; occ command matches NC idiom for admin bulk operations | `learning:import-users` occ command |
| `VARCHAR(512)` in a composite index | utf8mb4 pushes to ~2 KB index key; MariaDB InnoDB limit is row-format-dependent | Surrogate BIGINT PK + `CHAR(64)` SHA-256 hash column in unique index |
| `JSONB` column type | PostgreSQL 16 only; breaks MariaDB 11.4 compat | `Types::TEXT` with JSON-encoded string |
| `youtube.com` embed domain | Loads Google tracking on iframe inject, before consent | `youtube-nocookie.com` + consent gate (use existing DSGVO consent system) |
| `player.vimeo.com` without `dnt=1` | Vimeo loads tracking; DSGVO violation for mandatory training before consent | `?dnt=1` parameter + consent gate |

---

## Constraints Verification

| Constraint | Status | Notes |
|------------|--------|-------|
| Zero new npm deps | SATISFIED — CDN loads do not touch package.json | Vimeo + YT are runtime CDN, not bundled |
| Zero new composer deps | SATISFIED | All PHP via NC APIs already imported |
| No WebSocket | SATISFIED | Progress tracking via POST polling |
| PG16 + MariaDB 11.4 migration compat | NEEDS CARE — see surrogate PK + Types::TEXT notes above | Especially for `learning_video_progress` |
| PHPStan Level 5 | EXISTING standard | All new code must pass — especially null-safe `getEMailAddress()` reads |
| NC 33–35 compat | SATISFIED | `IGroupManager`, `INotificationManager`, `TimedJob`, `IRootFolder` stable across range |
| DSGVO | ACTIVE CONSTRAINT | Vimeo `dnt=1` + YT nocookie + consent gate mandatory; DSGVO email-shape pseudonymization extends to group reports |

---

## Sources

- Vimeo player.js GitHub (`github.com/vimeo/player.js`) — events list, CDN URL, version 2.30.4 — HIGH confidence
- YouTube IFrame Player API official docs (`developers.google.com/youtube/iframe_api_reference`) — state constants, getCurrentTime polling requirement, no version — HIGH confidence
- npm registry (`npm show @vimeo/player version`) — 2.30.4 confirmed — HIGH confidence
- Nextcloud community forum + GitHub issue #8729 — Range request behavior; `fastcgi_force_ranges on` as misconfiguration mitigation — MEDIUM confidence
- Codebase grep — `TimedJob`, `INotificationManager`, `IGroupManager`, `IUserManager`, `IRootFolder`, `DocumentService`, migration patterns, `ImportController` structure — HIGH confidence (primary source)
- `devcloud.andrestiebitz.de` reachability verified (curl → 401 expected for wrong creds, HTTPS works); Range response verification with valid credentials deferred to Phase implementation — MEDIUM confidence on Range behavior

---

*Stack research for: learning-nc v5.2.0 Pflichtschulung*
*Researched: 2026-07-01*
