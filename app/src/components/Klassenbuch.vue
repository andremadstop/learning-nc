<template>
	<div class="klassenbuch">
		<div class="klassenbuch-header">
			<div>
				<h4>{{ t('learning', 'Klassenbuch') }}</h4>
				<p class="klassenbuch-subtitle">
					{{ t('learning', 'Wer ist im Kurs, wer hilft bei welchen Themen und wem kannst du heute Kudos geben?') }}
				</p>
			</div>
			<div class="klassenbuch-actions">
				<span class="kudos-remaining">
					{{ t('learning', '{n} Kudos übrig heute', { n: kudosRemaining }) }}
				</span>
				<NcButton type="secondary" @click="exportVcard">
					{{ t('learning', 'vCard') }}
				</NcButton>
				<NcButton
					v-if="canToggleVisibility"
					type="tertiary"
					:disabled="togglingVisibility"
					@click="toggleVisibility">
					{{ myVisibility === 'private'
						? t('learning', 'Im Kurs sichtbar machen')
						: t('learning', 'Sichtbarkeit pausieren') }}
				</NcButton>
			</div>
		</div>

		<NcNoteCard v-if="myVisibility === 'private' && canToggleVisibility" type="info" class="klassenbuch-note">
			{{ t('learning', 'Dein Profil ist aktuell privat. Du siehst das Klassenbuch trotzdem, andere sehen dich aber erst nach dem Opt-in.') }}
		</NcNoteCard>

		<NcNoteCard v-if="success" type="success" class="klassenbuch-note">
			{{ success }}
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error" class="klassenbuch-note">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="klassenbuch-loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcEmptyContent
			v-else-if="profiles.length === 0"
			:name="t('learning', 'Noch keine sichtbaren Profile')">
			<template #description>
				{{ t('learning', 'Sobald Teilnehmende ihr Profil freigeben, erscheint hier eure Squad-Übersicht.') }}
			</template>
		</NcEmptyContent>

		<div v-else class="squad-grid">
			<article
				v-for="profile in profiles"
				:key="profile.user_id"
				class="squad-card"
				:class="{ 'squad-card--self': profile.is_self }">
				<div class="squad-card__top">
					<div class="squad-avatar">
						{{ avatarLetter(profile.display_name) }}
						<span v-if="profile.is_online" class="online-dot" />
					</div>
					<div class="squad-meta">
						<div class="squad-name-row">
							<strong>{{ profile.display_name }}</strong>
							<span class="role-pill" :class="profile.role">{{ roleLabel(profile.role) }}</span>
						</div>
						<div class="squad-level">
							{{ t('learning', 'Level {level}', { level: profile.level || 1 }) }}
						</div>
					</div>
				</div>

				<div class="squad-superpower">
					<span class="superpower-label">{{ t('learning', 'Superkraft') }}</span>
					<span class="superpower-badge" :class="profile.superpower_source || 'unset'">
						{{ profile.superpower || t('learning', 'Noch in Vorbereitung') }}
					</span>
				</div>

				<p v-if="profile.bio" class="squad-bio">{{ profile.bio }}</p>

				<div class="squad-footer">
					<span class="kudos-count">
						{{ t('learning', '{n} Kudos', { n: profile.kudos_received_count || 0 }) }}
					</span>
					<NcButton
						type="primary"
						:disabled="!canGiveKudos(profile)"
						@click="sendKudos(profile)">
						{{ sendingKudosFor === profile.user_id
							? t('learning', 'Sende...')
							: t('learning', 'Kudos') }}
					</NcButton>
				</div>
			</article>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

