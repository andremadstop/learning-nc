<template>
	<div v-if="hasScopes" class="teamlead-dashboard">
		<div class="teamlead-dashboard__header">
			<h3 class="teamlead-dashboard__title">{{ t('learning', 'Teamleiter-Dashboard') }}</h3>
			<p class="teamlead-dashboard__subtitle">{{ t('learning', 'Deine Gruppen-Compliance-Übersicht') }}</p>
		</div>

		<!-- Scope selector: one entry per (course, group) the user leads -->
		<div v-if="scopes.length > 1" class="teamlead-dashboard__scope-selector">
			<label class="teamlead-dashboard__scope-label" :for="'teamlead-scope-select'">
				{{ t('learning', 'Gruppe wählen') }}
			</label>
			<select
				id="teamlead-scope-select"
				class="teamlead-dashboard__scope-select"
				:value="selectedScopeIndex"
				@change="handleScopeChange(Number($event.target.value))">
				<option
					v-for="(scope, idx) in scopes"
					:key="scope.course_id + '::' + scope.group_id"
					:value="idx">
					{{ t('learning', 'Kurs {courseId}, Gruppe {groupId}', { courseId: scope.course_id, groupId: scope.group_id }) }}
				</option>
			</select>
		</div>

		<!-- Single scope: show inline label -->
		<p v-else-if="selectedScope" class="teamlead-dashboard__scope-hint">
			{{ t('learning', 'Kurs {courseId}, Gruppe {groupId}', { courseId: selectedScope.course_id, groupId: selectedScope.group_id }) }}
		</p>

		<!-- Loading state -->
		<div v-if="reportLoading" class="teamlead-dashboard__loading">
			<span>{{ t('learning', 'Lade Compliance-Daten...') }}</span>
		</div>

		<template v-else>
			<!-- Overdue / Missing members panel -->
			<div class="teamlead-dashboard__panel">
				<h4 class="teamlead-dashboard__panel-title">{{ t('learning', 'Fehlend / Überfällig') }}</h4>

				<table v-if="overdueOrMissing.length > 0" class="teamlead-dashboard__table">
					<thead>
						<tr>
							<th scope="col">{{ t('learning', 'Mitglied') }}</th>
							<th scope="col">{{ t('learning', 'Status') }}</th>
							<th scope="col">{{ t('learning', 'Fällig am') }}</th>
							<th scope="col">{{ t('learning', 'Aktion') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in overdueOrMissing" :key="row.member_ref" class="teamlead-dashboard__row">
							<td class="teamlead-dashboard__cell">{{ row.display_name }}</td>
							<td class="teamlead-dashboard__cell">
								<span class="teamlead-dashboard__status-badge"
									:class="'teamlead-dashboard__status-badge--' + row.status">
									{{ statusLabel(row.status) }}
								</span>
							</td>
							<td class="teamlead-dashboard__cell teamlead-dashboard__cell--date">
								{{ row.due_date || '—' }}
							</td>
							<td class="teamlead-dashboard__cell">
								<button
									class="teamlead-dashboard__remind-btn"
									:disabled="reminderStates[row.member_ref] === 'sending' || reminderStates[row.member_ref] === 'sent'"
									@click="sendReminder(row)">
									<span v-if="reminderStates[row.member_ref] === 'sent'" class="teamlead-dashboard__remind-sent">
										{{ t('learning', 'Erinnerung gesendet') }}
									</span>
									<span v-else>{{ t('learning', 'Erinnerung senden') }}</span>
								</button>
							</td>
						</tr>
					</tbody>
				</table>

				<p v-else class="teamlead-dashboard__empty">
					{{ t('learning', 'Keine fälligen oder fehlenden Mitglieder') }}
				</p>

				<p v-if="reminderError" class="teamlead-dashboard__error" role="alert">
					{{ reminderError }}
				</p>
			</div>

			<!-- Upcoming expirations panel -->
			<div class="teamlead-dashboard__panel">
				<h4 class="teamlead-dashboard__panel-title">{{ t('learning', 'Ablaufende Zertifikate') }}</h4>

				<table v-if="upcomingExpirations.length > 0" class="teamlead-dashboard__table">
					<thead>
						<tr>
							<th scope="col">{{ t('learning', 'Mitglied') }}</th>
							<th scope="col">{{ t('learning', 'Gültig bis') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in upcomingExpirations" :key="'exp-' + row.member_ref" class="teamlead-dashboard__row">
							<td class="teamlead-dashboard__cell">{{ row.display_name }}</td>
							<td class="teamlead-dashboard__cell teamlead-dashboard__cell--date">{{ row.expires_at }}</td>
						</tr>
					</tbody>
				</table>

				<p v-else class="teamlead-dashboard__empty">
					{{ t('learning', 'Keine ablaufenden Zertifikate') }}
				</p>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'TeamLeadDashboard',

	data() {
		return {
			scopes: [],
			selectedScopeIndex: 0,
			reportRows: [],
			scopesLoading: false,
			reportLoading: false,
			reminderStates: {},
			reminderError: null,
		}
	},

	computed: {
		hasScopes() {
			return this.scopes.length > 0
		},
		selectedScope() {
			return this.scopes[this.selectedScopeIndex] || null
		},
		currentCourseId() {
			return this.selectedScope ? this.selectedScope.course_id : null
		},
		currentGroupId() {
			return this.selectedScope ? this.selectedScope.group_id : null
		},
		overdueOrMissing() {
			return this.reportRows.filter((r) => r.status === 'overdue' || r.status === 'missing')
		},
		upcomingExpirations() {
			return this.reportRows.filter((r) => r.status === 'passed' && !!r.expires_at)
		},
	},

	mounted() {
		this.fetchScopes()
	},

	methods: {
		async fetchScopes() {
			this.scopesLoading = true
			try {
				const url = generateUrl('/apps/learning/api/my-team-lead-scopes')
				const response = await axios.get(url)
				this.scopes = (response.data && response.data.scopes) ? response.data.scopes : []
				if (this.scopes.length > 0) {
					await this.fetchReport()
				}
			} catch (err) {
				console.error('TeamLeadDashboard: failed to fetch scopes', err)
				this.scopes = []
			} finally {
				this.scopesLoading = false
			}
		},

		async fetchReport() {
			if (!this.selectedScope) return
			this.reportLoading = true
			this.reportRows = []
			this.reminderError = null
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/group-report', {
					courseId: this.currentCourseId,
				})
				const response = await axios.get(url, {
					params: {
						groupId: this.currentGroupId,
						expiringDays: 30,
					},
				})
				this.reportRows = (response.data && response.data.rows) ? response.data.rows : []
			} catch (err) {
				console.error('TeamLeadDashboard: failed to fetch report', err)
				this.reportRows = []
			} finally {
				this.reportLoading = false
			}
		},

		handleScopeChange(index) {
			this.selectedScopeIndex = index
			this.fetchReport()
		},

		async sendReminder(row) {
			const ref = row.member_ref
			this.reminderError = null
			this.reminderStates = { ...this.reminderStates, [ref]: 'sending' }
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/group-report/remind', {
					courseId: this.currentCourseId,
				})
				await axios.post(url, {
					groupId: this.currentGroupId,
					memberRef: ref,
				})
				this.reminderStates = { ...this.reminderStates, [ref]: 'sent' }
			} catch (err) {
				console.error('TeamLeadDashboard: failed to send reminder', err)
				this.reminderStates = { ...this.reminderStates, [ref]: 'error' }
				// Generic error — never leak membership detail from the 403 body
				this.reminderError = t('learning', 'Erinnerung konnte nicht gesendet werden.')
			}
		},

		statusLabel(status) {
			if (status === 'overdue') return t('learning', 'Überfällig')
			if (status === 'missing') return t('learning', 'Fehlend')
			return t('learning', 'Bestanden')
		},
	},
}
</script>

