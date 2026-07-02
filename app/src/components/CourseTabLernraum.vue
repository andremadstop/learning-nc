<template>
	<div class="course-tab-lernraum">
		<div class="lernraum-subnav" role="tablist" :aria-label="t('learning', 'Lernraum Bereiche')">
			<button
				v-for="tab in visibleSubTabs"
				:key="tab.id"
				class="lernraum-pill"
				:class="{ active: currentSubTab === tab.id }"
				@click="selectSubTab(tab.id)">
				{{ tab.label }}
			</button>
		</div>

		<div v-if="isInstructor && currentSubTab === 'pools'" class="pools-section">
			<div class="section-header">
				<h4>{{ t('learning', 'Course Pools') }}</h4>
				<NcButton class="add-pool-btn" @click="openAddPoolModal">
					{{ t('learning', '+ Add Pool') }}
				</NcButton>
			</div>

			<NcNoteCard type="info" class="course-pool-help">
				{{ t('learning', 'Students practice exactly the pools assigned here. Optional filters can limit a pool to one exam key, one chapter or explicit question IDs without changing the original pool. "Required + enforced" blocks optional pools until every filtered question in the required pool was answered at least once.') }}
			</NcNoteCard>

			<div v-if="courseToolTabs.length" class="course-tools-callout">
				<div class="course-tools-callout__header">
					<h5>{{ t('learning', 'Simulatoren im Kurs') }}</h5>
					<p>{{ t('learning', 'Diese Werkzeuge sind für diesen Kurs freigeschaltet und lassen sich direkt im Werkzeuge-Bereich öffnen.') }}</p>
				</div>
				<div class="course-tools-grid">
					<button
						v-for="tool in courseToolTabs"
						:key="'instructor-tool-' + tool.id"
						type="button"
						class="course-tool-chip"
						@click="openCourseTool(tool.id)"
					>
						<span class="course-tool-chip__icon" aria-hidden="true">{{ tool.icon }}</span>
						<span>{{ tool.shortLabel }}</span>
					</button>
				</div>
			</div>

			<div v-if="coursePools.length > 0" class="pool-list">
				<div
					v-for="pool in sortedPools"
					:key="pool.pool_id"
					class="pool-item"
					tabindex="0"
					role="button"
					@click="$emit('openPool', pool.pool_id)"
					@keydown.enter="$emit('openPool', pool.pool_id)"
					@keydown.space.prevent="$emit('openPool', pool.pool_id)">
					<span class="pool-sort-order">{{ (pool.sort_order || 0) + 1 }}.</span>
					<div class="pool-info">
						<span class="pool-name">{{ pool.pool_name }}</span>
						<div class="pool-meta-row">
							<span class="pool-questions">
								{{ t('learning', '{n} questions', { n: pool.question_count || 0 }) }}
							</span>
							<span v-if="poolLanguageSummary(pool)" class="pool-language-summary">
								{{ poolLanguageSummary(pool) }}
							</span>
						</div>
					</div>
					<div class="pool-badges">
						<span v-if="pool.exam_relevant" class="required-badge exam-relevant-badge">
							{{ t('learning', 'Exam') }}
						</span>
						<span v-if="pool.required" class="required-badge">
							{{ t('learning', 'Required') }}
						</span>
						<span v-if="pool.required_enforced" class="required-badge required-enforced-badge">
							{{ t('learning', 'Enforced') }}
						</span>
						<span v-for="summary in poolRuleSummary(pool)" :key="pool.pool_id + '-' + summary" class="filter-badge">
							{{ summary }}
						</span>
					</div>
					<NcButton
						type="tertiary"
						size="small"
						class="pool-rules-btn"
						@click.stop="openPoolRulesModal(pool)">
						{{ t('learning', 'Rules') }}
					</NcButton>
					<NcButton
						type="tertiary-no-background"
						class="remove-pool-btn"
						:aria-label="t('learning', 'Remove pool')"
						@click.stop="confirmRemovePool(pool)">
						&times;
					</NcButton>
				</div>
			</div>

			<NcEmptyContent
				v-else
				:name="t('learning', 'No pools assigned')">
				<template #description>
					{{ t('learning', 'Add question pools to this course') }}
				</template>
			</NcEmptyContent>
		</div>

		<div v-else-if="isStudentLearningTab" class="student-learning-section">
			<div v-if="!selectedLearningPool" class="smart-queue-hero" @click="$emit('openSmartQueue')">
				<div class="smart-queue-hero__count">{{ queueCount }}</div>
				<div class="smart-queue-hero__label">{{ t('learning', 'fällig — alle Kurse') }}</div>
				<NcButton type="primary" @click.stop="$emit('openSmartQueue')">
					{{ t('learning', 'Smart Queue starten') }}
				</NcButton>
			</div>

			<div v-if="!selectedLearningPool && courseToolTabs.length" class="course-tools-callout">
				<div class="course-tools-callout__header">
					<h5>{{ t('learning', 'Simulatoren im Kurs') }}</h5>
					<p>{{ t('learning', 'Diese Werkzeuge passen zu deinem Kurs und öffnen direkt den passenden Simulator.') }}</p>
				</div>
				<div class="course-tools-grid">
					<button
						v-for="tool in courseToolTabs"
						:key="'student-tool-' + tool.id"
						type="button"
						class="course-tool-chip"
						@click="openCourseTool(tool.id)"
					>
						<span class="course-tool-chip__icon" aria-hidden="true">{{ tool.icon }}</span>
						<span>{{ tool.shortLabel }}</span>
					</button>
				</div>
			</div>

			<div v-if="!selectedLearningPool" class="pools-section">
				<div class="section-header">
					<h4>{{ activeLearningModeLabel }}</h4>
				</div>

				<div v-if="coursePools.length > 0" class="pool-list">
					<div
						v-for="pool in sortedPools"
						:key="'learning-' + pool.pool_id"
						class="pool-item"
						:class="{ 'pool-item-loading': loadingLearningPoolId === pool.pool_id, 'pool-item-locked': pool.locked_for_student }"
						tabindex="0"
						role="button"
						@click="selectLearningPool(pool)"
						@keydown.enter="selectLearningPool(pool)"
						@keydown.space.prevent="selectLearningPool(pool)">
						<div class="pool-info">
							<span class="pool-name">{{ pool.pool_name }}</span>
							<div class="pool-meta-row">
								<span class="pool-questions">
									{{ t('learning', '{n} questions', { n: getLearningPoolQuestionCount(pool) }) }}
								</span>
								<span v-if="poolLanguageSummary(pool)" class="pool-language-summary">
									{{ poolLanguageSummary(pool) }}
								</span>
							</div>
						</div>
						<div class="pool-badges">
							<span v-if="pool.required" class="required-badge">
								{{ t('learning', 'Required') }}
							</span>
							<span v-if="pool.required_enforced" class="required-badge required-enforced-badge">
								{{ t('learning', 'Enforced') }}
							</span>
							<span
								v-for="summary in poolRuleSummary(pool)"
								:key="'student-' + pool.pool_id + '-' + summary"
								class="filter-badge">
								{{ summary }}
							</span>
						</div>
					</div>
				</div>

				<NcNoteCard v-if="currentRequiredBlockers.length > 0" type="warning" class="required-lock-note">
					{{ t('learning', 'Optional pools are locked until these required pools are completed once: {names}', { names: currentRequiredBlockers.join(', ') }) }}
				</NcNoteCard>

				<NcEmptyContent
					v-else
					:name="t('learning', 'No pools assigned')">
					<template #description>
						{{ t('learning', 'No question pools are available in this course yet.') }}
					</template>
				</NcEmptyContent>
			</div>

			<TrainingMode
				v-else-if="currentSubTab === 'training'"
				:poolId="selectedLearningPool.pool_id"
				:courseId="courseId"
				:totalQuestions="selectedLearningPoolQuestionCount"
				:contentLanguage="contentLanguage"
				@back="resetLearningPoolSelection" />

			<LeitnerMode
				v-else-if="currentSubTab === 'leitner'"
				:poolId="selectedLearningPool.pool_id"
				:courseId="courseId"
				:contentLanguage="contentLanguage"
				:fsrsDetailedStats="fsrsDetailedStats"
				@back="resetLearningPoolSelection" />

			<ExamMode
				v-else-if="currentSubTab === 'exam'"
				:poolId="selectedLearningPool.pool_id"
				:courseId="courseId"
				:totalQuestions="selectedLearningPoolQuestionCount"
				:contentLanguage="contentLanguage"
				@back="resetLearningPoolSelection" />
		</div>

		<div v-else-if="currentSubTab === 'videos' && !isInstructor" class="student-videos-section">
			<TrainingPrivacyNotice />

			<NcNoteCard v-if="!allVideoGatesComplete" type="warning" class="video-gate-note">
				{{ t('learning', 'Der Kurs-Quiz bleibt gesperrt, bis alle Pflicht-Videos und -Dokumente abgeschlossen sind. Die Sperre wird serverseitig erzwungen — diese Übersicht spiegelt nur deinen Fortschritt.') }}
			</NcNoteCard>
			<NcNoteCard v-else type="success" class="video-gate-note">
				{{ t('learning', 'Alle Pflichtinhalte abgeschlossen. Der Kurs-Quiz ist freigeschaltet.') }}
			</NcNoteCard>

			<ul class="video-item-list">
				<li v-for="item in normalizedCourseVideos" :key="'video-' + item.id" class="video-item">
					<div class="video-item__header">
						<span class="video-item__title">{{ item.title || defaultVideoTitle(item) }}</span>
						<span
							class="video-item__status"
							:class="isItemComplete(item.id) ? 'video-item__status--done' : 'video-item__status--pending'">
							{{ isItemComplete(item.id) ? t('learning', '✓ Abgeschlossen') : t('learning', 'Offen') }}
						</span>
					</div>

					<VideoPlayer
						v-if="item.sourceType === 'nc-files'"
						:content-id="item.id"
						:duration-seconds="item.durationSeconds"
						:subtitle-ref="item.subtitleRef"
						:title="item.title"
						:initial-completed="isItemComplete(item.id)"
						@completed="onItemCompleted"
						@progress="onItemProgress" />

					<VideoConsentOverlay
						v-else-if="item.sourceType === 'vimeo' || item.sourceType === 'youtube-nocookie'"
						:source-type="item.sourceType"
						:embed-id="item.ref"
						:title="item.title" />

					<div v-else-if="item.sourceType === 'document'" class="video-item__document">
						<a
							v-if="item.ref"
							:href="documentUrl(item)"
							target="_blank"
							rel="noopener noreferrer"
							class="video-item__doc-link">
							{{ t('learning', 'Dokument öffnen') }}
						</a>
						<NcButton
							type="primary"
							:disabled="isItemComplete(item.id) || documentPending[item.id]"
							@click="markDocumentRead(item)">
							{{ isItemComplete(item.id)
								? t('learning', 'Als gelesen bestätigt')
								: (documentPending[item.id] ? t('learning', 'Wird gespeichert…') : t('learning', 'Gelesen')) }}
						</NcButton>
					</div>
				</li>
			</ul>
		</div>

		<div v-else-if="currentSubTab === 'tools'" class="course-tools-section">
			<div class="section-header course-tools-section__header">
				<div>
					<h4>{{ t('learning', 'Werkzeuge') }}</h4>
					<p>{{ t('learning', 'Alle freigeschalteten Simulatoren dieses Kurses bleiben direkt im Lernraum verfügbar.') }}</p>
				</div>
			</div>

			<NcEmptyContent
				v-if="courseToolTabs.length === 0"
				:name="t('learning', 'Keine Werkzeuge freigeschaltet')">
				<template #description>
					{{ t('learning', 'Aktiviere Werkzeuge in der Kursverwaltung, damit sie hier erscheinen.') }}
				</template>
			</NcEmptyContent>

			<template v-else>
				<div class="sim-nav" role="tablist" :aria-label="t('learning', 'Werkzeuge im Kurs')">
					<button
						v-for="tool in courseToolTabs"
						:key="'course-tool-tab-' + tool.id"
						type="button"
						class="sim-nav__item"
						:class="{ 'sim-nav__item--active': activeToolId === tool.id }"
						:aria-selected="activeToolId === tool.id ? 'true' : 'false'"
						@click="activeToolId = tool.id">
						<span class="sim-nav__icon" aria-hidden="true">{{ tool.icon }}</span>
						<span class="sim-nav__label">{{ tool.shortLabel }}</span>
					</button>
				</div>

				<div class="course-tool-runtime">
					<SubnetCalculator v-if="activeToolId === 'subnet'" />
					<DnsResolver v-else-if="activeToolId === 'dns'" />
					<FirewallBuilder v-else-if="activeToolId === 'firewall'" />
					<PortScanner v-else-if="activeToolId === 'portscan'" />
					<RoutingTable v-else-if="activeToolId === 'routing'" />
					<NatTable v-else-if="activeToolId === 'nat'" />
					<WiresharkLite v-else-if="activeToolId === 'wireshark'" />
					<AuthFlowSimulator v-else-if="activeToolId === 'authflow'" />
				</div>
			</template>
		</div>

		<div v-else-if="currentSubTab === 'curriculum' && isInstructor" class="curriculum-section">
			<div class="curriculum-header">
				<h3>{{ t('learning', 'Themensteuerung') }}</h3>
				<p class="curriculum-desc">{{ t('learning', 'Lege fest, welche Kapitel aktuell im Kurs aktiv sind. Bei aktivem Filter werden Kurs-Duelle automatisch auf diese Kapitel eingeschränkt.') }}</p>
			</div>

			<div v-if="loadingCurriculum" class="curriculum-loading">
				<NcLoadingIcon :size="32" />
			</div>
			<template v-else>
				<div class="curriculum-toggle">
					<NcCheckboxRadioSwitch
						:model-value="curriculumEnabled"
						@update:model-value="curriculumEnabled = $event">
						{{ t('learning', 'Kapitel-Filter aktiv') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div v-if="curriculumEnabled" class="curriculum-chapters">
					<div v-if="curriculumAvailableChapters.length === 0" class="curriculum-empty">
						{{ t('learning', 'Keine Kapitelmetadaten gefunden. Importiere Fragen mit chapter_key-Werten, um diesen Filter zu nutzen.') }}
					</div>
					<template v-else>
						<div class="curriculum-select-actions">
							<NcButton size="small" @click="selectAllChapters">{{ t('learning', 'Alle') }}</NcButton>
							<NcButton size="small" @click="selectedChapterKeys = []">{{ t('learning', 'Keine') }}</NcButton>
						</div>
						<div
							v-for="chapter in curriculumAvailableChapters"
							:key="chapter.chapter_key"
							class="curriculum-chapter-row">
							<NcCheckboxRadioSwitch
								:model-value="selectedChapterKeys.includes(chapter.chapter_key)"
								@update:model-value="toggleChapter(chapter.chapter_key, $event)">
								<span class="chapter-title">{{ chapter.chapter_title || chapter.chapter_key }}</span>
								<span v-if="chapter.chapter_order" class="chapter-order">{{ t('learning', 'Kap. {n}', { n: chapter.chapter_order }) }}</span>
							</NcCheckboxRadioSwitch>
						</div>
					</template>
				</div>

				<div class="curriculum-actions">
					<NcButton type="primary" :disabled="savingCurriculum" @click="saveCurriculumScope">
						{{ savingCurriculum ? t('learning', 'Saving...') : t('learning', 'Save') }}
					</NcButton>
					<span v-if="curriculumSaved" class="curriculum-saved-hint">✓ {{ t('learning', 'Saved.') }}</span>
				</div>
			</template>
		</div>

		<div v-else-if="currentSubTab === 'materials'" class="tab-content materials-section">
			<CourseMaterials :course-id="courseId" :is-instructor="isInstructor" />
		</div>

		<div v-else-if="currentSubTab === 'knowledge' && isInstructor" class="tab-content knowledge-section">
			<CourseKnowledgeImport :course-id="courseId" :is-instructor="isInstructor" />
			<KnowledgeModeration :course-id="courseId" @pending-count="handleKnowledgePendingCount" />
		</div>

		<NcModal
			v-if="showAddPoolModal"
			:show="showAddPoolModal"
			@update:show="onAddPoolModalShowChanged"
			@close="closeAddPoolModal"
			@closing="closeAddPoolModal">
			<div class="modal-content">
				<h3>{{ t('learning', 'Add Pool to Course') }}</h3>

				<div v-if="poolsLoading" class="loading-container">
					<NcLoadingIcon :size="44" />
					<p>{{ t('learning', 'Loading available pools...') }}</p>
				</div>

				<template v-else>
					<NcNoteCard v-if="poolModalError" type="error" class="modal-error">
						{{ poolModalError }}
					</NcNoteCard>

					<div v-if="availablePools.length > 0" class="pool-select-list">
						<div
							v-for="pool in availablePools"
							:key="pool.id"
							class="pool-select-item"
							:class="{ disabled: isPoolAlreadyAdded(pool.id), selected: selectedPoolIds.includes(pool.id) }"
							tabindex="0"
							role="checkbox"
							:aria-checked="selectedPoolIds.includes(pool.id)"
							@click="togglePoolSelection(pool)"
							@keydown.enter="togglePoolSelection(pool)"
							@keydown.space.prevent="togglePoolSelection(pool)">
							<div class="pool-select-info">
								<span class="pool-select-name">{{ pool.name }}</span>
								<span v-if="pool.description" class="pool-select-desc">{{ pool.description }}</span>
							</div>
							<span v-if="isPoolAlreadyAdded(pool.id)" class="pool-already-added">
								{{ t('learning', 'Already added') }}
							</span>
							<span v-else-if="selectedPoolIds.includes(pool.id)" class="pool-check">✓</span>
						</div>
						<div v-if="selectedPoolIds.length > 0" class="pool-add-confirm">
							<NcButton type="primary" :disabled="savingPool" @click="addSelectedPools">
								{{ savingPool
									? t('learning', 'Adding...')
									: t('learning', 'Add {n} pool(s)', { n: selectedPoolIds.length }) }}
							</NcButton>
						</div>
					</div>

					<NcEmptyContent
						v-else
						:name="t('learning', 'No pools available')">
						<template #description>
							{{ t('learning', 'Create question pools first before adding them to a course.') }}
						</template>
					</NcEmptyContent>
				</template>
			</div>
		</NcModal>

		<NcModal v-if="showRemovePoolModal" @close="showRemovePoolModal = false" @closing="showRemovePoolModal = false" size="small">
			<div class="modal-content">
				<h3>{{ t('learning', 'Remove Pool') }}</h3>
				<p>{{ t('learning', 'Remove "{name}" from this course? Students will lose access to these questions.', { name: removingPool ? removingPool.pool_name : '' }) }}</p>
				<div class="modal-actions">
					<NcButton type="tertiary" :disabled="savingPool" @click="showRemovePoolModal = false">
						{{ t('learning', 'Cancel') }}
					</NcButton>
					<NcButton type="error" :disabled="savingPool" @click="removePool">
						{{ savingPool ? t('learning', 'Removing...') : t('learning', 'Remove') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<NcModal v-if="showPoolRulesModal" @close="closePoolRulesModal" @closing="closePoolRulesModal" size="normal">
			<div class="modal-content">
				<h3>{{ t('learning', 'Pool Rules') }}</h3>
				<p v-if="editingPoolRules" class="rules-pool-name">{{ editingPoolRules.pool_name }}</p>

				<NcNoteCard v-if="poolRulesError" type="error" class="modal-error">
					{{ poolRulesError }}
				</NcNoteCard>

				<div class="rules-field">
					<label class="rules-checkbox">
						<input v-model="poolRulesForm.examRelevant" type="checkbox">
						<span>{{ t('learning', 'Exam relevant (counts towards exam readiness)') }}</span>
					</label>
				</div>

				<div class="rules-field">
					<label class="rules-checkbox">
						<input v-model="poolRulesForm.required" type="checkbox">
						<span>{{ t('learning', 'Show this pool as required') }}</span>
					</label>
				</div>

				<div class="rules-field">
					<label class="rules-checkbox">
						<input v-model="poolRulesForm.requiredEnforced" type="checkbox" :disabled="!poolRulesForm.required">
						<span>{{ t('learning', 'Block optional pools until every filtered question here was answered once') }}</span>
					</label>
				</div>

				<div class="rules-field">
					<label for="pool-rule-exam">{{ t('learning', 'Exam key filter') }}</label>
					<select id="pool-rule-exam" v-model="poolRulesForm.filterExamKey" class="nc-input">
						<option value="">{{ t('learning', 'No exam filter') }}</option>
						<option v-for="examKey in currentPoolExamOptions" :key="examKey" :value="examKey">
							{{ examKey }}
						</option>
					</select>
				</div>

				<div class="rules-field">
					<label for="pool-rule-chapter">{{ t('learning', 'Chapter filter') }}</label>
					<select id="pool-rule-chapter" v-model="poolRulesForm.filterChapterKey" class="nc-input">
						<option value="">{{ t('learning', 'No chapter filter') }}</option>
						<option v-for="chapter in currentPoolChapterOptions" :key="chapter.key" :value="chapter.key">
							{{ chapter.order ? chapter.order + ' - ' + chapter.title : chapter.title }}
						</option>
					</select>
				</div>

				<div class="rules-field">
					<label for="pool-rule-question-ids">{{ t('learning', 'Specific question IDs') }}</label>
					<textarea
						id="pool-rule-question-ids"
						v-model="poolRulesForm.filterQuestionIdsText"
						rows="3"
						class="nc-input"
						:placeholder="t('learning', 'Optional comma-separated question IDs, e.g. 101, 102, 205')"></textarea>
					<p class="rules-help">
						{{ t('learning', 'Leave empty to use the full pool or the selected exam/chapter subset.') }}
					</p>
				</div>

				<div class="modal-actions">
					<NcButton type="tertiary" :disabled="savingPoolRules" @click="closePoolRulesModal">
						{{ t('learning', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" :disabled="savingPoolRules" @click="savePoolRules">
						{{ savingPoolRules ? t('learning', 'Saving...') : t('learning', 'Save Rules') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AuthFlowSimulator from './AuthFlowSimulator.vue'
import CourseKnowledgeImport from './CourseKnowledgeImport.vue'
import CourseMaterials from './CourseMaterials.vue'
import DnsResolver from './DnsResolver.vue'
import ExamMode from './ExamMode.vue'
import FirewallBuilder from './FirewallBuilder.vue'
import KnowledgeModeration from './KnowledgeModeration.vue'
import LeitnerMode from './LeitnerMode.vue'
import NatTable from './NatTable.vue'
import PortScanner from './PortScanner.vue'
import RoutingTable from './RoutingTable.vue'
import SubnetCalculator from './SubnetCalculator.vue'
import TrainingMode from './TrainingMode.vue'
import TrainingPrivacyNotice from './TrainingPrivacyNotice.vue'
import VideoConsentOverlay from './VideoConsentOverlay.vue'
import VideoPlayer from './VideoPlayer.vue'
import WiresharkLite from './WiresharkLite.vue'
import { ALL_TOOL_IDS, TOOL_CATALOG } from '../utils/toolCatalog.js'

export default {
	name: 'CourseTabLernraum',

	components: {
		AuthFlowSimulator,
		DnsResolver,
		FirewallBuilder,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		CourseKnowledgeImport,
		CourseMaterials,
		ExamMode,
		KnowledgeModeration,
		LeitnerMode,
		NatTable,
		PortScanner,
		RoutingTable,
		SubnetCalculator,
		TrainingMode,
		TrainingPrivacyNotice,
		VideoConsentOverlay,
		VideoPlayer,
		WiresharkLite,
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
			validator(value) {
				return ['instructor', 'student'].includes(value)
			},
		},
		coursePools: {
			type: Array,
			default: () => [],
		},
		allPools: {
			type: Array,
			default: () => [],
		},
		activeTab: {
			type: String,
			default: '',
		},
		contentLanguage: {
			type: String,
			default: '',
		},
		fsrsDetailedStats: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			currentSubTab: '',
			knowledgePendingCount: 0,
			selectedLearningPool: null,
			queueCount: 0,
			loadingQueueCount: false,
			poolQuestionCounts: {},
			loadingLearningPoolId: null,
			showAddPoolModal: false,
			poolsLoading: false,
			poolModalError: '',
			availablePoolsState: [],
			savingPool: false,
			showRemovePoolModal: false,
			removingPool: null,
			showPoolRulesModal: false,
			editingPoolRules: null,
			poolRulesError: '',
			savingPoolRules: false,
			poolRulesForm: {
				examRelevant: false,
				required: true,
				requiredEnforced: false,
				filterExamKey: '',
				filterChapterKey: '',
				filterQuestionIdsText: '',
			},
			selectedPoolIds: [],
			loadingCurriculum: false,
			savingCurriculum: false,
			curriculumSaved: false,
			curriculumEnabled: false,
			selectedChapterKeys: [],
			curriculumAvailableChapters: [],
			activeToolId: '',
			courseVideos: [],
			videoStatus: {},
			documentPending: {},
			videosLoaded: false,
		}
	},

	computed: {
		isInstructor() {
			return this.userRole === 'instructor' || this.course?.is_instructor === true
		},
		visibleSubTabs() {
			if (this.isInstructor) {
				const tabs = [
					{ id: 'pools', label: t('learning', 'Pools') },
				]
				if (this.courseToolTabs.length > 0) {
					tabs.push({ id: 'tools', label: t('learning', 'Werkzeuge') })
				}
				tabs.push(
					{ id: 'curriculum', label: t('learning', 'Themen') },
					{ id: 'materials', label: t('learning', 'Materialien') },
					{ id: 'knowledge', label: t('learning', 'Wissen') + (this.knowledgePendingCount > 0 ? ' (' + this.knowledgePendingCount + ')' : '') },
				)
				return tabs
			}
			const tabs = []
			if (this.courseVideos.length > 0) {
				tabs.push({ id: 'videos', label: t('learning', 'Pflichtinhalte') })
			}
			if (this.modeEnabled('training')) {
				tabs.push({ id: 'training', label: t('learning', 'Training') })
			}
			if (this.modeEnabled('leitner')) {
				tabs.push({ id: 'leitner', label: t('learning', 'Leitner') })
			}
			if (this.modeEnabled('exam')) {
				tabs.push({ id: 'exam', label: t('learning', 'Exam') })
			}
			if (this.courseToolTabs.length > 0) {
				tabs.push({ id: 'tools', label: t('learning', 'Werkzeuge') })
			}
			if (this.course?.material_folder) {
				tabs.push({ id: 'materials', label: t('learning', 'Materialien') })
			}
			return tabs
		},
		selectedLearningPoolQuestionCount() {
			if (!this.selectedLearningPool) {
				return 0
			}
			return this.poolQuestionCounts[this.selectedLearningPool.pool_id]
				?? this.selectedLearningPool.question_count
				?? 0
		},
		currentRequiredBlockers() {
			return this.sortedPools
				.filter((pool) => pool.required_enforced && !pool.required_completed)
				.map((pool) => pool.pool_name)
		},
		currentPoolExamOptions() {
			return this.editingPoolRules?.available_filters?.exam_keys || []
		},
		currentPoolChapterOptions() {
			return this.editingPoolRules?.available_filters?.chapters || []
		},
		sortedPools() {
			return [...this.coursePools].sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0))
		},
		availablePools() {
			return this.availablePoolsState
		},
		activeLearningModeLabel() {
			const labels = {
				training: t('learning', 'Training'),
				leitner: t('learning', 'Leitner'),
				exam: t('learning', 'Exam'),
			}
			return labels[this.currentSubTab] || t('learning', 'Choose a learning mode')
		},
		courseToolTabs() {
			const enabled = Array.isArray(this.course?.enabled_tools) && this.course.enabled_tools.length
				? this.course.enabled_tools
				: ALL_TOOL_IDS
			const enabledSet = new Set(enabled)
			return TOOL_CATALOG
				.filter((tool) => enabledSet.has(tool.id))
				.map((tool) => ({
					...tool,
					shortLabel: t('learning', tool.shortLabelKey),
				}))
		},
		isStudentLearningTab() {
			return !this.isInstructor && ['training', 'leitner', 'exam'].includes(this.currentSubTab)
		},
		normalizedCourseVideos() {
			return this.courseVideos
				.map((row) => this.normalizeVideo(row))
				.filter((item) => item.id !== null)
				.sort((a, b) => a.sortOrder - b.sortOrder)
		},
		allVideoGatesComplete() {
			if (this.normalizedCourseVideos.length === 0) {
				return true
			}
			return this.normalizedCourseVideos.every((item) => this.isItemComplete(item.id))
		},
	},

	mounted() {
		if (!this.isInstructor) {
			this.fetchQueueCount()
			this.fetchCourseVideos()
		}
		this.ensureActiveToolVisible()
	},

	watch: {
		allPools: {
			immediate: true,
			handler(pools) {
				if (Array.isArray(pools) && pools.length > 0) {
					this.availablePoolsState = [...pools]
				} else if (this.availablePoolsState.length === 0) {
					this.availablePoolsState = []
				}
			},
		},
		activeTab: {
			immediate: true,
			handler(tabId) {
				this.syncFromActiveTab(tabId)
			},
		},
		coursePools(newPools) {
			if (!this.selectedLearningPool) {
				return
			}
			const nextPool = newPools.find((pool) => pool.pool_id === this.selectedLearningPool.pool_id)
			if (!nextPool) {
				this.resetLearningPoolSelection()
				return
			}
			this.selectedLearningPool = nextPool
		},
		courseToolTabs() {
			this.ensureActiveToolVisible()
		},
	},

	methods: {
		async fetchQueueCount() {
			this.loadingQueueCount = true
			try {
				const r = await axios.get(generateUrl('/apps/learning/api/leitner/queue/count'))
				this.queueCount = r.data.count || 0
			} catch (e) {
				// non-fatal
			} finally {
				this.loadingQueueCount = false
			}
		},
		async fetchCourseVideos() {
			// Student-facing gated-content registry + this user's own progress.
			// videoProgress#courseStatus asserts enrollment server-side (non-member → 403,
			// graceful degrade → no "Pflichtinhalte" tab) and returns the registry annotated
			// with covered_pct/completed — WITHOUT the instructor file path (video_ref), which
			// is DSGVO/IDOR-sensitive and only ever served by content_id via the stream endpoint.
			try {
				const url = generateUrl('/apps/learning/api/video-progress/course/{courseId}', { courseId: this.courseId })
				const response = await axios.get(url)
				this.courseVideos = Array.isArray(response.data?.videos) ? response.data.videos : []
				const status = {}
				for (const row of this.courseVideos) {
					const item = this.normalizeVideo(row)
					if (item.id !== null) {
						status[item.id] = {
							completed: item.completed === true,
							coveredPct: typeof item.coveredPct === 'number' ? item.coveredPct : 0,
						}
					}
				}
				this.videoStatus = status
			} catch (e) {
				// 403 (instructor-gated read) / any error → no Pflichtinhalte tab.
				this.courseVideos = []
			} finally {
				this.videosLoaded = true
			}
		},
		normalizeVideo(row) {
			if (!row || typeof row !== 'object') {
				return { id: null }
			}
			const rawId = row.id ?? row.content_id ?? row.contentId ?? null
			const id = rawId === null ? null : Number(rawId)
			return {
				id: Number.isFinite(id) ? id : null,
				sourceType: row.source_type ?? row.sourceType ?? 'nc-files',
				ref: row.video_ref ?? row.videoRef ?? row.embed_id ?? row.embedId ?? '',
				title: row.title ?? '',
				durationSeconds: Number(row.duration_seconds ?? row.durationSeconds ?? 0) || 0,
				subtitleRef: row.subtitle_ref ?? row.subtitleRef ?? '',
				sortOrder: Number(row.sort_order ?? row.sortOrder ?? 0) || 0,
				completed: row.completed === true,
				coveredPct: Number(row.covered_pct ?? row.coveredPct ?? 0) || 0,
			}
		},
		defaultVideoTitle(item) {
			if (item.sourceType === 'document') {
				return t('learning', 'Pflichtdokument')
			}
			return t('learning', 'Pflichtvideo')
		},
		documentUrl(item) {
			// Documents reference a Nextcloud Files path; open it in the Files app.
			if (!item.ref) {
				return '#'
			}
			if (/^https?:\/\//.test(item.ref)) {
				return item.ref
			}
			return generateUrl('/apps/files/?dir=/&openfile={ref}', { ref: item.ref })
		},
		isItemComplete(id) {
			return this.videoStatus[id]?.completed === true
		},
		setItemStatus(id, patch) {
			this.videoStatus = {
				...this.videoStatus,
				[id]: { ...(this.videoStatus[id] || {}), ...patch },
			}
		},
		onItemCompleted(id) {
			this.setItemStatus(id, { completed: true, coveredPct: 1 })
		},
		onItemProgress(payload) {
			if (payload && payload.contentId != null) {
				this.setItemStatus(payload.contentId, {
					coveredPct: payload.coveredPct,
					completed: payload.completed === true,
				})
			}
		},
		async markDocumentRead(item) {
			if (item.sourceType !== 'document' || this.isItemComplete(item.id)) {
				return
			}
			this.documentPending = { ...this.documentPending, [item.id]: true }
			try {
				const response = await axios.post(
					generateUrl('/apps/learning/api/video-progress/document-read'),
					{ contentId: item.id },
				)
				// Server decides completion; a 200 with completed flips the gate.
				const completed = response.data?.completed !== false
				this.setItemStatus(item.id, { completed })
				if (completed) {
					this.$emit('gate-updated', { contentId: item.id, completed: true })
				}
			} catch (e) {
				this.$emit('error', t('learning', 'Dokument konnte nicht als gelesen markiert werden.'))
			} finally {
				this.documentPending = { ...this.documentPending, [item.id]: false }
			}
		},
		modeEnabled(key) {
			return (this.course?.mode_config || {})[key] !== false
		},
		defaultSubTab() {
			return this.visibleSubTabs[0]?.id || (this.isInstructor ? 'pools' : '')
		},
		isVisibleSubTab(tabId) {
			return this.visibleSubTabs.some((tab) => tab.id === tabId)
		},
		ensureActiveToolVisible() {
			if (this.courseToolTabs.length === 0) {
				this.activeToolId = ''
				return
			}
			if (!this.courseToolTabs.some((tool) => tool.id === this.activeToolId)) {
				this.activeToolId = this.courseToolTabs[0].id
			}
		},
		syncFromActiveTab(tabId) {
			const resolvedTab = this.isVisibleSubTab(tabId) ? tabId : this.defaultSubTab()
			const previousTab = this.currentSubTab
			this.currentSubTab = resolvedTab
			if (['training', 'leitner', 'exam'].includes(resolvedTab) && previousTab !== resolvedTab) {
				this.selectedLearningPool = null
				this.loadingLearningPoolId = null
			}
			if (resolvedTab === 'tools') {
				this.ensureActiveToolVisible()
			}
			if (resolvedTab === 'curriculum' && this.isInstructor) {
				this.fetchCurriculumScope()
			}
		},
		selectSubTab(tabId) {
			if (!this.isVisibleSubTab(tabId) || tabId === this.currentSubTab) {
				return
			}
			if (['training', 'leitner', 'exam'].includes(tabId)) {
				this.$emit('mode-activated', tabId)
			}
			this.$emit('tab-change', tabId)
		},
		openCourseTool(toolId) {
			this.ensureActiveToolVisible()
			if (this.courseToolTabs.some((tool) => tool.id === toolId)) {
				this.activeToolId = toolId
			}
			this.currentSubTab = 'tools'
			this.$emit('tab-change', 'tools')
		},
		handleKnowledgePendingCount(count) {
			this.knowledgePendingCount = Number(count || 0)
			this.$emit('knowledge-pending-count', this.knowledgePendingCount)
		},
		getLearningPoolQuestionCount(pool) {
			return this.poolQuestionCounts[pool.pool_id] ?? pool.question_count ?? 0
		},
		poolLanguageCodes(pool) {
			const langs = Array.isArray(pool.available_content_languages) ? pool.available_content_languages : ['de']
			return langs
				.filter((code) => ['de', 'en', 'ru', 'ar'].includes(code))
				.map((code) => code.toUpperCase())
		},
		poolLanguageSummary(pool) {
			const codes = this.poolLanguageCodes(pool)
			return codes.length > 1 ? codes.join(' | ') : ''
		},
		poolRuleSummary(pool) {
			const summary = []
			if (pool.filter_exam_key) {
				summary.push(t('learning', 'Exam: {value}', { value: pool.filter_exam_key }))
			}
			if (pool.filter_chapter_key) {
				const chapter = (pool.available_filters?.chapters || []).find((item) => item.key === pool.filter_chapter_key)
				summary.push(t('learning', 'Chapter: {value}', { value: chapter?.title || pool.filter_chapter_key }))
			}
			const questionIdCount = Array.isArray(pool.filter_question_ids) ? pool.filter_question_ids.length : 0
			if (questionIdCount > 0) {
				summary.push(t('learning', '{n} fixed questions', { n: questionIdCount }))
			}
			return summary
		},
		parsePoolRuleQuestionIds() {
			return this.poolRulesForm.filterQuestionIdsText
				.split(/[\s,;]+/)
				.map((value) => Number.parseInt(value, 10))
				.filter((value) => Number.isInteger(value) && value > 0)
		},
		openPoolRulesModal(pool) {
			this.editingPoolRules = { ...pool }
			this.poolRulesError = ''
			this.poolRulesForm = {
				examRelevant: pool.exam_relevant === true,
				required: pool.required !== false,
				requiredEnforced: pool.required_enforced === true,
				filterExamKey: pool.filter_exam_key || '',
				filterChapterKey: pool.filter_chapter_key || '',
				filterQuestionIdsText: Array.isArray(pool.filter_question_ids) ? pool.filter_question_ids.join(', ') : '',
			}
			this.showPoolRulesModal = true
		},
		closePoolRulesModal() {
			this.showPoolRulesModal = false
			this.editingPoolRules = null
			this.poolRulesError = ''
		},
		async savePoolRules() {
			if (!this.editingPoolRules) {
				return
			}
			this.savingPoolRules = true
			this.poolRulesError = ''
			const url = generateUrl('/apps/learning/api/courses/{courseId}/pools/{poolId}', {
				courseId: this.courseId,
				poolId: this.editingPoolRules.pool_id,
			})
			try {
				await axios.put(url, {
					required: this.poolRulesForm.required,
					requiredEnforced: this.poolRulesForm.required && this.poolRulesForm.requiredEnforced,
					filterExamKey: this.poolRulesForm.filterExamKey || null,
					filterChapterKey: this.poolRulesForm.filterChapterKey || null,
					filterQuestionIds: this.parsePoolRuleQuestionIds(),
					examRelevant: this.poolRulesForm.examRelevant,
				})
				this.closePoolRulesModal()
				this.$emit('refresh-course-detail')
			} catch (err) {
				this.poolRulesError = err.response?.data?.error || t('learning', 'Failed to save pool rules.')
			} finally {
				this.savingPoolRules = false
			}
		},
		async fetchPoolQuestionCount(poolId) {
			const pool = this.coursePools.find((item) => item.pool_id === poolId)
			if (pool && typeof pool.question_count === 'number') {
				this.poolQuestionCounts[poolId] = pool.question_count
				return pool.question_count
			}
			if (this.poolQuestionCounts[poolId] !== undefined) {
				return this.poolQuestionCounts[poolId]
			}

			const url = generateUrl('/apps/learning/api/pools/{poolId}/questions', { poolId })
			const response = await axios.get(url)
			const questionCount = Array.isArray(response.data) ? response.data.length : 0
			this.poolQuestionCounts[poolId] = questionCount
			return questionCount
		},
		async selectLearningPool(pool) {
			if (pool.locked_for_student) {
				this.$emit('error', t('learning', 'Complete all enforced required pools first.'))
				return
			}
			this.loadingLearningPoolId = pool.pool_id
			this.$emit('error', '')
			try {
				await this.fetchPoolQuestionCount(pool.pool_id)
				this.selectedLearningPool = pool
				this.$emit('pool-selected', pool)
			} catch (err) {
				this.$emit('error', t('learning', 'Failed to load pool questions.'))
			} finally {
				this.loadingLearningPoolId = null
			}
		},
		resetLearningPoolSelection() {
			this.selectedLearningPool = null
			this.$emit('pool-selected', null)
		},
		async openAddPoolModal() {
			this.showAddPoolModal = true
			this.poolModalError = ''
			this.selectedPoolIds = []
			if (this.availablePools.length === 0) {
				await this.fetchAllPools()
			}
		},
		onAddPoolModalShowChanged(show) {
			if (!show) {
				this.closeAddPoolModal()
			}
		},
		closeAddPoolModal() {
			this.showAddPoolModal = false
		},
		async fetchAllPools() {
			this.poolsLoading = true
			this.poolModalError = ''
			try {
				const url = generateUrl('/apps/learning/api/pools')
				const response = await axios.get(url)
				if (Array.isArray(response.data)) {
					this.availablePoolsState = response.data
				} else if (response.data && typeof response.data === 'object') {
					this.availablePoolsState = [...(response.data.own || []), ...(response.data.shared || [])]
				} else {
					this.availablePoolsState = []
				}
				this.$emit('all-pools-loaded', this.availablePoolsState)
			} catch (err) {
				this.poolModalError = t('learning', 'Failed to load available pools.')
			} finally {
				this.poolsLoading = false
			}
		},
		isPoolAlreadyAdded(poolId) {
			return this.coursePools.some((pool) => pool.pool_id === poolId)
		},
		togglePoolSelection(pool) {
			if (this.isPoolAlreadyAdded(pool.id)) {
				return
			}
			const index = this.selectedPoolIds.indexOf(pool.id)
			if (index >= 0) {
				this.selectedPoolIds.splice(index, 1)
			} else {
				this.selectedPoolIds.push(pool.id)
			}
		},
		async addSelectedPools() {
			if (this.selectedPoolIds.length === 0) {
				return
			}
			this.savingPool = true
			this.poolModalError = ''
			const url = generateUrl('/apps/learning/api/courses/{courseId}/pools', { courseId: this.courseId })
			const baseSortOrder = this.coursePools.length > 0
				? Math.max(...this.coursePools.map((pool) => pool.sort_order || 0)) + 1
				: 0
			try {
				for (let index = 0; index < this.selectedPoolIds.length; index++) {
					await axios.post(url, {
						poolId: this.selectedPoolIds[index],
						sortOrder: baseSortOrder + index,
						required: true,
					})
				}
				this.selectedPoolIds = []
				this.showAddPoolModal = false
				this.$emit('refresh-course-detail')
			} catch (err) {
				const message = err.response?.data?.error || err.response?.data?.message
				this.poolModalError = message || t('learning', 'Failed to add pool to course.')
			} finally {
				this.savingPool = false
			}
		},
		confirmRemovePool(pool) {
			this.removingPool = pool
			this.showRemovePoolModal = true
		},
		async removePool() {
			if (!this.removingPool) {
				return
			}

			this.savingPool = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/pools/{poolId}', {
					courseId: this.courseId,
					poolId: this.removingPool.pool_id,
				})
				await axios.delete(url)
				this.showRemovePoolModal = false
				this.removingPool = null
				this.$emit('refresh-course-detail')
			} catch (err) {
				this.$emit('error', t('learning', 'Failed to remove pool from course.'))
			} finally {
				this.savingPool = false
			}
		},
		async fetchCurriculumScope() {
			this.loadingCurriculum = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/curriculum-scope', { courseId: this.courseId })
				const response = await axios.get(url)
				this.curriculumEnabled = response.data.enabled || false
				this.selectedChapterKeys = response.data.selected_chapter_keys || []
				this.curriculumAvailableChapters = response.data.available_chapters || []
			} catch (err) {
				// Keep the tab usable even if the backend call fails.
			} finally {
				this.loadingCurriculum = false
			}
		},
		async saveCurriculumScope() {
			this.savingCurriculum = true
			this.curriculumSaved = false
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/curriculum-scope', { courseId: this.courseId })
				await axios.put(url, {
					enabled: this.curriculumEnabled,
					chapterKeys: this.selectedChapterKeys,
				})
				this.curriculumSaved = true
				setTimeout(() => {
					this.curriculumSaved = false
				}, 2500)
			} catch (err) {
				// Keep the current selection intact and let the user retry.
			} finally {
				this.savingCurriculum = false
			}
		},
		toggleChapter(chapterKey, checked) {
			if (checked) {
				if (!this.selectedChapterKeys.includes(chapterKey)) {
					this.selectedChapterKeys = [...this.selectedChapterKeys, chapterKey]
				}
				return
			}
			this.selectedChapterKeys = this.selectedChapterKeys.filter((key) => key !== chapterKey)
		},
		selectAllChapters() {
			this.selectedChapterKeys = this.curriculumAvailableChapters.map((chapter) => chapter.chapter_key)
		},
	},
}
</script>

