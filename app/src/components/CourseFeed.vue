<template>
	<div class="course-feed" tabindex="-1">
		<NcLoadingIcon v-if="loading && items.length === 0" :size="32" />
		<div v-else-if="items.length === 0" class="feed-empty">
			{{ t('learning', 'Noch keine Neuigkeiten.') }}
		</div>
		<div v-else class="feed-list">
			<div v-for="item in items"
				:key="item.id"
				:data-feed-id="item.id"
				class="feed-item"
				:class="'feed-type--' + item.type">
				<span class="feed-icon">{{ typeIcon(item.type) }}</span>
				<div class="feed-content">
					<div class="feed-header">
						<span class="feed-type-badge">{{ typeLabel(item.type) }}</span>
						<span class="feed-header-end">
							<span class="feed-time">{{ formatRelativeTime(item.created_at) }}</span>
							<span v-if="isEdited(item)" class="feed-edited">{{ t('learning', 'bearbeitet') }}</span>
							<!-- GitHub #40: instructors edit and delete an announcement right here; the
								separate list under "New announcement" duplicated the feed. -->
							<NcButton v-if="canDelete(item)"
								variant="tertiary"
								size="small"
								class="feed-edit"
								:aria-label="t('learning', 'Ankündigung bearbeiten')"
								@click="requestEdit(item)">
								{{ t('learning', 'Bearbeiten') }}
							</NcButton>
							<NcButton v-if="canDelete(item)"
								variant="tertiary"
								size="small"
								class="feed-delete"
								:aria-label="t('learning', 'Ankündigung löschen')"
								@click="requestDelete(item)">
								{{ t('learning', 'Löschen') }}
							</NcButton>
						</span>
					</div>
					<strong class="feed-title">{{ itemTitle(item) }}</strong>
					<p v-if="item.body" class="feed-body">{{ item.body }}</p>
				</div>
			</div>
		</div>
		<button v-if="hasMore"
			class="feed-load-more"
			@click="loadMore">
			{{ t('learning', 'Mehr laden...') }}
		</button>

		<AccessibleDialog v-if="editItem"
			:name="t('learning', 'Ankündigung bearbeiten')"
			@closing="cancelEdit">
			<div class="feed-edit-form">
				<label for="feed-edit-title">{{ t('learning', 'Titel') }}</label>
				<input id="feed-edit-title"
					v-model="editTitle"
					type="text"
					maxlength="255"
					class="feed-edit-input">
				<label for="feed-edit-body">{{ t('learning', 'Nachricht') }}</label>
				<textarea id="feed-edit-body"
					v-model="editBody"
					class="feed-edit-textarea"
					rows="6" />
			</div>
			<template #actions>
				<NcButton variant="tertiary" :disabled="saving" @click="cancelEdit">
					{{ t('learning', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving || !editTitle.trim()" @click="saveEdit">
					{{ t('learning', 'Speichern') }}
				</NcButton>
			</template>
		</AccessibleDialog>

		<AccessibleDialog v-if="confirmItem"
			:name="t('learning', 'Ankündigung löschen')"
			@closing="cancelDelete">
			<p>{{ t('learning', 'Möchtest du die Ankündigung „{title}“ wirklich löschen? Sie verschwindet für alle Kursmitglieder aus dem Feed.', { title: confirmItem.title }, undefined, { escape: false, sanitize: false }) }}</p>
			<template #actions>
				<NcButton variant="tertiary" :disabled="deleting" @click="cancelDelete">
					{{ t('learning', 'Nicht löschen') }}
				</NcButton>
				<NcButton variant="error" :disabled="deleting" @click="confirmDelete">
					{{ t('learning', 'Löschen') }}
				</NcButton>
			</template>
		</AccessibleDialog>
	</div>
</template>

<script>
import { nextTick } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcButton from '@nextcloud/vue/components/NcButton'
import { showError } from '@nextcloud/dialogs'
import AccessibleDialog from './AccessibleDialog.vue'
import { translate as t } from '@nextcloud/l10n'
import { formatRelativeTime as relativeTime } from '../utils/relativeTime.js'
import { feedItemEdited, feedItemTitle, feedTypeIcon, feedTypeLabel } from '../utils/feedItem.js'

export default {
	name: 'CourseFeed',

	components: {
		AccessibleDialog,
		NcButton,
		NcLoadingIcon,
	},

	props: {
		courseId: {
			type: Number,
			required: true,
		},
		// GitHub #40: CourseDetail's instructor gate (membership, student view off). The server
		// checks the course instructor again on delete.
		canManage: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			items: [],
			loading: false,
			hasMore: false,
			offset: 0,
			// announcements of the course, only to find the id of a feed row written before the
			// feed carried meta.announcement_id
			announcements: [],
			confirmItem: null,
			deleting: false,
			editItem: null,
			editTitle: '',
			editBody: '',
			saving: false,
		}
	},

	watch: {
		// The feed tab stays mounted when an instructor turns the student view off; without
		// this, legacy rows (no meta.announcement_id) got no Edit/Delete until a remount.
		canManage(value) {
			if (value && !this.announcements.length) {
				this.fetchAnnouncements()
			}
		},
	},

	mounted() {
		this.fetchFeed()
		if (this.canManage) {
			this.fetchAnnouncements()
		}
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

		async fetchAnnouncements() {
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/announcements', {
					courseId: this.courseId,
				})
				const response = await axios.get(url)
				this.announcements = Array.isArray(response.data?.announcements) ? response.data.announcements : []
			} catch (error) {
				// Only legacy rows lose their delete button; linked rows carry their id.
				console.error('Failed to fetch announcements:', error)
			}
		},

		/**
		 * The announcement behind a feed row: meta.announcement_id since GitHub #40, otherwise
		 * the announcement with the same title created within two seconds of the feed row.
		 *
		 * @param {object} item feed item
		 * @return {number|null}
		 */
		announcementIdFor(item) {
			if (item?.type !== 'announcement') return null
			const linked = Number(item.meta?.announcement_id)
			if (Number.isInteger(linked) && linked > 0) return linked
			const created = Number(item.created_at)
			const match = this.announcements.find((a) => a.title === item.title
				&& Math.abs(Number(a.created_at) - created) <= 2)
			return match ? Number(match.id) : null
		},

		canDelete(item) {
			return this.canManage && this.announcementIdFor(item) !== null
		},

		requestDelete(item) {
			this.confirmItem = item
		},

		cancelDelete() {
			if (!this.deleting) {
				this.confirmItem = null
			}
		},

		async confirmDelete() {
			const item = this.confirmItem
			const announcementId = this.announcementIdFor(item)
			if (!item || announcementId === null) return
			const focusTarget = this.focusTargetAfterDelete(item, announcementId)
			this.deleting = true
			try {
				await axios.delete(generateUrl('/apps/learning/api/courses/{courseId}/announcements/{announcementId}', {
					courseId: this.courseId,
					announcementId,
				}))
				// the server removed every feed copy of this announcement
				this.items = this.items.filter((i) => this.announcementIdFor(i) !== announcementId)
				this.announcements = this.announcements.filter((a) => Number(a.id) !== announcementId)
				this.confirmItem = null
				this.restoreFocusAfterDelete(focusTarget)
			} catch (error) {
				console.error('Failed to delete announcement:', error)
				showError(t('learning', 'Die Ankündigung konnte nicht gelöscht werden.'))
			} finally {
				this.deleting = false
			}
		},

		/**
		 * The feed row whose Delete button gets the focus once an announcement is gone: the next
		 * row that keeps one, else the previous one; null means the feed itself. The dialog's
		 * focus trap returns focus to the button that opened it, which leaves the DOM with the
		 * row, so focus fell to <body> (WCAG 2.4.3).
		 *
		 * @param {object} item the deleted feed item
		 * @param {number} announcementId its announcement
		 * @return {number|string|null} the id of the feed row to focus
		 */
		focusTargetAfterDelete(item, announcementId) {
			const index = this.items.indexOf(item)
			const keeps = (i) => this.announcementIdFor(i) !== announcementId && this.canDelete(i)
			const next = this.items.slice(index + 1).find(keeps)
			const previous = this.items.slice(0, Math.max(index, 0)).reverse().find(keeps)
			return (next || previous)?.id ?? null
		},

		restoreFocusAfterDelete(feedId) {
			nextTick(() => {
				const root = this.$el
				if (!root || typeof root.querySelector !== 'function') return
				const row = feedId === null
					? null
					: Array.from(root.querySelectorAll('.feed-item')).find((el) => el.dataset.feedId === String(feedId))
				const target = row?.querySelector('.feed-delete') || root
				target.focus()
			})
		},

		requestEdit(item) {
			this.editItem = item
			this.editTitle = item.title || ''
			this.editBody = item.body || ''
		},

		cancelEdit() {
			if (!this.saving) {
				this.editItem = null
			}
		},

		/**
		 * GitHub #40: PUT the new text; the server changes the announcement and every feed copy of
		 * it. The feed row and the announcement list entry change in place, so a legacy row is
		 * still matched by its (new) title afterwards and keeps its buttons.
		 */
		async saveEdit() {
			const item = this.editItem
			const announcementId = this.announcementIdFor(item)
			const title = this.editTitle
			if (!item || announcementId === null || !title.trim()) return
			this.saving = true
			try {
				const response = await axios.put(generateUrl('/apps/learning/api/courses/{courseId}/announcements/{announcementId}', {
					courseId: this.courseId,
					announcementId,
				}), { title, body: this.editBody })
				const saved = response?.data?.announcement || {}
				const editedAt = Number(saved.edited_at) || Math.floor(Date.now() / 1000)
				const newTitle = typeof saved.title === 'string' ? saved.title : title
				const newBody = typeof saved.body === 'string' ? saved.body : this.editBody
				this.items = this.items.map((i) => (this.announcementIdFor(i) === announcementId
					? { ...i, title: newTitle, body: newBody, meta: { ...(i.meta || {}), announcement_id: announcementId, edited_at: editedAt } }
					: i))
				this.announcements = this.announcements.map((a) => (Number(a.id) === announcementId
					? { ...a, title: newTitle, body: newBody }
					: a))
				this.editItem = null
			} catch (error) {
				// the dialog stays open with the typed text
				console.error('Failed to update announcement:', error)
				showError(t('learning', 'Die Ankündigung konnte nicht gespeichert werden.'))
			} finally {
				this.saving = false
			}
		},

		isEdited(item) {
			return feedItemEdited(item)
		},

		async loadMore() {
			this.offset += 50
			await this.fetchFeed()
		},

		formatRelativeTime(timestamp) {
			return relativeTime(timestamp)
		},

		typeLabel(type) {
			return feedTypeLabel(type)
		},

		typeIcon(type) {
			return feedTypeIcon(type)
		},

		itemTitle(item) {
			return feedItemTitle(item)
		},
	},
}
</script>

<style scoped>
.course-feed {
	padding: 16px;
}

/* focus target after the last announcement was deleted; a click inside draws no frame */
.course-feed:focus:not(:focus-visible) {
	outline: none;
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

.feed-header-end {
	display: flex;
	align-items: center;
	gap: 8px;
}

.feed-edited {
	font-size: 0.85em;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.feed-edit-form {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

/* Nextcloud core fixes every textarea at 130px; the dialog field takes the full width. */
.feed-edit-input,
.feed-edit-textarea {
	width: 100%;
	margin-inline: 0;
}

.feed-edit-textarea {
	min-height: 8em;
	resize: vertical;
}

/* GitHub #40: an announcement keeps the line breaks its author typed. Text, never v-html. */
.feed-body {
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
	margin-bottom: 0;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
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
