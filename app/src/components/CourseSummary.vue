<template>
	<div class="course-summary">
		<div class="summary-header">
			<div>
				<p class="section-label">{{ t('learning', 'Kursabschluss') }}</p>
				<h3>{{ t('learning', 'Dein Kursabschluss') }}</h3>
				<p class="summary-subtitle">{{ courseName }}</p>
			</div>
			<div class="summary-actions">
				<NcButton
					type="tertiary"
					disabled
					:title="t('learning', 'Kommt bald')">
					{{ t('learning', 'Als Markdown exportieren') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="loading || savingSnapshot || !summary"
					@click="saveSnapshot">
					{{ savingSnapshot ? t('learning', 'Sichere...') : t('learning', 'Zeugnis sichern') }}
				</NcButton>
			</div>
		</div>

		<div v-if="loading" class="loading-container">
			<NcLoadingIcon :size="44" />
			<p>{{ t('learning', 'Lade Kursabschluss...') }}</p>
		</div>

		<NcNoteCard v-else-if="error" type="error" class="summary-error">
			{{ error }}
		</NcNoteCard>

		<template v-else-if="summary">
			<div v-if="snapshotSaved" class="summary-toast">
				{{ t('learning', 'Snapshot gespeichert.') }}
			</div>

			<div class="summary-grid">
				<div class="widget-card mastery-card">
					<p class="card-kicker">{{ t('learning', 'Mastery') }}</p>
					<div class="mastery-ring-shell">
						<svg class="mastery-ring" viewBox="0 0 140 140" aria-hidden="true">
							<circle class="mastery-ring-track" cx="70" cy="70" r="54" />
							<circle
								class="mastery-ring-progress"
								cx="70"
								cy="70"
								r="54"
								:stroke-dasharray="masteryCircumference"
								:stroke-dashoffset="masteryDashOffset" />
						</svg>
						<div class="mastery-ring-copy">
							<span class="mastery-rate">{{ formatPercent(mastery.mastery_rate) }}</span>
							<span class="mastery-label">{{ t('learning', 'gemeistert') }}</span>
						</div>
					</div>
					<div class="mastery-meta">
						<span>{{ formatNumber(mastery.total_mastered) }} / {{ formatNumber(mastery.total_questions) }}</span>
						<span>{{ t('learning', 'Karten in Box 5') }}</span>
					</div>
				</div>

				<div class="widget-card snapshot-card">
					<p class="card-kicker">{{ t('learning', 'Zeugnisstatus') }}</p>
					<div class="snapshot-state">
						<strong>{{ snapshot ? t('learning', 'Snapshot vorhanden') : t('learning', 'Noch nicht gesichert') }}</strong>
						<span v-if="snapshot">{{ t('learning', 'Zuletzt gesichert: {date}', { date: formatTimestamp(snapshot.created_at) }) }}</span>
						<span v-else>{{ t('learning', 'Sichere deinen aktuellen Stand dauerhaft für später.') }}</span>
					</div>
					<div class="snapshot-inline-stats">
						<div class="snapshot-inline-stat">
							<span class="snapshot-inline-value">{{ formatNumber(swarm.total_contributions) }}</span>
							<span class="snapshot-inline-label">{{ t('learning', 'Schwarm-Beiträge') }}</span>
						</div>
						<div class="snapshot-inline-stat">
							<span class="snapshot-inline-value">{{ formatNumber(swarm.approved_contributions) }}</span>
							<span class="snapshot-inline-label">{{ t('learning', 'Freigegeben') }}</span>
						</div>
					</div>
				</div>
			</div>

			<div class="stats-grid">
				<div class="widget-card stat-card">
					<p class="card-kicker">{{ t('learning', 'XP & Level') }}</p>
					<div class="stat-value">{{ formatNumber(xp.total_xp) }} XP</div>
					<div class="stat-meta">{{ t('learning', 'Level {n}', { n: xp.current_level || 1 }) }}</div>
				</div>
				<div class="widget-card stat-card">
					<p class="card-kicker">{{ t('learning', 'Sessions & Accuracy') }}</p>
					<div class="stat-value">{{ formatNumber(sessions.total_sessions) }}</div>
					<div class="stat-meta">{{ formatPercent(sessions.overall_accuracy) }} {{ t('learning', 'Genauigkeit') }}</div>
				</div>
				<div class="widget-card stat-card">
					<p class="card-kicker">{{ t('learning', 'Streak') }}</p>
					<div class="stat-value">{{ formatNumber(streak.current_streak) }}</div>
					<div class="stat-meta">{{ t('learning', 'Längster: {n}', { n: streak.longest_streak || 0 }) }}</div>
				</div>
				<div class="widget-card stat-card">
					<p class="card-kicker">{{ t('learning', 'Duell-Win-Rate') }}</p>
					<div class="stat-value">{{ formatPercent(duels.win_rate) }}</div>
					<div class="stat-meta">{{ t('learning', '{wins} von {total} gewonnen', { wins: duels.wins || 0, total: duels.total_duels || 0 }) }}</div>
				</div>
			</div>

			<div class="detail-grid">
				<div class="widget-card badge-card">
					<div class="detail-header">
						<p class="card-kicker">{{ t('learning', 'Badge-Galerie') }}</p>
						<span class="detail-count">{{ earnedBadges.length }}</span>
					</div>
					<div v-if="earnedBadges.length > 0" class="badge-gallery">
						<div
							v-for="badge in earnedBadges"
							:key="badge.badge_id"
							class="badge-item"
							:title="badgeTitle(badge)">
							<span class="badge-emoji">{{ badge.emoji }}</span>
							<span class="badge-name">{{ badgeLabel(badge) }}</span>
						</div>
					</div>
					<p v-else class="empty-hint">{{ t('learning', 'Noch keine Badges in diesem Ueberblick sichtbar.') }}</p>
				</div>

				<div class="widget-card trouble-card">
					<div class="detail-header">
						<p class="card-kicker">{{ t('learning', 'Trouble Spots') }}</p>
						<span class="detail-count">{{ troubleSpots.length }}</span>
					</div>
					<div v-if="troubleSpots.length > 0" class="trouble-list">
						<div v-for="spot in troubleSpots" :key="spot.question_id" class="trouble-row">
							<div>
								<strong>{{ t('learning', 'Frage #{id}', { id: spot.question_id }) }}</strong>
								<p>{{ t('learning', '{n} Versuche', { n: spot.attempts }) }}</p>
							</div>
							<span class="trouble-accuracy">{{ formatPercent(spot.accuracy) }}</span>
						</div>
					</div>
					<p v-else class="empty-hint">{{ t('learning', 'Keine Trouble Spots erkannt.') }}</p>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

const MASTERY_RING_RADIUS = 54

export default {
	name: 'CourseSummary',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		courseId: {
			type: Number,
			required: true,
		},
		courseName: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			summary: null,
			snapshot: null,
			savingSnapshot: false,
			snapshotSaved: false,
		}
	},

	computed: {
		mastery() {
			return this.summary?.mastery || {}
		},
		sessions() {
			return this.summary?.sessions || {}
		},
		xp() {
			return this.summary?.xp || {}
		},
		streak() {
			return this.summary?.streak || {}
		},
		duels() {
			return this.summary?.duels || {}
		},
		swarm() {
			return this.summary?.swarm || {}
		},
		earnedBadges() {
			return (this.summary?.badges || []).filter((badge) => badge.earned)
		},
		troubleSpots() {
			return (this.summary?.trouble_spots || []).slice(0, 5)
		},
		masteryCircumference() {
			return 2 * Math.PI * MASTERY_RING_RADIUS
		},
		masteryDashOffset() {
			const rate = Math.max(0, Math.min(100, Number(this.mastery.mastery_rate || 0)))
			return this.masteryCircumference * (1 - rate / 100)
		},
	},

	watch: {
		courseId: {
			immediate: true,
			handler() {
				this.loadSummaryState()
			},
		},
	},

	methods: {
		async loadSummaryState() {
			this.loading = true
			this.error = ''
			try {
				await Promise.all([
					this.loadSummary(),
					this.loadSnapshot(),
				])
			} catch (error) {
				this.error = error?.response?.data?.error || t('learning', 'Kursabschluss konnte nicht geladen werden.')
			} finally {
				this.loading = false
			}
		},

		async loadSummary() {
			const response = await axios.get(generateUrl('/apps/learning/api/courses/{courseId}/summary', {
				courseId: this.courseId,
			}))
			this.summary = response.data
		},

		async loadSnapshot() {
			try {
				const response = await axios.get(generateUrl('/apps/learning/api/courses/{courseId}/summary/snapshot', {
					courseId: this.courseId,
				}))
				this.snapshot = response.data
			} catch (error) {
				if (error?.response?.status === 404) {
					this.snapshot = null
					return
				}
				throw error
			}
		},

		async saveSnapshot() {
			this.savingSnapshot = true
			try {
				await axios.post(generateUrl('/apps/learning/api/courses/{courseId}/summary/snapshot', {
					courseId: this.courseId,
				}))
				await this.loadSnapshot()
				this.snapshotSaved = true
				setTimeout(() => {
					this.snapshotSaved = false
				}, 3000)
			} catch (error) {
				this.error = error?.response?.data?.error || t('learning', 'Snapshot konnte nicht gespeichert werden.')
			} finally {
				this.savingSnapshot = false
			}
		},

		formatNumber(value) {
			return Number(value || 0).toLocaleString()
		},

		formatPercent(value) {
			return `${Number(value || 0).toLocaleString(undefined, {
				maximumFractionDigits: 1,
				minimumFractionDigits: Number(value || 0) % 1 === 0 ? 0 : 1,
			})}%`
		},

		badgeLabel(badge) {
			const key = badge?.name_key || badge?.name || ''
			return key ? t('learning', key) : ''
		},

		badgeDescription(badge) {
			const key = badge?.description_key || badge?.description || ''
			return key ? t('learning', key) : ''
		},

		badgeTitle(badge) {
			return [
				this.badgeLabel(badge),
				this.badgeDescription(badge),
			].filter(Boolean).join(': ')
		},

		formatTimestamp(timestamp) {
			if (!timestamp) {
				return ''
			}
			try {
				return new Date(Number(timestamp) * 1000).toLocaleDateString(undefined, {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
				})
			} catch {
				return String(timestamp)
			}
		},
	},
}
</script>

