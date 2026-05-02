<template>
  <NcAppContent id="app-learning">
    <div class="app-shell" :class="{ 'app-shell--with-virtuprof': showVirtuProfDock }">
      <div class="app-main">
        <div class="app-content-header">
          <h2>{{ effectiveUserRole === 'student' ? t('learning', 'Learning') : t('learning', 'Learning - Spaced Repetition') }}</h2>
        </div>

        <!-- Top-level navigation -->
        <div class="main-nav" role="tablist" @keydown="handleTablistKeydown">
          <button
            v-if="effectiveUserRole === 'student'"
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
            v-if="effectiveUserRole === 'student' || userRole === 'instructor'"
            :class="['main-nav-btn', { active: mainView === 'pools' }]"
            role="tab"
            :aria-selected="mainView === 'pools' ? 'true' : 'false'"
            @click="switchMainView('pools')"
          >
            {{ t('learning', 'Pools') }}
          </button>
          <button
            v-if="effectiveUserRole === 'student' && showVirtuProfDock"
            :class="['main-nav-btn', { active: mainView === 'virtuprof-fullscreen' }]"
            role="tab"
            :aria-selected="mainView === 'virtuprof-fullscreen' ? 'true' : 'false'"
            @click="switchMainView('virtuprof-fullscreen')"
          >
            {{ t('learning', 'Erklärbot') }}
          </button>
          <button
            v-if="effectiveUserRole === 'student'"
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
                <NcButton type="tertiary" @click="poolFromCourse ? backToCourse() : backToPools()" :aria-label="poolFromCourse ? t('learning', '← Zurück zum Kurs') : t('learning', 'Zurück zu Pools')">
                  {{ poolFromCourse ? t('learning', '← Zurück zum Kurs') : t('learning', '← Zurück zu Pools') }}
                </NcButton>
                <h3 class="pool-title">{{ selectedPool.name }}</h3>
              </div>

              <!-- Read-only banner for shared pools -->
              <NcNoteCard v-if="poolPermission === 'read'" type="info">
                {{ t('learning', 'Dieser Pool wurde mit dir geteilt (nur Ansicht)') }}
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
            <template v-if="effectiveUserRole !== 'student'">
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
                :user-role="userRole"
                :student-view-override="studentViewOverride"
                @content-language-changed="updateContentLanguage"
                @virtuprof-enabled-changed="updateVirtuProfEnabled"
                @fsrs-detailed-stats-changed="updateFsrsDetailedStats"
                @student-view-override-changed="setStudentViewOverride" />
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
            <div v-if="effectiveUserRole === 'instructor' && !selectedCourse" class="course-sub-nav" role="tablist" @keydown="handleTablistKeydown">
              <button
                :class="['mode-btn', { active: courseView === 'list' }]"
                role="tab"
                :aria-selected="courseView === 'list' ? 'true' : 'false'"
                @click="courseView = 'list'"
              >
                {{ t('learning', 'Kursliste') }}
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
              v-if="effectiveUserRole === 'instructor' && !selectedCourse && courseView === 'dashboard'"
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

      <OnboardingRedesign
        v-if="showOnboarding"
        @done="onOnboardingDone" />

      <OnboardingIntro
        v-if="showInstructorOnboarding && !showOnboarding"
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
import OnboardingRedesign from './components/OnboardingRedesign.vue';
import SkillMap from './components/SkillMap.vue';
import StudentDashboard from './components/StudentDashboard.vue';
import { ALL_TOOL_IDS, TOOL_CATALOG } from './utils/toolCatalog.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { useOptionalCourseStore } from './stores/courseStore.js';
import { defineAsyncComponent } from 'vue';
import { useOptionalVirtuProfStore } from './stores/virtuProfStore.js';

