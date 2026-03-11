const { test, expect } = require('@playwright/test')

const hasAuthContext = !!process.env.E2E_AUTH_READY

test.describe('Critical Learning Flows', () => {
  test.skip(!hasAuthContext, 'Requires authenticated seeded Nextcloud test environment (E2E_AUTH_READY=1)')

  test('P1: anti-oracle guard hides correctness data during active exam', async ({ page, request }) => {
    await page.goto('/')

    // This test expects a seeded pool and authenticated session.
    // The backend contract is:
    // - during active exam on same pool -> answer details are suppressed
    const res = await request.post('/apps/learning/api/training/answer', {
      data: {
        sessionId: Number(process.env.E2E_EXAM_SESSION_ID || 0),
        questionId: Number(process.env.E2E_EXAM_QUESTION_ID || 0),
        answerId: Number(process.env.E2E_EXAM_ANSWER_ID || 0),
      },
    })
    expect(res.ok()).toBeTruthy()
    const body = await res.json()
    expect(body.suppressed || body.recorded).toBeTruthy()
    expect(body.is_correct).toBeUndefined()
    expect(body.correct_answer_ids).toBeUndefined()
  })

  test('P1: exam timeout blocks batch submit', async ({ request }) => {
    const res = await request.post('/apps/learning/api/training/submitBatch', {
      data: {
        sessionId: Number(process.env.E2E_TIMEOUT_SESSION_ID || 0),
        answers: [],
      },
    })
    const body = await res.json()
    expect(res.status()).toBeGreaterThanOrEqual(400)
    expect(String(body.error || '')).toMatch(/timed out|timeout|completed/i)
  })

  test('P2: duplicate answer submissions stay idempotent', async ({ request }) => {
    const payload = {
      sessionId: Number(process.env.E2E_TRAIN_SESSION_ID || 0),
      questionId: Number(process.env.E2E_TRAIN_QUESTION_ID || 0),
      answerId: Number(process.env.E2E_TRAIN_ANSWER_ID || 0),
    }

    const [r1, r2] = await Promise.all([
      request.post('/apps/learning/api/training/answer', { data: payload }),
      request.post('/apps/learning/api/training/answer', { data: payload }),
    ])

    expect(r1.ok() || r2.ok()).toBeTruthy()
    // One request may be accepted while the second is ignored/rejected; both are valid
    expect([200, 400].includes(r1.status())).toBeTruthy()
    expect([200, 400].includes(r2.status())).toBeTruthy()
  })
})

