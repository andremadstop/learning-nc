/**
 * A fixed Nextcloud locale for every unit test.
 *
 * In Nextcloud the page carries the user's locale (`<html data-locale="de_DE">`). Without it,
 * @nextcloud/l10n falls back to the locale of the machine (Intl), so a test that formats a time
 * passed on a German workstation and failed in CI ("07:29 AM Uhr", GitHub Actions, 27.11.2026).
 * The source strings are German, so the tests run as a German Nextcloud would.
 */
globalThis._nc_l10n_locale = 'de_DE'
if (typeof document !== 'undefined') {
	document.documentElement.dataset.locale = 'de_DE'
}
