/**
 * Phase 106: Export utilities for course summary data.
 *
 * Generates Markdown (Obsidian-compatible). Works entirely client-side — no backend endpoint needed.
 */

import { getCanonicalLocale, translate as t } from '@nextcloud/l10n'

/**
 * A date in the user's Nextcloud locale (was fixed to de-DE, audit #244).
 *
 * @param {Date} date
 * @returns {string}
 */
function formatDate(date) {
	let locale
	try {
		locale = getCanonicalLocale()
	} catch {
		locale = undefined
	}
	return date.toLocaleDateString(locale)
}

/**
 * The translated badge name; the raw badge_id only when the badge has no name.
 *
 * @param {object} badge
 * @returns {string}
 */
function badgeLabel(badge) {
	const key = badge?.name_key || badge?.name || ''
	return key ? t('learning', key) : String(badge?.badge_id || '')
}

/**
 * @param {object} summary - Response from GET /api/courses/{courseId}/summary
 * @param {string} courseName
 * @param {string} userName
 * @returns {string} Markdown content
 */
export function summaryToMarkdown(summary, courseName, userName) {
	const lines = []
	const date = formatDate(new Date())

	lines.push(`# ${t('learning', 'Kursabschluss')}: ${courseName}`)
	lines.push(`**${userName}** — ${date}`)
	lines.push('')

	// Mastery
	const m = summary.mastery || {}
	lines.push(`## ${t('learning', 'Wissensstand')}`)
	lines.push(`- ${t('learning', 'Gemeistert')}: **${m.total_mastered || 0}** / ${m.total_questions || 0} (${m.mastery_rate || 0}%)`)
	lines.push('')

	// Sessions
	const s = summary.sessions || {}
	lines.push(`## ${t('learning', 'Lernsessions')}`)
	lines.push(`- ${t('learning', 'Sessions')}: ${s.total_sessions || 0}`)
	lines.push(`- ${t('learning', 'Fragen beantwortet')}: ${s.total_questions || 0}`)
	lines.push(`- ${t('learning', 'Genauigkeit')}: ${s.overall_accuracy || 0}%`)
	lines.push('')

	// XP & Level
	const x = summary.xp || {}
	lines.push(`## ${t('learning', 'Fortschritt')}`)
	lines.push(`- **${x.total_xp || 0} XP** — Level ${x.current_level || 1}`)
	lines.push(`- ${t('learning', 'Streak')}: ${x.current_streak || 0} ${t('learning', 'Tage')}`)
	lines.push('')

	// Badges
	// The summary lists every badge with an earned flag; only the earned ones belong here.
	const badges = (summary.badges || []).filter((b) => b.earned)
	if (badges.length > 0) {
		lines.push(`## ${t('learning', 'Abzeichen')} (${badges.length})`)
		for (const b of badges) {
			const earned = b.earned_at ? formatDate(new Date(b.earned_at * 1000)) : '-'
			lines.push(`- ${badgeLabel(b)} (${earned})`)
		}
		lines.push('')
	}

	// Duels
	const d = summary.duels || {}
	if (d.total_duels > 0) {
		lines.push(`## ${t('learning', 'Duelle')}`)
		lines.push(`- ${d.wins || 0} / ${d.total_duels} ${t('learning', 'gewonnen')} (${d.win_rate || 0}%)`)
		lines.push('')
	}

	// Swarm
	const sw = summary.swarm || {}
	if (sw.total_contributions > 0) {
		lines.push(`## ${t('learning', 'Schwarmgedächtnis')}`)
		lines.push(`- ${sw.approved_contributions || 0} / ${sw.total_contributions} ${t('learning', 'Beiträge freigegeben')}`)
		lines.push('')
	}

	// Trouble Spots
	const spots = summary.trouble_spots || []
	if (spots.length > 0) {
		lines.push(`## ${t('learning', 'Schwachstellen')}`)
		for (const sp of spots) {
			lines.push(`- ${t('learning', 'Frage')} #${sp.question_id}: ${sp.accuracy}% (${sp.attempts} ${t('learning', 'Versuche')})`)
		}
		lines.push('')
	}

	// Streak details
	const st = summary.streak || {}
	if (st.longest_streak > 0) {
		lines.push(`## ${t('learning', 'Streak-Details')}`)
		lines.push(`- ${t('learning', 'Längster Streak')}: ${st.longest_streak} ${t('learning', 'Tage')}`)
		if (st.freeze_tokens > 0) {
			lines.push(`- ${t('learning', 'Freeze-Tokens')}: ${st.freeze_tokens}`)
		}
		lines.push('')
	}

	lines.push('---')
	lines.push(`*${t('learning', 'Exportiert aus Learning-NC')} — ${date}*`)

	return lines.join('\n')
}

