/**
 * Labels for course feed items, shared by CourseFeed and GlobalFeed (audit #154, #177, #183).
 *
 * The type labels were a German object literal ('Ankuendigung', 'Neuer Pool' …) outside t(), so
 * they rendered German in every language. They are resolved here at render time, each as a literal
 * t() call the i18n coverage gate can see.
 */
import { translate as t } from '@nextcloud/l10n'

const TYPE_ICONS = {
	announcement: '📢',
	pool_added: '📚',
	milestone: '⭐',
	content_update: '🔄',
	progress: '📊',
}

/**
 * @param {string} type feed item type
 * @return {string} the translated label, or the type itself when unknown
 */
export function feedTypeLabel(type) {
	switch (type) {
	case 'announcement': return t('learning', 'Ankündigung')
	case 'pool_added': return t('learning', 'Neuer Pool')
	case 'milestone': return t('learning', 'Meilenstein')
	case 'content_update': return t('learning', 'Aktualisierung')
	case 'progress': return t('learning', 'Fortschritt')
	default: return type
	}
}

/**
 * @param {string} type feed item type
 * @return {string}
 */
export function feedTypeIcon(type) {
	return TYPE_ICONS[type] || '📌'
}

/**
 * The item title in the reader's language where the server stored what it needs: a "new pool"
 * item carries meta.pool_name since 5.10.x. Older rows and instructor-written items keep their
 * stored title.
 *
 * @param {object} item feed item from the API
 * @return {string}
 */
export function feedItemTitle(item) {
	const poolName = item?.meta?.pool_name
	if (item?.type === 'pool_added' && typeof poolName === 'string' && poolName !== '') {
		// rendered as text by Vue, so no HTML escaping or sanitizing here ("R&D" must not become
		// "R&amp;D", "<intern>" must not vanish)
		return t('learning', 'Neuer Fragenpool: {name}', { name: poolName }, undefined, { escape: false, sanitize: false })
	}
	return item?.title || ''
}
