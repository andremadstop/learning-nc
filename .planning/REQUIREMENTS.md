# Requirements: learning-nc — v5.2.0 „Pflichtschulung"

**Defined:** 2026-07-01
**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — hier: **verpflichtende Compliance-Schulung mit audit-festem Nachweis, direkt in der Nextcloud der Organisation.**
**Trigger:** AWO-Sachsen-Lead (Jan Knizek, Issue #20, 2000 MA). **Gates durchlaufen:** 5+1 Research-Agenten → Synthese → Scoping → Gemini-Konzept-Review (10 Funde eingearbeitet).

---

## v1 Requirements

v1 = das **„Gerüst"** (Content-Authoring / Hosting / SCORM sind spätere Ausbaustufen). Mappt auf Phasen ab 160. Null neue npm/composer-Deps. PG16 + MariaDB 11.4. PHPStan L5. 5-Sprachen-i18n.

### AUDIT — Manipulationssicherer Audit-Trail (🔴 Fundament, nicht nachrüstbar)

- [x] **AUDIT-01**: Jedes Compliance-Ereignis wird über `AuditService::logComplianceEvent()` in eine **Hash-Chain** geschrieben (`learning_audit_events` + `learning_audit_chain_state`), `chain_hash = sha256(canonical_json(seq, event_key, user_ref, course_id, created_at) || prev_hash)`.
- [x] **AUDIT-02**: `logComplianceEvent()` schluckt Exceptions **nicht** (anders als `logEvent()`) — ein still verworfener Compliance-Write sähe für einen Verifier wie Manipulation aus.
- [x] **AUDIT-03**: Die drei Compliance-Caller (`PassCriteriaService::emitPassEventIfFirst()`, `IssuanceService`, `CertificateController::revoke()`) sind auf `logComplianceEvent()` migriert; das Event-Interface + alle Compliance-Event-Typen (inkl. `course.video.completed`) sind in Phase 1 definiert.
- [x] **AUDIT-04**: Wöchentliche **Ed25519-signierte Checkpoints** über die Chain (`learning_audit_checkpoints` + `AuditCheckpointService` + `AuditCheckpointJob`), via `sodium_crypto_sign_detached` (NICHT `SigningService::sign()`, dessen Header auf `vc+jwt` fixiert ist).
- [x] **AUDIT-05**: **Externer Forgejo-Anker** — periodischer signierter Digest per HTTP-PUT in ein UG-Forgejo-Audit-Repo (Config-Flag + Token, `anchor_url`-Spalte). Schützt gegen den Admin, der Key UND DB hält, und gegen Timestamp-Backdating (Forgejo-Commit-Zeit ist admin-unabhängig).
- [x] **AUDIT-06**: `occ learning:audit:verify` prüft Chain-Integrität + Checkpoint-Signaturen + Anker-Konsistenz und meldet Brüche/Forks/Lücken.
- [x] **AUDIT-07**: **Auditor-Export** — ein berechtigter Nutzer (Datenschutzbeauftragter, nicht nur Shell-Admin) erzeugt einen signierten, menschenlesbaren Nachweis-Export (PDF + begleitendes JSONL/Signatur-File) für einen Zeitraum/Kurs.
- [x] **AUDIT-08**: **Audit-Liveness** — Admin-Status-Widget (letzter Checkpoint, Events seit letztem Checkpoint, Anker-Status); ausbleibender Checkpoint (> erwartetes Intervall) erzeugt eine Warnung.
- [x] **AUDIT-09**: **Fork-Resolution-Runbook** — dokumentierter Admin-Prozess (+ occ-Unterstützung), was bei einem entdeckten Chain-Fork zu tun ist.

### ASSIGN — Assignment als First-Class-Objekt (🔴 Substrat)

- [x] **ASSIGN-01**: `learning_assignments` — First-Class-Pflicht-Objekt: `course_id`, polymorphes Subjekt (`subject_type` = 'user'|'group', `subject_id`), `due_date`, `recert_interval_days`-Override, `status` (persistiert: assigned/in_progress/passed; abgeleitet: overdue/expired), `active_period_key` (nullable-unique wie `active_idem_key`). Index `(course_id, subject_type, subject_id)` **PLAIN, nicht UNIQUE** (Re-Zert = neue Row pro Periode).
- [x] **ASSIGN-02**: Zuweisung an eine **NC-Gruppe** deckt automatisch LDAP/AD/SSO-Mitglieder ab (via `IGroupManager`, keine LDAP-Sonderlogik in der App).
- [x] **ASSIGN-03**: Ein Owner/Instructor kann Person ODER Gruppe einem Kurs mit Frist zuweisen; `AssignmentService` expandiert Gruppen zur Report-/Reminder-Zeit.
- [x] **ASSIGN-04**: Cert-Ausstellung hängt **NICHT** von einer Assignment-Row ab — Selbstlerner ohne Zuweisung bekommen weiter Zertifikate; das Pass-Event *aktualisiert* Assignment, gated es nicht.
- [x] **ASSIGN-05**: **Frist-Verlängerung bei Systemausfall** — Admin kann Fristen für Gruppe/Kurs pauschal verlängern (dokumentierte, audit-geloggte Aktion).

### VIDEO — Video-/Material-Gating

- [x] **VIDEO-01**: `VideoStreamController` streamt NC-gehostete MP4 aus dem Dozenten-Namespace mit **Enrollment-Gate** (`IRootFolder->getUserFolder($instructorId)->fopen`) + HTTP-Range (206 Partial Content).
- [x] **VIDEO-02**: Server-seitige Watch-Completion — `VideoProgressService` merged Intervalle server-seitig, Entscheidung `covered_pct >= 0.95`; Client-Flags werden nie vertraut.
- [x] **VIDEO-03**: Quiz-Gate sitzt server-seitig in `TrainingService::startSession()` — wirft 403/ForbiddenException, wenn Pflicht-Video nicht abgeschlossen.
- [x] **VIDEO-04**: **Heartbeat-Plausibilität** — Server verwirft Fortschritts-Pings die schneller-als-Echtzeit / < 5s auseinander sind (Anti-Skript-Fälschung).
- [x] **VIDEO-05**: Vimeo- + YouTube-Embeds werden unterstützt (best-effort Tracking, Seek-Prevention ehrlich als unmöglich dokumentiert); YouTube via `youtube-nocookie.com` + `dnt=1`, Vimeo `dnt=1`, hinter Consent-Gate.
- [x] **VIDEO-06**: **DSGVO-transiente Segmente** — `learning_video_progress.intervals_json`/`covered_pct` sind Arbeitszustand; bei `completed_at`-Write wird die Segment-Row **gelöscht**. Permanent bleibt nur `(user_id, content_id, completed_at)`.
- [x] **VIDEO-07**: **Dokument-„gelesen"-Bestätigung** — Material-Typ mit „Gelesen"-Button als Gate-Bedingung (neben Video).
- [x] **VIDEO-08**: **Barrierefreiheit (BITV/WCAG)** — Video-Untertitel (WebVTT-Track), tastaturbedienbarer Player, Screenreader-Labels, ausreichende Kontraste im Gating-UI.
- [x] **VIDEO-09**: `learning_course_videos` — Per-Kurs-Video-Registry mit Dauer (Fallback: Admin trägt Dauer manuell ein, falls ffprobe auf Relay fehlt).

### RBAC — Teamleiter-Reports (gruppen-gescopt)

- [x] **RBAC-01**: `learning_oversight` (course_id, lead_user_id, scope_group_id) — View-Recht getrennt vom Assignment-Objekt (ersetzt die verworfene `learning_team_leads`).
- [ ] **RBAC-02**: `CertificateReportService::getGroupReport()` — Team-Lead sieht Compliance-Report **nur für die eigene Gruppe**; `assertTeamLeadForGroup()` als erste Zeile (IDOR-safe), Gruppenfilter auf **DB-Ebene** (`WHERE user_id IN (members)`), gleiche DSGVO-Projektion (kein Klartext-Mail/user_id im DTO).
- [ ] **RBAC-03**: `RoleService`-Erweiterung: `isTeamLeadForGroup()`, `getTeamLeadGroups()` via `learning_oversight`.
- [ ] **RBAC-04**: **Team-Lead-Dashboard** — „wer fehlt noch" + Ablauf-/Upcoming-Expirations-Panel + Auslösen einer **In-App-Erinnerung** an säumige Gruppenmitglieder.

### RECERT — Re-Zertifizierung

- [ ] **RECERT-01**: Cert-Ablauf-Status-Zustände (valid / expiring / overdue / expired), rolling-from-pass; `DateTimeImmutable::modify('+1 year')` (DST-sicher).
- [ ] **RECERT-02**: **Konfigurierbare Gültigkeit pro Kurs** (`recert_interval_days` / `cert_validity_months`, Default 12 Monate) + optionaler per-Assignment-Override.
- [ ] **RECERT-03**: **Grace-Period (14 Tage)** nach Ablauf, bevor Status auf „überfällig" kippt.
- [ ] **RECERT-04**: `RecertPeriodCloseJob` (täglicher TimedJob) schließt abgelaufene Perioden: `revoked_at` setzen + `active_idem_key`/`active_period_key` NULLen + frische Assignment-Row anlegen → gibt Re-Issue frei.
- [ ] **RECERT-05**: **Guard-Redesign** — `PassCriteriaService::emitPassEventIfFirst()` prüft „aktive Assignment-Periode mit `active_period_key` IS NOT NULL AND status != passed" statt „je bestanden"; `IssuanceService::issueIfPassed()` blockt nach Period-Close nicht mehr. (⚠ Codex-Security-Review Pflicht.)
- [ ] **RECERT-06**: Erinnerungen 30 + 7 Tage vor Ablauf über `INotificationManager` (**primär, mail-los-sicher**); `IMailer` nur additiv wo `getEMailAddress()` non-null. Idempotenz pro `(certId, threshold_days)` (kein Reminder-Sturm).
- [ ] **RECERT-07**: **Unveränderliche Cert-Historie** — Re-Zert erzeugt eine NEUE Cert-Row; alte Row immutable; alte `verification_id`-URL bleibt dauerhaft auflösbar.

### USER — Username-Politur

- [x] **USER-01**: User ohne E-Mail funktionieren durchgängig — Cert-Ausstellung (verifiziert: `credentialSubject.name` = Displayname, kein Mail), Report-Anzeige, Reminder (NC-Notification). Alle `getEMailAddress()`-Aufrufer null-safe (Audit + Fix in Phase 1).
- [x] **USER-02**: `occ learning:import-users <csv> --group=<nc-group>` (CSV: username, display_name, optional password) — Bulk-Enrollment über `IUserManager`/`IGroupManager`, BackgroundJob-tauglich für 2000 User; **keine** In-App-Upload-UI. (Phase 1, hängt am Assignment-Schema.)

### DSGVO — Datenschutz-Querschnitt (Compliance-Produkt-Pflicht)

- [x] **DSGVO-01**: **Art. 17 chain-sichere Anonymisierung** — bei User-Löschung wird die User-Referenz in Audit/Certs pseudonymisiert/anonymisiert, **ohne die Hash-Chain zu brechen** (User-Referenz im Hash ist bereits pseudonymisiert, nicht Klartext-uid).
- [ ] **DSGVO-02**: **Art. 20 Datenübertragbarkeit** — einzelner Nutzer kann seine Zertifikate + Lernhistorie maschinenlesbar exportieren (bestehenden `DataExportService` erweitern).
- [ ] **DSGVO-03**: **Retention/Löschkonzept (Art. 5(1)(e))** — konfigurierbare Auto-Anonymisierung von Certs/Audit/Assignments nach X Jahren.
- [x] **DSGVO-04**: **Art. 13 Transparenz** — Datenschutzhinweis zu Schulungsbeginn: welche Daten (Abschluss + Zeitstempel), welcher Zweck (Rechtspflicht Art. 6(1)(c), minimiert Art. 5(1)(c)), dass Wiedergabemuster NICHT permanent gespeichert werden.
- [ ] **DSGVO-05**: Alle neuen UI-Strings in 5 Sprachen (de/en/fr/ru/ar); Nachweis-/Zertifikat-Texte mehrsprachig.

---

## Future Requirements (deferred)

### v5.2.x (Fast-Follow nach Validierung)
- **CSV-Import Dry-Run/Preview**; **Fixed-Calendar-Recert** (alle-erneuern-bis-Datum); Multi-Level-Manager-Hierarchie.

### v5.3+
- **PGP/WKD-Countersignatur** auf dem Audit-Checkpoint (YubiKey-Touch — inkompatibel mit Auto-Cert-Ausstellung, gehört auf den Checkpoint, nicht pro Cert).
- **Content-Authoring-Stream** (Kurse einmal bauen → bei vielen Kunden deployen) + **portables/versionierbares Content-Format** (Tür in v1 offen halten: Content nicht hart an Instanz-DB koppeln).
- **Automatisierte E-Mail als primärer Reminder-Kanal** (IMailer schon jetzt als additiv designen).

---

## Out of Scope

| Feature | Grund |
|---------|-------|
| SCORM-Runtime / SCORM-Import | Monate Aufwand, nicht AWOs echter Bedarf; bewusste Positionierung NC-nativ. Minimaler SCORM-Import ggf. späterer Türöffner, kein v1-Treiber. |
| Multi-Tenancy (eine Instanz, mehrere Kunden) | Geschäftsmodell on-prem-first; NC-Gruppen liefern Fachbereichs-Isolation *innerhalb* einer Instanz. |
| Forward-Seek-Prevention für YouTube/Vimeo | IFrame-API blockt Seeking nicht → falsche Auditor-Sicherheit. Nur NC-MP4 = harte Sperre. |
| System-Zugangssperre bei Cert-Ablauf | Org-Policy-Entscheidung, nicht LMS-Aufgabe; Status → „überfällig" statt Aussperren. |
| Per-Frage-Analytics / Minuten-Engagement | DSGVO-Bedenken + außerhalb Scope (Verhaltenskontrolle). |
| Eigenes Multi-Tenant-Credentialing-SaaS | App = FOSS-Feature, kein Dienst; Aussteller = Instanz (aus v5.0.0). |

---

## Traceability

### Phase-to-Requirements Map

| Phase | Name | Requirements |
|-------|------|--------------|
| **160** | Foundation — Audit Hash-Chain + Assignment Schemas | AUDIT-01, AUDIT-02, AUDIT-03, ASSIGN-01..05, USER-01, USER-02, DSGVO-01, RBAC-01 |
| **161** | Audit Hardening — Checkpoints + Anchor + Export + Liveness | AUDIT-04, AUDIT-05, AUDIT-06, AUDIT-07, AUDIT-08, AUDIT-09 |
| **162** | Video-/Material-Gating + DSGVO Art.13 | VIDEO-01..09, DSGVO-04 |
| **163** | Teamleiter-RBAC-Reports + DSGVO Art.20 | RBAC-02, RBAC-03, RBAC-04, DSGVO-02 |
| **164** | Re-Zertifizierung + Retention + i18n Parity | RECERT-01..07, DSGVO-03, DSGVO-05 |

### Requirement-to-Phase Map

| Requirement | Phase | Status |
|-------------|-------|--------|
| AUDIT-01 | Phase 160 | Pending |
| AUDIT-02 | Phase 160 | Pending |
| AUDIT-03 | Phase 160 | Pending |
| AUDIT-04 | Phase 161 | Complete (2026-07-01) |
| AUDIT-05 | Phase 161 | Complete (2026-07-01) |
| AUDIT-06 | Phase 161 | Complete (2026-07-01) |
| AUDIT-07 | Phase 161 | Complete (2026-07-01) |
| AUDIT-08 | Phase 161 | Complete (2026-07-01) |
| AUDIT-09 | Phase 161 | Complete (2026-07-01) |
| ASSIGN-01 | Phase 160 | Pending |
| ASSIGN-02 | Phase 160 | Pending |
| ASSIGN-03 | Phase 160 | Pending |
| ASSIGN-04 | Phase 160 | Pending |
| ASSIGN-05 | Phase 160 | Pending |
| VIDEO-01 | Phase 162 | Complete |
| VIDEO-02 | Phase 162 | Complete |
| VIDEO-03 | Phase 162 | Complete |
| VIDEO-04 | Phase 162 | Complete |
| VIDEO-05 | Phase 162 | Complete |
| VIDEO-06 | Phase 162 | Complete |
| VIDEO-07 | Phase 162 | Complete |
| VIDEO-08 | Phase 162 | Complete |
| VIDEO-09 | Phase 162 | Complete |
| RBAC-01 | Phase 160 | Pending |
| RBAC-02 | Phase 163 | Pending |
| RBAC-03 | Phase 163 | Pending |
| RBAC-04 | Phase 163 | Pending |
| RECERT-01 | Phase 164 | Pending |
| RECERT-02 | Phase 164 | Pending |
| RECERT-03 | Phase 164 | Pending |
| RECERT-04 | Phase 164 | Pending |
| RECERT-05 | Phase 164 | Pending |
| RECERT-06 | Phase 164 | Pending |
| RECERT-07 | Phase 164 | Pending |
| USER-01 | Phase 160 | Pending |
| USER-02 | Phase 160 | Pending |
| DSGVO-01 | Phase 160 | Pending |
| DSGVO-02 | Phase 163 | Pending |
| DSGVO-03 | Phase 164 | Pending |
| DSGVO-04 | Phase 162 | Complete |
| DSGVO-05 | Phase 164 | Pending |

**Coverage:** v1 = 41 Requirements, 5 Phasen (160–164), **41/41 gemappt (100%)**. Migration-Sequenz ab Version009300.

---
*Requirements defined: 2026-07-01 (nach Gemini-Konzept-Review)*
*Traceability filled: 2026-07-01 (Roadmap v5.2.0 created, per-requirement rows added)*
*Last updated: 2026-07-01*
