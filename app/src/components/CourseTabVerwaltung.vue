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
			<p class="mode-config-hint" data-test="gamification-note">{{ t('learning', 'Gamification (XP, Abzeichen, Serien, Missionen) schaltet die Server-Administration für alle Kurse ein oder aus. Diese Modi bleiben auch ohne Gamification nutzbar, es gibt dann nur keine XP und Abzeichen dafür.') }}</p>
			<div class="mode-toggles">
				<div v-for="mode in modeConfigKeys" :key="mode.key" class="mode-toggle-row mode-toggle-row--described">
					<label class="mode-toggle-label">
						<input
							type="checkbox"
							:checked="modeConfigLocal[mode.key] !== false"
							:aria-describedby="'mode-desc-' + mode.key"
							@change="toggleMode(mode.key, $event.target.checked)" />
						{{ mode.label }}
					</label>
					<small :id="'mode-desc-' + mode.key" class="mode-config-note mode-description" :data-test="'mode-desc-' + mode.key">{{ mode.description }}</small>
				</div>
			</div>
			<NcButton variant="primary" :disabled="savingModeConfig" @click="saveModeConfig">
				{{ savingModeConfig ? t('learning', 'Saving...') : t('learning', 'Save') }}
			</NcButton>
			<NcNoteCard v-if="modeConfigSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>

			<div class="tool-config-section">
				<h3>{{ t('learning', 'Kursregeln — Werkzeuge') }}</h3>
				<label class="mode-toggle-label tools-master-toggle">
					<input
						type="checkbox"
						data-test="tools-master"
						:checked="toolsSwitchedOn"
						aria-describedby="tools-master-desc"
						@change="toggleMode('tools', $event.target.checked)" />
					{{ t('learning', 'Simulator-Werkzeuge in diesem Kurs anzeigen') }}
				</label>
				<p id="tools-master-desc" class="mode-config-hint">{{ t('learning', 'Ausgeschaltet sehen die Lernenden keinen Werkzeuge-Bereich und keine Werkzeug-Hinweise im Lernraum. Deine Auswahl der einzelnen Werkzeuge bleibt gespeichert.') }}</p>
				<template v-if="toolsSwitchedOn">
				<p class="mode-config-hint">{{ t('learning', 'Hier kannst du die acht Simulatoren pro Kurs einschränken. Global deaktivierte Werkzeuge bleiben gesperrt.') }}</p>
				<div v-if="loadingToolConfig" class="loading-hint">{{ t('learning', 'Loading...') }}</div>
				<div v-else class="mode-toggles" data-test="tool-list">
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
				</template>
				<NcButton variant="primary" :disabled="savingToolConfig || loadingToolConfig" @click="saveToolConfig">
					{{ savingToolConfig ? t('learning', 'Saving...') : t('learning', 'Save tools') }}
				</NcButton>
				<NcNoteCard v-if="toolConfigSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</div>

			<div v-if="leitnerModeOn" class="sprint-config tool-config-section" data-test="leitner-sprint">
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
					<NcButton variant="primary" @click="saveTalkRoomToken" :disabled="savingTalkToken">
						{{ savingTalkToken ? t('learning', 'Saving...') : t('learning', 'Save') }}
					</NcButton>
				</div>
				<NcNoteCard v-if="talkTokenSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</div>

			<div class="exam-date-config tool-config-section">
				<h3>{{ t('learning', 'Prüfungstermin') }}</h3>
				<p class="mode-config-hint">{{ t('learning', 'Nur für den Countdown und die Erinnerungen der Lernenden. Der Termin öffnet oder schließt keine Prüfung.') }}</p>
				<div class="exam-date-row">
					<input v-model="examDateLocal" type="datetime-local" class="exam-date-input" />
					<NcButton variant="primary" :disabled="savingExamDate" @click="saveExamDate">
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
				<p class="mode-config-hint" data-test="maintenance-hint">{{ t('learning', 'Eingeschaltet zeigt das Lern-Dashboard jedes Mitglieds eine Karte „Wartungsmodus“, sobald Leitner-Karten fällig sind. Die Zahl darauf zählt die fälligen Karten aus allen Kursen im Wartungsmodus zusammen. Ihr Knopf öffnet die normale Wiederholungs-Warteschlange mit allen fälligen Karten; dort stehen die Karten dieses Kurses auch ohne den Modus. Ein Kursende wird nicht geprüft: Schalte den Modus ein, wenn der Kurs vorbei ist.') }}</p>
				<label class="mode-toggle-label">
					<input type="checkbox" v-model="maintenanceMode" @change="saveMaintenanceMode" />
					{{ t('learning', 'Wartungskarte im Lern-Dashboard anzeigen') }}
				</label>
				<NcNoteCard v-if="maintenanceModeSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</div>

			<div v-if="abenteuerModeOn" class="campaign-config tool-config-section" data-test="adventure-campaigns">
				<h3>{{ t('learning', 'Abenteuer-Kampagnen') }}</h3>
				<p class="mode-config-hint">{{ t('learning', 'Wähle welche Kampagnen für Studierende sichtbar sind. Keine Auswahl = alle verfügbar.') }}</p>
				<div v-if="loadingCampaignList" class="loading-hint">{{ t('learning', 'Loading...') }}</div>
				<template v-else>
					<div class="campaign-quick-actions">
						<NcButton variant="tertiary" @click="selectAllCampaigns">{{ t('learning', 'Alle auswählen') }}</NcButton>
						<NcButton variant="tertiary" @click="selectTop5Campaigns">{{ t('learning', 'Top 5 empfohlen') }}</NcButton>
						<NcButton variant="tertiary" @click="clearCampaignSelection">{{ t('learning', 'Keine (alle verfügbar)') }}</NcButton>
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
					<NcButton variant="primary" :disabled="savingCampaigns" @click="saveCampaignSelection">
						{{ savingCampaigns ? t('learning', 'Saving...') : t('learning', 'Save') }}
					</NcButton>
					<NcNoteCard v-if="campaignsSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
				</template>
			</div>

			<!-- Zertifizierung (Phase 154) -->
			<div class="cert-config tool-config-section">
				<h3>{{ t('learning', 'Zertifizierung') }}</h3>
				<NcCheckboxRadioSwitch :model-value="certEnabled" @update:model-value="certEnabled = !!$event">
					{{ t('learning', 'Zertifizierung aktivieren') }}
				</NcCheckboxRadioSwitch>

				<template v-if="certEnabled">
					<!-- GitHub #35: only exams started in the course count; with the exam mode off
						nobody can be certified. A warning, nothing is refused. -->
					<NcNoteCard v-if="certWithoutExamMode" type="warning" data-test="cert-exam-mode-off">
						{{ t('learning', 'Der Lernmodus „Prüfung“ ist in diesem Kurs ausgeschaltet. Zertifikate gibt es nur für Prüfungen, die hier im Kurs gestartet werden. So kann niemand ein Zertifikat erhalten. Schalte oben unter den Lernmodi „Prüfung“ ein und speichere.') }}
					</NcNoteCard>
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

				<NcButton variant="primary" :disabled="certSaving" @click="saveCertConfig">
					{{ certSaving ? t('learning', 'Saving...') : t('learning', 'Speichern') }}
				</NcButton>
				<NcNoteCard v-if="certSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</div>

			<!-- Probeprüfung (Codeberg #9) -->
			<div class="practice-config tool-config-section">
				<h3>{{ t('learning', 'Probeprüfung') }}</h3>
				<p class="mode-config-hint">{{ t('learning', 'Lernende starten die Probeprüfung selbst. Die Fragen werden bei jedem Versuch neu zufällig aus den Pools des Kurses gezogen. Zusätzlich bietet jeder Pool im Prüfungsmodus einen Probedurchlauf mit denselben Einstellungen, nur aus diesem Pool. Beides zählt nicht für das Zertifikat.') }}</p>
				<NcCheckboxRadioSwitch :model-value="practiceEnabled" @update:model-value="practiceEnabled = !!$event">
					{{ t('learning', 'Probeprüfung anbieten') }}
				</NcCheckboxRadioSwitch>

				<template v-if="practiceEnabled">
					<div class="cert-config-field">
						<label for="practice-questions">{{ t('learning', 'Anzahl Fragen') }}</label>
						<input
							id="practice-questions"
							type="number"
							:value="practiceQuestions"
							min="1"
							max="500"
							class="cert-config-input"
							@change="practiceQuestions = Math.max(1, Math.min(500, parseInt($event.target.value) || 20))" />
					</div>
					<div class="cert-config-field">
						<label for="practice-minutes">{{ t('learning', 'Zeitlimit (Minuten, 0 = ohne Zeitlimit)') }}</label>
						<input
							id="practice-minutes"
							type="number"
							:value="practiceMinutes"
							min="0"
							max="600"
							class="cert-config-input"
							@change="practiceMinutes = Math.max(0, Math.min(600, parseInt($event.target.value) || 0))" />
					</div>
					<div class="cert-config-field">
						<label for="practice-pass">{{ t('learning', 'Bestehensgrenze (%)') }}</label>
						<input
							id="practice-pass"
							type="number"
							:value="practicePassPercent"
							min="1"
							max="100"
							class="cert-config-input"
							@change="practicePassPercent = Math.max(1, Math.min(100, parseInt($event.target.value) || 75))" />
					</div>
					<!-- Codeberg #9 follow-up: keep supplementary pools out of the exam simulation -->
					<NcCheckboxRadioSwitch :model-value="practiceRequiredOnly" @update:model-value="practiceRequiredOnly = !!$event">
						{{ t('learning', 'Nur Pflicht-Pools verwenden') }}
					</NcCheckboxRadioSwitch>
					<p class="mode-config-hint">{{ t('learning', 'Zieht die Fragen nur aus Pools, die als Pflicht markiert sind. Ergänzende Pools bleiben zum freien Üben verfügbar, kommen aber nicht in die Probeprüfung. Für den Probedurchlauf eines Pools gilt diese Einschränkung nicht.') }}</p>
					<NcNoteCard v-if="practiceRequiredOnly && !hasRequiredPools" type="warning">
						{{ t('learning', 'Kein Pool dieses Kurses ist als Pflicht markiert. So kann niemand die Probeprüfung starten. Markiere die prüfungsrelevanten Pools im Lernraum unter den Pool-Regeln als Pflicht.') }}
					</NcNoteCard>
				</template>

				<NcButton variant="primary" :disabled="practiceSaving" @click="savePracticeConfig">
					{{ practiceSaving ? t('learning', 'Saving...') : t('learning', 'Speichern') }}
				</NcButton>
				<NcNoteCard v-if="practiceSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</div>
		</div>

		<!-- Schedule -->
		<div v-if="currentSubTab === 'schedule'" class="tab-content schedule-section">
			<h3>{{ t('learning', 'Kurs-Zeitplan') }}</h3>
			<p class="mode-config-hint">{{ t('learning', 'Kapitel mit Start- und Zieldatum planen. Die Lernenden sehen den Zeitplan als Timeline auf ihrem Lern-Dashboard. Die Daten dienen nur der Orientierung: Sie sperren und öffnen nichts.') }}</p>
			<p class="mode-config-hint" data-test="schedule-chapter-help">{{ t('learning', 'Kapitel-Kennung: Stimmt sie mit dem Kapitel-Schlüssel (oder dem Namen) eines Kurs-Pools überein, zeigt die Timeline den Fortschritt der Lernenden in diesem Pool. Vorgeschlagen werden die Kapitel-Schlüssel der Kurs-Pools; freier Text ist erlaubt, dann zeigt die Station nur Titel und Datum.') }}</p>

			<div v-if="loadingSchedule" class="loading-hint">{{ t('learning', 'Loading...') }}</div>

			<!-- After a failed load the editor stays hidden: saving an empty or partial editor would
				replace the stored schedule (setSchedule deletes every row before inserting). -->
			<div v-else-if="scheduleLoadError" class="schedule-load-error" data-test="schedule-load-error">
				<NcNoteCard type="error">{{ t('learning', 'Der Zeitplan konnte nicht geladen werden.') }}</NcNoteCard>
				<NcButton variant="secondary" @click="loadSchedule">
					{{ t('learning', 'Erneut versuchen') }}
				</NcButton>
			</div>

			<template v-else>
				<table v-if="scheduleItems.length" class="schedule-table">
					<thead>
						<tr>
							<th>{{ t('learning', 'Kapitel') }}</th>
							<th>{{ t('learning', 'Kapitel-Kennung') }}</th>
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
									v-model.trim="item.chapter_ref"
									type="text"
									maxlength="255"
									list="schedule-chapter-refs"
									class="schedule-input schedule-input--ref"
									data-test="schedule-chapter-ref"
									:aria-label="t('learning', 'Kapitel-Kennung')"
									:placeholder="t('learning', 'z. B. chapter-03')" />
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
				<datalist id="schedule-chapter-refs">
					<option v-for="ref in knownChapterRefs" :key="ref" :value="ref" />
				</datalist>

				<div class="schedule-actions">
					<NcButton variant="secondary" @click="addScheduleRow">
						{{ t('learning', '+ Kapitel hinzufuegen') }}
					</NcButton>
					<NcButton variant="primary" :disabled="savingSchedule || !scheduleItems.length" @click="saveSchedule">
						{{ savingSchedule ? t('learning', 'Saving...') : t('learning', 'Zeitplan speichern') }}
					</NcButton>
					<NcButton v-if="scheduleItems.length" variant="error" :disabled="deletingSchedule" @click="deleteSchedule">
						{{ deletingSchedule ? t('learning', 'Deleting...') : t('learning', 'Zeitplan loeschen') }}
					</NcButton>
				</div>
				<NcNoteCard v-if="scheduleSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
			</template>
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
import { updateCertConfig, updatePracticeConfig } from '../services/CourseService.js'

/**
 * The rows the schedule PUT sends. A row without a chapter ID gets one from its title, so two
 * rows can end up with the same ID even though neither input shows it.
 */
function buildSchedulePayload(items) {
	return items.map((item, idx) => ({
		chapter_ref: String(item.chapter_ref || item.chapter_title?.toLowerCase().replace(/\s+/g, '-') || `ch-${idx + 1}`).trim(),
		chapter_title: item.chapter_title || '',
		start_date: item.start_date || null,
		target_date: item.target_date || null,
		sort_order: idx + 1,
	}))
}

/**
 * GitHub #35: the table has a unique index (course_id, chapter_ref) and ScheduleService refuses a
 * repeated ID with a 400 that the editor only showed as "Failed to save schedule".
 */
function firstDuplicateChapterRef(payload) {
	const seen = new Set()
	for (const entry of payload) {
		if (seen.has(entry.chapter_ref)) {
			return entry.chapter_ref
		}
		seen.add(entry.chapter_ref)
	}
	return null
}

export default {
	name: 'CourseTabVerwaltung',

	components: {
		NcButton,
		NcNoteCard,
		NcCheckboxRadioSwitch,
	},

	props: {
		// GitHub #35: CourseDetail's single instructor gate (membership, student view off).
		actsAsInstructor: { type: Boolean, default: false },
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
			// The mode config as the server last stored it. The sprint, Talk and tools saves send
			// this instead of modeConfigLocal, so switches changed elsewhere on the page without
			// pressing their own Save are not stored with them (GitHub #35).
			savedModeConfig: {},
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
			availableCampaigns: [],
			selectedCampaignIds: [],
			loadingCampaignList: false,
			savingCampaigns: false,
			campaignsSaved: false,
			maintenanceMode: false,
			maintenanceModeSaved: false,
			scheduleItems: [],
			knownChapterRefs: [],
			loadingSchedule: false,
			scheduleLoadError: false,
			savingSchedule: false,
			scheduleSaved: false,
			deletingSchedule: false,
			certEnabled: false,
			certPassPercent: 80,
			certRequiredPoolIds: [],
			certValidityDays: 0,
			certSaving: false,
			certSaved: false,
			practiceEnabled: false,
			practiceQuestions: 20,
			practiceMinutes: 0,
			practicePassPercent: 75,
			practiceRequiredOnly: false,
			practiceSaving: false,
			practiceSaved: false,
		}
	},

	computed: {
		// GitHub #35: course membership minus the student view, computed once in CourseDetail.
		isInstructor() {
			return this.actsAsInstructor
		},
		visibleSubTabs() {
			return [
				{ id: 'mode-config', label: t('learning', 'Kursregeln') },
				{ id: 'schedule', label: t('learning', 'Zeitplan') },
			]
		},
		// GitHub #35: each switch says what it switches. The texts describe the current behaviour;
		// change them together with it.
		modeConfigKeys() {
			return [
				{ key: 'training', label: t('learning', 'Training'), description: t('learning', 'Üben mit Rückmeldung und Erklärung direkt nach jeder Antwort.') },
				{ key: 'leitner', label: t('learning', 'Leitner'), description: t('learning', 'Karteikasten mit fünf Fächern: Richtig beantwortete Karten rücken ein Fach weiter und kommen in immer größeren Abständen wieder, falsch beantwortete zurück ins erste Fach. Die Sprint-Einstellung erscheint nur, solange Leitner eingeschaltet ist.') },
				{ key: 'exam', label: t('learning', 'Prüfung'), description: t('learning', 'Prüfung über einen Pool, Rückmeldung erst am Ende. Bei aktiver Zertifizierung zählen nur Prüfungen, die hier im Kurs gestartet werden.') },
				{ key: 'duel', label: t('learning', 'Duell'), description: t('learning', 'Zwei Kursmitglieder beantworten dieselben Fragen gegeneinander (Bereich Arena).') },
				{ key: 'gameshow', label: t('learning', 'Gameshow'), description: t('learning', 'Quizrunde für mehrere Kursmitglieder gleichzeitig, in verschiedenen Spielformen (Bereich Arena).') },
				{ key: 'league', label: t('learning', 'Liga'), description: t('learning', 'Wertung der Kursmitglieder über eine ganze Saison statt über eine einzelne Runde.') },
				{ key: 'abenteuer', label: t('learning', 'Abenteuer'), description: t('learning', 'Story-Kampagnen, in denen die Fragen in eine Handlung eingebettet sind. Die Auswahl der Kampagnen erscheint nur, solange Abenteuer eingeschaltet ist.') },
				{ key: 'course_summary', label: t('learning', 'Abschluss-Tab'), description: t('learning', 'Gibt den Lernenden ihre Abschluss-Übersicht frei (Beherrschung, XP, Abzeichen). Bei aktiver Zertifizierung sehen sie den Abschluss-Tab mit ihrem Zertifikat auch ohne diesen Schalter.') },
			]
		},
		// Gate the settings that belong to a mode on the switch as the instructor sees it now.
		leitnerModeOn() {
			return this.modeConfigLocal.leitner !== false
		},
		abenteuerModeOn() {
			return this.modeConfigLocal.abenteuer === true
		},
		toolsSwitchedOn() {
			return this.modeConfigLocal.tools !== false
		},
		// CoursePool serialises `required` as an integer (0/1), hence the Number().
		// GitHub #35: judged on the stored mode config, which is what learners get — an unsaved
		// switch above changes nothing for them yet.
		certWithoutExamMode() {
			return this.certEnabled && this.savedModeConfig?.exam === false
		},
		hasRequiredPools() {
			return (this.coursePools || []).some((pool) => Number(pool.required) === 1 || pool.required === true)
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
					this.practiceEnabled = !!c.practice_enabled
					this.practiceQuestions = c.practice_questions ?? 20
					this.practiceMinutes = c.practice_minutes ?? 0
					this.practicePassPercent = c.practice_pass_percent ?? 75
					this.practiceRequiredOnly = !!c.practice_required_only
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
				this.savedModeConfig = this.normalizeModeConfig(this.course?.mode_config || {})
				this.modeConfigSaved = false
				this.loadToolSettings()
				this.loadCampaignList()
			}
			if (tab === 'schedule') {
				this.loadSchedule()
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
				tools: true,
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
				const sent = { ...this.modeConfigLocal }
				const res = await axios.put(generateUrl(`/apps/learning/api/courses/${this.courseId}/mode-config`), {
					modeConfig: sent,
				})
				this.savedModeConfig = this.normalizeModeConfig(res.data?.mode_config || sent)
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
				// GitHub #35: the master switch lives in mode_config. Saved on the last saved mode
				// config, so pressing "Save tools" does not also save mode switches the instructor
				// changed above without saving them.
				const toolsOn = this.toolsSwitchedOn
				const sent = { ...this.normalizeModeConfig(this.savedModeConfig), tools: toolsOn }
				const modeRes = await axios.put(generateUrl(`/apps/learning/api/courses/${this.courseId}/mode-config`), {
					modeConfig: sent,
				})
				this.savedModeConfig = this.normalizeModeConfig(modeRes.data?.mode_config || sent)
				this.modeConfigLocal.tools = this.savedModeConfig.tools !== false
				if (!toolsOn) {
					// The hidden per-tool selection stays as it was stored.
					this.toolConfigSaved = true
					this.$emit('refresh-course-detail')
					setTimeout(() => { this.toolConfigSaved = false }, 3000)
					return
				}
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
				// The learning space renders from the course detail; without a refresh it kept
				// showing the previous selection until the page was reloaded.
				this.$emit('refresh-course-detail')
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
				// Only the sprint flag is saved here; the mode switches go as last stored.
				const sent = this.normalizeModeConfig(this.savedModeConfig)
				const res = await axios.put(generateUrl(`/apps/learning/api/courses/${this.courseId}/mode-config`), {
					modeConfig: sent,
					leitnerSprint: this.leitnerSprint,
				})
				this.savedModeConfig = this.normalizeModeConfig(res.data?.mode_config || sent)
				this.$emit('refresh-course-detail')
			} catch (e) {
				// The checkbox flipped before the request; put it back and say so (audit #180).
				this.leitnerSprint = !this.leitnerSprint
				this.$emit('error', t('learning', 'Failed to save mode config'))
			}
		},

		async saveTalkRoomToken() {
			this.savingTalkToken = true
			try {
				// Only the Talk token is saved here; the mode switches go as last stored.
				const sent = this.normalizeModeConfig(this.savedModeConfig)
				const res = await axios.put(generateUrl(`/apps/learning/api/courses/${this.courseId}/mode-config`), {
					modeConfig: sent,
					talkRoomToken: this.talkRoomToken,
				})
				this.savedModeConfig = this.normalizeModeConfig(res.data?.mode_config || sent)
				this.$emit('refresh-course-detail')
				this.talkTokenSaved = true
				setTimeout(() => { this.talkTokenSaved = false }, 3000)
			} catch (e) {
				// An invalid token is a 400 the user can fix; it used to fail without a word (audit #180).
				this.$emit('error', e?.response?.status === 400
					? t('learning', 'Ungültiges Talk-Token: Nur Buchstaben und Ziffern sind erlaubt.')
					: t('learning', 'Talk-Token konnte nicht gespeichert werden.'))
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


		async loadSchedule() {
			this.loadingSchedule = true
			this.scheduleLoadError = false
			try {
				const res = await axios.get(generateUrl(`/apps/learning/api/courses/${this.courseId}/schedule`))
				const items = res.data?.schedule || []
				this.scheduleItems = items.map((item) => ({ ...item }))
				this.knownChapterRefs = Array.isArray(res.data?.known_chapter_refs) ? res.data.known_chapter_refs : []
			} catch (e) {
				// An empty editor here looked like "no schedule yet"; a row added and saved then
				// replaced the whole stored schedule.
				console.error('Failed to load schedule', e)
				this.scheduleItems = []
				this.knownChapterRefs = []
				this.scheduleLoadError = true
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
			if (this.scheduleLoadError) {
				return
			}
			this.savingSchedule = true
			this.scheduleSaved = false
			try {
				const payload = buildSchedulePayload(this.scheduleItems)
				const duplicate = firstDuplicateChapterRef(payload)
				if (duplicate !== null) {
					this.$emit('error', t('learning', 'Die Kapitel-Kennung „{ref}“ kommt mehrfach vor. Jede Zeile braucht eine eigene Kennung.', { ref: duplicate }))
					return
				}
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
				// A 400 carries ScheduleService's reason (which row, which field); without it the
				// instructor could not tell what to change.
				const reason = e?.response?.status === 400 ? e.response.data?.error : ''
				this.$emit('error', reason
					? `${t('learning', 'Failed to save schedule')}: ${reason}`
					: t('learning', 'Failed to save schedule'))
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
		async savePracticeConfig() {
			this.practiceSaving = true
			this.practiceSaved = false
			try {
				const result = await updatePracticeConfig(this.courseId, {
					practiceEnabled: this.practiceEnabled,
					practiceQuestions: this.practiceQuestions,
					practiceMinutes: this.practiceMinutes,
					practicePassPercent: this.practicePassPercent,
					practiceRequiredOnly: this.practiceRequiredOnly,
				})
				this.practiceEnabled = !!result.practice_enabled
				this.practiceQuestions = result.practice_questions ?? 20
				this.practiceMinutes = result.practice_minutes ?? 0
				this.practicePassPercent = result.practice_pass_percent ?? 75
				this.practiceRequiredOnly = !!result.practice_required_only
				this.practiceSaved = true
				setTimeout(() => { this.practiceSaved = false }, 3000)
				this.$emit('refresh-course-detail')
			} catch (e) {
				console.error('savePracticeConfig failed', e)
				this.$emit('error', t('learning', 'Probeprüfung konnte nicht gespeichert werden.'))
			} finally {
				this.practiceSaving = false
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
.mode-toggle-row--described { flex-direction: column; align-items: flex-start; gap: 2px; }
.mode-description { padding-left: 28px; line-height: 1.4; }
.tools-master-toggle { margin-bottom: 8px; }
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

.schedule-input--ref {
	min-width: 140px;
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

.schedule-load-error {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
}
</style>
