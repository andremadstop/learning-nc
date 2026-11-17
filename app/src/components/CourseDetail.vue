<template>
	<div class="course-detail">
		<!-- Header -->
		<div class="course-detail-header">
			<NcButton variant="tertiary" @click="$emit('back')">
				{{ t('learning', '\u2190 Zurück zu Kursen') }}
			</NcButton>
			<div class="header-title">
				<!-- GitHub #82: a course that could not be loaded has no title; "Laden..." stayed there. -->
				<h3 v-if="course || loading">{{ course ? course.title : t('learning', 'Laden...') }}</h3>
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

		<!-- GitHub #82: a link to a course that was deleted, never existed or is not (any longer) this
			person's. The server answers 404 for all of them, so there is one message, without the
			course's name, and a way on. A failed load (500, offline) says so and offers a retry. -->
		<DeadLinkNotice v-if="loadState === 'unavailable'"
			:title="t('learning', 'Dieser Kurs ist nicht verfügbar')"
			:text="t('learning', 'Der Link führt zu einem Kurs, den es nicht (mehr) gibt oder in dem du nicht (mehr) eingeschrieben bist.')"
			:hint="t('learning', 'Wenn du meinst, dass du Zugang haben solltest, frag deine Kursleitung.')"
			:primary-label="t('learning', 'Zurück zu meinen Kursen')"
			@primary="$emit('back')" />
		<DeadLinkNotice v-else-if="loadState === 'failed'"
			:title="t('learning', 'Der Kurs konnte gerade nicht geladen werden')"
			:text="t('learning', 'Kursdetails konnten nicht geladen werden. Bitte erneut versuchen.')"
			:primary-label="t('learning', 'Erneut versuchen')"
			:secondary-label="t('learning', 'Zurück zu meinen Kursen')"
			@primary="retryCourseLoad"
			@secondary="$emit('back')" />
		<!-- GitHub #72: what a tab reported (a failed save, …). It belongs to that tab: it can be
			closed and goes away when another tab is opened or the course is loaded again. -->
		<NcNoteCard v-else-if="actionError" type="error" class="detail-error" data-test="detail-action-error">
			<div class="detail-action-error">
				<span class="detail-action-error__text">{{ actionError }}</span>
				<NcButton variant="tertiary"
					size="small"
					data-test="detail-action-error-close"
					@click="dismissActionError">
					{{ t('learning', 'Meldung schließen') }}
				</NcButton>
			</div>
		</NcNoteCard>

		<!-- GitHub #82: an archived course still opens; it says what that means for this viewer. -->
		<NcNoteCard v-if="!loading && course && course.status === 'archived'"
			type="info"
			class="detail-archived"
			data-test="course-archived">
			<!-- The reactivation menu of the course list (CourseList.vue) is only on the courses a
				person created, and only in the instructor role; a co-instructor has none. -->
			{{ !actsAsInstructor
				? t('learning', 'Dieser Kurs ist archiviert. Du kannst die Inhalte weiter ansehen. Wenn du Fragen zum Kurs hast, wende dich an deine Kursleitung.')
				: canReactivateCourse
					? t('learning', 'Dieser Kurs ist archiviert. Lernende sehen ihn weiterhin; über das Menü in der Kursliste lässt er sich wieder aktivieren.')
					: t('learning', 'Dieser Kurs ist archiviert. Lernende sehen ihn weiterhin; wieder aktivieren kann ihn die Person, die ihn angelegt hat.') }}
		</NcNoteCard>

		<!-- GitHub #82: the link named an area of this course the viewer does not have (any more):
			an instructor tab after losing the role, a switched-off mode, a mistyped address. The
			course opens on another area, and says why instead of switching without a word. -->
		<div v-if="!loading && course && tabUnavailable"
			role="status"
			class="detail-tab-unavailable"
			data-test="tab-unavailable">
			<NcNoteCard type="info">
				<div class="detail-action-error">
					<span class="detail-action-error__text">{{ t('learning', 'Der Link führt zu einem Bereich dieses Kurses, den es für dich nicht (mehr) gibt. Du siehst deshalb einen anderen Bereich des Kurses.') }}</span>
					<NcButton variant="tertiary"
						size="small"
						data-test="tab-unavailable-close"
						@click="tabUnavailable = false">
						{{ t('learning', 'Meldung schließen') }}
					</NcButton>
				</div>
			</NcNoteCard>
		</div>

		<template v-if="!loading && course">
			<!-- Mega-tab selector: the app-wide tab look (#46), the countdown beside it, not in the tablist -->
			<div class="mega-tab-row">
				<div class="mega-tab-selector lnc-tablist"
					role="tablist"
					:aria-label="t('learning', 'Kursbereiche')"
					@keydown="moveTablistFocus">
					<button v-for="tab in visibleMegaTabs"
						:key="tab.id"
						type="button"
						class="mega-tab-button lnc-tab"
						:class="{ active: activeMegaTab === tab.id }"
						role="tab"
						:aria-selected="activeMegaTab === tab.id ? 'true' : 'false'"
						@click="selectMegaTab(tab.id)">
						{{ tab.label }}
						<!-- GitHub #59: instructors see at the tab that questions wait for an answer -->
						<span v-if="tab.id === 'kommunikation' && isInstructor && openQuestionsCount > 0" class="mega-tab-count">
							<span aria-hidden="true">{{ openQuestionsCount }}</span>
							<span class="hidden-visually">{{ t('learning', 'Unbeantwortete Fragen: {count}', { count: openQuestionsCount }) }}</span>
						</span>
					</button>
				</div>
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
					:acts-as-instructor="actsAsInstructor"
					:course-pools="coursePools"
					:all-pools="allPools"
					:active-tab="currentTab"
					:content-language="contentLanguage"
					:fsrs-detailed-stats="fsrsDetailedStats"
					:admin-enabled-tools="adminEnabledTools"
					:ai-enabled="adminAiEnabled"
					:gamification-enabled="gamificationEnabled"
					@all-pools-loaded="allPools = $event"
					@error="actionError = $event"
					@knowledge-pending-count="knowledgePendingCount = $event"
					@mode-activated="activeLearningMode = $event"
					@openPool="$emit('openPool', $event)"
					@open-tool="$emit('open-tool', $event)"
					@pool-selected="selectedLearningPool = $event"
					@refresh-course-detail="fetchCourseDetail"
					@course-patched="onCoursePatched"
					@tab-change="onLeafTabChange"
					@videos-available="onVideosAvailable" />
			</div>

			<CourseTabTeilnehmer
				v-if="activeMegaTab === 'teilnehmer'"
				:course-id="courseId"
				:course="course"
				:user-role="userRole"
				:acts-as-instructor="actsAsInstructor"
				:course-members="courseMembers"
				:course-pools="coursePools"
				:active-tab="currentTab"
				:gamification-enabled="gamificationEnabled"
				@tab-change="onLeafTabChange"
				@error="actionError = $event"
				@members-changed="fetchCourseDetail"
				@selectStudent="$emit('selectStudent', $event)" />

			<!-- GitHub #36: the learners' completion area — summary with the certificate, then the recent sessions. -->
			<div v-if="activeMegaTab === 'abschluss' && !isInstructor" class="abschluss-mega-tab">
				<CourseSummary
					:course-id="courseId"
					:course-name="course.title || ''"
					:course="course"
					:gamification-enabled="gamificationEnabled"
					:ai-enabled="adminAiEnabled" />
				<!-- GitHub #63: an instructor in the student view has no learner record (404). -->
				<NcNoteCard v-if="previewsAsStudent" type="info" class="student-preview-note">
					{{ t('learning', 'Schüler-Ansicht: Lernende sehen hier ihren eigenen Lernfortschritt. Du bist in diesem Kurs Lehrkraft und hast deshalb keinen eigenen.') }}
				</NcNoteCard>
				<CourseRecentSessions
					v-else-if="myUserId"
					:course-id="courseId"
					:student-id="myUserId"
					:show-pool-accuracy="!learnerHasProgressTab" />
			</div>

			<CourseTabWettbewerb
				v-if="activeMegaTab === 'wettbewerb'"
				:course-id="courseId"
				:course="course"
				:user-role="userRole"
				:acts-as-instructor="actsAsInstructor"
				:course-pools="coursePools"
				:active-tab="currentTab"
				:content-language="contentLanguage"
				:preset-duel-code="presetDuelCode"
				:gamification-enabled="gamificationEnabled"
				@tab-change="onLeafTabChange"
				@error="actionError = $event"
				@arena-sub-mode="arenaSubMode = $event"
				@preset-consumed="$emit('clearPresetDuel')"
				@selectStudent="$emit('selectStudent', $event)" />

			<CourseTabKommunikation
				v-if="activeMegaTab === 'kommunikation'"
				:course-id="courseId"
				:course="course"
				:user-role="userRole"
				:acts-as-instructor="actsAsInstructor"
				:active-tab="currentTab"
				:ai-enabled="adminAiEnabled"
				:open-questions-count="openQuestionsCount"
				@tab-change="onLeafTabChange"
				@open-questions-count="openQuestionsCount = $event"
				@error="actionError = $event"
				@refresh-course-detail="fetchCourseDetail" />

			<CourseTabVerwaltung
				v-if="activeMegaTab === 'verwaltung' && isInstructor"
				:course-id="courseId"
				:course="course"
				:course-pools="coursePools"
				:user-role="userRole"
				:acts-as-instructor="actsAsInstructor"
				:active-tab="currentTab"
				:gamification-enabled="gamificationEnabled"
				@tab-change="onLeafTabChange"
				@error="actionError = $event"
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
import DeadLinkNotice from './DeadLinkNotice.vue'
import ExamReadiness from './ExamReadiness.vue'
import CourseSummary from './CourseSummary.vue'
import CourseRecentSessions from './CourseRecentSessions.vue'
import { useOptionalCourseStore } from '../stores/courseStore.js'
import { useOptionalVirtuProfStore } from '../stores/virtuProfStore.js'
import { courseToolIds } from '../utils/toolCatalog.js'
import { moveTablistFocus } from '../utils/tablistKeydown.js'
import { abschlussTabIds, actsAsCourseInstructor, LEGACY_TAB_ALIASES, kommunikationTabIds, previewsCourseAsStudent, learnerHasProgressTab, lernraumTabIds, teilnehmerTabIds, wettbewerbTabIds } from '../utils/courseView.js'

// Every leaf the Wettbewerb area can have; a deep link to one that is hidden for this viewer
// falls back to the Lernraum (GitHub #36).
const WETTBEWERB_LEAVES = ['leaderboard', 'league', 'arena', 'abenteuer']
// Every leaf the Teilnehmer area can have, for both roles. A learner's bookmark on one that is
// gone (class register switched off, progress moved to the completion area) is redirected
// (GitHub #36) instead of opening an empty area.
const TEILNEHMER_LEAVES = ['members', 'classbook', 'progress', 'heatmap', 'weak-questions', 'class-profile', 'my-progress', 'summary']
// Every leaf the Kommunikation area can have, for both roles. A bookmark on one this viewer does
// not have (learning partners off, AI off, an instructor leaf) falls back to the feed (GitHub #40).
const KOMMUNIKATION_LEAVES = ['announcements', 'feed', 'my-questions', 'buddies', 'schwarm', 'requests']
const MEGA_TAB_IDS = ['lernraum', 'teilnehmer', 'abschluss', 'wettbewerb', 'kommunikation', 'verwaltung']
// Every leaf the Lernraum can have, for both roles (lernraumTabIds).
const LERNRAUM_LEAVES = ['pools', 'tools', 'curriculum', 'materials', 'knowledge', 'training', 'leitner', 'exam', 'practice']
// The course administration, instructors only.
const VERWALTUNG_LEAVES = ['mode-config', 'schedule']
// GitHub #82: every leaf there is; a tab segment outside it is a mistyped or outdated address.
const ALL_LEAVES = [...LERNRAUM_LEAVES, ...TEILNEHMER_LEAVES, ...WETTBEWERB_LEAVES, ...KOMMUNIKATION_LEAVES, ...VERWALTUNG_LEAVES]

export default {
	name: 'CourseDetail',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		DeadLinkNotice,
		ExamReadiness,
		CourseSummary,
		CourseRecentSessions,
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
		// GitHub #35: an instructor previewing the app as a student (App.vue studentViewActive).
		studentViewActive: {
			type: Boolean,
			default: false,
		},
		// GitHub #36: the admin's global gamification switch (App.vue). Default on = as before.
		gamificationEnabled: {
			type: Boolean,
			default: true,
		},
	},

	data() {
		return {
			loading: false,
			// GitHub #82: '' (loaded or loading), 'unavailable' (404: gone, never there, no access)
			// or 'failed' (any other error: the link may be fine, the load was not)
			loadState: '',
			actionError: '',
			// GitHub #82: the address named a course area this viewer does not have
			tabUnavailable: false,
			course: null,
			coursePools: [],
			courseMembers: [],
			activeMegaTab: 'lernraum',
			currentTab: 'pools',
			knowledgePendingCount: 0,
			// GitHub #59: unanswered learner questions (instructors only)
			openQuestionsCount: 0,
			selectedLearningPool: null,
			activeLearningMode: null,
			allPools: [],
			// The admin's global tool selection; null until loaded or when unreadable (= all).
			adminEnabledTools: null,
			// GitHub #35: the admin's AI switch (GET /api/settings/tools); unknown counts as off
			adminAiEnabled: false,

			// Arena sub-mode (received from Wettbewerb child for VirtuProf context)
			arenaSubMode: null,

			// Reported by CourseTabLernraum once it knows whether required content exists.
			hasCourseVideos: false,
		}
	},

	computed: {
		/**
		 * GitHub #35: the one gate for every instructor tab in the course, handed to each mega-tab
		 * as actsAsInstructor. The course membership decides (never the global role), and the
		 * student view turns it off. That view is a UI preview only: the server keeps enforcing
		 * permissions from the real membership, so nothing here grants or removes a right.
		 */
		actsAsInstructor() {
			return actsAsCourseInstructor(this.course, this.studentViewActive)
		},
		isInstructor() {
			return this.actsAsInstructor
		},
		// GitHub #63: see previewsCourseAsStudent()
		previewsAsStudent() {
			return previewsCourseAsStudent(this.course, this.actsAsInstructor)
		},
		talkRoomUrl() {
			const token = String(this.course?.talk_room_token || '').trim()
			if (!token) return ''
			// Talk's route is /call/{token} at the server root; generateUrl adds the webroot and
			// index.php where the instance needs them (audit #92). '/apps/spreed/#/call/' was
			// absolute from the domain root and 404'd under a subpath.
			return generateUrl('/call/{token}', { token })
		},
		hasTalkRoomLink() {
			return this.talkRoomUrl.length > 0
		},
		myUserId() {
			const user = getCurrentUser()
			return user ? user.uid : null
		},
		// GitHub #82: the course list offers "Activate" only on the courses this person created,
		// and only in the instructor role (CourseList.vue, own courses).
		canReactivateCourse() {
			return this.userRole === 'instructor'
				&& !!this.myUserId
				&& String(this.course?.instructor_id || '') === String(this.myUserId)
		},
		isCourseSummaryReleased() {
			return this.course?.mode_config?.course_summary === true
		},
		isStudentLearningTab() {
			return !this.isInstructor && ['training', 'leitner', 'exam'].includes(this.currentTab)
		},
		hasCourseTools() {
			return courseToolIds(this.course, this.adminEnabledTools).length > 0
		},
		// Same list as the Kommunikation sub-navigation (one helper, GitHub #40).
		kommunikationLeafTabs() {
			return kommunikationTabIds(this.course, { actsAsInstructor: this.isInstructor, aiEnabled: this.adminAiEnabled })
		},
		verwaltungLeafTabs() {
			// GitHub #75: the required content moved into the learning materials (LEGACY_TAB_ALIASES).
			return ['mode-config', 'schedule']
		},
		// Same list as the Lernraum sub-navigation (one helper), so a disabled Training is not
		// routed to and the required content (videos) has a leaf of its own.
		lernraumLeafTabs() {
			return lernraumTabIds(this.course, {
				actsAsInstructor: this.isInstructor,
				hasCourseTools: this.hasCourseTools,
				hasVideos: this.hasCourseVideos,
				aiEnabled: this.adminAiEnabled,
			})
		},
		// Same list as the Wettbewerb sub-navigation (one helper, GitHub #36).
		wettbewerbLeafTabs() {
			return wettbewerbTabIds(this.course, {
				actsAsInstructor: this.isInstructor,
				gamificationEnabled: this.gamificationEnabled,
			})
		},
		// Same list as the Teilnehmer sub-navigation (one helper, GitHub #36).
		teilnehmerLeafTabs() {
			return teilnehmerTabIds(this.course, {
				actsAsInstructor: this.isInstructor,
				gamificationEnabled: this.gamificationEnabled,
			})
		},
		// GitHub #36: learners get the completion tab as an area of its own.
		// GitHub #36: without "Mein Fortschritt" its accuracy per pool shows in the completion area
		learnerHasProgressTab() {
			return learnerHasProgressTab(this.course, this.gamificationEnabled)
		},
		abschlussLeafTabs() {
			return abschlussTabIds(this.course, { actsAsInstructor: this.isInstructor })
		},
		visibleMegaTabs() {
			if (this.isInstructor) {
				// GitHub #51/#53: Wettbewerb follows the same leaf list as for learners.
				return [
					{ id: 'lernraum', label: t('learning', 'Lernraum') },
					{ id: 'teilnehmer', label: t('learning', 'Teilnehmer') },
					...(this.wettbewerbLeafTabs.length > 0 ? [{ id: 'wettbewerb', label: t('learning', 'Wettbewerb') }] : []),
					{ id: 'kommunikation', label: t('learning', 'Kommunikation') },
					{ id: 'verwaltung', label: t('learning', 'Verwaltung') },
				]
			}
			const tabs = [{ id: 'lernraum', label: t('learning', 'Lernraum') }]
			// GitHub #36: with the class register off and nothing left in "Mein Fortschritt" the
			// participants area is empty for learners and disappears.
			if (this.teilnehmerLeafTabs.length > 0) {
				tabs.push({ id: 'teilnehmer', label: t('learning', 'Teilnehmer') })
			}
			if (this.abschlussLeafTabs.length > 0) {
				tabs.push({
					id: 'abschluss',
					label: this.course?.cert_enabled ? t('learning', 'Abschluss & Zertifikat') : t('learning', 'Abschluss'),
				})
			}
			// GitHub #36: without gamification the leaderboard is gone; with league, arena and
			// adventure switched off too nothing is left, and the area disappears.
			if (this.wettbewerbLeafTabs.length > 0) {
				tabs.push({ id: 'wettbewerb', label: t('learning', 'Wettbewerb') })
			}
			tabs.push({ id: 'kommunikation', label: t('learning', 'Kommunikation') })
			return tabs
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
		// GitHub #59: leaving the student view shows the badge again
		actsAsInstructor(on) {
			if (on) {
				this.fetchOpenQuestionsCount()
			}
		},
		$route(route) {
			if (route && Number(route.params?.id || 0) === Number(this.courseId || 0)) {
				// GitHub #82: an address change is a link; a hidden area it names gets a note
				this.syncCurrentTabFromRoute(route.params?.tab || '', true)
			}
		},
		visibleTabs(tabs) {
			const ids = tabs.map((t) => t.id)
			const isCoveredByMega = this.isLernraumTab(this.currentTab)
				|| this.isTeilnehmerTab(this.currentTab)
				|| this.isAbschlussTab(this.currentTab)
				|| this.isWettbewerbTab(this.currentTab)
				|| this.isKommunikationTab(this.currentTab)
				|| this.isVerwaltungTab(this.currentTab)
			const isVisibleMega = ids.includes('lernraum') && this.isLernraumTab(this.currentTab)
				|| ids.includes('teilnehmer') && this.isTeilnehmerTab(this.currentTab)
				|| ids.includes('abschluss') && this.isAbschlussTab(this.currentTab)
				|| ids.includes('wettbewerb') && this.isWettbewerbTab(this.currentTab)
				|| ids.includes('kommunikation') && this.isKommunikationTab(this.currentTab)
				|| ids.includes('verwaltung') && this.isVerwaltungTab(this.currentTab)
			if (!isVisibleMega && !isCoveredByMega) {
				this.currentTab = this.defaultLernraumTab()
				this.activeMegaTab = 'lernraum'
				return
			}
			// GitHub #36: the same leaf can live in another area for the new viewer (summary under
			// Teilnehmer for instructors, its own area in the student view); the area follows it.
			const mega = this.megaTabForLeaf(this.currentTab)
			if (mega && mega !== this.activeMegaTab && ids.includes(mega)) {
				this.activeMegaTab = mega
			}
		},
		courseId: {
			immediate: true,
			handler(newId) {
				this.tabUnavailable = false
				// GitHub #82: /courses/abc or /courses/0 names no course at all. A load still
				// running for the course before is dropped when it answers (fetchCourseDetail).
				if (!Number.isInteger(newId) || newId <= 0) {
					this.course = null
					this.loading = false
					this.loadState = 'unavailable'
					return
				}
				// a newly opened course came from a link (notification, bookmark, course card)
				this._announceHiddenTab = true
				this.fetchCourseDetail()
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
		moveTablistFocus,
		currentRouteTab() {
			if (!this.$route || Number(this.$route.params?.id || 0) !== Number(this.courseId || 0)) {
				return ''
			}
			return String(this.$route.params?.tab || '').trim()
		},
		/**
		 * @param {string} tabId the tab segment of the address
		 * @param {boolean} fromLink GitHub #82: the address came from a link (a course opened, the
		 *   address changed), not from a reload of the course data; a hidden area it names gets a note
		 */
		syncCurrentTabFromRoute(tabId = '', fromLink = false) {
			if (!tabId) {
				return
			}
			const requested = LEGACY_TAB_ALIASES[String(tabId)] ?? String(tabId)
			// GitHub #82: a leaf this viewer does not have, or no leaf at all (a mistyped address),
			// left the course on an area without content. 'materials' is left out: whether a
			// learner has it is only known once the Lernraum reports its required content
			// (onVideosAvailable falls back then).
			const hiddenLernraumLeaf = LERNRAUM_LEAVES.includes(requested)
				&& requested !== 'materials'
				&& !this.isLernraumTab(requested)
			const hiddenVerwaltungLeaf = VERWALTUNG_LEAVES.includes(requested) && !this.isInstructor
			const unknownTab = !MEGA_TAB_IDS.includes(requested) && !ALL_LEAVES.includes(requested)
			if (hiddenLernraumLeaf || hiddenVerwaltungLeaf || unknownTab) {
				this.redirectHiddenTab(requested)
				this.tabUnavailable = fromLink
				return
			}
			// GitHub #36: a bookmark on an area or competition leaf this viewer does not have
			// (gamification off, mode switched off) lands on the Lernraum, not on an empty area.
			const hiddenMega = MEGA_TAB_IDS.includes(requested)
				&& !this.visibleMegaTabs.some((tab) => tab.id === requested)
			const hiddenWettbewerbLeaf = WETTBEWERB_LEAVES.includes(requested) && !this.isWettbewerbTab(requested)
			const hiddenTeilnehmerLeaf = TEILNEHMER_LEAVES.includes(requested)
				&& !this.isTeilnehmerTab(requested)
				&& !this.isAbschlussTab(requested)
			const hiddenKommunikationLeaf = KOMMUNIKATION_LEAVES.includes(requested) && !this.isKommunikationTab(requested)
			if (hiddenMega || hiddenWettbewerbLeaf || hiddenTeilnehmerLeaf || hiddenKommunikationLeaf) {
				const relocated = this.redirectHiddenTab(requested)
				// GitHub #82: the area is gone for this viewer; say so. Not when its content only
				// moved (GitHub #36: participants and "Mein Fortschritt" into the completion area).
				this.tabUnavailable = fromLink && !relocated
				return
			}
			const resolvedTabId = this.resolveSelectableTab(requested)
			this.currentTab = resolvedTabId
			if (MEGA_TAB_IDS.includes(requested)) {
				this.activeMegaTab = requested
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
		/**
		 * GitHub #36: where a bookmark on an area or leaf this viewer does not have lands. What
		 * moved goes to its new place — the participants area and "Mein Fortschritt" to the
		 * completion area (the recent sessions live there), a switched-off class register to the
		 * remaining participants leaf, a hidden communication leaf to the feed (GitHub #40);
		 * everything else to the Lernraum.
		 *
		 * @param {string} requested the requested area or leaf
		 * @return {boolean} true when the content only moved (its new place is shown)
		 */
		redirectHiddenTab(requested) {
			const moved = requested === 'teilnehmer' || requested === 'my-progress'
			if (moved && this.abschlussLeafTabs.length > 0) {
				this.currentTab = this.abschlussLeafTabs[0]
				this.activeMegaTab = 'abschluss'
				return true
			}
			if (TEILNEHMER_LEAVES.includes(requested) && this.teilnehmerLeafTabs.length > 0) {
				this.currentTab = this.teilnehmerLeafTabs[0]
				this.activeMegaTab = 'teilnehmer'
				return false
			}
			if (KOMMUNIKATION_LEAVES.includes(requested) && this.kommunikationLeafTabs.length > 0) {
				this.currentTab = this.kommunikationLeafTabs[0]
				this.activeMegaTab = 'kommunikation'
				return false
			}
			this.currentTab = this.defaultLernraumTab()
			this.activeMegaTab = 'lernraum'
			return false
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
		isAbschlussTab(tabId) {
			return this.abschlussLeafTabs.includes(tabId)
		},
		isTabActive(tabId) {
			if (tabId === 'lernraum') return this.isLernraumTab(this.currentTab)
			if (tabId === 'kommunikation') return this.isKommunikationTab(this.currentTab)
			if (tabId === 'verwaltung') return this.isVerwaltungTab(this.currentTab)
			if (tabId === 'wettbewerb') return this.isWettbewerbTab(this.currentTab)
			if (tabId === 'teilnehmer') return this.isTeilnehmerTab(this.currentTab)
			if (tabId === 'abschluss') return this.isAbschlussTab(this.currentTab)
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
			if (tabId === 'abschluss') {
				return this.abschlussLeafTabs[0]
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
				// GitHub #40: without the id a course question fell back to the admin inbox
				courseId: this.courseId,
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
					// Fresh-eyes C3 (#35): in a certifying course the exam is the real one, not a
					// "simulated test run"; the text there matches the App's course exam guide.
					text: this.course?.cert_enabled
						? t('learning', 'Prüfungsmodus: keine Hinweise, ein Zeitlimit und dein Ergebnis erst am Ende. Vergibt der Kurs ein Zertifikat, siehst du vor dem Start, ob die Prüfung dafür zählt. Ein Probedurchlauf zählt nie.')
						: t('learning', 'Der Prüfungsmodus verbirgt Feedback bis zum Ende, damit du einen echten Testlauf simulieren kannst. Nutze ihn, wenn du Druck, Tempo und ein klareres Ergebnis willst.'),
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
					// GitHub #36: without gamification there are no streaks and badges to name
					text: this.gamificationEnabled
						? t('learning', 'Dieser Bereich fasst deinen Kursfortschritt in eine Übersicht mit Meisterschaft, Streaks, Badges und offenen Fragen zusammen.')
						: t('learning', 'Dieser Bereich fasst deinen Kursfortschritt in eine Übersicht mit Sessions, Genauigkeit und offenen Fragen zusammen.'),
					shortText: t('learning', 'Kursabschluss zeigt deinen zusammengefassten Stand für den Kurs.'),
				},
			}
			return guides[tabId] || null
		},
		megaTabForLeaf(leafId) {
			if (this.lernraumLeafTabs.includes(leafId)) return 'lernraum'
			if (this.teilnehmerLeafTabs.includes(leafId)) return 'teilnehmer'
			if (this.abschlussLeafTabs.includes(leafId)) return 'abschluss'
			if (this.wettbewerbLeafTabs.includes(leafId)) return 'wettbewerb'
			if (this.kommunikationLeafTabs.includes(leafId)) return 'kommunikation'
			if (this.verwaltungLeafTabs.includes(leafId)) return 'verwaltung'
			return null
		},
		/**
		 * The close button disappears with the note it sits in. Without a new target the focus
		 * would fall to <body> and the next Tab start at the top of the document (WCAG 2.4.3):
		 * it moves to the tab the message belongs to before the note goes.
		 */
		dismissActionError() {
			const activeTab = this.$el?.querySelector?.('.mega-tab-button.active')
			if (activeTab) {
				activeTab.focus()
			}
			this.actionError = ''
		},
		selectMegaTab(megaTabId) {
			this.actionError = ''
			this.tabUnavailable = false
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
			this.actionError = ''
			this.tabUnavailable = false
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
		/**
		 * CourseTabLernraum reports whether required content (Pflichtinhalte) exists: after its
		 * fetch, and false when it unmounts. A leaf that is gone falls back to the default, so
		 * currentTab never names a sub-tab the Lernraum does not show — a click on that sub-tab
		 * would then change nothing.
		 */
		/**
		 * GitHub #75: the switches of the required content (learning materials) change two course
		 * fields; a full reload would remount the tab the instructor is working in.
		 */
		onCoursePatched(patch) {
			if (!this.course || !patch || typeof patch !== 'object') {
				return
			}
			const allowed = ['video_gate_enabled', 'reconfirm_per_cycle']
			const next = { ...this.course }
			for (const key of allowed) {
				if (key in patch) {
					next[key] = patch[key] === true
				}
			}
			this.course = next
		},
		onVideosAvailable(available) {
			this.hasCourseVideos = available === true
			if (!this.isInstructor && this.currentTab === 'materials' && !this.isLernraumTab('materials')) {
				this.currentTab = this.defaultLernraumTab()
			}
		},
		selectTab(tabId) {
			this.actionError = ''
			this.tabUnavailable = false
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
				tools: true,
				classbook: true,
				buddies: true,
				linkedin: true,
			}, modeConfig || {})
		},

		// Codeberg #7: the course view is where tools appear, so it has to honour the admin's
		// global selection too. A failed read must not cost the course view — it falls back to
		// "no global restriction", which is what the setting's absence means.
		async fetchAdminEnabledTools() {
			try {
				const response = await axios.get(generateUrl('/apps/learning/api/settings/tools'))
				this.adminEnabledTools = Array.isArray(response.data?.enabled_tools) ? response.data.enabled_tools : null
				this.adminAiEnabled = response.data?.ai_enabled === true
			} catch (e) {
				this.adminEnabledTools = null
				// The knowledge import and the text extraction only serve the AI assistant: a failed
				// read hides them instead of offering them on a server where AI may be off.
				this.adminAiEnabled = false
			}
		},
		// GitHub #59: the badge at "Kommunikation"; learners and the student view never ask
		async fetchOpenQuestionsCount() {
			if (!this.isInstructor || !this.courseId) {
				return
			}
			const courseId = this.courseId
			try {
				const res = await axios.get(generateUrl('/apps/learning/api/courses/{courseId}/support-tickets/open-count', { courseId }))
				const count = res.data?.open_count
				if (courseId === this.courseId && Number.isInteger(count)) {
					this.openQuestionsCount = count
				}
			} catch (e) {
				// only a hint at the tab; the tab itself loads its list
				console.error('Failed to load the open question count', e)
			}
		},

		// GitHub #82: "Erneut versuchen" after a failed load still opens the link the person came by
		retryCourseLoad() {
			this._announceHiddenTab = true
			this.fetchCourseDetail()
		},

		async fetchCourseDetail() {
			// GitHub #82: the course this load is for. The component stays mounted while the
			// address moves to another course (or to /courses/abc); a late answer for the course
			// before must not fill the page under the new address.
			const requestedId = this.courseId
			const announceHiddenTab = this._announceHiddenTab === true
			this._announceHiddenTab = false
			this.loading = true
			this.loadState = ''
			this.actionError = ''
			// The Lernraum unmounts while loading and reports its required content again after the
			// remount; a stale flag would route learners to a videos leaf it does not show yet.
			this.hasCourseVideos = false
			this.openQuestionsCount = 0
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}', { courseId: this.courseId })
				const [response] = await Promise.all([axios.get(url), this.fetchAdminEnabledTools()])
				if (requestedId !== this.courseId) {
					return
				}
				this.course = {
					...response.data,
					mode_config: this.normalizeModeConfig(response.data?.mode_config || {}),
				}
				this.coursePools = response.data.pools || []
				this.courseMembers = response.data.members || []

				// Default tab for students (and for an instructor in the student view)
				if (!this.isInstructor) {
					this.currentTab = this.presetDuelCode ? 'arena' : this.defaultLernraumTab()
					this.activeMegaTab = this.presetDuelCode ? 'wettbewerb' : 'lernraum'
					this.arenaSubMode = this.presetDuelCode ? 'duel' : null
					this.activeLearningMode = 'training'
					this.selectedLearningPool = null
				} else if (this.presetDuelCode) {
					this.currentTab = 'arena'
					this.activeMegaTab = 'wettbewerb'
					this.arenaSubMode = 'duel'
				}
				this.syncCurrentTabFromRoute(this.currentRouteTab(), announceHiddenTab)
				this.fetchOpenQuestionsCount()
				this.$nextTick(() => {
					this.emitVirtuProfContext()
				})
			} catch (err) {
				if (requestedId !== this.courseId) {
					return
				}
				// GitHub #82: 404 is every "not yours to see" (the server never says which); 403 is
				// treated the same so that no answer tells a stranger the course exists. The course
				// shown so far goes: a reload after being removed must not keep showing it.
				const status = err.response?.status
				if (status === 404 || status === 403) {
					this.course = null
					this.loadState = 'unavailable'
				} else {
					console.error('Failed to fetch course detail:', err)
					this.loadState = 'failed'
				}
			} finally {
				if (requestedId === this.courseId) {
					this.loading = false
				}
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

/* GitHub #59: unanswered learner questions at the Kommunikation tab */
.mega-tab-count {
	display: inline-block;
	min-width: 1.5em;
	margin-inline-start: 6px;
	padding: 0 6px;
	border-radius: 10px;
	background: var(--color-primary-element, var(--color-primary));
	color: var(--color-primary-element-text, var(--color-primary-text));
	font-size: 0.8em;
	font-weight: 600;
	line-height: 1.5;
	text-align: center;
}
/* on the active tab (primary background) the bubble is inverted so it stays readable */
.mega-tab-button.active .mega-tab-count {
	background: var(--color-primary-element-text, var(--color-main-background));
	color: var(--color-primary-element, var(--color-primary));
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
	background: var(--color-success);
	color: var(--color-success-text, var(--color-success));
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

.detail-error,
.detail-archived,
.detail-tab-unavailable {
	margin-bottom: 16px;
}

.detail-action-error {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.detail-action-error__text {
	flex: 1 1 16em;
}

/* Mega-tab selector — look and width: .lnc-tablist / .lnc-tab in styles/tabs.css (#46) */
.mega-tab-row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	margin-bottom: 24px;
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
}
</style>
