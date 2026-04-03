<template>
  <NcAppContent id="app-learning">
    <div class="app-shell" :class="{ 'app-shell--with-virtuprof': showVirtuProfDock }">
      <div class="app-main">
        <div class="app-content-header">
          <h2>{{ userRole === 'student' ? t('learning', 'Learning') : t('learning', 'Learning - Spaced Repetition') }}</h2>
        </div>

        <!-- Top-level navigation -->
        <div class="main-nav" role="tablist" @keydown="handleTablistKeydown">
          <button
            v-if="userRole === 'student'"
            :class="['main-nav-btn', { active: mainView === 'dashboard' }]"
            role="tab"
            :aria-selected="mainView === 'dashboard' ? 'true' : 'false'"
            @click="switchMainView('dashboard')"
          >
            {{ t('learning', 'Heute') }}
          </button>
          <button
            :class="['main-nav-btn', { active: mainView === 'courses' }]"
            role="tab"
            :aria-selected="mainView === 'courses' ? 'true' : 'false'"
            @click="switchMainView('courses')"
          >
            {{ t('learning', 'Kurse') }}
          </button>
          <button
            v-if="userRole === 'student'"
            :class="['main-nav-btn', { active: mainView === 'pools' }]"
            role="tab"
            :aria-selected="mainView === 'pools' ? 'true' : 'false'"
            @click="switchMainView('pools')"
          >
            {{ t('learning', 'Pools') }}
          </button>
          <button
            :class="['main-nav-btn', { active: mainView === 'werkzeuge' }]"
            role="tab"
            :aria-selected="mainView === 'werkzeuge' ? 'true' : 'false'"
            @click="switchMainView('werkzeuge')"
          >
            {{ t('learning', 'Werkzeuge') }}
          </button>
          <button
            v-if="userRole === 'student' && showVirtuProfDock"
            :class="['main-nav-btn', { active: mainView === 'virtuprof-fullscreen' }]"
            role="tab"
            :aria-selected="mainView === 'virtuprof-fullscreen' ? 'true' : 'false'"
            @click="switchMainView('virtuprof-fullscreen')"
          >
            {{ t('learning', 'Erklärbot') }}
          </button>
          <button
            v-if="userRole === 'student'"
            :class="['main-nav-btn', { active: mainView === 'skillmap' }]"
            role="tab"
            :aria-selected="mainView === 'skillmap' ? 'true' : 'false'"
            @click="switchMainView('skillmap')"
          >
            {{ t('learning', 'Skill-Map') }}
          </button>
          <button
            :class="['main-nav-btn', { active: mainView === 'settings' }]"
            role="tab"
            :aria-selected="mainView === 'settings' ? 'true' : 'false'"
            @click="switchMainView('settings')"
          >
            {{ t('learning', 'Einstellungen') }}
          </button>
        </div>

        <router-view v-slot="{ route }">
          <!-- ==================== DASHBOARD VIEW (Student) ==================== -->
          <template v-if="route && route.name === 'dashboard'">
            <StudentDashboard
              @openSmartQueue="openSmartQueue"
              @openRemediation="openRemediation"
              @switchView="switchMainView"
            />
          </template>

          <!-- ==================== POOLS VIEW ==================== -->
          <template v-else-if="route && route.name === 'pools'">
            <SmartQueue
              v-if="currentView === 'smartQueue'"
              :mode="smartQueueMode"
              :contentLanguage="contentLanguage"
              @back="backToPools"
            />

            <AbenteuerMode
              v-else-if="currentView === 'abenteuer'"
              :contentLanguage="contentLanguage"
              :initial-coop-mode="adventureRoute.coop"
              :initial-coop-code="adventureRoute.code"
              @back="backToPools"
            />

            <PoolList
              v-else-if="currentView === 'pools'"
              :userRole="userRole"
              @selectPool="selectPool"
              @openSmartQueue="openSmartQueue"
              @openRemediation="openRemediation"
            />

            <div v-else-if="currentView === 'questions'" class="pool-view">
              <div class="pool-view-header">
                <NcButton type="tertiary" @click="poolFromCourse ? backToCourse() : backToPools()" :aria-label="poolFromCourse ? t('learning', '← Back to Course') : t('learning', 'Back to Pools')">
                  {{ poolFromCourse ? t('learning', '← Back to Course') : t('learning', '← Back to Pools') }}
                </NcButton>
                <h3 class="pool-title">{{ selectedPool.name }}</h3>
              </div>

              <!-- Read-only banner for shared pools -->
              <NcNoteCard v-if="poolPermission === 'read'" type="info">
                {{ t('learning', 'This pool is shared with you (view only)') }}
              </NcNoteCard>

              <div class="mode-selector" role="tablist" @keydown="handleTablistKeydown">
                <button
                  v-for="m in filteredModes"
                  :key="m.id"
                  @click="setMode(m.id)"
                  :class="['mode-btn', { active: mode === m.id }]"
                  role="tab"
                  :aria-selected="mode === m.id ? 'true' : 'false'"
                >
                  {{ m.label }}
                </button>
              </div>
              <p v-if="modeDescriptions[mode]" class="mode-description">{{ modeDescriptions[mode] }}</p>

              <!-- Error banner -->
              <NcNoteCard v-if="error" type="error">
                {{ error }}
                <template #icon>
                  <span></span>
                </template>
              </NcNoteCard>

              <TrainingMode
                v-if="mode === 'train'"
                :poolId="selectedPool.id"
                :totalQuestions="questionCount"
                :contentLanguage="contentLanguage"
                @back="backToPools"
              />

              <LeitnerMode
                v-else-if="mode === 'leitner'"
                :poolId="selectedPool.id"
                :contentLanguage="contentLanguage"
                :fsrsDetailedStats="fsrsDetailedStats"
                @back="setMode('train')"
              />

              <ExamMode
                v-else-if="mode === 'exam'"
                :poolId="selectedPool.id"
                :totalQuestions="questionCount"
                :contentLanguage="contentLanguage"
                @back="setMode('train')"
              />

              <template v-else-if="mode === 'gameshow'">
                <ArenaSelector
                  v-if="arenaSubMode === null"
                  @select-mode="onArenaSelectMode"
                />
                <DuelMode
                  v-else-if="arenaSubMode === 'duel'"
                  :initialPoolId="selectedPool.id"
                  :contentLanguage="contentLanguage"
                  @back="arenaSubMode = null"
                />
                <GameshowMode
                  v-else-if="arenaSubMode === 'sprint'"
                  :initialPoolId="selectedPool.id"
                  :contentLanguage="contentLanguage"
                  :mode="'sprint'"
                  @back="arenaSubMode = null"
                />
                <GameshowMode
                  v-else-if="arenaSubMode === 'elimination'"
                  :initialPoolId="selectedPool.id"
                  :contentLanguage="contentLanguage"
                  :mode="'elimination'"
                  @back="arenaSubMode = null"
                />
                <AbenteuerMode
                  v-else-if="arenaSubMode === 'abenteuer'"
                  :contentLanguage="contentLanguage"
                  @back="arenaSubMode = null"
                />
              </template>

              <AnalyticsDashboard
                v-else-if="mode === 'stats'"
                :poolId="selectedPool.id"
                @back="setMode('train')"
              />

              <QuestionList
                v-else-if="mode === 'manage'"
                :poolId="selectedPool.id"
                :poolName="selectedPool.name"
                :readonly="poolPermission === 'read'"
                @back="backToPools"
              />
            </div>
          </template>

          <!-- ==================== SETTINGS VIEW ==================== -->
          <template v-else-if="route && route.name === 'settings'">
            <!-- Instructor: sub-tabs for Kurs-Verwaltung + Meine Einstellungen -->
            <template v-if="userRole !== 'student'">
              <div class="course-sub-nav" role="tablist" @keydown="handleTablistKeydown">
                <button
                  :class="['mode-btn', { active: settingsSubTab === 'admin' }]"
                  role="tab"
                  :aria-selected="settingsSubTab === 'admin' ? 'true' : 'false'"
                  @click="settingsSubTab = 'admin'"
                >
                  {{ t('learning', 'Kurs-Verwaltung') }}
                </button>
                <button
                  :class="['mode-btn', { active: settingsSubTab === 'personal' }]"
                  role="tab"
                  :aria-selected="settingsSubTab === 'personal' ? 'true' : 'false'"
                  @click="settingsSubTab = 'personal'"
                >
                  {{ t('learning', 'Meine Einstellungen') }}
                </button>
              </div>
              <AdminSettings v-if="settingsSubTab === 'admin'" />
              <PersonalSettings
                v-else
                @content-language-changed="updateContentLanguage"
                @virtuprof-enabled-changed="updateVirtuProfEnabled"
                @fsrs-detailed-stats-changed="updateFsrsDetailedStats" />
            </template>
            <!-- Student: only PersonalSettings, no sub-tabs -->
            <PersonalSettings
              v-else
              @content-language-changed="updateContentLanguage"
              @virtuprof-enabled-changed="updateVirtuProfEnabled"
              @fsrs-detailed-stats-changed="updateFsrsDetailedStats" />
          </template>

          <!-- ==================== COURSES VIEW ==================== -->
          <template v-else-if="route && (route.name === 'courses' || route.name === 'course-tab')">
            <!-- Instructor sub-navigation: List | Dashboard -->
            <div v-if="userRole === 'instructor' && !selectedCourse" class="course-sub-nav" role="tablist" @keydown="handleTablistKeydown">
              <button
                :class="['mode-btn', { active: courseView === 'list' }]"
                role="tab"
                :aria-selected="courseView === 'list' ? 'true' : 'false'"
                @click="courseView = 'list'"
              >
                {{ t('learning', 'Course List') }}
              </button>
              <button
                :class="['mode-btn', { active: courseView === 'dashboard' }]"
                role="tab"
                :aria-selected="courseView === 'dashboard' ? 'true' : 'false'"
                @click="courseView = 'dashboard'"
              >
                {{ t('learning', 'Dashboard') }}
              </button>
            </div>

            <InstructorDashboard
              v-if="userRole === 'instructor' && !selectedCourse && courseView === 'dashboard'"
              @selectCourse="selectCourse"
            />

            <CourseList
              v-else-if="!selectedCourse"
              :userRole="userRole"
              @selectCourse="selectCourse"
            />

            <StudentDetail
              v-else-if="selectedStudent"
              :courseId="selectedStudent.courseId"
              :studentId="selectedStudent.userId"
              @back="selectedStudent = null"
            />

            <CourseDetail
              v-else
              :courseId="selectedCourse.id"
              :userRole="userRole"
              :contentLanguage="contentLanguage"
              :fsrsDetailedStats="fsrsDetailedStats"
              :presetDuelCode="pendingVirtuProfDuel.courseId === selectedCourse.id ? pendingVirtuProfDuel.duelCode : ''"
              @back="selectedCourse = null"
              @openPool="openPoolFromCourse"
              @open-tool="openCourseTool"
              @clearPresetDuel="clearVirtuProfDuel"
              @selectStudent="selectStudent"
            />
          </template>

          <!-- ==================== WERKZEUGE VIEW ==================== -->
          <template v-else-if="route && route.name === 'tools'">
            <NcNoteCard v-if="selectedCourse" type="info" class="tools-course-note">
              {{ t('learning', 'Diese Werkzeuge sind auch im Kurs "{title}" verfügbar.', { title: selectedCourse.title || t('learning', 'Kurs') }) }}
            </NcNoteCard>
            <div v-if="visibleToolsTabs.length" class="sim-nav" role="tablist" @keydown="handleTablistKeydown">
              <button
                v-for="tab in visibleToolsTabs"
                :key="tab.id"
                :class="['sim-nav__item', { 'sim-nav__item--active': toolsView === tab.id }]"
                role="tab"
                :aria-selected="toolsView === tab.id ? 'true' : 'false'"
                :aria-label="tab.label"
                @click="toolsView = tab.id"
              >
                <span class="sim-nav__icon" aria-hidden="true">{{ tab.icon }}</span>
                <span class="sim-nav__label">{{ tab.shortLabel }}</span>
              </button>
            </div>

            <SubnetCalculator v-if="toolsView === 'subnet'" />
            <DnsResolver v-else-if="toolsView === 'dns'" />
            <FirewallBuilder v-else-if="toolsView === 'firewall'" />
            <PortScanner v-else-if="toolsView === 'portscan'" />
            <RoutingTable v-else-if="toolsView === 'routing'" />
            <NatTable v-else-if="toolsView === 'nat'" />
            <WiresharkLite v-else-if="toolsView === 'wireshark'" />
            <AuthFlowSimulator v-else-if="toolsView === 'authflow'" />
          </template>

          <!-- ==================== SKILL-MAP VIEW ==================== -->
          <template v-else-if="route && route.name === 'skill-map'">
            <SkillMap @openPool="openPoolFromSkillMap" />
          </template>

          <template v-else-if="route && route.name === 'virtuprof'">
            <div class="virtuprof-fullscreen-view" />
          </template>
        </router-view>
      </div>

      <aside v-if="showVirtuProfDock" class="app-virtuprof-dock" aria-label="VirtuProf">
        <VirtuProf
          ref="virtuprof"
          :enabled="virtuProfEnabled"
          :user-role="userRole"
          :fullscreen-active="mainView === 'virtuprof-fullscreen'"
          @open-duel="openVirtuProfDuel"
          @open-fullscreen="openVirtuProfFullscreen"
          @close-fullscreen="closeVirtuProfFullscreen"
          @ready="triggerInitialVirtuProfHints"
          @enabled-change="handleVirtuProfEnabledChange" />
      </aside>

      <OnboardingIntro
        v-if="showInstructorOnboarding"
        role="instructor"
        @done="onInstructorOnboardingDone" />
    </div>
  </NcAppContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent';
