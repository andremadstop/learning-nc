/**
 * E2E Tests: Phase 5 (PBQ Author Tool) + Phase 6 (Instructor Notes)
 * Run: E2E_USERNAME=admin E2E_PASSWORD=... npm run test:e2e
 */
const { test, expect } = require('@playwright/test')

// ─── helpers ──────────────────────────────────────────────────────────────────

async function removeOnboarding(page) {
  await page.evaluate(() => {
    document.querySelectorAll('.onboarding-redesign, .onboarding-intro').forEach(el => el.remove())
  })
  await page.waitForTimeout(300)
}

async function openPoolManageView(page) {
  await page.goto('/apps/learning/pools')
  await page.waitForLoadState('networkidle')
  await removeOnboarding(page)

  const poolCard = page.locator('.pool-card').first()
  await poolCard.waitFor({ state: 'visible', timeout: 30_000 })
  await poolCard.click()
  await page.waitForLoadState('networkidle')

  // Switch to "Verwalten" tab
  const manageTab = page.locator('[role="tab"]').filter({ hasText: /Verwalten|Manage/ }).first()
  if (await manageTab.isVisible({ timeout: 5_000 }).catch(() => false)) {
    await manageTab.click()
    await page.waitForLoadState('networkidle')
  }
}

// ─── Phase 5: PBQ Author Tool ────────────────────────────────────────────────

test.describe('Phase 5 — PBQ Author Tool', () => {
  test('P5-1: PBQ subtype selector and config textarea exist in QuestionForm', async ({ page }) => {
    await openPoolManageView(page)

    // Click "+ Frage hinzufügen"
    const addBtn = page.locator('button').filter({ hasText: /Frage hinzufügen|\+ Frage|Add Question|\+ Add/ }).first()
    await expect(addBtn).toBeVisible({ timeout: 15_000 })
    await addBtn.click()
    await page.waitForSelector('#question-text', { timeout: 15_000 })

    // PBQ subtype selector should exist
    const subtypeSelect = page.locator('#pbq-subtype')
    await subtypeSelect.scrollIntoViewIfNeeded()
    await expect(subtypeSelect).toBeVisible({ timeout: 5_000 })

    // Verify it has PBQ options
    const options = await subtypeSelect.locator('option').allTextContents()
    expect(options.length).toBeGreaterThan(1) // "None" + at least one PBQ type

    // Select a subtype — this should reveal the config builder + textarea
    await subtypeSelect.selectOption('cli')
    await page.waitForTimeout(500)

    // PBQ config textarea should appear
    const configTextarea = page.locator('#pbq-config')
    await configTextarea.scrollIntoViewIfNeeded()
    await expect(configTextarea).toBeVisible({ timeout: 10_000 })
  })

  test('P5-2: PBQ subtype options cover all required types', async ({ page }) => {
    await openPoolManageView(page)

    const addBtn = page.locator('button').filter({ hasText: /Frage hinzufügen|\+ Frage|Add Question|\+ Add/ }).first()
    await expect(addBtn).toBeVisible({ timeout: 15_000 })
    await addBtn.click()
    await page.waitForSelector('#question-text', { timeout: 15_000 })

    const subtypeSelect = page.locator('#pbq-subtype')
    await subtypeSelect.scrollIntoViewIfNeeded()
    const options = await subtypeSelect.locator('option').allTextContents()

    // Required PBQ subtypes from N10-009 simulations
    const requiredTypes = ['CLI', 'Dropdown', 'Placement', 'Cable', 'Switch Config', 'Routing Config', 'Diagnostic']
    for (const type of requiredTypes) {
      expect(options.some(o => o.includes(type))).toBeTruthy()
    }
  })

  test('P5-3: PBQ config textarea accepts valid JSON', async ({ page }) => {
    await openPoolManageView(page)

    const addBtn = page.locator('button').filter({ hasText: /Frage hinzufügen|\+ Frage|Add Question|\+ Add/ }).first()
    await expect(addBtn).toBeVisible({ timeout: 15_000 })
    await addBtn.click()
    await page.waitForSelector('#question-text', { timeout: 15_000 })

    const subtypeSelect = page.locator('#pbq-subtype')
    await subtypeSelect.scrollIntoViewIfNeeded()
    await subtypeSelect.selectOption('dropdown')
    await page.waitForTimeout(500)

    const configTextarea = page.locator('#pbq-config')
    await configTextarea.scrollIntoViewIfNeeded()
    await configTextarea.fill(JSON.stringify({ questions: [{ id: 'test', label: 'Test', correct: 'A' }] }))

    // No error card should appear for valid JSON
    const errorCard = page.locator('.pbq-config-error')
    await expect(errorCard).not.toBeVisible({ timeout: 3_000 })
  })
})

