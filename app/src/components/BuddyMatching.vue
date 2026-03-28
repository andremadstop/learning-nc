<template>
	<div class="buddy-matching">
		<h4>{{ t('learning', 'Lernpartner') }}</h4>
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">{{ error }}</NcNoteCard>
		<div v-else-if="!canHelpMe.length && !iCanHelp.length" class="buddy-empty">
			<p>{{ t('learning', 'Noch keine Lernpartner-Matches. Trage in deinem Telos-Profil ein, wobei du Hilfe suchst oder anbieten kannst.') }}</p>
		</div>
		<template v-else>
			<div v-if="canHelpMe.length" class="buddy-section">
				<h5>{{ t('learning', 'Kann dir helfen bei...') }}</h5>
				<div class="buddy-cards">
					<div v-for="buddy in canHelpMe" :key="buddy.user_id" class="buddy-card">
						<span class="buddy-name">{{ buddy.display_name }}</span>
						<div class="buddy-topics">
							<span v-for="topic in buddy.topics" :key="topic" class="buddy-topic-tag">{{ topic }}</span>
						</div>
					</div>
				</div>
			</div>
			<div v-if="iCanHelp.length" class="buddy-section">
				<h5>{{ t('learning', 'Du kannst helfen bei...') }}</h5>
				<div class="buddy-cards">
					<div v-for="buddy in iCanHelp" :key="buddy.user_id" class="buddy-card">
						<span class="buddy-name">{{ buddy.display_name }}</span>
						<div class="buddy-topics">
							<span v-for="topic in buddy.topics" :key="topic" class="buddy-topic-tag">{{ topic }}</span>
						</div>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

export default {
	name: 'BuddyMatching',

	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		courseId: {
			type: Number,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			canHelpMe: [],
			iCanHelp: [],
			error: null,
		}
	},

	mounted() {
		this.fetchBuddies()
	},

	methods: {
		async fetchBuddies() {
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/buddies', { courseId: this.courseId })
				const response = await axios.get(url)
				this.canHelpMe = response.data.can_help_me || []
				this.iCanHelp = response.data.i_can_help || []
			} catch (e) {
				this.error = 'Lernpartner konnten nicht geladen werden.'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.buddy-matching {
	padding: 8px 0;
}

.buddy-section {
	margin-bottom: 16px;
}

.buddy-cards {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.buddy-card {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	background: var(--color-background-dark);
	border-radius: 8px;
}

.buddy-name {
	font-weight: bold;
	white-space: nowrap;
}

.buddy-topics {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.buddy-topic-tag {
	background: var(--color-primary-element-light);
	border-radius: 12px;
	padding: 2px 10px;
	font-size: 0.85em;
}

.buddy-empty p {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