<style scoped>
.teamlead-dashboard {
	margin-top: 24px;
	padding: 20px 24px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.teamlead-dashboard__header {
	margin-bottom: 16px;
}

.teamlead-dashboard__title {
	margin: 0 0 4px 0;
	font-size: 16px;
	font-weight: 700;
	color: var(--color-main-text);
}

.teamlead-dashboard__subtitle {
	margin: 0;
	font-size: 0.875em;
	color: var(--color-text-maxcontrast);
}

.teamlead-dashboard__scope-selector {
	margin-bottom: 16px;
	display: flex;
	align-items: center;
	gap: 10px;
}

.teamlead-dashboard__scope-label {
	font-size: 0.875em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.teamlead-dashboard__scope-select {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875em;
}

.teamlead-dashboard__scope-hint {
	margin: 0 0 12px 0;
	font-size: 0.875em;
	color: var(--color-text-maxcontrast);
}

.teamlead-dashboard__loading {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.teamlead-dashboard__panel {
	margin-bottom: 24px;
}

.teamlead-dashboard__panel:last-child {
	margin-bottom: 0;
}

.teamlead-dashboard__panel-title {
	margin: 0 0 12px 0;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 1px;
	color: var(--color-text-maxcontrast);
}

.teamlead-dashboard__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.teamlead-dashboard__table th,
.teamlead-dashboard__cell {
	padding: 8px 10px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.teamlead-dashboard__table th {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
}

.teamlead-dashboard__row:last-child .teamlead-dashboard__cell {
	border-bottom: none;
}

.teamlead-dashboard__cell--date {
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.teamlead-dashboard__status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
}

.teamlead-dashboard__status-badge--overdue {
	background: color-mix(in srgb, var(--color-error) 12%, transparent);
	color: var(--color-error);
}

.teamlead-dashboard__status-badge--missing {
	background: color-mix(in srgb, var(--color-warning) 15%, transparent);
	color: var(--color-warning);
}

.teamlead-dashboard__status-badge--passed {
	background: color-mix(in srgb, var(--color-success) 12%, transparent);
	color: var(--color-success);
}

.teamlead-dashboard__remind-btn {
	padding: 4px 12px;
	font-size: 0.85em;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-primary-element);
	cursor: pointer;
	transition: background 0.15s;
}

.teamlead-dashboard__remind-btn:hover:not(:disabled) {
	background: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
}

.teamlead-dashboard__remind-btn:disabled {
	opacity: 0.6;
	cursor: default;
}

.teamlead-dashboard__remind-sent {
	color: var(--color-success);
}

.teamlead-dashboard__empty {
	margin: 8px 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.teamlead-dashboard__error {
	margin-top: 8px;
	color: var(--color-error);
	font-size: 0.875em;
}
</style>