/**
 * Trigger file download in browser.
 * @param {string} content
 * @param {string} filename
 * @param {string} mimeType
 */
export function downloadFile(content, filename, mimeType = 'text/plain') {
	const blob = new Blob([content], { type: mimeType + ';charset=utf-8' })
	const url = URL.createObjectURL(blob)
	const a = document.createElement('a')
	a.href = url
	a.download = filename
	document.body.appendChild(a)
	a.click()
	document.body.removeChild(a)
	URL.revokeObjectURL(url)
}

/**
 * Export summary as Markdown file.
 */
export function exportMarkdown(summary, courseName, userName) {
	const md = summaryToMarkdown(summary, courseName, userName)
	const safeName = courseName.replace(/[^a-zA-Z0-9_-]/g, '_')
	downloadFile(md, `Kursabschluss_${safeName}.md`, 'text/markdown')
}

/**
 * Convert cheat-sheet data to Markdown.
 *
 * @param {object} cheatSheet - Response from GET /api/profile/cheat-sheet
 * @param {string} userName
 * @returns {string} Markdown content
 */
export function cheatSheetToMarkdown(cheatSheet, userName) {
	const lines = []
	const date = formatDate(new Date())
	const spots = cheatSheet.spots || []

	lines.push(`# ${t('learning', 'Spickzettel — Schwachstellen')}`)
	lines.push(`**${userName}** — ${date}`)
	lines.push(`${t('learning', '{count} Schwachstellen aus {courses} Kursen', { count: spots.length, courses: cheatSheet.course_count || 0 })}`)
	lines.push('')

	// Group by chapter
	const byChapter = {}
	for (const sp of spots) {
		const chapter = sp.chapter_title || sp.chapter_key || t('learning', 'Ohne Kapitel')
		if (!byChapter[chapter]) {
			byChapter[chapter] = []
		}
		byChapter[chapter].push(sp)
	}

	for (const [chapter, items] of Object.entries(byChapter)) {
		lines.push(`## ${chapter}`)
		lines.push('')
		for (const sp of items) {
			lines.push(`### ${sp.question_text}`)
			lines.push(`*${t('learning', 'Pool')}: ${sp.pool_name}${sp.course_name ? ' — ' + sp.course_name : ''} | ${t('learning', 'Genauigkeit')}: ${sp.accuracy}% (${sp.attempts} ${t('learning', 'Versuche')})*`)
			lines.push('')
			if (sp.correct_answers && sp.correct_answers.length > 0) {
				lines.push(`**${t('learning', 'Korrekte Antwort')}:** ${sp.correct_answers.join('; ')}`)
			}
			if (sp.explanation) {
				lines.push(`> ${sp.explanation.replace(/\n/g, '\n> ')}`)
			}
			lines.push('')
		}
	}

	lines.push('---')
	lines.push(`*${t('learning', 'Exportiert aus Learning-NC')} — ${date}*`)

	return lines.join('\n')
}

/**
 * Export cheat-sheet as Markdown file.
 */
export function exportCheatSheetMarkdown(cheatSheet, userName) {
	const md = cheatSheetToMarkdown(cheatSheet, userName)
	downloadFile(md, `Spickzettel_${new Date().toISOString().slice(0, 10)}.md`, 'text/markdown')
}
