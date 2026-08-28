<template>
	<div class="course-tab-verwaltung">
		<div class="verwaltung-subnav" role="tablist" :aria-label="t('learning', 'Verwaltung Bereiche')">
			<button
				v-for="tab in visibleSubTabs"
				:key="tab.id"
				class="verwaltung-pill"
				:class="{ active: currentSubTab === tab.id }"
				@click="selectSubTab(tab.id)">
				{{ tab.label }}
			</button>
		</div>

		<!-- Mode Config -->
		<div v-if="currentSubTab === 'mode-config'" class="tab-content mode-config-section">
			<h3>{{ t('learning', 'Kursregeln — Lernmodi') }}</h3>
			<p class="mode-config-hint">{{ t('learning', 'Deaktivierte Modi werden Studierenden nicht angezeigt.') }}</p>
			<div class="mode-toggles">
				<div v-for="mode in modeConfigKeys" :key="mode.key" class="mode-toggle-row">
					<label class="mode-toggle-label">
						<input type="checkbox" :checked="modeConfigLocal[mode.key] !== false" @change="toggleMode(mode.key, $event.target.checked)" />
						{{ mode.label }}
					</label>
				</div>
			</div>
			<NcButton type="primary" :disabled="savingModeConfig" @click="saveModeConfig">
				{{ savingModeConfig ? t('learning', 'Saving...') : t('learning', 'Save') }}
			</NcButton>
			<NcNoteCard v-if="modeConfigSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>

			<div class="tool-config-section">
				<h3>{{ t('learning', 'Kursregeln — Werkzeuge') }}</h3>
				<p class="mode-config-hint">{{ t('learning', 'Hier kannst du die acht Simulatoren pro Kurs einschränken. Global deaktivierte Werkzeuge bleiben gesperrt.') }}</p>
				<div v-if="loadingToolConfig" class="loading-hint">{{ t('learning', 'Loading...') }}</div>
				<div v-else class="mode-toggles">
					<div v-for="tool in toolConfigKeys" :key="tool.key" class="mode-toggle-row">
						<label class="mode-toggle-label">
							<input
								type="checkbox"
								:model-value="toolConfigLocal[tool.key] !== false"
								:disabled="!isAdminToolEnabled(tool.key)"
								@change="toggleCourseTool(tool.key, $event.target.checked)" />
							{{ tool.label }}
						</label>
						<small v-if="!isAdminToolEnabled(tool.key)" class="mode-config-note">{{ t('learning', 'Global deaktiviert') }}</small>
					</div>
				</div>
				<NcButton type="primary" :disabled="savingToolConfig || loadingToolConfig" @click="saveToolConfig">
					{{ savingToolConfig ? t('learning', 'Saving...') : t('learning', 'Save tools') }}
				</NcButton>
				<NcNoteCard v-if="toolConfigSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</div>

			<div class="sprint-config tool-config-section">
				<h3>{{ t('learning', 'Leitner Sprint-Modus') }}</h3>
				<p class="mode-config-hint">{{ t('learning', 'Sprint-Intervalle verkürzen die Wiederholungszeiten (4h/12h/1d/2d statt 1d/3d/7d/14d). Ideal für Intensivkurse.') }}</p>
				<label class="mode-toggle-label">
					<input type="checkbox" v-model="leitnerSprint" @change="saveLeitnerSprint" />
					{{ t('learning', 'Sprint-Modus aktivieren') }}
				</label>
			</div>

			<div class="talk-config tool-config-section">
				<h3>{{ t('learning', 'Talk-Raum') }}</h3>
				<p class="mode-config-hint">{{ t('learning', 'Token des NC Talk-Raums eintragen (z.B. abc123xyz aus der Talk-URL).') }}</p>
				<div class="talk-token-row">
					<input type="text" v-model="talkRoomToken" :placeholder="t('learning', 'Talk-Token')" maxlength="255" class="talk-token-input" />
					<NcButton type="primary" @click="saveTalkRoomToken" :disabled="savingTalkToken">
						{{ savingTalkToken ? t('learning', 'Saving...') : t('learning', 'Save') }}
					</NcButton>
				</div>
				<NcNoteCard v-if="talkTokenSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</div>

			<div class="exam-date-config tool-config-section">
				<h3>{{ t('learning', 'Prüfungstermin') }}</h3>
				<p class="mode-config-hint">{{ t('learning', 'Prüfungsdatum und -uhrzeit für den Dashboard-Countdown setzen.') }}</p>
				<div class="exam-date-row">
					<input v-model="examDateLocal" type="datetime-local" class="exam-date-input" />
					<NcButton type="primary" :disabled="savingExamDate" @click="saveExamDate">
						{{ savingExamDate ? t('learning', 'Saving...') : t('learning', 'Save') }}
					</NcButton>
					<NcButton v-if="examDateLocal" :disabled="savingExamDate" @click="clearExamDate">
						{{ t('learning', 'Entfernen') }}
					</NcButton>
				</div>
				<NcNoteCard v-if="examDateSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</div>

			<div class="maintenance-config tool-config-section">
				<h3>{{ t('learning', 'Maintenance Mode') }}</h3>
				<p class="mode-config-hint">{{ t('learning', 'Studenten erhalten nach Kursende taeglich eine kleine Wiederholungs-Portion basierend auf dem FSRS-Algorithmus.') }}</p>
				<label class="mode-toggle-label">
					<input type="checkbox" v-model="maintenanceMode" @change="saveMaintenanceMode" />
					{{ t('learning', 'Maintenance Mode nach Kursende') }}
				</label>
				<NcNoteCard v-if="maintenanceModeSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</div>

			<div class="campaign-config tool-config-section">
				<h3>{{ t('learning', 'Abenteuer-Kampagnen') }}</h3>
				<p class="mode-config-hint">{{ t('learning', 'Wähle welche Kampagnen für Studierende sichtbar sind. Keine Auswahl = alle verfügbar.') }}</p>
				<div v-if="loadingCampaignList" class="loading-hint">{{ t('learning', 'Loading...') }}</div>
				<template v-else>
					<div class="campaign-quick-actions">
						<NcButton type="tertiary" @click="selectAllCampaigns">{{ t('learning', 'Alle auswählen') }}</NcButton>
						<NcButton type="tertiary" @click="selectTop5Campaigns">{{ t('learning', 'Top 5 empfohlen') }}</NcButton>
						<NcButton type="tertiary" @click="clearCampaignSelection">{{ t('learning', 'Keine (alle verfügbar)') }}</NcButton>
					</div>
					<div class="mode-toggles">
						<div v-for="c in availableCampaigns" :key="c.campaign_id" class="mode-toggle-row">
							<label class="mode-toggle-label">
								<input type="checkbox" :checked="selectedCampaignIds.includes(c.campaign_id)" @change="toggleCampaign(c.campaign_id, $event.target.checked)" />
								{{ c.title }}
							</label>
							<small class="mode-config-note">{{ c.difficulty }}</small>
						</div>
					</div>
					<NcButton type="primary" :disabled="savingCampaigns" @click="saveCampaignSelection">
						{{ savingCampaigns ? t('learning', 'Saving...') : t('learning', 'Save') }}
					</NcButton>
					<NcNoteCard v-if="campaignsSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
				</template>
			</div>

			<!-- Zertifizierung (Phase 154) -->
			<div class="cert-config tool-config-section">
				<h3>{{ t('learning', 'Zertifizierung') }}</h3>
				<NcCheckboxRadioSwitch :checked="certEnabled" @update:checked="certEnabled = $event">
					{{ t('learning', 'Zertifizierung aktivieren') }}
				</NcCheckboxRadioSwitch>

				<template v-if="certEnabled">
					<div class="cert-config-field">
						<label>{{ t('learning', 'Mindest-Score (%)') }}</label>
						<input
							type="number"
							:value="certPassPercent"
							min="1"
							max="100"
							class="cert-config-input"
							@change="certPassPercent = Math.max(1, Math.min(100, parseInt($event.target.value) || 80))" />
					</div>

					<div v-if="coursePools.length" class="cert-config-field">
						<label>{{ t('learning', 'Pflicht-Pools') }}</label>
						<div class="mode-toggles">
							<div v-for="pool in coursePools" :key="pool.pool_id" class="mode-toggle-row">
								<label class="mode-toggle-label">
									<input
										type="checkbox"
										:checked="certRequiredPoolIds.includes(Number(pool.pool_id))"
										@change="toggleCertPool(pool.pool_id, $event.target.checked)" />
									{{ pool.pool_name }}
								</label>
							</div>
						</div>
					</div>

					<div class="cert-config-field">
						<label>{{ t('learning', 'Gültigkeitsdauer (Tage, 0 = unbegrenzt)') }}</label>
						<input
							type="number"
							:value="certValidityDays"
							min="0"
							class="cert-config-input"
							@change="certValidityDays = Math.max(0, parseInt($event.target.value) || 0)" />
					</div>
				</template>

				<NcButton type="primary" :disabled="certSaving" @click="saveCertConfig">
					{{ certSaving ? t('learning', 'Saving...') : t('learning', 'Speichern') }}
				</NcButton>
				<NcNoteCard v-if="certSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</div>
		</div>

		<!-- Schedule -->
		<div v-if="currentSubTab === 'schedule'" class="tab-content schedule-section">
			<h3>{{ t('learning', 'Kurs-Zeitplan') }}</h3>
			<p class="mode-config-hint">{{ t('learning', 'Kapitel mit Start- und Zieldatum planen. Studierende sehen den Zeitplan als Timeline.') }}</p>

			<div v-if="loadingSchedule" class="loading-hint">{{ t('learning', 'Loading...') }}</div>

			<template v-else>
				<table v-if="scheduleItems.length" class="schedule-table">
					<thead>
						<tr>
							<th>{{ t('learning', 'Kapitel') }}</th>
							<th>{{ t('learning', 'Startdatum') }}</th>
							<th>{{ t('learning', 'Zieldatum') }}</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<tr v-for="(item, idx) in scheduleItems" :key="idx">
							<td>
								<input
									v-model="item.chapter_title"
									type="text"
									class="schedule-input schedule-input--title"
									:placeholder="t('learning', 'Kapitelname')" />
							</td>
							<td>
								<input
									v-model="item.start_date"
									type="date"
									class="schedule-input" />
							</td>
							<td>
								<input
									v-model="item.target_date"
									type="date"
									class="schedule-input" />
							</td>
							<td>
								<button class="schedule-remove-btn" :title="t('learning', 'Entfernen')" @click="removeScheduleRow(idx)">
									&times;
								</button>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="schedule-actions">
					<NcButton type="secondary" @click="addScheduleRow">
						{{ t('learning', '+ Kapitel hinzufuegen') }}
					</NcButton>
					<NcButton type="primary" :disabled="savingSchedule || !scheduleItems.length" @click="saveSchedule">
						{{ savingSchedule ? t('learning', 'Saving...') : t('learning', 'Zeitplan speichern') }}
					</NcButton>
					<NcButton v-if="scheduleItems.length" type="error" :disabled="deletingSchedule" @click="deleteSchedule">
						{{ deletingSchedule ? t('learning', 'Deleting...') : t('learning', 'Zeitplan loeschen') }}
					</NcButton>
				</div>
				<NcNoteCard v-if="scheduleSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</template>
		</div>

		<!-- Exam Slot -->
		<div v-if="currentSubTab === 'exam-slot'" class="tab-content exam-slot-section">
			<div v-if="activeExamSlot" class="active-slot-banner">
				<NcNoteCard type="warning">
					{{ t('learning', 'Exam is running!') }} {{ t('learning', 'Ends at:') }} {{ formatTimestamp(activeExamSlot.ends_at) }}
				</NcNoteCard>
				<NcButton type="error" @click="closeExamSlot">{{ t('learning', 'Close exam') }}</NcButton>
			</div>
			<div v-else class="start-slot-form">
				<h3>{{ t('learning', 'Start exam slot') }}</h3>
				<div class="form-row-cd">
					<label>{{ t('learning', 'Duration (minutes)') }}</label>
					<input v-model.number="examSlotDuration" type="number" min="10" max="300" class="nc-input" />
				</div>
				<div class="form-row-cd">
					<label>{{ t('learning', 'Question scope') }}</label>
					<select v-model="examSlotScope" class="nc-select-cd">
						<option value="all">{{ t('learning', 'All course questions') }}</option>
						<option value="curriculum">{{ t('learning', 'Active curriculum only') }}</option>
					</select>
				</div>
				<NcButton type="primary" :disabled="startingExamSlot" @click="startExamSlot">
					{{ t('learning', 'Start exam') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { ALL_TOOL_IDS, TOOL_CATALOG } from '../utils/toolCatalog.js'
import { updateCertConfig } from '../services/CourseService.js'

export default {
	name: 'CourseTabVerwaltung',

	components: {
		NcButton,
		NcNoteCard,
		NcCheckboxRadioSwitch,
	},

	props: {
		courseId: {
			type: Number,
			required: true,
		},
		course: {
			type: Object,
			default: null,
		},
		coursePools: {
			type: Array,
			default: () => [],
		},
		userRole: {
			type: String,
			required: true,
		},
		activeTab: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			currentSubTab: 'mode-config',
			modeConfigLocal: {},
			savingModeConfig: false,
			modeConfigSaved: false,
			adminEnabledTools: [...ALL_TOOL_IDS],
			toolConfigLocal: {},
			loadingToolConfig: false,
			savingToolConfig: false,
			toolConfigSaved: false,
			leitnerSprint: false,
			talkRoomToken: '',
			savingTalkToken: false,
			talkTokenSaved: false,
			examDateLocal: '',
			savingExamDate: false,
			examDateSaved: false,
			activeExamSlot: null,
			examSlotDuration: 90,
			examSlotScope: 'all',
			startingExamSlot: false,
			availableCampaigns: [],
			selectedCampaignIds: [],
			loadingCampaignList: false,
			savingCampaigns: false,
			campaignsSaved: false,
			maintenanceMode: false,
			maintenanceModeSaved: false,
			scheduleItems: [],
			loadingSchedule: false,
			savingSchedule: false,
			scheduleSaved: false,
			deletingSchedule: false,
			certEnabled: false,
			certPassPercent: 80,
			certRequiredPoolIds: [],
			certValidityDays: 0,
			certSaving: false,
			certSaved: false,
		}
	},

	computed: {
		isInstructor() {
			return this.course && this.course.is_instructor
		},
		visibleSubTabs() {
			return [
				{ id: 'mode-config', label: t('learning', 'Kursregeln') },
				{ id: 'schedule', label: t('learning', 'Zeitplan') },
				{ id: 'exam-slot', label: t('learning', 'Prüfungs-Slot') },
			]
		},
		modeConfigKeys() {
			return [
				{ key: 'training', label: t('learning', 'Training') },
				{ key: 'leitner', label: t('learning', 'Leitner') },
				{ key: 'exam', label: t('learning', 'Prüfung') },
				{ key: 'duel', label: t('learning', 'Duell') },
				{ key: 'gameshow', label: t('learning', 'Gameshow') },
				{ key: 'league', label: t('learning', 'Liga') },
				{ key: 'abenteuer', label: t('learning', 'Abenteuer') },
				{ key: 'course_summary', label: t('learning', 'Abschluss-Tab') },
			]
		},
		toolConfigKeys() {
			return TOOL_CATALOG.map((tool) => ({
				key: tool.id,
				label: t('learning', tool.labelKey),
			}))
		},
	},

	watch: {
		activeTab: {
			immediate: true,
			handler(tab) {
				this.syncFromActiveTab(tab)
			},
		},
		course: {
			immediate: true,
			handler(c) {
				if (c) {
					this.leitnerSprint = !!c.leitner_sprint
					this.talkRoomToken = c.talk_room_token || ''
					this.examDateLocal = this.normalizeExamDate(c.exam_date)
					this.maintenanceMode = !!c.maintenance_mode
					// Cert config — course prop fields are snake_case (Course::jsonSerialize)
					this.certEnabled = !!c.cert_enabled
					this.certPassPercent = c.cert_pass_percent ?? 80
					this.certRequiredPoolIds = Array.isArray(c.cert_required_pool_ids)
						? c.cert_required_pool_ids.map((id) => Number(id))
						: []
					this.certValidityDays = c.cert_validity_days ?? 0
				}
			},
		},
	},

	methods: {
		t,

		defaultSubTab() {
			return 'mode-config'
		},

		syncFromActiveTab(tab) {
			const validIds = this.visibleSubTabs.map((s) => s.id)
			if (validIds.includes(tab)) {
				this.currentSubTab = tab
			} else {
				this.currentSubTab = this.defaultSubTab()
			}
			this.lazyLoad(this.currentSubTab)
		},

		selectSubTab(tabId) {
			this.currentSubTab = tabId
			this.$emit('tab-change', tabId)
			this.lazyLoad(tabId)
		},

		lazyLoad(tab) {
			if (tab === 'mode-config') {
				this.modeConfigLocal = this.normalizeModeConfig(this.course?.mode_config || {})
				this.modeConfigSaved = false
				this.loadToolSettings()
				this.loadCampaignList()
			}
			if (tab === 'schedule') {
				this.loadSchedule()
			}
			if (tab === 'exam-slot') {
				this.fetchActiveExamSlot()
			}
		},

		normalizeModeConfig(modeConfig = {}) {
			return Object.assign({
				training: true,
				leitner: true,
				exam: true,
				duel: true,
				gameshow: true,
				league: true,
				abenteuer: false,
				course_summary: false,
			}, modeConfig || {})
		},

		normalizeExamDate(examDate) {
			if (typeof examDate !== 'string') return ''
			// "YYYY-MM-DDTHH:MM" (datetime-local) or legacy "YYYY-MM-DD"
			if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(examDate)) return examDate
			if (/^\d{4}-\d{2}-\d{2}$/.test(examDate)) return examDate + 'T09:00'
			return ''
		},

		toggleMode(key, value) {
			this.modeConfigLocal[key] = value
		},

		async saveModeConfig() {
			this.savingModeConfig = true
			try {
				const res = await axios.put(generateUrl(`/apps/learning/api/courses/${this.courseId}/mode-config`), {
					modeConfig: this.modeConfigLocal,
				})
				this.modeConfigSaved = true
				setTimeout(() => { this.modeConfigSaved = false }, 3000)
				this.$emit('refresh-course-detail')
			} catch (e) {
				console.error('Failed to save mode config', e)
				this.$emit('error', t('learning', 'Failed to save mode config'))
			} finally {
				this.savingModeConfig = false
			}
		},

		normalizeToolSelection(enabledTools = null, adminEnabledTools = this.adminEnabledTools) {
			const adminSource = Array.isArray(adminEnabledTools) ? adminEnabledTools : ALL_TOOL_IDS
			const effectiveTools = Array.isArray(enabledTools) ? enabledTools : adminSource
			return this.toolConfigKeys.reduce((acc, tool) => {
				acc[tool.key] = adminSource.includes(tool.key) && effectiveTools.includes(tool.key)
				return acc
			}, {})
		},

		isAdminToolEnabled(toolId) {
			return this.adminEnabledTools.includes(toolId)
		},

		toggleCourseTool(key, value) {
			if (!this.isAdminToolEnabled(key)) return
			this.toolConfigLocal[key] = value
		},

		async loadToolSettings() {
			this.loadingToolConfig = true
			try {
				const [adminResponse, courseResponse] = await Promise.all([
					axios.get(generateUrl('/apps/learning/api/settings/tools')),
					axios.get(generateUrl(`/apps/learning/api/courses/${this.courseId}/tools`)),
				])
				this.adminEnabledTools = ALL_TOOL_IDS.filter((toolId) =>
					(adminResponse.data?.enabled_tools || ALL_TOOL_IDS).includes(toolId),
				)
				const enabledTools = courseResponse.data?.enabled_tools ?? null
				this.toolConfigLocal = this.normalizeToolSelection(enabledTools, this.adminEnabledTools)
				this.toolConfigSaved = false
			} catch (e) {
				this.adminEnabledTools = [...ALL_TOOL_IDS]
				this.toolConfigLocal = this.normalizeToolSelection(this.course?.enabled_tools ?? null, this.adminEnabledTools)
			} finally {
				this.loadingToolConfig = false
			}
		},

		async saveToolConfig() {
			this.savingToolConfig = true
			try {
				const selectedTools = this.toolConfigKeys
					.map((tool) => tool.key)
					.filter((toolId) => this.isAdminToolEnabled(toolId) && this.toolConfigLocal[toolId] !== false)
				const normalizedAdminTools = ALL_TOOL_IDS.filter((toolId) => this.adminEnabledTools.includes(toolId))
				const payloadEnabledTools = JSON.stringify(selectedTools) === JSON.stringify(normalizedAdminTools)
					? null
					: selectedTools
				const res = await axios.put(generateUrl(`/apps/learning/api/courses/${this.courseId}/tools`), {
					enabledTools: payloadEnabledTools,
				})
				const savedTools = res.data?.enabled_tools ?? payloadEnabledTools
				this.toolConfigLocal = this.normalizeToolSelection(savedTools, this.adminEnabledTools)
				this.toolConfigSaved = true
				setTimeout(() => { this.toolConfigSaved = false }, 3000)
			} catch (e) {
				console.error('Failed to save tool config', e)
				this.$emit('error', t('learning', 'Failed to save tool config'))
			} finally {
				this.savingToolConfig = false
			}
		},

		async saveMaintenanceMode() {
			try {
				await axios.patch(
					generateUrl(`/apps/learning/api/courses/${this.courseId}/maintenance`),
					{ enabled: this.maintenanceMode },
				)
				this.maintenanceModeSaved = true
				setTimeout(() => { this.maintenanceModeSaved = false }, 3000)
				this.$emit('refresh-course-detail')
			} catch (e) {
				console.error('Failed to save maintenance mode', e)
				this.$emit('error', t('learning', 'Failed to save maintenance mode'))
			}
		},

		async saveLeitnerSprint() {
			try {
				const res = await axios.put(generateUrl(`/apps/learning/api/courses/${this.courseId}/mode-config`), {
					modeConfig: this.modeConfigLocal,
					leitnerSprint: this.leitnerSprint,
				})
				this.$emit('refresh-course-detail')
			} catch (e) {
				console.error('Failed to save leitner sprint', e)
			}
		},

		async saveTalkRoomToken() {
			this.savingTalkToken = true
			try {
				const res = await axios.put(generateUrl(`/apps/learning/api/courses/${this.courseId}/mode-config`), {
					modeConfig: this.modeConfigLocal,
					talkRoomToken: this.talkRoomToken,
				})
				this.$emit('refresh-course-detail')
				this.talkTokenSaved = true
				setTimeout(() => { this.talkTokenSaved = false }, 3000)
			} catch (e) {
				console.error('Failed to save talk room token', e)
			} finally {
				this.savingTalkToken = false
			}
		},

		async saveExamDate() {
			this.savingExamDate = true
			try {
				const payloadExamDate = this.examDateLocal || null
				const res = await axios.patch(generateUrl(`/apps/learning/api/courses/${this.courseId}/exam-date`), {
					examDate: payloadExamDate,
				})
				this.examDateLocal = this.normalizeExamDate(res.data?.exam_date)
				this.examDateSaved = true
				setTimeout(() => { this.examDateSaved = false }, 3000)
				this.$emit('refresh-course-detail')
			} catch (e) {
				console.error('Failed to save exam date', e)
				this.$emit('error', t('learning', 'Failed to save exam date'))
			} finally {
				this.savingExamDate = false
			}
		},

		async clearExamDate() {
			this.examDateLocal = ''
			await this.saveExamDate()
		},

		async fetchActiveExamSlot() {
			if (!this.course) return
			try {
				const res = await axios.get(generateUrl(`/apps/learning/api/courses/${this.courseId}/exam-slot/active`))
				this.activeExamSlot = res.data.slot || null
			} catch (e) {
				this.activeExamSlot = null
			}
		},

		async startExamSlot() {
			this.startingExamSlot = true
			try {
				const res = await axios.post(generateUrl(`/apps/learning/api/courses/${this.courseId}/exam-slot`), {
					durationMinutes: this.examSlotDuration,
					scopeMode: this.examSlotScope,
				})
				this.activeExamSlot = res.data.slot || res.data
			} catch (e) {
				console.error('Failed to start exam slot', e)
			} finally {
				this.startingExamSlot = false
			}
		},

		async closeExamSlot() {
			try {
				await axios.post(generateUrl(`/apps/learning/api/courses/${this.courseId}/exam-slot/close`))
				this.activeExamSlot = null
			} catch (e) {
				console.error('Failed to close exam slot', e)
			}
		},

		async loadCampaignList() {
			this.loadingCampaignList = true
			try {
				const res = await axios.get(generateUrl('/apps/learning/api/story/campaigns'))
				const raw = res.data.campaigns || res.data || []
				this.availableCampaigns = Array.isArray(raw) ? raw : []
				// Init selection from course data
				const allowed = this.course?.allowed_campaigns
				if (Array.isArray(allowed) && allowed.length > 0) {
					this.selectedCampaignIds = [...allowed]
				} else {
					this.selectedCampaignIds = []
				}
			} catch (e) {
				this.availableCampaigns = []
			} finally {
				this.loadingCampaignList = false
			}
		},

		toggleCampaign(campaignId, checked) {
			if (checked) {
				if (!this.selectedCampaignIds.includes(campaignId)) {
					this.selectedCampaignIds.push(campaignId)
				}
			} else {
				this.selectedCampaignIds = this.selectedCampaignIds.filter((id) => id !== campaignId)
			}
		},

		selectAllCampaigns() {
			this.selectedCampaignIds = this.availableCampaigns.map((c) => c.campaign_id)
		},

		selectTop5Campaigns() {
			const top5 = ['solarwinds', 'wannacry', 'log4shell', 'phishing_friday', 'ransomware']
			this.selectedCampaignIds = top5.filter((id) => this.availableCampaigns.some((c) => c.campaign_id === id))
		},

		clearCampaignSelection() {
			this.selectedCampaignIds = []
		},

		async saveCampaignSelection() {
			this.savingCampaigns = true
			try {
				const payload = this.selectedCampaignIds.length > 0
					? { campaignIds: this.selectedCampaignIds }
					: { campaignIds: null }
				await axios.patch(
					generateUrl(`/apps/learning/api/courses/${this.courseId}/campaign-selection`),
					payload,
				)
				this.campaignsSaved = true
				setTimeout(() => { this.campaignsSaved = false }, 3000)
				this.$emit('refresh-course-detail')
			} catch (e) {
				console.error('Failed to save campaign selection', e)
				this.$emit('error', t('learning', 'Failed to save campaign selection'))
			} finally {
				this.savingCampaigns = false
			}
		},

		formatTimestamp(timestamp) {
			if (!timestamp) return ''
			try {
				return new Date(timestamp * 1000).toLocaleTimeString()
			} catch {
				return String(timestamp)
			}
		},

		async loadSchedule() {
			this.loadingSchedule = true
			try {
				const res = await axios.get(generateUrl(`/apps/learning/api/courses/${this.courseId}/schedule`))
				const items = res.data?.schedule || []
				this.scheduleItems = items.map((item) => ({ ...item }))
			} catch (e) {
				this.scheduleItems = []
			} finally {
				this.loadingSchedule = false
			}
		},

		addScheduleRow() {
			const nextOrder = this.scheduleItems.length + 1
			this.scheduleItems.push({
				chapter_ref: '',
				chapter_title: '',
				start_date: '',
				target_date: '',
				sort_order: nextOrder,
			})
		},

		removeScheduleRow(idx) {
			this.scheduleItems.splice(idx, 1)
			this.scheduleItems.forEach((item, i) => {
				item.sort_order = i + 1
			})
		},

		async saveSchedule() {
			this.savingSchedule = true
			this.scheduleSaved = false
			try {
				const payload = this.scheduleItems.map((item, idx) => ({
					chapter_ref: item.chapter_ref || item.chapter_title?.toLowerCase().replace(/\s+/g, '-') || `ch-${idx + 1}`,
					chapter_title: item.chapter_title || '',
					start_date: item.start_date || null,
					target_date: item.target_date || null,
					sort_order: idx + 1,
				}))
				const res = await axios.put(
					generateUrl(`/apps/learning/api/courses/${this.courseId}/schedule`),
					// CourseController::setSchedule() takes `entries`. Sending `schedule` left it at
					// its [] default, which ScheduleService reads as "replace with nothing": it
					// deleted every row and inserted none, then answered 200 so the UI said "saved".
					{ entries: payload },
				)
				const saved = res.data?.schedule || payload
				this.scheduleItems = saved.map((item) => ({ ...item }))
				this.scheduleSaved = true
				setTimeout(() => { this.scheduleSaved = false }, 3000)
			} catch (e) {
				console.error('Failed to save schedule', e)
				this.$emit('error', t('learning', 'Failed to save schedule'))
			} finally {
				this.savingSchedule = false
			}
		},

		async deleteSchedule() {
			this.deletingSchedule = true
			try {
				await axios.delete(generateUrl(`/apps/learning/api/courses/${this.courseId}/schedule`))
				this.scheduleItems = []
			} catch (e) {
				console.error('Failed to delete schedule', e)
				this.$emit('error', t('learning', 'Failed to delete schedule'))
			} finally {
				this.deletingSchedule = false
			}
		},

		toggleCertPool(poolId, checked) {
			const id = Number(poolId)
			if (checked) {
				if (!this.certRequiredPoolIds.includes(id)) {
					this.certRequiredPoolIds.push(id)
				}
			} else {
				this.certRequiredPoolIds = this.certRequiredPoolIds.filter((p) => p !== id)
			}
		},

		async saveCertConfig() {
			this.certSaving = true
			this.certSaved = false
			try {
				const result = await updateCertConfig(this.courseId, {
					certEnabled: this.certEnabled,
					certPassPercent: this.certPassPercent,
					certRequiredPoolIds: this.certRequiredPoolIds,
					certValidityDays: this.certValidityDays,
				})
				// Server response is camelCase (CourseController::updateCertConfig)
				this.certEnabled = result.certEnabled ?? false
				this.certPassPercent = result.certPassPercent ?? 80
				this.certRequiredPoolIds = Array.isArray(result.certRequiredPoolIds)
					? result.certRequiredPoolIds.map((id) => Number(id))
					: []
				this.certValidityDays = result.certValidityDays ?? 0
				this.certSaved = true
				setTimeout(() => { this.certSaved = false }, 3000)
				this.$emit('refresh-course-detail')
			} catch (e) {
				console.error('saveCertConfig failed', e)
				this.$emit('error', t('learning', 'Failed to save cert config'))
			} finally {
				this.certSaving = false
			}
		},
	},
}
</script>