export default {
	name: 'Klassenbuch',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		courseId: {
			type: Number,
			required: true,
		},
		userRole: {
			type: String,
			default: 'student',
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			success: '',
			profiles: [],
			currentUserId: '',
			myVisibility: 'private',
			kudosRemaining: 0,
			canToggleVisibility: false,
			sendingKudosFor: '',
			togglingVisibility: false,
		}
	},

	mounted() {
		this.loadClassbook()
	},

	methods: {
		async loadClassbook() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(generateUrl('/apps/learning/api/courses/{courseId}/classbook', {
					courseId: this.courseId,
				}))
				const data = response.data || {}
				this.profiles = Array.isArray(data) ? data : (data.profiles || [])
				this.currentUserId = data.current_user_id || ''
				this.myVisibility = data.my_visibility || 'private'
				this.kudosRemaining = Number(data.kudos_remaining || 0)
				this.canToggleVisibility = data.can_toggle_visibility === true
			} catch (e) {
				this.error = e?.response?.data?.error || t('learning', 'Klassenbuch konnte nicht geladen werden.')
			} finally {
				this.loading = false
			}
		},
		avatarLetter(name) {
			const safe = String(name || '').trim()
			return safe ? safe.charAt(0).toUpperCase() : '?'
		},
		roleLabel(role) {
			return role === 'instructor' ? t('learning', 'Dozent') : t('learning', 'Student')
		},
		canGiveKudos(profile) {
			return this.userRole === 'student'
				&& this.kudosRemaining > 0
				&& profile
				&& profile.user_id !== this.currentUserId
				&& this.sendingKudosFor === ''
		},
		async sendKudos(profile) {
			if (!this.canGiveKudos(profile)) return
			this.sendingKudosFor = profile.user_id
			this.error = ''
			this.success = ''
			try {
				const response = await axios.post(generateUrl('/apps/learning/api/courses/{courseId}/kudos', {
					courseId: this.courseId,
				}), {
					toUser: profile.user_id,
				})
				this.kudosRemaining = Number(response.data?.kudos_remaining || 0)
				profile.kudos_received_count = Number(profile.kudos_received_count || 0) + 1
				this.success = t('learning', 'Kudos an {name} gesendet.', { name: profile.display_name })
			} catch (e) {
				this.error = e?.response?.data?.error || t('learning', 'Kudos konnten nicht gesendet werden.')
			} finally {
				this.sendingKudosFor = ''
			}
		},
		async toggleVisibility() {
			this.togglingVisibility = true
			this.error = ''
			try {
				const response = await axios.post(generateUrl('/apps/learning/api/courses/{courseId}/classbook/visibility', {
					courseId: this.courseId,
				}))
				this.myVisibility = response.data?.visibility || this.myVisibility
				this.success = this.myVisibility === 'private'
					? t('learning', 'Dein Klassenbuch-Profil ist wieder privat.')
					: t('learning', 'Dein Klassenbuch-Profil ist jetzt sichtbar.')
				await this.loadClassbook()
			} catch (e) {
				this.error = e?.response?.data?.error || t('learning', 'Sichtbarkeit konnte nicht geändert werden.')
			} finally {
				this.togglingVisibility = false
			}
		},
		exportVcard() {
			window.location.href = generateUrl('/apps/learning/api/courses/{courseId}/classbook/vcard', {
				courseId: this.courseId,
			})
		},
	},
}
</script>

<style scoped>
.klassenbuch {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.klassenbuch-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
}

.klassenbuch-header h4 {
	margin: 0 0 6px 0;
}

.klassenbuch-subtitle {
	margin: 0;
	color: var(--color-text-maxcontrast);
	max-width: 54ch;
}

.klassenbuch-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: flex-end;
	gap: 8px;
}

.kudos-remaining {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.klassenbuch-note {
	margin: 0;
}

.klassenbuch-loading {
	display: flex;
	justify-content: center;
	padding: 28px 0;
}

.squad-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 14px;
}

.squad-card {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: 16px;
	background: linear-gradient(180deg, color-mix(in srgb, var(--color-main-background) 92%, var(--color-primary-element) 8%), var(--color-main-background));
}

.squad-card--self {
	box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--color-primary-element) 45%, transparent);
}

.squad-card__top {
	display: flex;
	align-items: center;
	gap: 12px;
}

.squad-avatar {
	position: relative;
	width: 48px;
	height: 48px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	background: color-mix(in srgb, var(--color-primary-element) 18%, white);
	color: var(--color-primary-element);
	font-weight: 700;
	font-size: 18px;
}

.online-dot {
	position: absolute;
	right: 2px;
	bottom: 2px;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-success);
	border: 2px solid var(--color-main-background);
}

.squad-meta {
	min-width: 0;
}

.squad-name-row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.squad-level {
	margin-top: 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.role-pill {
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 700;
}

.role-pill.instructor {
	background: color-mix(in srgb, var(--color-primary-element) 18%, white);
	color: var(--color-primary-element);
}

.role-pill.student {
	background: color-mix(in srgb, var(--color-success) 18%, white);
	color: var(--color-success);
}

.squad-superpower {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.superpower-label {
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.superpower-badge {
	display: inline-flex;
	align-self: flex-start;
	padding: 6px 10px;
	border-radius: 999px;
	background: color-mix(in srgb, var(--color-main-text) 6%, white);
	font-size: 13px;
	font-weight: 600;
}

.superpower-badge.help_offer {
	background: color-mix(in srgb, var(--color-success) 18%, white);
}

.superpower-badge.pool_progress {
	background: color-mix(in srgb, var(--color-primary-element) 18%, white);
}

.squad-bio {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	line-height: 1.45;
}

.squad-footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 10px;
	margin-top: auto;
}

.kudos-count {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 720px) {
	.klassenbuch-header {
		flex-direction: column;
	}

	.klassenbuch-actions {
		justify-content: flex-start;
	}
}
</style>