<style scoped>
.course-summary {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.summary-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
}

.section-label,
.card-kicker {
	margin: 0 0 10px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 1px;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.summary-header h3 {
	margin: 0;
	font-size: 1.45em;
	color: var(--color-main-text);
}

.summary-subtitle {
	margin: 8px 0 0;
	color: var(--color-text-maxcontrast);
}

.summary-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	justify-content: flex-end;
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 48px 16px;
	color: var(--color-text-maxcontrast);
}

.summary-error {
	margin: 0;
}

.summary-toast {
	padding: 12px 14px;
	border-radius: 12px;
	background: color-mix(in srgb, var(--color-success) 16%, transparent);
	color: var(--color-success);
	font-weight: 600;
}

.summary-grid,
.detail-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 20px;
}

.stats-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 16px;
}

.widget-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-left: 3px solid var(--color-primary-element);
	border-radius: var(--border-radius-large, 12px);
	padding: 20px;
	box-shadow: 0 4px 14px color-mix(in srgb, var(--color-main-text) 5%, transparent);
}

.mastery-card {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
}

.mastery-ring-shell {
	position: relative;
	width: 160px;
	height: 160px;
	margin-bottom: 16px;
}

.mastery-ring {
	width: 100%;
	height: 100%;
	transform: rotate(-90deg);
}

