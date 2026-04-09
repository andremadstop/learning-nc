<template>
	<div class="course-detail">
		<!-- Header -->
		<div class="course-detail-header">
			<NcButton type="tertiary" @click="$emit('back')">
				{{ t('learning', '\u2190 Zurück zu Kursen') }}
			</NcButton>
			<div class="header-title">
				<h3>{{ course ? course.title : t('learning', 'Laden...') }}</h3>
				<span v-if="course && course.status" class="status-badge" :class="course.status">
					{{ course.status === 'active' ? t('learning', 'Aktiv') : t('learning', 'Archiviert') }}
				</span>
			</div>
			<p v-if="course && course.description" class="course-description">
				{{ course.description }}
			</p>
			<a v-if="hasTalkRoomLink"
				:href="talkRoomUrl"
				target="_blank"
				rel="noopener"
				class="talk-room-link">
				{{ t('learning', 'Talk-Raum') }}
			</a>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="loading-container">
			<NcLoadingIcon :size="44" />
			<p>{{ t('learning', 'Lade Kursdetails...') }}</p>
		</div>

		<!-- Error state -->
		<NcNoteCard v-if="error" type="error" class="detail-error">
			{{ error }}
		</NcNoteCard>

		<template v-if="!loading && course">
			<!-- Mega-tab selector -->
			<div class="mega-tab-selector">
				<button v-for="tab in visibleMegaTabs"
					:key="tab.id"
					class="mega-tab-button"
					:class="{ active: activeMegaTab === tab.id }"
					@click="selectMegaTab(tab.id)">
					{{ tab.label }}
				</button>
				<ExamReadiness
					v-if="course.exam_date"
					:course-id="courseId"
					class="mega-tab-countdown" />
			</div>

			<!-- Mega-tab content -->
			<div v-if="activeMegaTab === 'lernraum'" class="lernraum-mega-tab">

				<CourseTabLernraum
					:course-id="courseId"
					:course="course"
					:user-role="userRole"
					:course-pools="coursePools"
					:all-pools="allPools"
					:active-tab="currentTab"
					:content-language="contentLanguage"
					:fsrs-detailed-stats="fsrsDetailedStats"
					@all-pools-loaded="allPools = $event"
					@error="error = $event"
					@knowledge-pending-count="knowledgePendingCount = $event"
					@mode-activated="activeLearningMode = $event"
					@openPool="$emit('openPool', $event)"
					@open-tool="$emit('open-tool', $event)"
					@pool-selected="selectedLearningPool = $event"
					@refresh-course-detail="fetchCourseDetail"
					@tab-change="onLeafTabChange" />
			</div>

			<CourseTabTeilnehmer
				v-if="activeMegaTab === 'teilnehmer'"
				:course-id="courseId"
				:course="course"
				:user-role="userRole"
				:course-members="courseMembers"
				:course-pools="coursePools"
				:active-tab="currentTab"
				@tab-change="onLeafTabChange"
				@error="error = $event"
				@members-changed="fetchCourseDetail"
				@selectStudent="$emit('selectStudent', $event)" />

			<CourseTabWettbewerb
				v-if="activeMegaTab === 'wettbewerb'"
				:course-id="courseId"
				:course="course"
				:user-role="userRole"
				:course-pools="coursePools"
				:active-tab="currentTab"
				:content-language="contentLanguage"
				:preset-duel-code="presetDuelCode"
				@tab-change="onLeafTabChange"
				@error="error = $event"
				@arena-sub-mode="arenaSubMode = $event"
				@preset-consumed="$emit('clearPresetDuel')"
				@selectStudent="$emit('selectStudent', $event)" />

			<CourseTabKommunikation
				v-if="activeMegaTab === 'kommunikation'"
				:course-id="courseId"
				:course="course"
				:user-role="userRole"
				:active-tab="currentTab"
				@tab-change="onLeafTabChange"
				@error="error = $event"
				@refresh-course-detail="fetchCourseDetail" />

			<CourseTabVerwaltung
				v-if="activeMegaTab === 'verwaltung' && isInstructor"
				:course-id="courseId"
				:course="course"
				:user-role="userRole"
				:active-tab="currentTab"
				@tab-change="onLeafTabChange"
				@error="error = $event"
				@refresh-course-detail="fetchCourseDetail" />

		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import CourseTabLernraum from './CourseTabLernraum.vue'