import NcButton from '@nextcloud/vue/components/NcButton';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import PoolList from './components/PoolList.vue';
import QuestionList from './components/QuestionList.vue';
import TrainingMode from './components/TrainingMode.vue';
import LeitnerMode from './components/LeitnerMode.vue';
import ExamMode from './components/ExamMode.vue';
import AnalyticsDashboard from './components/AnalyticsDashboard.vue';
import CourseList from './components/CourseList.vue';
import CourseDetail from './components/CourseDetail.vue';
import StudentDetail from './components/StudentDetail.vue';
import InstructorDashboard from './components/InstructorDashboard.vue';
import SmartQueue from './components/SmartQueue.vue';
import AdminSettings from './components/AdminSettings.vue';
import PersonalSettings from './components/PersonalSettings.vue';
import GameshowMode from './components/GameshowMode.vue';
import DuelMode from './components/DuelMode.vue';
import ArenaSelector from './components/ArenaSelector.vue';
import AbenteuerMode from './components/AbenteuerMode.vue';
import SubnetCalculator from './components/SubnetCalculator.vue';
import DnsResolver from './components/DnsResolver.vue';
import FirewallBuilder from './components/FirewallBuilder.vue';
import PortScanner from './components/PortScanner.vue';
import RoutingTable from './components/RoutingTable.vue';
import NatTable from './components/NatTable.vue';
import WiresharkLite from './components/WiresharkLite.vue';
import AuthFlowSimulator from './components/AuthFlowSimulator.vue';
import OnboardingIntro from './components/OnboardingIntro.vue';
import SkillMap from './components/SkillMap.vue';
import StudentDashboard from './components/StudentDashboard.vue';
import { ALL_TOOL_IDS, TOOL_CATALOG } from './utils/toolCatalog.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { useOptionalCourseStore } from './stores/courseStore.js';
import { useOptionalVirtuProfStore } from './stores/virtuProfStore.js';