<style scoped>
.course-tab-verwaltung { padding: 0; }

/* Sub-nav pills */
.verwaltung-subnav {
	display: flex;
	gap: 6px;
	padding: 8px 0 16px;
	flex-wrap: wrap;
}
.verwaltung-pill {
	padding: 6px 16px;
	border-radius: 20px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.9em;
	transition: background 0.15s, border-color 0.15s;
}
.verwaltung-pill:hover { background: var(--color-background-hover); }
.verwaltung-pill.active {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-color: var(--color-primary);
}

/* Mode Config */
.mode-config-section { padding: 16px 0; }
.mode-config-hint { color: var(--color-text-maxcontrast); margin-bottom: 16px; font-size: 0.9em; }
.mode-toggles { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; }
.mode-toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.mode-toggle-label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 1em; }
.mode-toggle-label input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.mode-toggle-label input[type="checkbox"]:disabled { opacity: 0.5; cursor: not-allowed; }
.mode-config-saved { margin-top: 12px; }
.mode-config-note { color: var(--color-text-maxcontrast); font-size: 0.85em; }
.tool-config-section { margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--color-border); }
.tool-config-section h3 { margin: 0 0 8px; }
.exam-date-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 8px; }
.exam-date-input {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 0.95em;
	max-width: 100%;
}

