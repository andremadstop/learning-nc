<template>
	<div class="knowledge-moderation">
		<h4>{{ t('learning', 'Schwarmgedaechtnis — Beitraege moderieren') }}</h4>

		<div v-if="loading" class="loading-container">
			<span class="icon-loading" />
			<p>{{ t('learning', 'Lade...') }}</p>
		</div>

		<div v-else-if="pending.length === 0" class="empty-state">
			<p>{{ t('learning', 'Keine ausstehenden Beitraege.') }}</p>
		</div>

		<div v-else class="pending-list">
			<div v-for="item in pending"
				:key="item.id"
				class="pending-item">
				<div class="pending-header">
					<span class="pending-title">{{ item.title || item.source_file }}</span>
					<span class="pending-user">{{ item.user_id }}</span>
				</div>
				<p class="pending-text">{{ truncateText(item.content || item.text, 200) }}</p>
				<div class="pending-meta">
					{{ formatDate(item.created_at) }}
				</div>
				<div class="pending-actions">
					<button
						class="material-btn small primary"
						:disabled="moderating[item.id]"
						@click="moderate(item.id, 'approve')">
						{{ t('learning', 'Freigeben') }}
					</button>
					<button
						class="material-btn small danger"
						:disabled="moderating[item.id]"
						@click="moderate(item.id, 'reject')">
						{{ t('learning', 'Ablehnen') }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'KnowledgeModeration',

	props: {
		courseId: {
			type: Number,
			required: true,
		},
	},

	data() {
		return {
			pending: [],
			loading: false,
			moderating: {},
		}
	},

	computed: {
		pendingCount() {
			return this.pending.length
		},
	},

	watch: {
		pendingCount(count) {
			this.$emit('pending-count', count)
		},
	},

	mounted() {
		this.loadPending()
	},

	methods: {
		t,

		async loadPending() {
			this.loading = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/knowledge/pending', {
					courseId: this.courseId,
				})
				const response = await axios.get(url)
				this.pending = response.data || []
				this.$emit('pending-count', this.pending.length)
			} catch (e) {
				console.error('Failed to load pending contributions', e)
			} finally {
				this.loading = false
			}
		},

		async moderate(chunkId, action) {
			this.$set(this.moderating, chunkId, true)
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/knowledge/moderate/{chunkId}', {
					courseId: this.courseId,
					chunkId,
				})
				await axios.post(url, { action })
				this.pending = this.pending.filter(item => item.id !== chunkId)
				this.$emit('pending-count', this.pending.length)
			} catch (e) {
				console.error('Failed to moderate contribution', e)
			} finally {
				this.$set(this.moderating, chunkId, false)
			}
		},

		truncateText(text, maxLength) {
			if (!text) return ''
			if (text.length <= maxLength) return text
			return text.substring(0, maxLength) + '...'
		},

		formatDate(timestamp) {
			if (!timestamp) return '-'
			return new Date(timestamp * 1000).toLocaleDateString('de-DE')
		},
	},
}
</script>

<style scoped>
.knowledge-moderation {
	margin-top: 32px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border, #ededed);
}

.knowledge-moderation h4 {
	margin: 0 0 16px 0;
}

.empty-state {
	padding: 24px 16px;
	text-align: center;
	color: var(--color-text-maxcontrast, #767676);
}

.pending-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.pending-item {
	padding: 14px 16px;
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background, #fff);
}

.pending-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	margin-bottom: 6px;
}

.pending-title {
	font-weight: 600;
	font-size: 14px;
	word-break: break-word;
}

.pending-user {
	flex-shrink: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #767676);
	background: var(--color-background-dark, #f0f0f0);
	padding: 2px 8px;
	border-radius: 10px;
}

.pending-text {
	font-size: 13px;
	color: var(--color-text-light, #555);
	margin: 4px 0 8px 0;
	line-height: 1.5;
	word-break: break-word;
}

.pending-meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #767676);
	margin-bottom: 8px;
}

.pending-actions {
	display: flex;
	gap: 8px;
}

.material-btn {
	padding: 8px 16px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background, #fff);
	cursor: pointer;
	font-size: 14px;
	min-height: 44px;
}

.material-btn.small {
	padding: 6px 14px;
	font-size: 13px;
	min-height: 36px;
}

.material-btn.primary {
	background: var(--color-primary-element, #00679e);
	color: var(--color-primary-element-text, #fff);
	border-color: var(--color-primary-element, #00679e);
}

.material-btn.danger {
	color: #c62828;
	border-color: #ef9a9a;
}

.material-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 24px;
	gap: 8px;
}

@media (max-width: 768px) {
	.pending-header {
		flex-direction: column;
		align-items: flex-start;
	}

	.pending-actions {
		flex-direction: column;
	}
}
</style>