const VirtuProf = () => import('./components/VirtuProf.vue');

export default {
  name: 'App',
  components: {
    NcAppContent,
    NcButton,
    NcNoteCard,
    PoolList,
    QuestionList,
    TrainingMode,
    LeitnerMode,
    ExamMode,
    AnalyticsDashboard,
    CourseList,
    CourseDetail,
    StudentDetail,
    InstructorDashboard,
    SmartQueue,
    GameshowMode,
    DuelMode,
    ArenaSelector,
    AdminSettings,
    PersonalSettings,
    VirtuProf,
    AbenteuerMode,
    SubnetCalculator,
    DnsResolver,
    FirewallBuilder,
    PortScanner,
    RoutingTable,
    NatTable,
    WiresharkLite,
    AuthFlowSimulator,
    OnboardingIntro,
    SkillMap,
    StudentDashboard,
  },
  data() {
    return {
      // Top-level navigation
      mainView: 'courses',
      userRole: 'student',

      // Pools view state
      currentView: 'pools',
      smartQueueMode: 'queue',
      selectedPool: null,
      mode: 'train',
      arenaSubMode: null,
      questionCount: 0,
      poolPermission: 'owner',
      error: null,
      poolFromCourse: false,
      poolFromCourseObj: null,

      // Courses view state
      selectedCourse: null,
      selectedStudent: null,
      courseView: 'list',
      courseTab: 'training',
      settingsSubTab: 'admin',
      toolsView: 'subnet',
      previousMainView: 'courses',
      enabledTools: [...ALL_TOOL_IDS],
      contentLanguage: '',
      fsrsDetailedStats: false,
      virtuProfEnabled: true,
      appInitialized: false,
      initialVirtuProfHintsTriggered: false,
      pendingVirtuProfDuel: {
        courseId: null,
        duelCode: '',
      },
      adventureRoute: {
        coop: false,
        code: '',
      },
      showInstructorOnboarding: false,
    };
  },
  computed: {
    modes() {
      return [
        { id: 'train', label: t('learning', 'Training') },
        { id: 'leitner', label: t('learning', 'Leitner') },
        { id: 'exam', label: t('learning', 'Exam') },
        { id: 'gameshow', label: t('learning', 'Arena') },
        { id: 'stats', label: t('learning', 'Stats') },
        { id: 'manage', label: this.poolPermission === 'read' ? t('learning', 'View Questions') : t('learning', 'Manage') }
      ];
    },
    filteredModes() {
      if (this.userRole === 'student') {
        return this.modes.filter(m => ['train', 'leitner', 'exam', 'gameshow'].includes(m.id));
      }
      return this.modes;
    },
    showVirtuProfDock() {
      return this.appInitialized && this.virtuProfEnabled;
    },
    toolsTabs() {
      let enabled = this.normalizeEnabledTools(this.enabledTools);
      // Course-level tool restriction for students
      if (this.userRole === 'student' && this.selectedCourse?.enabled_tools) {
        const courseTools = this.selectedCourse.enabled_tools;
        if (Array.isArray(courseTools)) {
          enabled = enabled.filter(toolId => courseTools.includes(toolId));
        }
      }
      return TOOL_CATALOG
        .map((tool) => ({
          id: tool.id,
          label: t('learning', tool.labelKey),
          shortLabel: t('learning', tool.shortLabelKey),
          icon: tool.icon,
          disabled: !enabled.includes(tool.id),
        }));
    },
    visibleToolsTabs() {
      return this.toolsTabs.filter(t => !t.disabled);
    },
    modeDescriptions() {
      return {
        train: t('learning', 'Quick quiz — test your knowledge with random questions.'),
        leitner: t('learning', 'Spaced repetition — difficult questions come back more often. 5 boxes, step by step.'),
        exam: t('learning', 'Exam mode — no feedback until the end, like a real exam.'),
        gameshow: t('learning', 'Arena mit Duell, Sprint oder Elimination.'),
        stats: t('learning', 'Learning statistics and box distribution for this pool.'),
        manage: t('learning', 'Add, edit, delete and import questions.'),
      };
    },
  },
  async created() {
    const courseStore = useOptionalCourseStore();
    if (courseStore) {
      this._courseTabUnwatch = this.$watch(
        () => courseStore.currentTab,
        (tabId) => {
          if (!tabId || tabId === this.courseTab) {
            return;
          }
          this.courseTab = tabId;
          this.emitViewGuide();
        },
        { immediate: true }
      );
    }
    await Promise.all([this.fetchRole(), this.fetchPersonalSettings(), this.fetchEnabledTools()]);
    this.appInitialized = true;
    this.checkInstructorOnboarding();
    const openedAdventureRoute = this.applyInitialAdventureRoute();
    if (!openedAdventureRoute) {
      if (this.$route?.name === 'home') {
        this.navigateToDefaultRoute(true);
      } else {
        this.applyRouteState(this.$route);
      }
    }
    this.$nextTick(() => {
      this.emitVirtuProfContext();
    });
  },
  beforeUnmount() {
    if (typeof this._courseTabUnwatch === 'function') {
      this._courseTabUnwatch();
      this._courseTabUnwatch = null;
    }
  },
  watch: {
    $route(route) {
      this.applyRouteState(route);
    },
    mainView() {
      this.emitVirtuProfContext();
      this.emitToolGuide();
      this.emitViewGuide();
    },
    currentView() {
      this.emitVirtuProfContext();
      this.emitViewGuide();
    },
    mode() {
      this.emitVirtuProfContext();
      this.emitViewGuide();
    },
    toolsView() {
      this.emitVirtuProfContext();
      this.emitToolGuide();
    },
    selectedPool() {
      this.emitVirtuProfContext();
      this.emitViewGuide();
    },
    selectedCourse() {
      this.emitVirtuProfContext();
      this.emitViewGuide();
    },
    selectedStudent() {
      this.emitVirtuProfContext();
    },
    toolsTabs() {
      this.ensureActiveToolVisible();
    },
  },
  methods: {
    normalizeEnabledTools(enabledTools) {
      const source = Array.isArray(enabledTools) ? enabledTools : ALL_TOOL_IDS;
      return ALL_TOOL_IDS.filter((toolId) => source.includes(toolId));
    },
    async fetchEnabledTools() {
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/settings/tools'));
        this.enabledTools = this.normalizeEnabledTools(response.data?.enabled_tools);
      } catch (err) {
        this.enabledTools = [...ALL_TOOL_IDS];
      }
      this.ensureActiveToolVisible();
    },
    ensureActiveToolVisible() {
      const visible = this.visibleToolsTabs;
      if (visible.length === 0) {
        this.toolsView = '';
        return;
      }
      if (!visible.find(t => t.id === this.toolsView)) {
        this.toolsView = visible[0].id;
      }
    },
    async fetchPersonalSettings() {
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/settings/personal'));
        const lang = response.data?.content_language || '';
        this.contentLanguage = ['de', 'en', 'ru', 'ar'].includes(lang) ? lang : '';
        this.fsrsDetailedStats = (response.data?.fsrs_detailed_stats || 'no') === 'yes';
        this.virtuProfEnabled = (response.data?.virtuprof_enabled || 'yes') !== 'no';
      } catch (err) {
        this.contentLanguage = '';
        this.fsrsDetailedStats = false;
        this.virtuProfEnabled = true;
      }
    },

    updateContentLanguage(lang) {
      this.contentLanguage = ['de', 'en', 'ru', 'ar'].includes(lang) ? lang : '';
    },
    updateVirtuProfEnabled(enabled) {
      this.virtuProfEnabled = enabled !== false;
    },
    updateFsrsDetailedStats(enabled) {
      this.fsrsDetailedStats = enabled === true;
    },
    handleVirtuProfEnabledChange(enabled) {
      this.virtuProfEnabled = enabled !== false;
      if (!this.virtuProfEnabled && this.mainView === 'virtuprof-fullscreen') {
        this.closeVirtuProfFullscreen();
      }
    },
    checkInstructorOnboarding() {
      if (this.userRole !== 'instructor') return;
      try {
        const uid = (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function' && OC.getCurrentUser()?.uid) || 'user';
        if (!window.localStorage.getItem(`learning:onboarding-seen:${uid}`)) {
          this.showInstructorOnboarding = true;
        }
      } catch (e) {
        // Ignore
      }
    },
    onInstructorOnboardingDone() {
      this.showInstructorOnboarding = false;
      try {
        const uid = (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function' && OC.getCurrentUser()?.uid) || 'user';
        window.localStorage.setItem(`learning:onboarding-seen:${uid}`, 'yes');
      } catch (e) {
        // Ignore
      }
    },
    emitVirtuProf(triggerId, context = {}) {
      if (!this.appInitialized || this.userRole !== 'student') {
        return;
      }
      useOptionalVirtuProfStore()?.trigger(triggerId, context);
    },
    emitVirtuProfContext(context = null) {
      if (!this.appInitialized || this.userRole !== 'student') {
        return;
      }
      useOptionalVirtuProfStore()?.updateContext(context || this.virtuprofContextPayload());
    },
    emitToolGuide() {
      if (!this.appInitialized || this.userRole !== 'student' || this.mainView !== 'werkzeuge') {
        return;
      }
      const payload = this.toolGuidePayload(this.toolsView);
      if (payload) {
        useOptionalVirtuProfStore()?.guide(payload);
      }
    },
    currentViewKey() {
      if (this.mainView === 'virtuprof-fullscreen') {
        return null;
      }
      if (this.mainView === 'dashboard') {
        return 'view:dashboard';
      }
      if (this.mainView === 'settings') {
        return 'view:settings';
      }
      if (this.mainView === 'courses') {
        if (this.selectedCourse) {
          // Map CourseDetail tabs to view keys
          const tabMap = {
            'training': 'view:course-training',
            'leitner': 'view:course-leitner',
            'exam': 'view:course-exam',
            'my-progress': 'view:course-my-progress',
            'summary': 'view:course-summary',
            'leaderboard': 'view:course-leaderboard',
            'league': 'view:course-league',
            'arena': 'view:course-arena',
            'oldschool': 'view:course-oldschool',
            'abenteuer': 'view:course-abenteuer',
            'pools': 'view:course-pools',
            'members': 'view:course-members',
            'progress': 'view:course-progress',
            'class-profile': 'view:course-class-profile',
            'heatmap': 'view:course-heatmap',
            'weak-questions': 'view:course-weak-questions',
            'announcements': 'view:course-announcements',
            'exam-slot': 'view:course-exam-slot',
            'requests': 'view:course-requests',
            'mode-config': 'view:course-mode-config',
            'materials': 'view:course-materials',
            'curriculum': 'view:course-curriculum',
            'feed': 'view:course-feed',
            'buddies': 'view:course-buddies',
            'schwarm': 'view:course-schwarm',
            'knowledge': 'view:course-knowledge',
          };
          return tabMap[this.courseTab] || 'view:course-detail';
        }
        return 'view:courses';
      }
      if (this.mainView === 'pools') {
        if (this.currentView === 'abenteuer') {
          return 'view:abenteuer';
        }
        if (this.currentView === 'questions' && this.selectedPool) {
          if (this.mode === 'leitner') {
            return 'view:leitner';
          }
          if (this.mode === 'exam') {
            return 'view:exam';
          }
          if (this.mode === 'manage') {
            return 'view:pool-manage';
          }
          return 'view:training';
        }
        return 'view:pools';
      }
      return null;
    },
    emitViewGuide() {
      if (!this.appInitialized || this.userRole !== 'student') {
        return;
      }
      const viewKey = this.currentViewKey();
      if (!viewKey) {
        return;
      }
      const payload = this.viewGuidePayload(viewKey);
      if (payload) {
        useOptionalVirtuProfStore()?.guide(payload);
      }
    },
    viewGuidePayload(viewKey) {
      const guides = {
        'view:dashboard': {
          title: t('learning', 'Dashboard'),
          text: t('learning', 'Your daily learning overview: due cards, Daily Challenge, streak and quick links. Start your learning session from here.'),
          shortText: t('learning', 'Daily overview with due cards, challenge and streak.'),
        },
        'view:courses': {
          title: t('learning', 'Courses'),
          text: t('learning', 'Here you see all your courses. Select a course to open its learning modes. Courses can contain Training, Leitner, Exam and Arena.'),
          shortText: t('learning', 'Select a course to open your learning modes.'),
        },
        'view:course-detail': {
          title: t('learning', 'Course Detail'),
          text: t('learning', 'In this course you can start Training, Leitner, True/False or Exam mode. Choose the mode that fits your current learning goal.'),
          shortText: t('learning', 'Start Training, Leitner, Exam or Arena from this course.'),
        },
        // Student course tabs
        'view:course-training': {
          title: t('learning', 'Training'),
          text: t('learning', 'Training is the direct-feedback mode for quick practice. Pick a pool, answer questions and see immediately whether you got it right.'),
          shortText: t('learning', 'Quick practice with immediate feedback.'),
        },
        'view:course-leitner': {
          title: t('learning', 'Leitner System'),
          text: t('learning', 'The Leitner system shows you due cards based on your progress. The more often you answer a card correctly, the less often it appears — until it is mastered.'),
          shortText: t('learning', 'Answer due cards. Correct ones move forward, wrong ones go back.'),
        },
        'view:course-exam': {
          title: t('learning', 'Exam Simulation'),
          text: t('learning', 'Exam mode simulates a real test: no hints, a time limit and your result only at the end. Use it to check whether you are ready.'),
          shortText: t('learning', 'Simulated exam — result shown after all questions.'),
        },
        'view:course-my-progress': {
          title: t('learning', 'My Progress'),
          text: t('learning', 'Here you see your personal learning progress in this course: mastered questions, current streak, XP and level. Track how far you have come.'),
          shortText: t('learning', 'Your personal stats: mastered, streak, XP.'),
        },
        'view:course-summary': {
          title: t('learning', 'Kursabschluss'),
          text: t('learning', 'Your course summary turns mastery, sessions, streaks, badges and trouble spots into one final overview for this course.'),
          shortText: t('learning', 'Final course overview with mastery, badges and trouble spots.'),
        },
        'view:course-leaderboard': {
          title: t('learning', 'Leaderboard'),
          text: t('learning', 'The leaderboard ranks all course members by XP and mastered questions. See where you stand compared to your classmates.'),
          shortText: t('learning', 'Course ranking by XP and mastered questions.'),
        },
        'view:course-league': {
          title: t('learning', 'League'),
          text: t('learning', 'The league system groups learners into tiers based on weekly activity. Stay active to climb up — or risk dropping a tier.'),
          shortText: t('learning', 'Weekly competition tiers based on activity.'),
        },
        'view:course-arena': {
          title: t('learning', 'Arena'),
          text: t('learning', 'Arena mode lets you compete in real-time quiz battles: Sprint (speed round) or Elimination (last one standing). Challenge your course mates!'),
          shortText: t('learning', 'Real-time quiz battles: Sprint or Elimination.'),
        },
        'view:course-oldschool': {
          title: t('learning', 'Oldschool'),
          text: t('learning', 'Classic board-game style learning: Lernwürfel (dice + questions) and Wissenssturm (5 categories, steal mechanics). Play with 2-4 people at the same screen.'),
          shortText: t('learning', 'Board-game learning: dice, categories, steal.'),
        },
        'view:course-abenteuer': {
          title: t('learning', 'Adventure'),
          text: t('learning', 'Adventure mode wraps learning in a story with branching paths. Solve network problems, make decisions and reach different endings.'),
          shortText: t('learning', 'Story-driven learning with branching paths.'),
        },
        // Instructor course tabs
        'view:course-pools': {
          title: t('learning', 'Course Pools'),
          text: t('learning', 'Manage question pools assigned to this course. Add or remove pools, set them as required or optional for students.'),
          shortText: t('learning', 'Manage pools for this course.'),
        },
        'view:course-members': {
          title: t('learning', 'Members'),
          text: t('learning', 'View and manage course members. Add students, assign roles or remove inactive members.'),
          shortText: t('learning', 'Manage course members and roles.'),
        },
        'view:course-progress': {
          title: t('learning', 'Student Progress'),
          text: t('learning', 'Overview of all students: mastered questions, XP, last activity. Identify who needs help and who is ahead.'),
          shortText: t('learning', 'Track student progress and identify at-risk learners.'),
        },
        'view:course-class-profile': {
          title: t('learning', 'Class Profile'),
          text: t('learning', 'Aggregated class profile from Telos onboarding: experience levels, target certifications, learning goals. Understand your class at a glance.'),
          shortText: t('learning', 'Aggregated student profiles and learning goals.'),
        },
        'view:course-heatmap': {
          title: t('learning', 'Heatmap'),
          text: t('learning', 'Visual heatmap showing which questions are easy (green) and which are hard (red) across the whole class. Spot trouble areas quickly.'),
          shortText: t('learning', 'Question difficulty heatmap across all students.'),
        },
        'view:course-weak-questions': {
          title: t('learning', 'Weak Questions'),
          text: t('learning', 'Questions with the highest error rate across all students. These are the topics your class struggles with most — focus your teaching here.'),
          shortText: t('learning', 'Questions students get wrong most often.'),
        },
        'view:course-announcements': {
          title: t('learning', 'Announcements'),
          text: t('learning', 'Post announcements visible to all course members. Use for exam reminders, schedule changes or study tips.'),
          shortText: t('learning', 'Post messages visible to all students.'),
        },
        'view:course-exam-slot': {
          title: t('learning', 'Exam Slot'),
          text: t('learning', 'Schedule exam time windows: set start/end, choose pools and configure time limits. Students can only take the exam during the slot.'),
          shortText: t('learning', 'Schedule timed exam windows for students.'),
        },
        'view:course-requests': {
          title: t('learning', 'Requests'),
          text: t('learning', 'Student requests and error reports. Review flagged questions, respond to help requests and improve your content.'),
          shortText: t('learning', 'Review student requests and error reports.'),
        },
        'view:course-mode-config': {
          title: t('learning', 'Mode Config'),
          text: t('learning', 'Enable or disable learning modes for this course: Training, Leitner, Exam, Arena, Oldschool, Adventure. Control what students can access.'),
          shortText: t('learning', 'Enable/disable learning modes for students.'),
        },
        'view:course-materials': {
          title: t('learning', 'Materials'),
          text: t('learning', 'Upload and manage learning materials: PDFs, links, documents. Students see these as reference material alongside their questions.'),
          shortText: t('learning', 'Upload reference materials for students.'),
        },
        'view:course-curriculum': {
          title: t('learning', 'Curriculum'),
          text: t('learning', 'Structure your course content into chapters and topics. Map questions to curriculum objectives for targeted learning.'),
          shortText: t('learning', 'Organize content into chapters and topics.'),
        },
        // Pool-level views (outside courses)
        'view:training': {
          title: t('learning', 'Training'),
          text: t('learning', 'Here you practise questions in flashcard style. After each answer you immediately see whether it was correct. Correct answers move up to higher Leitner boxes.'),
          shortText: t('learning', 'Answer questions and learn from immediate feedback.'),
        },
        'view:leitner': {
          title: t('learning', 'Leitner System'),
          text: t('learning', 'The Leitner system shows you due cards based on your progress. The more often you answer a card correctly, the less often it appears — until it is mastered.'),
          shortText: t('learning', 'Answer due cards. Correct ones move forward, wrong ones go back.'),
        },
        'view:exam': {
          title: t('learning', 'Exam Simulation'),
          text: t('learning', 'Exam mode: no hints, no immediate feedback, time limit like in the real exam. You see your result only at the end.'),
          shortText: t('learning', 'Exam mode without hints — result shown after all questions.'),
        },
        'view:pool-manage': {
          title: t('learning', 'Pool Management'),
          text: t('learning', 'Here you create and manage questions in this pool. You can add, edit, delete and import questions via CSV or JSON.'),
          shortText: t('learning', 'Add, edit, delete and import questions.'),
        },
        'view:pools': {
          title: t('learning', 'Question Pools'),
          text: t('learning', 'Here you see all your question pools. Select a pool to start practising. As an instructor you can also create and manage pools.'),
          shortText: t('learning', 'Select a pool to start practising.'),
        },
        'view:abenteuer': {
          title: t('learning', 'Adventure Mode'),
          text: t('learning', 'Start a campaign and solve network problems in a story. Every decision moves you forward — mistakes cost life points.'),
          shortText: t('learning', 'Solve network problems in an interactive story.'),
        },
        'view:settings': {
          title: t('learning', 'Settings'),
          text: t('learning', 'Personal settings: learning language, Telos profile and VirtuProf options. You can also enable or disable VirtuProf here.'),
          shortText: t('learning', 'Set language, Telos profile and VirtuProf options.'),
        },
      };

      const guide = guides[viewKey];
      if (!guide) {
        return null;
      }

      return {
        key: viewKey,
        title: guide.title,
        text: guide.text,
        shortText: guide.shortText,
      };
    },
    toolGuidePayload(toolId) {
      const guides = {
        subnet: {
          title: t('learning', 'Subnet Calculator'),
          text: t('learning', 'This tool breaks down CIDR networks into network address, broadcast, host range and binary form. Use it when subnet masks still feel abstract.'),
          shortText: t('learning', 'The subnet calculator shows network, broadcast and host range for one CIDR block.'),
        },
        dns: {
          title: t('learning', 'DNS Resolver'),
          text: t('learning', 'This simulator walks through recursive DNS lookups step by step. It helps you see which server answers what and where common DNS failures appear.'),
          shortText: t('learning', 'The DNS resolver shows the lookup chain from resolver to authoritative answer.'),
        },
        firewall: {
          title: t('learning', 'Firewall / ACL Builder'),
          text: t('learning', 'Here you build ordered firewall rules and test packets against them. It is useful for understanding first-match logic, allow/deny decisions and implicit deny.'),
          shortText: t('learning', 'The firewall builder lets you test packet flow against ordered ACL rules.'),
        },
        portscan: {
          title: t('learning', 'Port Scanner'),
          text: t('learning', 'This tool simulates typical scan results for different host profiles. Use it to practice reading service exposure and spotting suspicious open ports quickly.'),
          shortText: t('learning', 'The port scanner shows which services a host exposes and what that implies.'),
        },
        routing: {
          title: t('learning', 'Routing Table'),
          text: t('learning', 'This simulator compares routes by prefix length and metric. It is designed to make longest-prefix-match and default-route behavior visible instead of purely theoretical.'),
          shortText: t('learning', 'The routing table simulator explains longest-prefix-match and route selection.'),
        },
        nat: {
          title: t('learning', 'NAT Table'),
          text: t('learning', 'This tool demonstrates static NAT, dynamic NAT and PAT with live translations. Use it to see how inside and outside addresses are rewritten in each mode.'),
          shortText: t('learning', 'The NAT simulator shows how private and public addresses are translated.'),
        },
        wireshark: {
          title: t('learning', 'Wireshark Lite'),
          text: t('learning', 'This packet viewer strips captures down to the essential layers and anomalies. It is meant for practicing protocol reading without getting lost in every field.'),
          shortText: t('learning', 'Wireshark Lite highlights the important packet layers and anomalies.'),
        },
        authflow: {
          title: t('learning', '802.1X Auth Flow'),
          text: t('learning', 'This simulator compares EAP-based authentication flows step by step. Use it to understand who talks to whom during 802.1X and where credentials or certificates are checked.'),
          shortText: t('learning', 'The auth-flow simulator compares the main 802.1X handshakes.'),
        },
      };

      const guide = guides[toolId];
      if (!guide) {
        return null;
      }

      return {
        key: `tool:${toolId}`,
        title: guide.title,
        text: guide.text,
        shortText: guide.shortText,
      };
    },
    virtuprofContextPayload() {
      const mainView = this.mainView === 'virtuprof-fullscreen' ? this.previousMainView : this.mainView;
      if (mainView === 'dashboard') {
        return { area: 'dashboard' };
      }
      if (mainView === 'settings') {
        return { area: 'settings' };
      }
      if (mainView === 'werkzeuge') {
        return {
          area: `tool-${this.toolsView || 'overview'}`,
        };
      }
      if (mainView === 'skillmap') {
        return { area: 'skillmap' };
      }
      if (mainView === 'pools') {
        if (this.currentView === 'questions') {
          if (this.mode === 'gameshow') {
            const area = this.arenaSubMode ? `arena-${this.arenaSubMode}` : 'arena';
            return {
              area,
              poolName: this.selectedPool?.name || '',
            };
          }
          return {
            area: `pool-${this.mode}`,
            poolName: this.selectedPool?.name || '',
          };
        }
        if (this.currentView === 'smartQueue') {
          return { area: 'smartqueue' };
        }
        return { area: 'pools' };
      }
      if (mainView === 'courses') {
        if (this.selectedStudent) {
          return {
            area: 'course-my-progress',
            courseTitle: this.selectedCourse?.title || '',
          };
        }
        if (this.selectedCourse) {
          return {
            area: 'course-detail',
            courseTitle: this.selectedCourse.title || '',
          };
        }
        return { area: 'courses' };
      }
      return { area: 'courses' };
    },
    currentUserId() {
      if (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function') {
        return OC.getCurrentUser()?.uid || 'user';
      }
      return 'user';
    },
    lastActiveStorageKey() {
      return `learning:virtuprof:last-active:${this.currentUserId()}`;
    },
    triggerInitialVirtuProfHints() {
      if (this.userRole !== 'student' || this.initialVirtuProfHintsTriggered) {
        return;
      }
      this.initialVirtuProfHintsTriggered = true;
      this.emitVirtuProf('app-first-visit');
      try {
        const lastActiveRaw = window.localStorage.getItem(this.lastActiveStorageKey());
        const lastActive = lastActiveRaw ? Number(lastActiveRaw) : 0;
        const absenceMs = 7 * 24 * 60 * 60 * 1000;
        if (lastActive > 0 && (Date.now() - lastActive) >= absenceMs) {
          this.emitVirtuProf('return-after-absence');
        }
        window.localStorage.setItem(this.lastActiveStorageKey(), String(Date.now()));
      } catch (e) {
        // Ignore local storage failures.
      }
    },
    clearVirtuProfDuel() {
      this.pendingVirtuProfDuel = {
        courseId: null,
        duelCode: '',
      };
    },
    async openVirtuProfDuel(payload) {
      const courseId = Number(payload?.courseId || 0);
      const duelCode = String(payload?.duelCode || '').trim();
      if (!courseId || !duelCode) {
        return;
      }

      this.selectedStudent = null;
      this.courseView = 'list';
      this.pendingVirtuProfDuel = {
        courseId,
        duelCode,
      };

      try {
        const response = await axios.get(generateUrl('/apps/learning/api/courses/' + courseId));
        this.selectedCourse = {
          id: courseId,
          title: response.data?.title || '',
        };
      } catch (e) {
        this.selectedCourse = {
          id: courseId,
          title: '',
        };
      }
      this.pushAppRoute(this.courseRouteLocation(courseId, 'wettbewerb'));
    },

    handleTablistKeydown(event) {
      const tabs = [...event.currentTarget.querySelectorAll('[role="tab"]')];
      const currentIndex = tabs.indexOf(document.activeElement);
      if (currentIndex === -1) {
        return;
      }

      let nextIndex;
      if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
        nextIndex = (currentIndex + 1) % tabs.length;
      } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
        nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
      } else {
        return;
      }

      event.preventDefault();
      tabs[nextIndex].focus();
    },

    async fetchRole() {
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/role'));
        this.userRole = response.data.role || 'student';
      } catch (err) {
        this.userRole = 'student';
      }
    },

    applyInitialAdventureRoute() {
      if (typeof window === 'undefined') {
        return false;
      }

      const params = new URLSearchParams(window.location.search || '');
      const requestedView = params.get('learningView');
      const coopParam = params.get('learningCoop');
      const coopCode = String(params.get('learningCoopCode') || '').trim().toUpperCase();
      const wantsCoop = ['1', 'true', 'yes'].includes(String(coopParam || '').toLowerCase()) || coopCode.length > 0;

      if (requestedView !== 'abenteuer' && !wantsCoop) {
        return false;
      }

      this._pendingAdventureRoute = {
        coop: wantsCoop,
        code: coopCode,
      };
      this.pushAppRoute({ name: 'pools' }, true);
      return true;
    },
    navigateToDefaultRoute(replace = false) {
      const target = this.userRole === 'student' ? { name: 'dashboard' } : { name: 'courses' };
      this.pushAppRoute(target, replace);
    },
    courseRouteLocation(courseId, tab = 'lernraum') {
      return {
        name: 'course-tab',
        params: {
          id: String(courseId),
          tab,
        },
      };
    },
    routeLocationForView(view) {
      if (view === 'dashboard') {
        return { name: 'dashboard' };
      }
      if (view === 'courses') {
        return { name: 'courses' };
      }
      if (view === 'pools') {
        return { name: 'pools' };
      }
      if (view === 'werkzeuge') {
        return { name: 'tools', query: this.toolsView ? { tool: this.toolsView } : {} };
      }
      if (view === 'skillmap') {
        return { name: 'skill-map' };
      }
      if (view === 'settings') {
        return { name: 'settings' };
      }
      if (view === 'virtuprof-fullscreen') {
        return { name: 'virtuprof' };
      }
      return null;
    },
    pushAppRoute(location, replace = false) {
      if (!this.$router || !location) {
        return;
      }
      const navigation = replace ? this.$router.replace(location) : this.$router.push(location);
      if (navigation && typeof navigation.catch === 'function') {
        navigation.catch(() => {});
      }
    },
    applyRouteState(route) {
      if (!route) {
        return;
      }

      if (route.name === 'home') {
        if (this.appInitialized) {
          this.navigateToDefaultRoute(true);
        }
        return;
      }

      if (route.name === 'dashboard') {
        this.mainView = 'dashboard';
        this.selectedCourse = null;
        this.selectedStudent = null;
        this.courseView = 'list';
        useOptionalCourseStore()?.setCourse(null);
        return;
      }

      if (route.name === 'courses') {
        this.mainView = 'courses';
        this.selectedCourse = null;
        this.selectedStudent = null;
        this.courseView = 'list';
        useOptionalCourseStore()?.setCourse(null);
        this.backToPools();
        return;
      }

      if (route.name === 'course-tab') {
        const courseId = Number(route.params?.id || 0);
        const routeTab = String(route.params?.tab || '').trim();
        this.mainView = 'courses';
        this.selectedStudent = null;
        this.courseView = 'list';
        if (courseId) {
          if (!this.selectedCourse || Number(this.selectedCourse.id) !== courseId) {
            this.selectedCourse = {
              id: courseId,
              title: this.selectedCourse?.title || '',
            };
          }
          useOptionalCourseStore()?.setCourse(courseId);
          if (routeTab) {
            useOptionalCourseStore()?.setTab(routeTab);
          }
        }
        return;
      }

      if (route.name === 'pools') {
        this.mainView = 'pools';
        this.backToPools();
        if (this._pendingAdventureRoute) {
          this.currentView = 'abenteuer';
          this.adventureRoute = { ...this._pendingAdventureRoute };
          this._pendingAdventureRoute = null;
        }
        return;
      }

      if (route.name === 'tools') {
        this.mainView = 'werkzeuge';
        const toolId = String(route.query?.tool || '').trim();
        if (toolId) {
          this.toolsView = toolId;
        }
        return;
      }

      if (route.name === 'skill-map') {
        this.mainView = 'skillmap';
        return;
      }

      if (route.name === 'settings') {
        this.mainView = 'settings';
        return;
      }

      if (route.name === 'virtuprof') {
        this.mainView = 'virtuprof-fullscreen';
      }
    },

    switchMainView(view) {
      if (view === 'virtuprof-fullscreen') {
        this.openVirtuProfFullscreen();
        return;
      }

      if (this.mainView === 'virtuprof-fullscreen' && view !== 'virtuprof-fullscreen') {
        this.previousMainView = view;
      } else if (view !== 'virtuprof-fullscreen') {
        this.previousMainView = view;
      }
      this.pushAppRoute(this.routeLocationForView(view));
    },
    openVirtuProfFullscreen() {
      if (!this.showVirtuProfDock) {
        return;
      }
      const virtuProf = this.$refs.virtuprof;
      if (virtuProf) {
        if (!virtuProf.currentBubbleStep && typeof virtuProf.openHelpHome === 'function') {
          virtuProf.openHelpHome();
        }
        virtuProf.visible = true;
        virtuProf.isMinimized = false;
      }
      if (this.mainView !== 'virtuprof-fullscreen') {
        this.previousMainView = this.mainView;
      }
      this.pushAppRoute({ name: 'virtuprof' });
    },
    closeVirtuProfFullscreen() {
      const fallbackView = this.previousMainView && this.previousMainView !== 'virtuprof-fullscreen'
        ? this.previousMainView
        : (this.userRole === 'student' ? 'dashboard' : 'courses');
      this.pushAppRoute(this.routeLocationForView(fallbackView), true);
    },

    // --- Pools methods ---
    async selectPool(pool) {
      this.selectedPool = pool;
      this.currentView = 'questions';
      this.error = null;
      this.poolPermission = pool.is_shared ? (pool.permission || 'read') : 'owner';
      try {
        const response = await axios.get(
          generateUrl('/apps/learning/api/pools/' + pool.id + '/questions')
        );
        this.questionCount = response.data.length;
      } catch (err) {
        this.error = t('learning', 'Failed to load question count');
        this.questionCount = 0;
      }
    },
    backToPools() {
      this.currentView = 'pools';
      this.selectedPool = null;
      this.mode = 'train';
      this.arenaSubMode = null;
      this.poolPermission = 'owner';
      this.error = null;
      this.poolFromCourse = false;
      this.poolFromCourseObj = null;
      this.adventureRoute = {
        coop: false,
        code: '',
      };
    },
    backToCourse() {
      const course = this.poolFromCourseObj;
      this.currentView = 'pools';
      this.selectedPool = null;
      this.mode = 'train';
      this.arenaSubMode = null;
      this.poolPermission = 'owner';
      this.error = null;
      this.poolFromCourse = false;
      this.poolFromCourseObj = null;
      if (course) {
        this.selectedCourse = course;
        useOptionalCourseStore()?.setCourse(course.id || null);
        this.pushAppRoute(this.courseRouteLocation(course.id || this.courseId, 'lernraum'));
      } else {
        useOptionalCourseStore()?.setCourse(null);
        this.pushAppRoute({ name: 'courses' });
      }
    },
    openSmartQueue() {
      this.smartQueueMode = 'queue';
      this.currentView = 'smartQueue';
    },
    openCourseTool(toolId) {
      if (toolId) {
        this.toolsView = toolId;
      }
      this.pushAppRoute(this.routeLocationForView('werkzeuge'));
    },
    openRemediation() {
      this.smartQueueMode = 'remediation';
      this.currentView = 'smartQueue';
    },
    setMode(newMode) {
      if (newMode === 'swipe') {
        newMode = 'train';
      }
      this.mode = newMode;
      if (newMode !== 'gameshow') {
        this.arenaSubMode = null;
      } else {
        this.emitVirtuProf('arena-first-visit');
      }
      this.error = null;
    },
    onArenaSelectMode(mode) {
      this.arenaSubMode = mode;
      if (mode === 'sprint') {
        this.emitVirtuProf('gameshow-sprint-first-start');
      } else if (mode === 'elimination') {
        this.emitVirtuProf('gameshow-elimination-first-start');
      }
    },

    // --- Courses methods ---
    selectCourse(course) {
      this.selectedCourse = course;
      this.selectedStudent = null;
      useOptionalCourseStore()?.setCourse(course?.id || null);
      if (course?.id) {
        this.pushAppRoute(this.courseRouteLocation(course.id, 'lernraum'));
      }
    },
    selectStudent(studentInfo) {
      this.selectedStudent = studentInfo;
    },
    async openPoolFromCourse(poolId) {
      this.poolFromCourse = true;
      this.poolFromCourseObj = this.selectedCourse;
      this.selectedCourse = null;
      useOptionalCourseStore()?.setCourse(null);
      this.pushAppRoute({ name: 'pools' });
      try {
        const response = await axios.get(
          generateUrl('/apps/learning/api/pools/' + poolId)
        );
        const pool = response.data;
        this.selectPool({ id: pool.id, name: pool.name, is_shared: !!pool.is_shared, permission: pool.permission });
      } catch {
        this.selectPool({ id: poolId, name: '', is_shared: true, permission: 'read' });
      }
    },

    // --- Skill-Map methods ---
    async openPoolFromSkillMap(poolId) {
      this.pushAppRoute({ name: 'pools' });
      try {
        const response = await axios.get(
          generateUrl('/apps/learning/api/pools/' + poolId)
        );
        const pool = response.data;
        this.selectPool({ id: pool.id, name: pool.name, is_shared: !!pool.is_shared, permission: pool.permission });
      } catch {
        this.selectPool({ id: poolId, name: '', is_shared: false, permission: 'owner' });
      }
    },
  }
};
</script>