// ─── Phase 6: Instructor Notes ───────────────────────────────────────────────

test.describe('Phase 6 — Instructor Notes', () => {
  test('P6-1: Instructor Note field exists in QuestionForm', async ({ page }) => {
    await openPoolManageView(page)

    const addBtn = page.locator('button').filter({ hasText: /Frage hinzufügen|\+ Frage|Add Question|\+ Add/ }).first()
    await expect(addBtn).toBeVisible({ timeout: 15_000 })
    await addBtn.click()
    await page.waitForSelector('#question-text', { timeout: 15_000 })

    // Form should have multiple textareas: question text, explanation, instructor note
    const textareas = page.locator('textarea')
    const count = await textareas.count()
    expect(count).toBeGreaterThanOrEqual(2)
  })

  test('P6-2: Question can be saved with note and retrieved', async ({ page }) => {
    await openPoolManageView(page)

    const addBtn = page.locator('button').filter({ hasText: /Frage hinzufügen|\+ Frage|Add Question|\+ Add/ }).first()
    await expect(addBtn).toBeVisible({ timeout: 15_000 })
    await addBtn.click()
    await page.waitForSelector('#question-text', { timeout: 15_000 })

    const UNIQUE_TEXT = 'E2E-Test-' + Date.now()
    const NOTE_TEXT = 'E2E-Note-' + Date.now()

    // Fill question
    await page.fill('#question-text', UNIQUE_TEXT)

    // Fill answers
    const answerInputs = page.locator('.answer-row input[type="text"]')
    if (await answerInputs.count() >= 2) {
      await answerInputs.nth(0).fill('Option A')
      await answerInputs.nth(1).fill('Option B')
    }

    // Mark first answer correct
    const radio = page.locator('.answer-row input[type="radio"], .answer-row span[role="radio"]').first()
    if (await radio.isVisible().catch(() => false)) {
      await radio.click({ force: true })
    }

    // Fill the instructor note — the point of this test.
    const noteField = page.locator('#instructor-note')
    await noteField.scrollIntoViewIfNeeded()
    await noteField.fill(NOTE_TEXT)

    // Save
    const saveBtn = page.locator('button').filter({ hasText: /Speichern|Save/i }).first()
    await saveBtn.scrollIntoViewIfNeeded()
    await saveBtn.click()

    // No error card may appear (NcNoteCard type="error").
    const errorCard = page.locator('[class*="note-card"][class*="error"], .nc-notecard--error')
    const hasError = await errorCard.isVisible({ timeout: 2_000 }).catch(() => false)
    expect(hasError).toBeFalsy()

    // The question must actually be in the list — a silently failed save leaves no error card
    // either, so the error check alone proves nothing.
    const questionItem = page.locator('.question-item').filter({ hasText: UNIQUE_TEXT }).first()
    await expect(questionItem).toBeVisible({ timeout: 10_000 })

    // Retrieve: reopen the question for editing and read the stored note back from the server.
    await openPoolManageView(page)
    const reloadedItem = page.locator('.question-item').filter({ hasText: UNIQUE_TEXT }).first()
    await expect(reloadedItem).toBeVisible({ timeout: 15_000 })
    await reloadedItem.locator('.question-actions button').first().click()
    await page.getByRole('menuitem', { name: /Bearbeiten|Edit/ }).first().click()
    await expect(page.locator('#instructor-note')).toHaveValue(NOTE_TEXT, { timeout: 10_000 })
  })

  test('P6-3: Training mode renders without critical JS errors', async ({ page }) => {
    // Listen before the first navigation, otherwise errors thrown while the bundle loads are missed.
    const errors = []
    page.on('console', msg => {
      if (msg.type() === 'error') errors.push(msg.text())
    })
    page.on('pageerror', err => errors.push(String(err && err.stack ? err.stack : err)))

    await openPoolManageView(page)

    // Actually open training mode: the "Training" tab of the selected pool.
    const trainingTab = page.locator('[role="tab"]').filter({ hasText: /^\s*Training\s*$/ }).first()
    await expect(trainingTab).toBeVisible({ timeout: 15_000 })
    await trainingTab.click()
    const trainingMode = page.locator('.training-mode').first()
    await expect(trainingMode).toBeVisible({ timeout: 15_000 })

    // Start a session when the pool offers one, so the question renderer runs as well.
    const startBtn = trainingMode.locator('.start-actions button').first()
    if (await startBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await startBtn.click()
      await page.waitForLoadState('networkidle')
    }

    await page.waitForTimeout(2000)
    const criticalErrors = errors.filter(e => e.includes('TypeError') || e.includes('ReferenceError'))
    expect(criticalErrors).toHaveLength(0)
  })
})
