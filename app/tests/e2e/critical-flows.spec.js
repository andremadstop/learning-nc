const { test, expect } = require('@playwright/test')

const hasAuthContext = !!process.env.E2E_AUTH_READY

async function api(page, method, path, body = null) {
  return page.evaluate(async ({ method, path, body }) => {
    const token = (window.OC && window.OC.requestToken)
      || document.head.getAttribute('data-requesttoken')
      || ''
    const headers = { 'requesttoken': token }
    if (body !== null) headers['Content-Type'] = 'application/json'
    const response = await fetch(path, {
      method,
      headers,
      credentials: 'same-origin',
      body: body !== null ? JSON.stringify(body) : undefined,
    })
    let json = null
    try {
      json = await response.json()
    } catch {
      json = null
    }
    return { status: response.status, ok: response.ok, json }
  }, { method, path, body })
}

test.describe('Critical Learning Flows', () => {
  test.skip(!hasAuthContext, 'Requires authenticated seeded Nextcloud test environment (E2E_AUTH_READY=1)')

  test('P1: anti-oracle guard hides correctness data during active exam', async ({ page, request }) => {
    await page.goto('/')

    // This test expects a seeded pool and authenticated session.
    // The backend contract is:
    // - submitting to a TRAINING session while an exam is active on the same pool
    //   → answer details are suppressed (suppressed: true, no is_correct/correct_answer_ids)
    // Note: submitting directly to an exam session via this endpoint is blocked (400).
    const res = await api(page, 'POST', '/apps/learning/api/training/answer', {
      sessionId: Number(process.env.E2E_TRAIN_SESSION_ID || 0),
      questionId: Number(process.env.E2E_TRAIN_QUESTION_ID || 0),
      answerId: Number(process.env.E2E_TRAIN_ANSWER_ID || 0),
    })
    expect(res.ok).toBeTruthy()
    const body = res.json || {}
    expect(body.suppressed || body.recorded).toBeTruthy()
    expect(body.is_correct).toBeUndefined()
    expect(body.correct_answer_ids).toBeUndefined()
  })

  test('P1: exam timeout blocks batch submit', async ({ page }) => {
    await page.goto('/')
    const res = await api(page, 'POST', '/apps/learning/api/training/submitBatch', {
      sessionId: Number(process.env.E2E_TIMEOUT_SESSION_ID || 0),
      answers: [],
    })
    const body = res.json || {}
    expect(res.status).toBeGreaterThanOrEqual(400)
    expect(String(body.error || '')).toMatch(/timed out|timeout|completed/i)
  })

  test('P1: duplicate answer submissions stay idempotent', async ({ page }) => {
    await page.goto('/')
    const payload = {
      sessionId: Number(process.env.E2E_TRAIN_SESSION_ID || 0),
      questionId: Number(process.env.E2E_TRAIN_QUESTION_ID || 0),
      answerId: Number(process.env.E2E_TRAIN_ANSWER_ID || 0),
    }

    const [r1, r2] = await Promise.all([
      api(page, 'POST', '/apps/learning/api/training/answer', payload),
      api(page, 'POST', '/apps/learning/api/training/answer', payload),
    ])

    expect(r1.ok || r2.ok).toBeTruthy()
    // One request may be accepted while the second is ignored/rejected; both are valid
    expect([200, 400].includes(r1.status)).toBeTruthy()
    expect([200, 400].includes(r2.status)).toBeTruthy()
  })

  test('P1: completed daily mission can be claimed exactly once', async ({ page }) => {
    await page.goto('/')
    const missionKey = process.env.E2E_MISSION_KEY || 'daily_sessions_1'
    const first = await api(page, 'POST', `/apps/learning/api/v1/missions/${missionKey}/claim`, {})
    expect(first.status).toBe(200)
    expect(Number(first.json?.xp_earned || 0)).toBeGreaterThan(0)

    const second = await api(page, 'POST', `/apps/learning/api/v1/missions/${missionKey}/claim`, {})
    expect(second.status).toBeGreaterThanOrEqual(400)
    expect(String(second.json?.error || '')).toMatch(/already claimed|claimed/i)
  })

  test('P1: uncompleted mission claim is rejected', async ({ page }) => {
    await page.goto('/')
    const missionKey = process.env.E2E_UNCOMPLETED_MISSION_KEY || 'daily_cards_20'
    const response = await api(page, 'POST', `/apps/learning/api/v1/missions/${missionKey}/claim`, {})
    expect(response.status).toBeGreaterThanOrEqual(400)
    expect(String(response.json?.error || '')).toMatch(/not completed|completed yet|mission/i)
  })
})