<style scoped>
#app-learning {
  padding: 24px 40px;
  max-width: 1720px;
  margin: 0 auto;
}

.app-shell {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 24px;
  align-items: start;
}

.app-main {
  min-width: 0;
}

.app-virtuprof-dock {
  min-width: 0;
}

.app-content-header {
  margin-bottom: 12px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--color-border);
}

.app-content-header h2 {
  font-size: 26px;
  font-weight: 700;
  letter-spacing: -0.3px;
  margin: 0;
  color: var(--color-main-text);
}

/* Top-level navigation */
.main-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 24px;
  padding: 0 0 12px;
  border-bottom: 1px solid var(--color-border);
  width: 100%;
  max-width: 100%;
  overflow: visible;
}

.main-nav-btn {
  flex: 0 1 auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  max-width: 100%;
  padding: 10px 16px;
  border: 1px solid transparent;
  border-radius: 999px;
  background: transparent;
  cursor: pointer;
  font-weight: 600;
  font-size: 15px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 0;
  line-height: 1.2;
  transition: color 0.2s, border-color 0.2s, background-color 0.2s;
  white-space: normal;
  word-break: normal;
  overflow-wrap: anywhere;
  text-align: center;
}

.main-nav-btn:hover {
  color: var(--color-main-text);
  background: var(--color-background-hover);
}

