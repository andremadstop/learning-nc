<template>
	<div class="buddy-matching">
		<h4>{{ t('learning', 'Lernpartner') }}</h4>
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">{{ error }}</NcNoteCard>
		<!-- GitHub #40: the empty state said "Telos profile", a name the app shows nowhere, and
			offered no way to get there. It explains the feature and opens the learning profile. -->
		<div v-else-if="!canHelpMe.length && !iCanHelp.length" class="buddy-empty">
			<p class="buddy-empty__title">{{ t('learning', 'Noch keine Lernpartner in diesem Kurs.') }}</p>
			<p>{{ t('learning', 'Lernpartner sind andere Kursmitglieder, die dir bei einem Thema helfen können oder bei einem Thema Hilfe suchen, das du beherrschst. Trage dazu in deinem Lernprofil unter „Ich kann helfen bei...“ und „Ich brauche Hilfe bei...“ deine Themen ein und stelle die Sichtbarkeit auf „Kurs“. Ein Treffer entsteht, sobald ein anderes Kursmitglied dasselbe Thema in derselben Schreibweise eingetragen hat und sein Profil nicht auf „Privat“ steht.') }}</p>
			<NcButton class="buddy-empty__profile" @click="openLearningProfile">
				{{ t('learning', 'Lernprofil öffnen') }}
			</NcButton>
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
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { LEARNING_PROFILE_SECTION } from '../utils/telosProfile.js'

export default {
	name: 'BuddyMatching',

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
		t,

		/**
		 * The learning profile lives in the personal settings (route "settings"). The query tells
		 * App.vue to open "Meine Einstellungen" for instructors and PersonalSettings to scroll to
		 * the profile section.
		 */
		openLearningProfile() {
			this.$router?.push({ name: 'settings', query: { section: LEARNING_PROFILE_SECTION } })
		},

		async fetchBuddies() {
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/buddies', { courseId: this.courseId })
				const response = await axios.get(url)
				this.canHelpMe = response.data.can_help_me || []
				this.iCanHelp = response.data.i_can_help || []
			} catch (e) {
				this.error = t('learning', 'Lernpartner konnten nicht geladen werden.')
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
}

.buddy-empty__title {
	font-weight: bold;
}

.buddy-empty__profile {
	margin-top: 8px;
}
</style>
