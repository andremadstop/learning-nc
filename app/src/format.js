import { translate as t } from '@nextcloud/l10n'

/**
 * Format XP value for display (e.g., 2850 -> "2.8k", 150 -> "150")
 */
export function formatXp(value) {
	if (value == null) return '0'
	const num = Number(value)
	if (num >= 1000) {
		const k = num / 1000
		return k % 1 === 0 ? k + 'k' : k.toFixed(1) + 'k'
	}
	return String(num)
}

/**
 * Format a Unix timestamp (seconds) as relative time string.
 * Returns: "today", "yesterday", "Xd ago", "Xw ago", or formatted date.
 */
export function formatRelativeDate(timestamp) {
	if (!timestamp) return '\u2014'
	const now = new Date()
	const date = new Date(timestamp * 1000)
	const diffMs = now - date
	const diffDays = Math.floor(diffMs / 86400000)

	if (diffDays < 0) return t('learning', 'today')
	if (diffDays === 0) return t('learning', 'today')
	if (diffDays === 1) return t('learning', 'yesterday')
	if (diffDays < 7) return t('learning', '{n}d ago', { n: diffDays })
	if (diffDays < 30) return t('learning', '{n}w ago', { n: Math.floor(diffDays / 7) })
	return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

/**
 * Format a date string (YYYY-MM-DD) as relative time.
 */
export function formatRelativeDateString(dateStr) {
	if (!dateStr) return '\u2014'
	const parts = dateStr.split('-')
	if (parts.length !== 3) return dateStr
	const date = new Date(Date.UTC(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])))
	const now = new Date()
	const todayUtc = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate())
	const diffDays = Math.floor((todayUtc - date.getTime()) / 86400000)

	if (diffDays < 0) return t('learning', 'today')
	if (diffDays === 0) return t('learning', 'today')
	if (diffDays === 1) return t('learning', 'yesterday')
	if (diffDays < 7) return t('learning', '{n}d ago', { n: diffDays })
	if (diffDays < 30) return t('learning', '{n}w ago', { n: Math.floor(diffDays / 7) })
	return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}
