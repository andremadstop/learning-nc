<?php
/**
 * Audit export print report (Phase 161, AUDIT-07). Self-contained, portable HTML — NO external fonts
 * or resources, NO NC template helpers ($l / p() are intentionally unused so this file can be rendered
 * via ob_start()+include from AuditExportController::report() in unit context too). Every dynamic value
 * is escaped with htmlspecialchars().
 *
 * @media print hides the chrome (print button); the table + integrity footer print full-width so the
 * Datenschutzbeauftragte / Betriebsrat can "Als PDF speichern" straight from the browser (window.print).
 *
 * The footer carries sha256(JSONL) and the detached Ed25519 signature (hex) so the printed report can
 * be cross-referenced against the separately downloaded .jsonl + .sig files.
 *
 * @var list<array<string, mixed>> $events
 * @var array{from_date: ?int, to_date: ?int, course_id: ?int} $filters
 * @var string $sha256
 * @var string $sigHex
 * @var int $exportedAt
 * @var int $total
 */

$esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fmtTs = static function ($ts) use ($esc): string {
	if ($ts === null || $ts === '' || (int)$ts === 0) {
		return '—';
	}
	return $esc(date('Y-m-d H:i:s', (int)$ts));
};
$fromLabel = ($filters['from_date'] ?? null) !== null ? $fmtTs($filters['from_date']) : '—';
$toLabel = ($filters['to_date'] ?? null) !== null ? $fmtTs($filters['to_date']) : '—';
$courseLabel = ($filters['course_id'] ?? null) !== null ? $esc($filters['course_id']) : 'alle';
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Audit-Nachweis Export</title>
	<style>
		:root { color-scheme: light; }
		* { box-sizing: border-box; }
		body {
			font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
			color: #1a1a1a;
			margin: 24px;
			line-height: 1.4;
		}
		h1 { font-size: 20px; margin: 0 0 4px; }
		.meta { font-size: 12px; color: #444; margin-bottom: 16px; }
		.meta dt { font-weight: 600; display: inline; }
		.meta div { margin: 2px 0; }
		table { width: 100%; border-collapse: collapse; font-size: 11px; }
		th, td { border: 1px solid #bbb; padding: 4px 6px; text-align: left; vertical-align: top; }
		th { background: #f0f0f0; }
		td.mono, .footer code { font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace; }
		.footer {
			margin-top: 20px;
			padding-top: 12px;
			border-top: 2px solid #333;
			font-size: 11px;
			word-break: break-all;
		}
		.footer div { margin: 4px 0; }
		button.no-print {
			margin-bottom: 16px;
			padding: 8px 16px;
			font-size: 14px;
			cursor: pointer;
			border: 1px solid #0a5c8c;
			background: #0a5c8c;
			color: #fff;
			border-radius: 4px;
		}
		.empty { color: #666; font-style: italic; margin: 12px 0; }
		@media screen { .print-only { display: none; } }
		@media print {
			body { margin: 0; }
			.no-print { display: none; }
			th { background: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
		}
	</style>
</head>
<body>
	<button class="no-print" onclick="window.print()">Drucken / Als PDF speichern</button>

	<h1>Audit-Nachweis Export</h1>
	<div class="meta">
		<div><dt>Exportiert am:</dt> <?php echo $fmtTs($exportedAt); ?></div>
		<div><dt>Zeitraum:</dt> <?php echo $fromLabel; ?> &ndash; <?php echo $toLabel; ?></div>
		<div><dt>Kurs-Filter:</dt> <?php echo $courseLabel; ?></div>
		<div><dt>Ereignisse gesamt:</dt> <?php echo (int)$total; ?></div>
	</div>

	<?php if ($total === 0): ?>
		<p class="empty">Keine Compliance-Ereignisse im gewählten Zeitraum.</p>
	<?php else: ?>
		<table>
			<thead>
				<tr>
					<th>Seq</th>
					<th>Ereignis</th>
					<th>User-Ref (Pseudonym)</th>
					<th>Kurs-ID</th>
					<th>Zeitstempel</th>
					<th>Chain-Hash</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($events as $event): ?>
					<tr>
						<td class="mono"><?php echo (int)($event['seq_num'] ?? 0); ?></td>
						<td><?php echo $esc($event['event_key'] ?? ''); ?></td>
						<td class="mono"><?php echo $esc($event['user_ref'] ?? '—'); ?></td>
						<td class="mono"><?php echo ($event['course_id'] ?? null) !== null ? (int)$event['course_id'] : '—'; ?></td>
						<td><?php echo $fmtTs($event['created_at'] ?? null); ?></td>
						<td class="mono"><?php echo $esc(substr((string)($event['chain_hash'] ?? ''), 0, 16)); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<div class="footer">
		<div><strong>Integritäts-Nachweis</strong> (gegen die separat geladenen .jsonl / .sig Dateien abgleichbar):</div>
		<div>SHA-256 (JSONL): <code><?php echo $esc($sha256); ?></code></div>
		<div>Signature (Ed25519, hex): <code><?php echo $esc($sigHex); ?></code></div>
	</div>
</body>
</html>