import CourseTabTeilnehmer from './CourseTabTeilnehmer.vue'
import CourseTabWettbewerb from './CourseTabWettbewerb.vue'
import CourseTabKommunikation from './CourseTabKommunikation.vue'
import CourseTabVerwaltung from './CourseTabVerwaltung.vue'
import ExamReadiness from './ExamReadiness.vue'
import { useOptionalCourseStore } from '../stores/courseStore.js'
import { useOptionalVirtuProfStore } from '../stores/virtuProfStore.js'

export default {
	name: 'CourseDetail',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		ExamReadiness,
		CourseTabLernraum,
		CourseTabTeilnehmer,
		CourseTabWettbewerb,
		CourseTabKommunikation,
		CourseTabVerwaltung,
	},

	props: {
		courseId: {
			type: Number,
			required: true,
		},
		userRole: {
			type: String,
			required: true,
			validator(value) {
				return ['instructor', 'student'].includes(value)
			},
		},
		contentLanguage: {
			type: String,
			default: '',
		},
		fsrsDetailedStats: {
			type: Boolean,
			default: false,
		},
		presetDuelCode: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			course: null,
			coursePools: [],
			courseMembers: [],
			activeMegaTab: 'lernraum',
			currentTab: 'pools',
			knowledgePendingCount: 0,
			selectedLearningPool: null,
			activeLearningMode: null,
			allPools: [],

			// Student progress (for student own progress bars — kept for fetchStudentProgress)
			studentProgress: [],
			myProgressMode: 'mastery',

			// Arena sub-mode (received from Wettbewerb child for VirtuProf context)
			arenaSubMode: null,
		}
	},

	computed: {
		isInstructor() {
			return this.course && this.course.is_instructor
		},
		talkRoomUrl() {
			const token = String(this.course?.talk_room_token || '').trim()
			if (!token) return ''
			return '/apps/spreed/#/call/' + token
		},
		hasTalkRoomLink() {
			return this.talkRoomUrl.length > 0
		},
		myUserId() {
			const user = getCurrentUser()
			return user ? user.uid : null
		},
		isCourseSummaryReleased() {
			return this.course?.mode_config?.course_summary === true
		},
		isStudentLearningTab() {
			return !this.isInstructor && ['training', 'leitner', 'exam'].includes(this.currentTab)
		},
		hasCourseTools() {
			return Array.isArray(this.course?.enabled_tools) ? this.course.enabled_tools.length > 0 : true
		},
		kommunikationLeafTabs() {
			if (this.isInstructor) {
				return ['announcements', 'feed', 'requests']
			}
			return ['feed', 'buddies', 'schwarm']
		},
		verwaltungLeafTabs() {
			return ['mode-config', 'schedule', 'exam-slot']
		},
		lernraumLeafTabs() {
			if (this.isInstructor) {
				const tabs = ['pools']
				if (this.hasCourseTools) tabs.push('tools')
				tabs.push('curriculum', 'materials', 'knowledge')
				return tabs
			}
			const mc = this.course?.mode_config || {}
			const enabled = (key) => mc[key] !== false
			const tabs = ['training']
			if (enabled('leitner')) tabs.push('leitner')
			if (enabled('exam')) tabs.push('exam')
			if (this.hasCourseTools) tabs.push('tools')
			if (this.course?.material_folder) tabs.push('materials')
			return tabs
		},
		wettbewerbLeafTabs() {
			const mc = this.course?.mode_config || {}
			const enabled = (key) => mc[key] !== false
			if (this.isInstructor) {
				return ['leaderboard', 'league', 'arena', 'abenteuer']
			}
			const tabs = ['leaderboard']
			if (enabled('league')) tabs.push('league')
			if (this.hasEnabledArenaModes) tabs.push('arena')
			if (enabled('abenteuer')) tabs.push('abenteuer')
			return tabs
		},
		teilnehmerLeafTabs() {
			if (this.isInstructor) {
				return ['members', 'classbook', 'progress', 'heatmap', 'weak-questions', 'class-profile', 'summary']
			}
			const tabs = ['classbook', 'my-progress']
			if (this.isCourseSummaryReleased) tabs.push('summary')
			return tabs
		},
		hasEnabledArenaModes() {
			const mc = this.course?.mode_config || {}
			const enabled = (key) => mc[key] !== false
			return enabled('duel') || enabled('gameshow')
		},
		visibleMegaTabs() {
			if (this.isInstructor) {
				return [
					{ id: 'lernraum', label: t('learning', 'Lernraum') },
					{ id: 'teilnehmer', label: t('learning', 'Teilnehmer') },
					{ id: 'wettbewerb', label: t('learning', 'Wettbewerb') },
					{ id: 'kommunikation', label: t('learning', 'Kommunikation') },
					{ id: 'verwaltung', label: t('learning', 'Verwaltung') },
				]
			}
			return [
				{ id: 'lernraum', label: t('learning', 'Lernraum') },
				{ id: 'teilnehmer', label: t('learning', 'Teilnehmer') },
				// Wettbewerb always visible (leaderboard is always enabled)
				{ id: 'wettbewerb', label: t('learning', 'Wettbewerb') },
				{ id: 'kommunikation', label: t('learning', 'Kommunikation') },
			]
		},
		/**
		 * Internal reference for the visibleTabs watcher (fallback tab logic).
		 * Returns the same data as visibleMegaTabs.
		 */
		visibleTabs() {
			return this.visibleMegaTabs
		},
	},

	watch: {
		$route(route) {
			if (route && Number(route.params?.id || 0) === Number(this.courseId || 0)) {
				this.syncCurrentTabFromRoute(route.params?.tab || '')
			}
		},
		visibleTabs(tabs) {
			const ids = tabs.map((t) => t.id)
			const isCoveredByMega = this.isLernraumTab(this.currentTab)
				|| this.isTeilnehmerTab(this.currentTab)
				|| this.isWettbewerbTab(this.currentTab)
				|| this.isKommunikationTab(this.currentTab)
				|| this.isVerwaltungTab(this.currentTab)
			const isVisibleMega = ids.includes('lernraum') && this.isLernraumTab(this.currentTab)
				|| ids.includes('teilnehmer') && this.isTeilnehmerTab(this.currentTab)
				|| ids.includes('wettbewerb') && this.isWettbewerbTab(this.currentTab)
				|| ids.includes('kommunikation') && this.isKommunikationTab(this.currentTab)
				|| ids.includes('verwaltung') && this.isVerwaltungTab(this.currentTab)
			if (!isVisibleMega && !isCoveredByMega) {
				this.currentTab = this.defaultLernraumTab()
				this.activeMegaTab = 'lernraum'
			}
		},
		courseId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.fetchCourseDetail()
				}
			},
		},
		currentTab(tab) {
			// Sync activeMegaTab from leaf tab
			const mega = this.megaTabForLeaf(tab)
			if (mega) {
				this.activeMegaTab = mega
			}
			useOptionalCourseStore()?.setTab(tab)
			if (this.isStudentLearningTab) {
				this.activeLearningMode = tab
				this.selectedLearningPool = null
			}
			this.emitVirtuProfContext()
			this.emitLearningGuide()
		},
		activeLearningMode() {
			this.emitVirtuProfContext()
		},
		selectedLearningPool() {
			this.emitVirtuProfContext()
		},
		course() {
			this.emitVirtuProfContext()
		},
		presetDuelCode(newCode) {
			if (newCode) {
				this.currentTab = 'arena'
				this.activeMegaTab = 'wettbewerb'
				this.arenaSubMode = 'duel'
			}
		},
	},

	methods: {
		currentRouteTab() {
			if (!this.$route || Number(this.$route.params?.id || 0) !== Number(this.courseId || 0)) {
				return ''
			}
			return String(this.$route.params?.tab || '').trim()
		},
		syncCurrentTabFromRoute(tabId = '') {
			if (!tabId) {
				return
			}
			const resolvedTabId = this.resolveSelectableTab(String(tabId))
			this.currentTab = resolvedTabId
			if (['lernraum', 'teilnehmer', 'wettbewerb', 'kommunikation', 'verwaltung'].includes(String(tabId))) {
				this.activeMegaTab = String(tabId)
			} else {
				const mega = this.megaTabForLeaf(resolvedTabId)
				if (mega) {
					this.activeMegaTab = mega
				}
			}
		},
		pushCourseTabRoute(tabId) {
			if (!this.$router || !this.courseId) {
				return
			}
			const nextTab = String(tabId || '').trim()
			if (!nextTab) {
				return
			}
			const navigation = this.$router.push({
				name: 'course-tab',
				params: {
					id: String(this.courseId),
					tab: nextTab,
				},
			})
			if (navigation && typeof navigation.catch === 'function') {
				navigation.catch(() => {})
			}
		},
		defaultLernraumTab() {
			return this.lernraumLeafTabs[0] || (this.isInstructor ? 'pools' : 'training')
		},
		isLernraumTab(tabId) {
			return this.lernraumLeafTabs.includes(tabId)
		},
		isKommunikationTab(tabId) {
			return this.kommunikationLeafTabs.includes(tabId)
		},
		isVerwaltungTab(tabId) {
			return this.verwaltungLeafTabs.includes(tabId)
		},
		isWettbewerbTab(tabId) {
			return this.wettbewerbLeafTabs.includes(tabId)
		},
		isTeilnehmerTab(tabId) {
			return this.teilnehmerLeafTabs.includes(tabId)
		},
		isTabActive(tabId) {
			if (tabId === 'lernraum') return this.isLernraumTab(this.currentTab)
			if (tabId === 'kommunikation') return this.isKommunikationTab(this.currentTab)
			if (tabId === 'verwaltung') return this.isVerwaltungTab(this.currentTab)
			if (tabId === 'wettbewerb') return this.isWettbewerbTab(this.currentTab)
			if (tabId === 'teilnehmer') return this.isTeilnehmerTab(this.currentTab)
			return this.currentTab === tabId
		},
		resolveSelectableTab(tabId) {
			if (tabId === 'lernraum') {
				return this.isLernraumTab(this.currentTab) ? this.currentTab : this.defaultLernraumTab()
			}
			if (tabId === 'kommunikation') {
				return this.isKommunikationTab(this.currentTab) ? this.currentTab : this.kommunikationLeafTabs[0]
			}
			if (tabId === 'verwaltung') {
				return this.isVerwaltungTab(this.currentTab) ? this.currentTab : this.verwaltungLeafTabs[0]
			}
			if (tabId === 'wettbewerb') {
				return this.isWettbewerbTab(this.currentTab) ? this.currentTab : this.wettbewerbLeafTabs[0]
			}
			if (tabId === 'teilnehmer') {
				return this.isTeilnehmerTab(this.currentTab) ? this.currentTab : this.teilnehmerLeafTabs[0]
			}
			return tabId
		},
		emitVirtuProfContext() {
			if (this.isInstructor || !this.course) {
				return
			}
			let area = 'course-detail'
			if (this.currentTab === 'my-progress') {
				area = 'course-my-progress'
			} else if (this.currentTab === 'summary') {
				area = 'course-summary'
			} else if (this.currentTab === 'leaderboard') {
				area = 'course-leaderboard'
			} else if (this.currentTab === 'league') {
				area = 'course-league'
			} else if (this.currentTab === 'arena') {
				if (this.arenaSubMode === 'sprint') {
					area = 'course-arena-sprint'
				} else if (this.arenaSubMode === 'elimination') {
					area = 'course-arena-elimination'
				} else if (this.arenaSubMode === 'duel') {
					area = 'course-duel'
				} else {
					area = 'course-arena'
				}
			} else if (this.isStudentLearningTab) {
				area = this.selectedLearningPool
					? `course-${this.activeLearningMode}-active`
					: `course-${this.activeLearningMode}-pool-select`
			}
			useOptionalVirtuProfStore()?.updateContext({
				area,
				courseTitle: this.course?.title || '',
				poolName: this.selectedLearningPool?.pool_name || '',
			})
		},
		emitLearningGuide() {
			if (this.isInstructor) {
				return
			}
			const payload = this.learningGuidePayload(this.currentTab)
			if (payload) {
				useOptionalVirtuProfStore()?.guide(payload)
			}
		},
		learningGuidePayload(tabId) {
			const guides = {
				training: {
					key: 'mode:course-training',
					title: t('learning', 'Training'),
					text: t('learning', 'Training gibt dir sofortiges Feedback nach jeder Antwort. Der beste Modus für aktives Üben, wenn du noch Erklärungen und schnelle Korrekturen brauchst.'),
					shortText: t('learning', 'Training ist der Modus mit direktem Feedback für schnelles Üben.'),
				},
				leitner: {
					key: 'mode:course-leitner',
					title: t('learning', 'Leitner'),
					text: t('learning', 'Leitner fokussiert auf Spaced Repetition. Schwierige Karten kommen schneller zurück, gemeisterte verschwinden länger — dein Hauptmodus für langfristiges Behalten.'),
					shortText: t('learning', 'Leitner wiederholt schwache Karten häufiger und gemeisterte seltener.'),
				},
				exam: {
					key: 'mode:course-exam',
					title: t('learning', 'Exam'),
					text: t('learning', 'Der Prüfungsmodus verbirgt Feedback bis zum Ende, damit du einen echten Testlauf simulieren kannst. Nutze ihn, wenn du Druck, Tempo und ein klareres Ergebnis willst.'),
					shortText: t('learning', 'Prüfungsmodus zeigt Feedback erst am Ende des Durchlaufs.'),
				},
				leaderboard: {
					key: 'mode:course-leaderboard',
					title: t('learning', 'Leaderboard'),
					text: t('learning', 'Die Rangliste vergleicht Kursaktivität und Meisterschaft. Nützlich, um Dynamik zu erkennen — nicht nur reines Ranking, besonders mit Aktiv-Filter.'),
					shortText: t('learning', 'Die Rangliste vergleicht XP, Meisterschaft und aktuelle Aktivität im Kurs.'),
				},
				league: {
					key: 'mode:course-league',
					title: t('learning', 'Liga'),
					text: t('learning', 'Liga verfolgt langfristigen Wettbewerbsfortschritt im Kurs. Es geht weniger um eine einzelne Session und mehr um anhaltende Lernleistung über die Zeit.'),
					shortText: t('learning', 'Liga zeigt anhaltenden Kursfortschritt über die Zeit.'),
				},
				arena: {
					key: 'mode:course-arena',
					title: t('learning', 'Arena'),
					text: t('learning', 'Arena ist für Wettbewerbsformate wie Duell, Sprint und Elimination. Nutze sie, wenn du Tempo, Druck und direkten Vergleich mit anderen Lernenden willst.'),
					shortText: t('learning', 'Arena bündelt die kompetitiven Lernmodi.'),
				},
				abenteuer: {
					key: 'mode:course-abenteuer',
					title: t('learning', 'Abenteuer'),
					text: t('learning', 'Abenteuermodus verpackt Lernen in narrative Progression. Nützlich, wenn du mehr Atmosphäre und einen langsameren, geführten Rhythmus als reinen Quiz-Modus willst.'),
					shortText: t('learning', 'Abenteuermodus fügt narrative Progression um die Fragen hinzu.'),
				},
				'my-progress': {
					key: 'mode:course-my-progress',
					title: t('learning', 'Mein Fortschritt'),
					text: t('learning', 'Dieser Bereich fasst deinen eigenen Lernstand im Kurs zusammen. Sieh, wo sich Meisterschaft aufbaut und wo Lücken bleiben.'),
					shortText: t('learning', 'Mein Fortschritt zeigt deinen aktuellen Stand im Kurs.'),
				},
				summary: {
					key: 'mode:course-summary',
					title: t('learning', 'Kursabschluss'),
					text: t('learning', 'Dieser Bereich fasst deinen Kursfortschritt in eine Übersicht mit Meisterschaft, Streaks, Badges und offenen Fragen zusammen.'),
					shortText: t('learning', 'Kursabschluss zeigt deinen zusammengefassten Stand für den Kurs.'),
				},
			}
			return guides[tabId] || null
		},
		megaTabForLeaf(leafId) {
			if (this.lernraumLeafTabs.includes(leafId)) return 'lernraum'
			if (this.teilnehmerLeafTabs.includes(leafId)) return 'teilnehmer'
			if (this.wettbewerbLeafTabs.includes(leafId)) return 'wettbewerb'
			if (this.kommunikationLeafTabs.includes(leafId)) return 'kommunikation'
			if (this.verwaltungLeafTabs.includes(leafId)) return 'verwaltung'
			return null
		},
		selectMegaTab(megaTabId) {
			this.activeMegaTab = megaTabId
			const resolvedTabId = this.resolveSelectableTab(megaTabId)
			this.currentTab = resolvedTabId
			useOptionalCourseStore()?.setTab(resolvedTabId)
			this.pushCourseTabRoute(megaTabId)
			if (resolvedTabId !== 'arena') {
				this.arenaSubMode = null
			}
			if (resolvedTabId === 'arena' && !this.isInstructor) {
				useOptionalVirtuProfStore()?.trigger('arena-first-visit')
			}
			if (resolvedTabId === 'league' && !this.isInstructor) {
				useOptionalVirtuProfStore()?.trigger('liga-first-visit')
			}
		},
		/**
		 * Called by child mega-tab components when they change sub-tab.
		 * Receives a leaf tab ID, updates currentTab, syncs activeMegaTab, emits to App.vue.
		 */
		onLeafTabChange(leafTabId) {
			this.currentTab = leafTabId
			useOptionalCourseStore()?.setTab(leafTabId)
			this.pushCourseTabRoute(leafTabId)
			if (leafTabId !== 'arena') {
				this.arenaSubMode = null
			}
			if (leafTabId === 'arena' && !this.isInstructor) {
				useOptionalVirtuProfStore()?.trigger('arena-first-visit')
			}
			if (leafTabId === 'league' && !this.isInstructor) {
				useOptionalVirtuProfStore()?.trigger('liga-first-visit')
			}
		},
		selectTab(tabId) {
			const resolvedTabId = this.resolveSelectableTab(tabId)
			this.currentTab = resolvedTabId
			useOptionalCourseStore()?.setTab(resolvedTabId)
			this.pushCourseTabRoute(tabId)
			if (resolvedTabId !== 'arena') {
				this.arenaSubMode = null
			}
			if (resolvedTabId === 'arena' && !this.isInstructor) {
				useOptionalVirtuProfStore()?.trigger('arena-first-visit')
			}
			if (resolvedTabId === 'league' && !this.isInstructor) {
				useOptionalVirtuProfStore()?.trigger('liga-first-visit')
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

		async fetchCourseDetail() {
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}', { courseId: this.courseId })
				const response = await axios.get(url)
				this.course = {
					...response.data,
					mode_config: this.normalizeModeConfig(response.data?.mode_config || {}),
				}
				this.coursePools = response.data.pools || []
				this.courseMembers = response.data.members || []

				// Default tab for students
				if (!this.course.is_instructor) {
					this.currentTab = this.presetDuelCode ? 'arena' : 'training'
					this.activeMegaTab = this.presetDuelCode ? 'wettbewerb' : 'lernraum'
					this.arenaSubMode = this.presetDuelCode ? 'duel' : null
					this.activeLearningMode = 'training'
					this.selectedLearningPool = null
					this.fetchStudentProgress()
				} else if (this.presetDuelCode) {
					this.currentTab = 'arena'
					this.activeMegaTab = 'wettbewerb'
					this.arenaSubMode = 'duel'
				}
				this.syncCurrentTabFromRoute(this.currentRouteTab())
				this.$nextTick(() => {
					this.emitVirtuProfContext()
				})
			} catch (err) {
				console.error('Failed to fetch course detail:', err)
				if (err.response?.status === 404) {
					this.error = t('learning', 'Kurs nicht gefunden.')
				} else if (err.response?.status === 403) {
					this.error = t('learning', 'Du hast keinen Zugriff auf diesen Kurs.')
				} else {
					this.error = t('learning', 'Kursdetails konnten nicht geladen werden. Bitte erneut versuchen.')
				}
			} finally {
				this.loading = false
			}
		},

		async fetchStudentProgress() {
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/my-progress', { courseId: this.courseId })
				const response = await axios.get(url)
				const data = response.data
				let pools = []
				if (Array.isArray(data)) {
					pools = data
				} else if (data && data.pools) {
					pools = data.pools
				}
				this.studentProgress = pools.map(p => ({
					...p,
					mastery: p.total_questions > 0 ? Math.round((p.mastered || 0) / p.total_questions * 100) : 0,
				}))
			} catch (err) {
				console.error('Failed to fetch student progress:', err)
			}
		},
	},
}
</script>

