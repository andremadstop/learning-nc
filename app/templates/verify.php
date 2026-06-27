<?php
/**
 * Public certificate verify page (Phase 157, VERIFY-01/02). PURE server-rendered — no Vue island,
 * no script(), no data-* JSON. All copy goes through $l->t() (server-side i18n, 5-lang parity); every
 * interpolated value is HTML-escaped via p(). The params come from PublicVerifyController::buildParams,
 * which carries ONLY the DSGVO display fields — the recipient name is never passed here and never rendered.
 *
 * Four status banners (Gemini-locked): valid (green), withdrawn (ocker tombstone), expired (grey),
 * unknown|invalid (red). For unknown/invalid only the red banner renders (no cert exists → nothing leaks,
 * no enumeration oracle). Information order: banner → course → issuer → data → missing-name explainer →
 * fine print → trust badge → plain-language footer.
 *
 * @var array<string, mixed> $_
 * @var \OCP\IL10N $l
 */

$status = $_['status'] ?? 'unknown';
$isCert = in_array($status, ['valid', 'withdrawn', 'expired'], true);

/** Localised date (falls back to ISO if IL10N->l is unavailable in this context). */
$fmtDate = static function ($ts) use ($l): string {
    if ($ts === null || $ts === '') {
        return '';
    }
    try {
        return (string)$l->l('date', new \DateTime('@' . (int)$ts));
    } catch (\Throwable $e) {
        return date('Y-m-d', (int)$ts);
    }
};
try {
    $queryTime = (string)$l->l('datetime', new \DateTime('now'));
} catch (\Throwable $e) {
    $queryTime = date('Y-m-d H:i');
}