<style scoped>
.course-tab-lernraum {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.lernraum-subnav {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 4px;
}

.lernraum-pill {
	border: 1px solid var(--color-border);
	border-radius: 999px;
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	padding: 8px 14px;
	font-size: 0.88em;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s, border-color 0.15s, color 0.15s;
}

.lernraum-pill:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.lernraum-pill.active {
	border-color: var(--color-primary-element);
	background: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	color: var(--color-primary-element);
}

.section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.section-header h4 {
	margin: 0;
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 1px;
	color: var(--color-text-maxcontrast);
	font-weight: 700;
}

.add-pool-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	text-align: center;
}

.pool-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.course-pool-help,
.required-lock-note {
	margin-bottom: 12px;
}

.course-tools-callout {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	margin-bottom: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: color-mix(in srgb, var(--color-primary-element) 6%, var(--color-main-background));
}

.course-tools-callout__header h5 {
	margin: 0 0 4px;
	font-size: 14px;
	font-weight: 700;
	color: var(--color-main-text);
}

.course-tools-callout__header p {
	margin: 0;
	font-size: 13px;
	line-height: 1.45;
	color: var(--color-text-maxcontrast);
}

.course-tools-section__header p {
	margin: 4px 0 0 0;
	color: var(--color-text-maxcontrast);
}