<style scoped>
.course-detail {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

/* Header */
.course-detail-header {
	margin-bottom: 24px;
}

.header-title {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 8px;
}

.header-title h3 {
	margin: 0;
	font-size: 1.4em;
	font-weight: 700;
	color: var(--color-main-text);
}

.course-description {
	margin: 8px 0 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.95em;
	line-height: 1.5;
}

.status-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.03em;
	white-space: nowrap;
}

.status-badge.active {
	background: color-mix(in srgb, var(--color-success) 40%, transparent);
	color: var(--color-success);
}

.status-badge.archived {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

/* Loading */
.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
}

.loading-container p { margin-top: 12px; }

.detail-error {
	margin-bottom: 16px;
}

/* Mega-tab selector */
.mega-tab-selector {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 24px;
	border-bottom: 2px solid var(--color-border);
	padding-bottom: 0;
}

.mega-tab-button {
	padding: 12px 24px;
	border: none;
	background: none;
	cursor: pointer;
	font-size: 1em;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	border-bottom: 3px solid transparent;
	margin-bottom: -2px;
	transition: color 0.15s, border-color 0.15s;
	white-space: nowrap;
}

.mega-tab-button:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.mega-tab-button.active {
	color: var(--color-primary-element);
	border-bottom-color: var(--color-primary-element);
	font-weight: 600;
}

.mega-tab-countdown {
	margin-inline-start: auto;
}

/* Talk link in header */
.talk-room-link {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--color-primary);
	text-decoration: none;
	font-size: 0.9em;
	margin-top: 4px;
}
.talk-room-link:hover { text-decoration: underline; }

/* Responsive */
@media (max-width: 768px) {
	.course-detail {
		padding: 12px;
	}

	.mega-tab-selector {
		gap: 2px;
	}

	.mega-tab-button {
		padding: 8px 14px;
		font-size: 0.9em;
	}
}
</style>