.main-nav-btn.active {
  color: var(--color-primary-element);
  border-color: color-mix(in srgb, var(--color-primary-element) 45%, var(--color-border));
  background: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
}

/* Course sub-navigation */
.course-sub-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 20px;
  padding: 5px;
  background: var(--color-background-hover);
  border-radius: 10px;
}

/* Pool view styles */
.pool-view-header {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 20px;
}

.pool-title {
  font-size: 22px;
  font-weight: 600;
  margin: 0;
  color: var(--color-main-text);
}

.mode-selector {
  display: flex;
  gap: 6px;
  margin-bottom: 28px;
  padding: 5px;
  background: var(--color-background-hover);
  border-radius: 10px;
  max-width: 900px;
}

.mode-btn {
  flex: 1;
  padding: 12px 16px;
  border: none;
  background: transparent;
  border-radius: 8px;
  cursor: pointer;
  font-weight: 500;
  transition: all 0.2s;
  font-size: 14px;
  text-align: center;
  color: var(--color-main-text);
}

.mode-btn:hover {
  background: var(--color-background-dark);
}

.mode-btn.active {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}

.mode-description {
  font-size: 13px;
  color: var(--color-text-maxcontrast);
  margin: -16px 0 20px 0;
  padding: 0 8px;
  max-width: 900px;
}

@media (min-width: 1180px) {
  .app-shell--with-virtuprof {
    grid-template-columns: minmax(0, 1fr) minmax(320px, 372px);
  }

  .app-virtuprof-dock {
    position: sticky;
    top: 0;
  }
}

@media (max-width: 768px) {
  #app-learning { padding: 16px; }
  .app-shell { gap: 16px; }
  .pool-view-header { flex-direction: column; align-items: flex-start; }
  .mode-selector { flex-direction: column; max-width: 100%; }
  .main-nav-btn { padding: 9px 14px; font-size: 14px; }
}
</style>