.mastery-ring-track,
.mastery-ring-progress {
	fill: none;
	stroke-width: 12;
}

.mastery-ring-track {
	stroke: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-border));
}

.mastery-ring-progress {
	stroke: var(--color-primary-element);
	stroke-linecap: round;
	transition: stroke-dashoffset 0.25s ease;
}

.mastery-ring-copy {
	position: absolute;
	inset: 0;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
}

.mastery-rate,
.stat-value,
.snapshot-inline-value {
	font-size: 2em;
	font-weight: 700;
	color: var(--color-main-text);
}

.mastery-label,
.stat-meta,
.snapshot-inline-label {
	color: var(--color-text-maxcontrast);
}

.mastery-meta {
	display: flex;
	flex-direction: column;
	gap: 4px;
	color: var(--color-text-maxcontrast);
}

.snapshot-card {
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.snapshot-state {
	display: flex;
	flex-direction: column;
	gap: 6px;
	color: var(--color-text-maxcontrast);
}

.snapshot-inline-stats {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
}

.snapshot-inline-stat {
	padding: 14px;
	border-radius: 12px;
	background: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.stat-card {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-height: 150px;
}

.detail-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.detail-count {
	min-width: 28px;
	padding: 4px 10px;
	border-radius: 999px;
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-weight: 600;
	text-align: center;
}

.badge-gallery {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(132px, 1fr));
	gap: 12px;
}

.badge-item {
	padding: 14px 12px;
	border-radius: 12px;
	background: linear-gradient(180deg, color-mix(in srgb, var(--color-warning) 12%, transparent), var(--color-background-hover));
	border: 1px solid color-mix(in srgb, var(--color-warning) 24%, var(--color-border));
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-start;
}

.badge-emoji {
	font-size: 1.8em;
	line-height: 1;
}

.badge-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.trouble-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.trouble-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	padding: 14px 0;
	border-top: 1px solid var(--color-border);
}

.trouble-row:first-child {
	padding-top: 0;
	border-top: 0;
}

.trouble-row p {
	margin: 6px 0 0;
	color: var(--color-text-maxcontrast);
}

.trouble-accuracy {
	color: var(--color-warning);
	font-weight: 700;
}

.empty-hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 900px) {
	.summary-header {
		flex-direction: column;
	}

	.summary-actions {
		width: 100%;
		justify-content: flex-start;
	}

	.summary-grid,
	.detail-grid,
	.stats-grid,
	.snapshot-inline-stats {
		grid-template-columns: 1fr;
	}
}
</style>