const VirtuProf = defineAsyncComponent(() => import('./components/VirtuProf.vue'));

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
    OnboardingRedesign,
    SkillMap,
    StudentDashboard,
  },
  data() {
    return {
      // Top-level navigation
      mainView: 'courses',
      userRole: 'student',
      // Issue #10: NC-admins are auto-classified as instructors. This client-side
      // override lets a self-hosting admin/instructor flip into the student view
      // without juggling accounts. Persisted in localStorage.
      studentViewOverride: false,

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
      showOnboarding: false,
      showInstructorOnboarding: false,
    };
  },
  computed: {
    effectiveUserRole() {
      return this.studentViewOverride && this.userRole === 'instructor' ? 'student' : this.userRole;
    },
    modes() {
      return [
        { id: 'train', label: t('learning', 'Training') },
        { id: 'leitner', label: t('learning', 'Leitner') },
        { id: 'exam', label: t('learning', 'Prüfung') },
        { id: 'gameshow', label: t('learning', 'Arena') },
        { id: 'stats', label: t('learning', 'Statistik') },
        { id: 'manage', label: this.poolPermission === 'read' ? t('learning', 'Fragen ansehen') : t('learning', 'Verwalten') }
      ];
    },
    filteredModes() {
      if (this.effectiveUserRole === 'student') {
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
      if (this.effectiveUserRole === 'student' && this.selectedCourse?.enabled_tools) {
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
        train: t('learning', 'Schnelles Quiz — teste dein Wissen mit zufälligen Fragen.'),
        leitner: t('learning', 'Spaced Repetition — schwierige Fragen kommen häufiger. 5 Boxen, Schritt für Schritt.'),
        exam: t('learning', 'Prüfungsmodus — kein Feedback bis zum Ende, wie eine echte Prüfung.'),
        gameshow: t('learning', 'Arena mit Duell, Sprint oder Elimination.'),
        stats: t('learning', 'Lernstatistiken und Box-Verteilung für diesen Pool.'),
        manage: t('learning', 'Fragen hinzufügen, bearbeiten, löschen und importieren.'),
      };
    },
  },
  async created() {
    // Issue #10: hydrate student-view override before role fetch so the initial
    // render is consistent with the user's last choice (admins/instructors only).
    try {
      this.studentViewOverride = typeof window !== 'undefined'
        && window.localStorage?.getItem('learning:view-as-student') === 'yes';
    } catch (_e) {
      this.studentViewOverride = false;
    }
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
    this.checkOnboarding();
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
    checkOnboarding() {
      try {
        const uid = (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function' && OC.getCurrentUser()?.uid) || 'user';
        if (!window.localStorage.getItem(`learning:onboarding-seen:${uid}`)) {
          this.showOnboarding = true;
        }
      } catch {
        // Ignore
      }
    },
    onOnboardingDone() {
      this.showOnboarding = false;
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
          text: t('learning', 'Deine tägliche Lernübersicht: fällige Karten, Daily Challenge, Streak und Schnellzugriffe. Starte deine Lernsession von hier.'),
          shortText: t('learning', 'Tagesübersicht mit fälligen Karten, Challenge und Streak.'),
        },
        'view:courses': {
          title: t('learning', 'Kurse'),
          text: t('learning', 'Hier siehst du alle deine Kurse. Wähle einen Kurs, um die Lernmodi zu öffnen. Kurse können Training, Leitner, Prüfung und Arena enthalten.'),
          shortText: t('learning', 'Wähle einen Kurs, um deine Lernmodi zu öffnen.'),
        },
        'view:course-detail': {
          title: t('learning', 'Kursdetail'),
          text: t('learning', 'In diesem Kurs kannst du Training, Leitner, Richtig/Falsch oder Prüfungsmodus starten. Wähle den Modus, der zu deinem aktuellen Lernziel passt.'),
          shortText: t('learning', 'Starte Training, Leitner, Prüfung oder Arena in diesem Kurs.'),
        },
        // Student course tabs
        'view:course-training': {
          title: t('learning', 'Training'),
          text: t('learning', 'Training ist der Modus mit direktem Feedback für schnelles Üben. Wähle einen Pool, beantworte Fragen und sieh sofort, ob du richtig lagst.'),
          shortText: t('learning', 'Schnelles Üben mit sofortigem Feedback.'),
        },
        'view:course-leitner': {
          title: t('learning', 'Leitner-System'),
          text: t('learning', 'Das Leitner-System zeigt dir fällige Karten basierend auf deinem Fortschritt. Je öfter du eine Karte richtig beantwortest, desto seltener erscheint sie — bis sie gemeistert ist.'),
          shortText: t('learning', 'Beantworte fällige Karten. Richtige rücken vor, falsche gehen zurück.'),
        },
        'view:course-exam': {
          title: t('learning', 'Prüfungssimulation'),
          text: t('learning', 'Prüfungsmodus simuliert einen echten Test: keine Hinweise, ein Zeitlimit und dein Ergebnis erst am Ende. Nutze ihn, um zu prüfen ob du bereit bist.'),
          shortText: t('learning', 'Simulierte Prüfung — Ergebnis erst nach allen Fragen.'),
        },
        'view:course-my-progress': {
          title: t('learning', 'Mein Fortschritt'),
          text: t('learning', 'Hier siehst du deinen persönlichen Lernfortschritt in diesem Kurs: gemeisterte Fragen, aktueller Streak, XP und Level. Verfolge, wie weit du gekommen bist.'),
          shortText: t('learning', 'Deine persönliche Statistik: gemeistert, Streak, XP.'),
        },
        'view:course-summary': {
          title: t('learning', 'Kursabschluss'),
          text: t('learning', 'Dein Kursabschluss fasst Meisterschaft, Sessions, Streaks, Badges und Problemstellen in einer Gesamtübersicht zusammen.'),
          shortText: t('learning', 'Kursübersicht mit Meisterschaft, Badges und Problemstellen.'),
        },
        'view:course-leaderboard': {
          title: t('learning', 'Rangliste'),
          text: t('learning', 'Die Rangliste ordnet alle Kursteilnehmer nach XP und gemeisterten Fragen. Sieh, wo du im Vergleich zu deinen Mitschülern stehst.'),
          shortText: t('learning', 'Kurs-Ranking nach XP und gemeisterten Fragen.'),
        },
        'view:course-league': {
          title: t('learning', 'Liga'),
          text: t('learning', 'Das Liga-System gruppiert Lernende in Stufen basierend auf wöchentlicher Aktivität. Bleib aktiv, um aufzusteigen — oder riskiere einen Abstieg.'),
          shortText: t('learning', 'Wöchentliche Wettbewerbsstufen basierend auf Aktivität.'),
        },
        'view:course-arena': {
          title: t('learning', 'Arena'),
          text: t('learning', 'Im Arena-Modus trittst du in Echtzeit-Quiz-Duellen an: Sprint (Schnellrunde) oder Elimination (letzter steht). Fordere deine Kurskameraden heraus!'),
          shortText: t('learning', 'Echtzeit-Quiz-Duelle: Sprint oder Elimination.'),
        },
        'view:course-abenteuer': {
          title: t('learning', 'Abenteuer'),
          text: t('learning', 'Der Abenteuermodus verpackt Lernen in eine Geschichte mit Verzweigungen. Löse Netzwerkprobleme, triff Entscheidungen und erreiche verschiedene Enden.'),
          shortText: t('learning', 'Story-basiertes Lernen mit verzweigten Pfaden.'),
        },
        // Instructor course tabs
        'view:course-pools': {
          title: t('learning', 'Kurs-Pools'),
          text: t('learning', 'Verwalte die Fragenpools dieses Kurses. Füge Pools hinzu oder entferne sie, lege sie als Pflicht oder optional für Studierende fest.'),
          shortText: t('learning', 'Pools für diesen Kurs verwalten.'),
        },
        'view:course-members': {
          title: t('learning', 'Mitglieder'),
          text: t('learning', 'Sieh und verwalte Kursmitglieder. Füge Studierende hinzu, weise Rollen zu oder entferne inaktive Mitglieder.'),
          shortText: t('learning', 'Kursmitglieder und Rollen verwalten.'),
        },
        'view:course-progress': {
          title: t('learning', 'Studierendenfortschritt'),
          text: t('learning', 'Übersicht aller Studierenden: gemeisterte Fragen, XP, letzte Aktivität. Erkenne, wer Hilfe braucht und wer voraus ist.'),
          shortText: t('learning', 'Fortschritt der Studierenden verfolgen und Risikolerner erkennen.'),
        },
        'view:course-class-profile': {
          title: t('learning', 'Klassen-Profil'),
          text: t('learning', 'Aggregiertes Klassenprofil aus dem Telos-Onboarding: Erfahrungslevel, Ziel-Zertifizierungen, Lernziele. Verstehe deine Klasse auf einen Blick.'),
          shortText: t('learning', 'Aggregierte Studierendenprofile und Lernziele.'),
        },
        'view:course-heatmap': {
          title: t('learning', 'Heatmap'),
          text: t('learning', 'Visuelle Heatmap, die zeigt welche Fragen leicht (grün) und welche schwer (rot) sind — klassenübergreifend. Erkenne Problemzonen schnell.'),
          shortText: t('learning', 'Schwierigkeits-Heatmap über alle Studierenden.'),
        },
        'view:course-weak-questions': {
          title: t('learning', 'Schwache Fragen'),
          text: t('learning', 'Fragen mit der höchsten Fehlerquote über alle Studierenden. Das sind die Themen, bei denen deine Klasse am meisten Probleme hat — fokussiere deinen Unterricht hier.'),
          shortText: t('learning', 'Fragen, die Studierende am häufigsten falsch beantworten.'),
        },
        'view:course-announcements': {
          title: t('learning', 'Ankündigungen'),
          text: t('learning', 'Veröffentliche Ankündigungen für alle Kursteilnehmer. Nutze sie für Prüfungserinnerungen, Terminänderungen oder Lerntipps.'),
          shortText: t('learning', 'Nachrichten für alle Studierenden veröffentlichen.'),
        },
        'view:course-exam-slot': {
          title: t('learning', 'Prüfungstermin'),
          text: t('learning', 'Plane Prüfungszeitfenster: Start/Ende festlegen, Pools wählen und Zeitlimits konfigurieren. Studierende können die Prüfung nur während des Zeitfensters ablegen.'),
          shortText: t('learning', 'Zeitgesteuerte Prüfungsfenster für Studierende planen.'),
        },
        'view:course-requests': {
          title: t('learning', 'Anfragen'),
          text: t('learning', 'Studierenden-Anfragen und Fehlermeldungen. Prüfe gemeldete Fragen, beantworte Hilfeanfragen und verbessere deine Inhalte.'),
          shortText: t('learning', 'Studierenden-Anfragen und Fehlermeldungen prüfen.'),
        },
        'view:course-mode-config': {
          title: t('learning', 'Modus-Konfiguration'),
          text: t('learning', 'Aktiviere oder deaktiviere Lernmodi für diesen Kurs: Training, Leitner, Prüfung, Arena, Oldschool, Abenteuer. Steuere, worauf Studierende zugreifen können.'),
          shortText: t('learning', 'Lernmodi für Studierende aktivieren/deaktivieren.'),
        },
        'view:course-materials': {
          title: t('learning', 'Materialien'),
          text: t('learning', 'Lade Lernmaterialien hoch und verwalte sie: PDFs, Links, Dokumente. Studierende sehen diese als Referenzmaterial neben ihren Fragen.'),
          shortText: t('learning', 'Referenzmaterialien für Studierende hochladen.'),
        },
        'view:course-curriculum': {
          title: t('learning', 'Lehrplan'),
          text: t('learning', 'Strukturiere deine Kursinhalte in Kapitel und Themen. Ordne Fragen Lehrplanzielen zu für gezieltes Lernen.'),
          shortText: t('learning', 'Inhalte in Kapitel und Themen organisieren.'),
        },
        // Pool-level views (outside courses)
        'view:training': {
          title: t('learning', 'Training'),
          text: t('learning', 'Hier übst du Fragen im Karteikarten-Stil. Nach jeder Antwort siehst du sofort, ob sie richtig war. Richtige Antworten wandern in höhere Leitner-Boxen.'),
          shortText: t('learning', 'Beantworte Fragen und lerne aus sofortigem Feedback.'),
        },
        'view:leitner': {
          title: t('learning', 'Leitner-System'),
          text: t('learning', 'Das Leitner-System zeigt dir fällige Karten basierend auf deinem Fortschritt. Je öfter du eine Karte richtig beantwortest, desto seltener erscheint sie — bis sie gemeistert ist.'),
          shortText: t('learning', 'Beantworte fällige Karten. Richtige rücken vor, falsche gehen zurück.'),
        },
        'view:exam': {
          title: t('learning', 'Prüfungssimulation'),
          text: t('learning', 'Prüfungsmodus: keine Hinweise, kein sofortiges Feedback, Zeitlimit wie in der echten Prüfung. Dein Ergebnis siehst du erst am Ende.'),
          shortText: t('learning', 'Prüfungsmodus ohne Hinweise — Ergebnis erst nach allen Fragen.'),
        },
        'view:pool-manage': {
          title: t('learning', 'Pool-Verwaltung'),
          text: t('learning', 'Hier erstellst und verwaltest du Fragen in diesem Pool. Du kannst Fragen hinzufügen, bearbeiten, löschen und per CSV oder JSON importieren.'),
          shortText: t('learning', 'Fragen hinzufügen, bearbeiten, löschen und importieren.'),
        },
        'view:pools': {
          title: t('learning', 'Fragenpools'),
          text: t('learning', 'Hier siehst du alle deine Fragenpools. Wähle einen Pool, um mit dem Üben zu beginnen. Als Dozent kannst du auch Pools erstellen und verwalten.'),
          shortText: t('learning', 'Wähle einen Pool, um mit dem Üben zu beginnen.'),
        },
        'view:abenteuer': {
          title: t('learning', 'Abenteuermodus'),
          text: t('learning', 'Starte eine Kampagne und löse Netzwerkprobleme in einer Geschichte. Jede Entscheidung bringt dich weiter — Fehler kosten Lebenspunkte.'),
          shortText: t('learning', 'Löse Netzwerkprobleme in einer interaktiven Geschichte.'),
        },
        'view:settings': {
          title: t('learning', 'Einstellungen'),
          text: t('learning', 'Persönliche Einstellungen: Lernsprache, Telos-Profil und VirtuProf-Optionen. Hier kannst du VirtuProf auch aktivieren oder deaktivieren.'),
          shortText: t('learning', 'Sprache, Telos-Profil und VirtuProf-Optionen einstellen.'),
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
          title: t('learning', 'Subnetzrechner'),
          text: t('learning', 'Dieses Tool zerlegt CIDR-Netzwerke in Netzadresse, Broadcast, Hostbereich und Binärform. Nutze es, wenn Subnetzmasken noch abstrakt wirken.'),
          shortText: t('learning', 'Der Subnetzrechner zeigt Netzwerk, Broadcast und Hostbereich für einen CIDR-Block.'),
        },
        dns: {
          title: t('learning', 'DNS-Resolver'),
          text: t('learning', 'Dieser Simulator durchläuft rekursive DNS-Lookups Schritt für Schritt. Er hilft dir zu sehen, welcher Server was antwortet und wo typische DNS-Fehler auftreten.'),
          shortText: t('learning', 'Der DNS-Resolver zeigt die Lookup-Kette vom Resolver bis zur autoritativen Antwort.'),
        },
        firewall: {
          title: t('learning', 'Firewall / ACL Builder'),
          text: t('learning', 'Hier baust du geordnete Firewall-Regeln und testest Pakete dagegen. Nützlich zum Verständnis von First-Match-Logik, Allow/Deny-Entscheidungen und Implicit Deny.'),
          shortText: t('learning', 'Der Firewall-Builder lässt dich Paketflüsse gegen geordnete ACL-Regeln testen.'),
        },
        portscan: {
          title: t('learning', 'Port-Scanner'),
          text: t('learning', 'Dieses Tool simuliert typische Scan-Ergebnisse für verschiedene Host-Profile. Nutze es, um Service-Exposition zu lesen und verdächtige offene Ports schnell zu erkennen.'),
          shortText: t('learning', 'Der Port-Scanner zeigt, welche Dienste ein Host exponiert und was das bedeutet.'),
        },
        routing: {
          title: t('learning', 'Routing-Tabelle'),
          text: t('learning', 'Dieser Simulator vergleicht Routen nach Präfixlänge und Metrik. Er macht Longest-Prefix-Match und Default-Route-Verhalten sichtbar statt rein theoretisch.'),
          shortText: t('learning', 'Der Routing-Tabellen-Simulator erklärt Longest-Prefix-Match und Routenauswahl.'),
        },
        nat: {
          title: t('learning', 'NAT-Tabelle'),
          text: t('learning', 'Dieses Tool demonstriert statisches NAT, dynamisches NAT und PAT mit Live-Übersetzungen. Sieh, wie interne und externe Adressen in jedem Modus umgeschrieben werden.'),
          shortText: t('learning', 'Der NAT-Simulator zeigt, wie private und öffentliche Adressen übersetzt werden.'),
        },
        wireshark: {
          title: t('learning', 'Wireshark Lite'),
          text: t('learning', 'Dieser Paket-Viewer reduziert Captures auf die wesentlichen Layer und Anomalien. Gedacht zum Üben des Protokoll-Lesens, ohne sich in jedem Feld zu verlieren.'),
          shortText: t('learning', 'Wireshark Lite hebt die wichtigen Paket-Layer und Anomalien hervor.'),
        },
        authflow: {
          title: t('learning', '802.1X Auth Flow'),
          text: t('learning', 'Dieser Simulator vergleicht EAP-basierte Authentifizierungsabläufe Schritt für Schritt. Verstehe, wer mit wem während 802.1X kommuniziert und wo Credentials oder Zertifikate geprüft werden.'),
          shortText: t('learning', 'Der Auth-Flow-Simulator vergleicht die wichtigsten 802.1X-Handshakes.'),
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

    // Issue #10: persist student-view override and re-route to a sensible page
    // if the user is currently on a route that no longer matches the new view.
    setStudentViewOverride(enabled) {
      const next = !!enabled;
      this.studentViewOverride = next;
      try {
        if (typeof window !== 'undefined' && window.localStorage) {
          if (next) {
            window.localStorage.setItem('learning:view-as-student', 'yes');
          } else {
            window.localStorage.removeItem('learning:view-as-student');
          }
        }
      } catch (_e) {
        // localStorage may be unavailable (private mode) — fail silently
      }
      const studentRoutes = ['dashboard', 'skillmap', 'virtuprof-fullscreen'];
      const instructorRoutes = ['courses', 'course-tab'];
      const currentName = this.$route?.name;
      if (next && currentName && instructorRoutes.includes(currentName)) {
        this.pushAppRoute({ name: 'dashboard' });
      } else if (!next && currentName && studentRoutes.includes(currentName)) {
        this.pushAppRoute({ name: 'courses' });
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
      const target = this.effectiveUserRole === 'student' ? { name: 'dashboard' } : { name: 'courses' };
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
        if (!this._skipRouteReset) {
          this.backToPools();
        }
        const routePoolId = Number(route.query?.poolId || 0);
        if (routePoolId > 0) {
          this.openPoolFromRoute(routePoolId);
        }
        if (this._pendingAdventureRoute) {
          this.currentView = 'abenteuer';
          this.adventureRoute = { ...this._pendingAdventureRoute };
          this._pendingAdventureRoute = null;
        }
        return;
      }

      if (route.name === 'tools') {
        if (this.selectedCourse?.id) {
          this.pushAppRoute(this.courseRouteLocation(this.selectedCourse.id, 'tools'), true);
        } else {
          this.pushAppRoute({ name: 'courses' }, true);
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
        this.error = t('learning', 'Fragenanzahl konnte nicht geladen werden');
        this.questionCount = 0;
      }
    },
    async openPoolFromRoute(poolId) {
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/pools/' + poolId));
        await this.selectPool(response.data);
      } catch (err) {
        this.error = t('learning', 'Pool konnte nicht geöffnet werden');
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
    openCourseTool(_toolId) {
      if (this.selectedCourse?.id) {
        this.pushAppRoute(this.courseRouteLocation(this.selectedCourse.id, 'tools'));
        return;
      }
      this.pushAppRoute({ name: 'courses' });
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
      this._skipRouteReset = true;
      this.pushAppRoute({ name: 'pools' });
      this.$nextTick(() => { this._skipRouteReset = false; });
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
