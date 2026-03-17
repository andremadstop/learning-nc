/**
 * Scoring summary utility for PBQ placement questions.
 *
 * @param {Array} positions - Array of position objects with optional `correct` field
 * @param {Object} value    - Map of posId -> assigned device name
 * @param {string} mode     - 'strict' (default) or 'partial'
 * @returns {string}
 */
export function scoringSummary(positions, value, mode = 'strict') {
  if (!positions.length) return ''
  const correct = positions.filter(
    p => p.correct !== undefined && value[p.id] === p.correct
  ).length
  const total = positions.length
  if (mode === 'partial') {
    const pct = Math.round((correct / total) * 100)
    return `${correct} / ${total} korrekt (${pct}%)`
  }
  return correct === total ? 'Alle korrekt' : `${correct} / ${total} korrekt`
}