.course-tools-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
}

.course-tool-chip {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-radius: 999px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
	transition: transform 0.15s, border-color 0.15s, background 0.15s;
}

.course-tool-chip:hover {
	transform: translateY(-1px);
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.course-tool-chip__icon {
	font-size: 15px;
}

.sim-nav {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 16px;
}

.sim-nav__item {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-radius: 999px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.sim-nav__item--active {
	border-color: var(--color-primary-element);
	background: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
}

.sim-nav__icon {
	font-size: 15px;
}

.course-tool-runtime {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.pool-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	cursor: pointer;
	transition: transform 0.2s, background 0.15s, box-shadow 0.2s;
}

.pool-item:hover {
	background: var(--color-background-hover);
	transform: translateY(-1px);
	box-shadow: 0 3px 10px color-mix(in srgb, var(--color-main-text) 6%, transparent);
}

.pool-item-loading {
	opacity: 0.7;
}

.pool-item-locked {
	opacity: 0.65;
	cursor: not-allowed;
}

.pool-item-locked:hover {
	transform: none;
	box-shadow: none;
}

.pool-sort-order {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	font-weight: 600;
	min-width: 24px;
	flex-shrink: 0;
}

.pool-info {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.pool-name {
	font-weight: 600;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.pool-questions {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.pool-meta-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.pool-language-summary {
	font-size: 0.76em;
	font-weight: 700;
	letter-spacing: 0.04em;
	color: var(--color-primary-element);
	white-space: nowrap;
}

.pool-badges {
	display: flex;
	gap: 6px;
	flex-shrink: 0;
}

.required-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.75em;
	font-weight: 600;
	background: color-mix(in srgb, var(--color-warning) 15%, transparent);
	color: var(--color-warning);
	white-space: nowrap;
}

.required-enforced-badge {
	background: color-mix(in srgb, var(--color-error) 12%, transparent);
	color: var(--color-error);
}

.exam-relevant-badge {
	background: color-mix(in srgb, var(--color-success) 15%, transparent);
	color: var(--color-success);
}

.filter-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.75em;
	font-weight: 600;
	background: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	color: var(--color-primary-element);
	white-space: nowrap;
}

.pool-rules-btn,
.remove-pool-btn {
	flex-shrink: 0;
}

.remove-pool-btn {
	font-size: 1.3em;
	line-height: 1;
	color: var(--color-text-maxcontrast);
}

.remove-pool-btn:hover {
	color: var(--color-error);
}

.curriculum-section {
	padding: 12px 0 0;
	max-width: 720px;
}

.curriculum-header h3 {
	font-size: 20px;
	font-weight: 600;
	margin-bottom: 8px;
}

.curriculum-desc {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
	font-size: 14px;
	line-height: 1.5;
}

.curriculum-loading {
	display: flex;
	justify-content: center;
	padding: 40px;
}

.curriculum-toggle {
	margin-bottom: 20px;
}

.curriculum-chapters {
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 16px;
	margin-bottom: 20px;
}

.curriculum-empty {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	text-align: center;
	padding: 20px;
}

.curriculum-select-actions {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
}

.curriculum-chapter-row {
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.curriculum-chapter-row:last-child {
	border-bottom: none;
}

.chapter-title {
	font-size: 14px;
}

.chapter-order {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-inline-start: 8px;
}

.curriculum-actions {
	display: flex;
	align-items: center;
	gap: 12px;
}

.curriculum-saved-hint {
	font-size: 13px;
	color: var(--color-success);
	font-weight: 600;
}

.tab-content {
	padding: 4px 0 0;
}

.student-videos-section {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.video-gate-note {
	margin: 0;
}

.video-item-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.video-item {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
}

.video-item__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.video-item__title {
	font-weight: 600;
	color: var(--color-main-text);
}

.video-item__status {
	font-size: 0.8em;
	font-weight: 700;
	padding: 2px 10px;
	border-radius: 999px;
	white-space: nowrap;
}

.video-item__status--done {
	background: color-mix(in srgb, var(--color-success) 18%, transparent);
	color: var(--color-success);
}

.video-item__status--pending {
	background: color-mix(in srgb, var(--color-warning) 18%, transparent);
	color: var(--color-warning);
}

.video-item__document {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.video-item__doc-link {
	color: var(--color-primary-element);
	font-weight: 600;
}

.modal-content {
	padding: 24px;
	min-width: 320px;
}

.modal-content h3 {
	margin: 0 0 20px 0;
	font-size: 1.2em;
	font-weight: 700;
	color: var(--color-main-text);
}

.modal-error {
	margin-bottom: 16px;
}

.pool-select-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-height: 420px;
	overflow-y: auto;
}

.pool-select-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	cursor: pointer;
	transition: background 0.15s;
}

.pool-select-item:hover:not(.disabled) {
	background: var(--color-background-hover);
}

.pool-select-item.selected {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.pool-select-item.disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.pool-add-confirm {
	display: flex;
	justify-content: flex-end;
	padding-top: 8px;
}

.pool-select-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.pool-select-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.pool-select-desc {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.pool-already-added {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	flex-shrink: 0;
}

.pool-check {
	font-size: 1.1em;
	color: var(--color-primary-element);
	font-weight: 700;
	flex-shrink: 0;
}

.rules-pool-name {
	margin: -8px 0 16px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.rules-field {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.rules-checkbox {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	cursor: pointer;
}

.rules-help {
	margin: 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

@media (max-width: 768px) {
	.lernraum-subnav {
		flex-wrap: nowrap;
		overflow-x: auto;
		padding-bottom: 4px;
	}

	.section-header {
		flex-direction: column;
		align-items: flex-start;
		gap: 8px;
	}

	.modal-content {
		padding: 16px;
		min-width: unset;
	}

	.pool-select-list {
		max-height: 300px;
	}
}

@media (prefers-reduced-motion: reduce) {
	.pool-item:hover {
		transform: none;
	}
}
</style>
