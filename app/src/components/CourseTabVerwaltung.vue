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
								:checked="toolConfigLocal[tool.key] !== false"
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
						{{ savingTalkToken ? t('learning', 'Saving...') : t('learning', 'Speichern') }}
					</NcButton>
				</div>
				<NcNoteCard v-if="talkTokenSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</div>
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
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import { ALL_TOOL_IDS, TOOL_CATALOG } from '../utils/toolCatalog.js'

export default {
	name: 'CourseTabVerwaltung',

	components: {
		NcButton,
		NcNoteCard,
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
			activeExamSlot: null,
			examSlotDuration: 90,
			examSlotScope: 'all',
			startingExamSlot: false,
		}
	},

	computed: {
		isInstructor() {
			return this.course && this.course.is_instructor
		},
		visibleSubTabs() {
			return [
				{ id: 'mode-config', label: t('learning', 'Kursregeln') },
				{ id: 'exam-slot', label: t('learning', 'Pruefungs-Slot') },
			]
		},
		modeConfigKeys() {
			return [
				{ key: 'training', label: t('learning', 'Training') },
				{ key: 'leitner', label: t('learning', 'Leitner') },
				{ key: 'exam', label: t('learning', 'Pruefung') },
				{ key: 'duel', label: t('learning', 'Duell') },
				{ key: 'gameshow', label: t('learning', 'Gameshow') },
				{ key: 'league', label: t('learning', 'Liga') },
				{ key: 'oldschool', label: t('learning', 'Oldschool') },
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
				oldschool: true,
				abenteuer: false,
				course_summary: false,
			}, modeConfig || {})
		},

		toggleMode(key, value) {
			this.$set(this.modeConfigLocal, key, value)
		},

		async saveModeConfig() {
			this.savingModeConfig = true
			try {
				const res = await axios.put(generateUrl(`/apps/learning/api/courses/${this.courseId}/mode-config`), {
					modeConfig: this.modeConfigLocal,
				})
				if (this.course) {
					this.course.mode_config = this.normalizeModeConfig(res.data?.mode_config || this.modeConfigLocal)
				}
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
			this.$set(this.toolConfigLocal, key, value)
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
				if (this.course) {
					this.course.enabled_tools = enabledTools
				}
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
				if (this.course) {
					this.course.enabled_tools = res.data?.enabled_tools ?? payloadEnabledTools
				}
				this.toolConfigLocal = this.normalizeToolSelection(this.course?.enabled_tools ?? null, this.adminEnabledTools)
				this.toolConfigSaved = true
				setTimeout(() => { this.toolConfigSaved = false }, 3000)
			} catch (e) {
				console.error('Failed to save tool config', e)
				this.$emit('error', t('learning', 'Failed to save tool config'))
			} finally {
				this.savingToolConfig = false
			}
		},

		async saveLeitnerSprint() {
			try {
				const res = await axios.put(generateUrl(`/apps/learning/api/courses/${this.courseId}/mode-config`), {
					modeConfig: this.modeConfigLocal,
					leitnerSprint: this.leitnerSprint,
				})
				if (this.course) {
					this.course.leitner_sprint = this.leitnerSprint
					if (res.data?.mode_config) {
						this.course.mode_config = this.normalizeModeConfig(res.data.mode_config)
					}
				}
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
				if (this.course) {
					this.course.talk_room_token = this.talkRoomToken
					if (res.data?.mode_config) {
						this.course.mode_config = this.normalizeModeConfig(res.data.mode_config)
					}
				}
				this.talkTokenSaved = true
				setTimeout(() => { this.talkTokenSaved = false }, 3000)
			} catch (e) {
				console.error('Failed to save talk room token', e)
			} finally {
				this.savingTalkToken = false
			}
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

		formatTimestamp(timestamp) {
			if (!timestamp) return ''
			try {
				return new Date(timestamp * 1000).toLocaleTimeString()
			} catch {
				return String(timestamp)
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
</style>
