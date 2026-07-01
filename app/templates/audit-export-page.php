<?php
/**
 * Auditor export page (Phase 161, AUDIT-07). Rendered by AuditExportController::page() via a group-gated
 * TemplateResponse (@NoAdminRequired + learning-auditors) — a NON-admin auditor reaches this directly.
 *
 * Self-contained server-rendered filter UI (zero build wiring): date-from / date-to / course-id inputs
 * plus three download actions wired to /audit/export/{events,sig,report}. A small inline script appends
 * the chosen filters as query params (JSONL + .sig download; report opens in a new tab for window.print).
 *
 * The companion Options-API component src/components/AuditExport.vue provides the same UI for future SPA
 * integration; this template is the live, dependency-free surface.
 *
 * @var array<string, mixed> $_
 * @var \OCP\IL10N $l
 */
?>
<div id="learning-audit-export" class="section" style="max-width: 720px; margin: 24px auto; padding: 0 16px;">
	<h2><?php p($l->t('Audit-Export')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Lade ein signiertes, manipulationssicheres Ereignisprotokoll der Compliance-Kette herunter. Nur für Mitglieder der Auditor-Gruppe.')); ?>
	</p>

	<div style="display: flex; flex-wrap: wrap; gap: 16px; margin: 16px 0;">
		<label style="display: flex; flex-direction: column; font-weight: 600;">
			<?php p($l->t('Von (Datum)')); ?>
			<input type="date" id="audit-export-from">
		</label>
		<label style="display: flex; flex-direction: column; font-weight: 600;">
			<?php p($l->t('Bis (Datum)')); ?>
			<input type="date" id="audit-export-to">
		</label>
		<label style="display: flex; flex-direction: column; font-weight: 600;">
			<?php p($l->t('Kurs-ID (optional)')); ?>
			<input type="number" id="audit-export-course" min="1" placeholder="<?php p($l->t('alle')); ?>">
		</label>
	</div>

	<div style="display: flex; flex-wrap: wrap; gap: 12px;">
		<a id="audit-export-events" class="button primary" href="<?php p($_['eventsUrl']); ?>" download>
			<?php p($l->t('Ereignisse herunterladen (JSONL)')); ?>
		</a>
		<a id="audit-export-sig" class="button" href="<?php p($_['sigUrl']); ?>" download>
			<?php p($l->t('Signatur herunterladen (.sig)')); ?>
		</a>
		<a id="audit-export-report" class="button" href="<?php p($_['reportUrl']); ?>" target="_blank" rel="noopener">
			<?php p($l->t('Bericht öffnen (Drucken / PDF)')); ?>
		</a>
	</div>

	<div
		id="audit-export-urls"
		data-events-url="<?php p($_['eventsUrl']); ?>"
		data-sig-url="<?php p($_['sigUrl']); ?>"
		data-report-url="<?php p($_['reportUrl']); ?>"
		hidden></div>
</div>

<script nonce="<?php p(\OCP\Util::getCspNonce()); ?>">
(function () {
	var cfg = document.getElementById('audit-export-urls');
	if (!cfg) { return; }
	var base = {
		events: cfg.getAttribute('data-events-url'),
		sig: cfg.getAttribute('data-sig-url'),
		report: cfg.getAttribute('data-report-url')
	};
	function toTs(dateStr, endOfDay) {
		if (!dateStr) { return null; }
		var ms = Date.parse(dateStr + (endOfDay ? 'T23:59:59' : 'T00:00:00'));
		return isNaN(ms) ? null : Math.floor(ms / 1000);
	}
	function buildQuery() {
		var from = toTs(document.getElementById('audit-export-from').value, false);
		var to = toTs(document.getElementById('audit-export-to').value, true);
		var course = document.getElementById('audit-export-course').value;
		var parts = [];
		if (from !== null) { parts.push('from_date=' + from); }
		if (to !== null) { parts.push('to_date=' + to); }
		if (course) { parts.push('course_id=' + encodeURIComponent(course)); }
		return parts.length ? ('?' + parts.join('&')) : '';
	}
	function wire(id, key) {
		var el = document.getElementById(id);
		if (!el) { return; }
		el.addEventListener('click', function () {
			el.setAttribute('href', base[key] + buildQuery());
		});
	}
	wire('audit-export-events', 'events');
	wire('audit-export-sig', 'sig');
	wire('audit-export-report', 'report');
})();
</script>
