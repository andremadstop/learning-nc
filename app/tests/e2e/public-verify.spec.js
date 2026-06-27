// Phase 157-05 — public certificate verify page, LOGGED-OUT (no NC session).
//
// Proves two things against the LIVE devcloud public route:
//   VERIFY-01  the route is reachable with NO authentication (no 403, no login redirect, HTTP 200)
//   VERIFY-03  the rendered HTML body of a REAL valid cert leaks the recipient identity NOWHERE
//              (visible text, <script>, data-* attrs, embedded JSON, AND fallback texts — Codex #3,
//               stronger than 156's DTO-array grep).
//
// This spec uses a NO-AUTH context (empty storageState) so it hits the PUBLIC route exactly as an
// anonymous visitor holding a verificationId would — see the `public-verify` project in
// playwright.config.js (no `setup` dependency, no admin.json storageState).

const { test, expect } = require('@playwright/test')

// Origin-absolute path: the configured baseURL already contains /apps/learning, but a leading-slash
// path resolves against the ORIGIN, so we spell the full app path here (matches exam-mode.spec.js).
const verifyPath = (vid) => `/apps/learning/verify/${vid}`

// ── LIVE valid cert under test (VERIFY-03 no-leak) ───────────────────────────────────────────────
// NOTE (2026-06-27): at the time this spec was written the synthetic cert below was ALREADY CLEANED
// UP — `SELECT * FROM oc_learning_certificates` on live devcloud returned ZERO rows (the user
// `zz-test-cert155` is also gone; `occ user:info` → "user not found"). The STATE.md "Synthetic Cert
// Smoke — LEFT IN PLACE" block is STALE; the cert was removed afterwards. Because minting a fresh
// cert on live prod is a forbidden mutation (PROD BOUNDARY), the no-leak test below SELF-SKIPS (it
// does NOT pass) until the authorized demo-course provisioning pass re-mints a cert. A skip is honest
// where a green would be vacuous.
//
// ⚠ PROVISIONING PASS — DO ALL THREE AS ONE ATOMIC STEP, or the gate runs vacuously / never:
//   1. Set LIVE_VID to the verificationId of the freshly-minted cert.
//   2. Set RECIPIENT_DISPLAY + RECIPIENT_USERID to that cert's REAL recipient (display name + user id).
//   3. CONFIRM the name is actually IN the credential before trusting the absence assertion:
//        ssh relais 'docker exec devcloud-db psql -U oc_admin -d nextcloud -t -c \
//          "SELECT credential_json FROM oc_learning_certificates WHERE verification_id='\''<LIVE_VID>'\'';"'
//      base64url-decode the MIDDLE JWT segment and verify credentialSubject.name === RECIPIENT_DISPLAY.
//      If it is instead the fallback "Teilnehmer:in" (IssuanceService::FALLBACK_RECIPIENT), the name was
//      never in the credential → absence proves NOTHING; pick/mint a cert with a real display name.
// Only after (3) is the page.content() absence assertion a real VERIFY-03 gate.
const LIVE_VID = 'eb97720c-59d1-4c65-ba49-229c47341047'

// Assert the LIVE cert's REAL recipient is absent — NOT the unit-test fixture "Jürgen Müller", which
// was never in any real cert (asserting it would be a vacuous pass). DO NOT "fix" these to the fixture.
const RECIPIENT_DISPLAY = 'ZZ Testkandidat'
const RECIPIENT_USERID = 'zz-test-cert155'

// Language-NEUTRAL selectors. The h1 text localizes (chromium sends Accept-Language: en →
// "Verification failed", curl with no header → "Verifizierung fehlgeschlagen"), so we assert on the
// stable banner class + the locked per-status background colours instead of any translated string.
const BANNER_CLASS = 'lrn-verify__banner'
const COLOUR_FAIL = 'background:#d92f2f' // unknown/invalid (the generic no-oracle page)
const COLOUR_VALID = 'background:#2f9a48'
const COLOUR_WITHDRAWN = 'background:#e69900'
const COLOUR_EXPIRED = 'background:#6c757d'

test.describe('Public certificate verify — logged out (no NC session)', () => {
  // Empty storageState → no NC session cookie. Hits the PUBLIC route as an anonymous visitor.
  test.use({ storageState: { cookies: [], origins: [] } })

  test('VERIFY-01: reachable logged-out — malformed id → HTTP 200 generic page, no login redirect', async ({ page }) => {
    const resp = await page.goto(verifyPath('not-a-uuid'))
    expect(resp, 'navigation produced a response').not.toBeNull()
    // A logged-out hit must NOT be a 401/403 nor a redirect to the NC login wall.
    expect(resp.status(), 'public route returns 200 logged-out (not 401/403)').toBe(200)
    expect(page.url(), 'not redirected to the NC login page').not.toContain('/login')
    const html = await page.content()
    // Server-rendered verify banner present (proves the public template rendered, not a login/error wall).
    expect(html).toContain(BANNER_CLASS)
    expect(html, 'malformed id renders the red unknown/invalid banner').toContain(COLOUR_FAIL)
    // Even the generic page must leak nothing.
    expect(html).not.toContain(RECIPIENT_DISPLAY)
    expect(html).not.toContain(RECIPIENT_USERID)
  })

  test('VERIFY-06: not-found UUID → identical generic page (no existence oracle)', async ({ page }) => {
    const resp = await page.goto(verifyPath('00000000-0000-4000-8000-000000000000'))
    expect(resp.status()).toBe(200)
    const html = await page.content()
    // Well-formed-but-missing renders the SAME red generic banner as malformed → no 404-vs-200 oracle.
    expect(html).toContain(BANNER_CLASS)
    expect(html).toContain(COLOUR_FAIL)
    expect(html).not.toContain(RECIPIENT_DISPLAY)
    expect(html).not.toContain(RECIPIENT_USERID)
  })

  test('VERIFY-03: no recipient leak in the LIVE valid cert page (DOM-level, whole body)', async ({ page }) => {
    const resp = await page.goto(verifyPath(LIVE_VID))
    expect(resp.status()).toBe(200)
    const html = await page.content()

    // GATE, not a vacuous pass: this assertion is only meaningful against a REAL provisioned cert whose
    // credentialSubject.name IS RECIPIENT_DISPLAY. If the cert is not provisioned the page is the generic
    // "unknown" banner that never contained the name → asserting absence would prove NOTHING (advisor
    // integrity guard). Skip (do NOT pass) in that case; the gate auto-activates once a real cert renders.
    const isProvisionedCert =
      html.includes(COLOUR_VALID) || html.includes(COLOUR_WITHDRAWN) || html.includes(COLOUR_EXPIRED)
    test.skip(
      !isProvisionedCert,
      `Live cert ${LIVE_VID} is NOT provisioned (generic page rendered) — the DOM no-leak gate runs ` +
        'during the authorized demo-course provisioning pass. PRECONDITION before it becomes a real ' +
        `gate: update LIVE_VID + recipient constants and psql-confirm credentialSubject.name === "${RECIPIENT_DISPLAY}".`,
    )

    // DOM-level no-leak across the ENTIRE rendered body — visible text, <script> tags, data-* attributes,
    // embedded JSON, and fallback/error texts. The public path must never project the recipient identity.
    expect(html, 'recipient display name absent from whole HTML body').not.toContain(RECIPIENT_DISPLAY)
    expect(html, 'recipient user id absent from whole HTML body').not.toContain(RECIPIENT_USERID)
  })
})
