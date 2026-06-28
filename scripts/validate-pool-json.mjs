// scripts/validate-pool-json.mjs — node scripts/validate-pool-json.mjs <file>
// Validates a question-pool JSON against the import format used by app/examples/*.json.
import { readFileSync } from 'node:fs'

const file = process.argv[2]
if (!file) { console.error('usage: node scripts/validate-pool-json.mjs <file>'); process.exit(2) }

const data = JSON.parse(readFileSync(file, 'utf8'))
if (!Array.isArray(data)) { console.error('not an array'); process.exit(1) }

let ok = true
data.forEach((q, i) => {
  const where = `Q${i + 1}`
  if (typeof q.text !== 'string' || !q.text.trim()) { console.error(`${where}: empty text`); ok = false }
  if (typeof q.explanation !== 'string' || !q.explanation.trim()) { console.error(`${where}: empty explanation`); ok = false }
  if (!Array.isArray(q.answers) || q.answers.length !== 4) { console.error(`${where}: need exactly 4 answers`); ok = false }
  const correct = (q.answers || []).filter(a => a && a.is_correct === true).length
  if (correct < 1) { console.error(`${where}: need >=1 correct answer`); ok = false }
  ;(q.answers || []).forEach((a, j) => {
    if (typeof a.text !== 'string' || !a.text.trim()) { console.error(`${where}.A${j + 1}: empty answer`); ok = false }
    if (typeof a.is_correct !== 'boolean') { console.error(`${where}.A${j + 1}: is_correct must be bool`); ok = false }
  })
})
if (data.length !== 18) { console.error(`expected 18 questions, got ${data.length}`); ok = false }

console.log(ok ? `OK: ${file} — ${data.length} questions valid` : `FAIL: ${file}`)
process.exit(ok ? 0 : 1)