/* Exam Slot */
.exam-slot-section {}
.active-slot-banner { margin-bottom: 16px; display: flex; flex-direction: column; gap: 12px; }
.start-slot-form h3 { margin: 0 0 16px 0; font-size: 1.1em; font-weight: 600; }
.form-row-cd { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.form-row-cd label { font-weight: 600; font-size: 0.9em; }

/* Talk token input */
.talk-token-row { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
.talk-token-input {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 0.95em;
	width: 280px;
	max-width: 100%;
}
.campaign-quick-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

/* Cert config (Phase 154) */
.cert-config-field { margin: 14px 0; display: flex; flex-direction: column; gap: 6px; }
.cert-config-field > label { font-weight: 600; font-size: 0.9em; }
.cert-config-input {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95em;
	width: 160px;
	max-width: 100%;
}

/* Schedule */
.schedule-section { padding: 16px 0; }

.schedule-table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 16px;
}

.schedule-table th {
	text-align: left;
	font-size: 0.85em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	padding: 8px 8px 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.schedule-table td {
	padding: 6px 8px 6px 0;
	vertical-align: middle;
}

.schedule-input {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9em;
	width: 100%;
	max-width: 100%;
}

.schedule-input--title {
	min-width: 180px;
}

.schedule-remove-btn {
	background: none;
	border: none;
	font-size: 1.3em;
	color: var(--color-error);
	cursor: pointer;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	transition: background 0.15s;
}

.schedule-remove-btn:hover {
	background: color-mix(in srgb, var(--color-error) 10%, transparent);
}

.schedule-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
