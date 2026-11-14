<template>
	<div v-if="sessions.length > 0" class="session-list">
		<div v-for="(sess, idx) in sessions" :key="idx" class="session-item">
			<span class="session-date">{{ formatDate(sess.completed_at) }}</span>
			<span class="session-pool">{{ sess.pool_name }}</span>
			<span v-if="modeLabel(sess.mode)" class="session-mode">{{ modeLabel(sess.mode) }}</span>
			<span class="session-score">{{ sess.score }}</span>
		</div>
	</div>
	<p v-else class="empty-hint">{{ t('learning', 'No sessions found for this student in course pools.') }}</p>
</template>

<script>
import { getCanonicalLocale, translate as t } from '@nextcloud/l10n'
import { intlLocale } from '../utils/relativeTime.js'

/**
 * The recent completed sessions of one learner in a course (GET /api/courses/{id}/students/{uid},
 * recent_sessions). Shown in the instructor drill-down and in "Mein Fortschritt", and in the
 * learners' completion area when the course has one (GitHub #36).
 */
export default {
	name: 'RecentSessionsList',

	props: {
		sessions: {
			type: Array,
			default: () => [],
		},
	},

	methods: {
		t,

		/**
		 * The session mode in the reader's language. The server sends the stored value
		 * (training, exam, …); shown raw it was English in every language. An unknown value
		 * gets no badge rather than a raw database word.
		 *
		 * @param {string} mode stored session mode
		 * @return {string}
		 */
		modeLabel(mode) {
			switch (mode) {
			case 'training': return t('learning', 'Training')
			case 'exam': return t('learning', 'Prüfung')
			case 'leitner': return t('learning', 'Leitner')
			case 'duel': return t('learning', 'Duell')
			case 'gameshow': return t('learning', 'Gameshow')
			default: return ''
			}
		},

		formatDate(timestamp) {
			if (!timestamp) return ''
			try {
				const ts = typeof timestamp === 'number' ? timestamp * 1000 : Date.parse(timestamp)
				const date = new Date(ts)
				if (isNaN(date.getTime())) return String(timestamp)
				// the Nextcloud locale, not the browser's
				return date.toLocaleDateString(intlLocale(getCanonicalLocale()), { year: 'numeric', month: 'short', day: 'numeric' })
			} catch {
				return String(timestamp)
			}
		},
	},
}
</script>

<style scoped>
.session-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.session-item {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 12px 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	font-size: 0.9em;
	transition: transform 0.2s, box-shadow 0.2s;
}

.session-item:hover {
	transform: translateY(-1px);
	box-shadow: 0 2px 8px color-mix(in srgb, var(--color-main-text) 6%, transparent);
}

.session-date {
	width: 100px;
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.session-pool {
	flex: 1;
	font-weight: 500;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.session-mode {
	font-size: 11px;
	font-weight: 600;
	padding: 2px 10px;
	border-radius: 10px;
	background: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
	color: var(--color-primary-element);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.session-score {
	font-weight: 700;
	color: var(--color-main-text);
	white-space: nowrap;
}

.empty-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	font-style: italic;
	padding: 16px;
	text-align: center;
	background: color-mix(in srgb, var(--color-background-dark) 50%, transparent);
	border-radius: var(--border-radius-large, 8px);
}

@media (prefers-reduced-motion: reduce) {
	.session-item:hover { transform: none; }
}

@media (max-width: 768px) {
	.session-item { flex-wrap: wrap; gap: 8px; }
	.session-date { width: auto; }
}
</style>