// Banner: red unknown/invalid is the default; the found+sig-OK statuses override it.
$glyph = '✕';
$color = '#d92f2f';
$bannerTitle = $l->t('Verifizierung fehlgeschlagen');
$bannerSub = $l->t('Das Zertifikat konnte nicht gefunden werden. Bitte prüfen Sie den Link oder QR-Code.');
if ($status === 'valid') {
    $glyph = '✓';
    $color = '#2f9a48';
    $bannerTitle = $l->t('Zertifikat erfolgreich verifiziert');
    $bannerSub = $l->t('Dieses Zertifikat ist gültig.');
} elseif ($status === 'withdrawn') {
    $glyph = '⦸';
    $color = '#e69900';
    $bannerTitle = $l->t('Zertifikat zurückgezogen');
    $bannerSub = $l->t('Dieses Zertifikat wurde am %s vom Aussteller widerrufen.', [$fmtDate($_['revoked_at'] ?? null)]);
} elseif ($status === 'expired') {
    $glyph = '⌛';
    $color = '#6c757d';
    $bannerTitle = $l->t('Zertifikat abgelaufen');
    $bannerSub = $l->t('Die Gültigkeit endete am %s.', [$fmtDate($_['expires_at'] ?? null)]);
}
?>
<style>
	.lrn-verify { max-width: 640px; margin-inline: auto; padding: 0 16px 48px; box-sizing: border-box; }
	.lrn-verify * { box-sizing: border-box; }
	.lrn-verify__banner {
		margin-block: 24px; padding: 28px 24px; border-radius: 12px; color: #fff;
		text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,.12);
	}
	.lrn-verify__icon { font-size: 56px; line-height: 1; display: block; margin-bottom: 8px; }
	.lrn-verify__banner h1 { margin: 0 0 6px; font-size: 1.6em; font-weight: 700; color: #fff; }
	.lrn-verify__banner p { margin: 0; font-size: 1.05em; opacity: .96; }
	.lrn-verify__course { margin: 24px 0 8px; font-size: 1.35em; color: #1a3a5c; text-align: start; }
	.lrn-verify__issuer { display: flex; align-items: center; gap: 12px; margin: 12px 0 24px; }
	.lrn-verify__issuer img { height: 40px; width: auto; }
	.lrn-verify__issuer-name { font-weight: 600; color: #1a3a5c; }
	.lrn-verify__data { border: 1px solid #e0e0e0; border-radius: 10px; padding: 16px 20px; margin: 16px 0; }
	.lrn-verify__row { display: flex; justify-content: space-between; gap: 16px; padding: 6px 0; }
	.lrn-verify__row dt { color: #555; margin: 0; }
	.lrn-verify__row dd { margin: 0; font-weight: 600; color: #1a3a5c; text-align: end; }
	.lrn-verify__info {
		border-inline-start: 4px solid #1a3a5c; background: #f4f7fb; border-radius: 8px;
		padding: 14px 18px; margin: 20px 0; text-align: start;
	}
	.lrn-verify__info strong { display: block; margin-bottom: 6px; color: #1a3a5c; }
	.lrn-verify__fine { font-size: .85em; color: #777; margin: 20px 0; text-align: start; word-break: break-all; }
	.lrn-verify__badge {
		display: inline-flex; align-items: center; gap: 6px; font-size: .85em; color: #2f9a48;
		font-weight: 600; border: 1px solid #2f9a48; border-radius: 999px; padding: 4px 12px;
	}
	.lrn-verify__how { margin-top: 24px; color: #555; }
	.lrn-verify__how summary { cursor: pointer; color: #1a3a5c; font-weight: 600; }
	.lrn-verify__how p { margin: 10px 0 0; font-size: .92em; line-height: 1.5; }
</style>

<div class="lrn-verify">
	<div class="lrn-verify__banner" role="status" aria-live="polite" style="background:<?php p($color); ?>;">
		<span class="lrn-verify__icon" aria-hidden="true"><?php p($glyph); ?></span>
		<h1><?php p($bannerTitle); ?></h1>
		<p><?php p($bannerSub); ?></p>
	</div>

	<?php if ($isCert) { ?>
		<h2 class="lrn-verify__course"><?php p($_['course_title'] ?? ''); ?></h2>

		<div class="lrn-verify__issuer">
			<?php if (!empty($_['issuer_logo'])) { ?>
				<img src="<?php p($_['issuer_logo']); ?>" alt="" aria-hidden="true">
			<?php } ?>
			<span class="lrn-verify__issuer-name"><?php p($_['issuer_name'] ?? ''); ?></span>
		</div>

		<dl class="lrn-verify__data">
			<div class="lrn-verify__row">
				<dt><?php p($l->t('Ausgestellt am')); ?></dt>
				<dd><?php p($fmtDate($_['issued_at'] ?? null)); ?></dd>
			</div>
			<div class="lrn-verify__row">
				<dt><?php p($l->t('Gültig bis')); ?></dt>
				<dd><?php
					$exp = $_['expires_at'] ?? null;
					p($exp !== null ? $fmtDate($exp) : $l->t('unbegrenzt gültig'));
				?></dd>
			</div>
		</dl>

		<div class="lrn-verify__info">
			<strong><?php p($l->t('Hinweis zum Datenschutz')); ?></strong>
			<?php p($l->t('Aus Datenschutzgründen (DSGVO) wird der Name des Inhabers auf dieser öffentlichen Seite nicht angezeigt. So prüfen Sie die Zugehörigkeit: Vergleichen Sie die oben angezeigte Verifizierungs-ID mit der ID auf dem Ihnen vorliegenden Dokument.')); ?>
		</div>

		<p class="lrn-verify__fine">
			<?php p($l->t('Verifizierungs-ID')); ?>: <?php p($_['verification_id'] ?? ''); ?><br>
			<?php p($l->t('Abgefragt am')); ?>: <?php p($queryTime); ?>
		</p>

		<span class="lrn-verify__badge">
			<span aria-hidden="true">✓</span> <?php p($l->t('Digital verifiziert')); ?>
		</span>
	<?php } ?>

	<details class="lrn-verify__how">
		<summary><?php p($l->t('Wie funktioniert diese Verifizierung?')); ?></summary>
		<p><?php p($l->t('Das Zertifikat enthält eine digitale Unterschrift des Ausstellers. Diese Seite prüft sie automatisch und zeigt zusätzlich, ob das Zertifikat zurückgezogen oder abgelaufen ist.')); ?></p>
	</details>
</div>
