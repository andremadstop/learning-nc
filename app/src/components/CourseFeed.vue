<template>
	<div class="course-feed">
		<NcLoadingIcon v-if="loading && items.length === 0" :size="32" />
		<div v-else-if="items.length === 0" class="feed-empty">
			{{ t('learning', 'Noch keine Neuigkeiten.') }}
		</div>
		<div v-else class="feed-list">
			<div v-for="item in items"
				:key="item.id"
				class="feed-item"
				:class="'feed-type--' + item.type">
				<span class="feed-icon">{{ typeIcon(item.type) }}</span>
				<div class="feed-content">
					<div class="feed-header">
						<span class="feed-type-badge">{{ typeLabel(item.type) }}</span>
						<span class="feed-time">{{ formatRelativeTime(item.created_at) }}</span>
					</div>
					<strong class="feed-title">{{ item.title }}</strong>
					<p v-if="item.body" class="feed-body">{{ item.body }}</p>
				</div>
			</div>
		</div>
		<button v-if="hasMore"
			class="feed-load-more"
			@click="loadMore">
			{{ t('learning', 'Mehr laden...') }}
		</button>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import { translate as t } from '@nextcloud/l10n'

const TYPE_LABELS = {
	announcement: 'Ankuendigung',
	pool_added: 'Neuer Pool',
	milestone: 'Meilenstein',
	content_update: 'Aktualisierung',
	progress: 'Fortschritt',
}

const TYPE_ICONS = {
	announcement: '\uD83D\uDCE2',
	pool_added: '\uD83D\uDCDA',
	milestone: '\u2B50',
	content_update: '\uD83D\uDD04',
	progress: '\uD83D\uDCCA',
}

export default {
	name: 'CourseFeed',

	components: {
		NcLoadingIcon,
	},

	props: {
		courseId: {
			type: Number,
			required: true,
		},
	},

	data() {
		return {
			items: [],
			loading: false,
			hasMore: false,
			offset: 0,
		}
	},

	mounted() {
		this.fetchFeed()
	},

	methods: {
		t,

		async fetchFeed() {
			this.loading = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/feed', {
					courseId: this.courseId,
				})
				const response = await axios.get(url, {
					params: { limit: 50, offset: this.offset },
				})
				const newItems = response.data.items || []
				this.items = this.items.concat(newItems)
				this.hasMore = newItems.length === 50
			} catch (error) {
				console.error('Failed to fetch feed:', error)
			} finally {
				this.loading = false
			}
		},

		async loadMore() {
			this.offset += 50
			await this.fetchFeed()
		},

		formatRelativeTime(timestamp) {
			const now = Math.floor(Date.now() / 1000)
			const diff = now - timestamp

			if (diff < 60) {
				return 'gerade eben'
			}
			const minutes = Math.floor(diff / 60)
			if (minutes < 60) {
				return minutes === 1 ? 'vor 1 Minute' : `vor ${minutes} Minuten`
			}
			const hours = Math.floor(diff / 3600)
			if (hours < 24) {
				return hours === 1 ? 'vor 1 Stunde' : `vor ${hours} Stunden`
			}
			const days = Math.floor(diff / 86400)
			if (days <= 30) {
				return days === 1 ? 'vor 1 Tag' : `vor ${days} Tagen`
			}
			const date = new Date(timestamp * 1000)
			const dd = String(date.getDate()).padStart(2, '0')
			const mm = String(date.getMonth() + 1).padStart(2, '0')
			const yyyy = date.getFullYear()
			return `${dd}.${mm}.${yyyy}`
		},

		typeLabel(type) {
			return TYPE_LABELS[type] || type
		},

		typeIcon(type) {
			return TYPE_ICONS[type] || '\uD83D\uDCCC'
		},
	},
}
</script>

<style scoped>
.course-feed {
	padding: 16px;
}

.feed-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.feed-item {
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	display: flex;
	gap: 12px;
	align-items: flex-start;
}

.feed-type--announcement {
	border-left: 3px solid var(--color-primary);
}

.feed-icon {
	font-size: 1.4em;
	flex-shrink: 0;
}

.feed-content {
	flex: 1;
	min-width: 0;
}

.feed-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 4px;
}

.feed-type-badge {
	font-size: 0.8em;
	padding: 2px 8px;
	border-radius: 4px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.feed-time {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.feed-title {
	display: block;
}

.feed-body {
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
	margin-bottom: 0;
}

.feed-empty {
	text-align: center;
	padding: 32px;
	color: var(--color-text-maxcontrast);
}

.feed-load-more {
	display: block;
	margin: 16px auto 0;
	padding: 8px 24px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.feed-load-more:hover {
	background: var(--color-background-hover);
}
</style>
