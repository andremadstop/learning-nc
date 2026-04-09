<template>
  <div class="student-dashboard">
    <div class="dashboard-header">
      <h3>{{ t('learning', 'Heute') }}</h3>
    </div>

    <div v-if="loading" class="loading-container">
      <NcLoadingIcon :size="44" />
      <p>{{ t('learning', 'Loading...') }}</p>
    </div>

    <template v-if="!loading">
      <div class="dashboard-grid">
        <!-- Left column: SmartQueue + Daily Challenge -->
        <div class="dashboard-col-left">
          <!-- SmartQueue Widget -->
          <div class="widget-card widget-smartqueue">
            <p class="section-label">{{ t('learning', 'Fällige Karten') }}</p>
            <div class="sq-widget-body">
              <span class="sq-widget-icon">&#x1F4DA;</span>
              <span class="sq-widget-count">{{ queueCount }}</span>
              <span class="sq-widget-label">{{ t('learning', 'due') }}</span>
            </div>
            <div class="sq-widget-actions">
              <button class="sq-start-btn" :disabled="queueCount === 0" @click="$emit('openSmartQueue')">
                {{ t('learning', 'Jetzt lernen') }}
              </button>
              <button v-if="remediationCount > 0" class="sq-remediation-btn" @click="$emit('openRemediation')">
                <span>&#x26A0;</span>
                {{ t('learning', 'Problemkarten') }}
                <span class="sq-rem-count">{{ remediationCount }}</span>
              </button>
            </div>
          </div>

          <!-- Maintenance Widget -->
          <div v-if="maintenanceStats && maintenanceStats.due_cards > 0" class="widget-card widget-maintenance">
            <p class="section-label">{{ t('learning', 'Wartungsmodus') }}</p>
            <div class="maintenance-body">
              <span class="maintenance-icon">&#x1F527;</span>
              <div class="maintenance-info">
                <span class="maintenance-count">{{ maintenanceStats.due_cards }}</span>
                <span class="maintenance-label">{{ t('learning', 'Karten faellig aus {n} Kursen', { n: maintenanceStats.courses }) }}</span>
              </div>
            </div>
            <p class="maintenance-estimate">~{{ maintenanceStats.estimated_minutes }} {{ t('learning', 'Min.') }}</p>
            <button class="sq-start-btn maintenance-start-btn" @click="startMaintenanceSession">
              {{ t('learning', '10 Min Wartung') }}
            </button>
          </div>

          <!-- Daily Challenge -->
          <DailyChallengeCard />
        </div>

        <!-- Right column: Streak + Daily Progress + Quick Links -->
        <div class="dashboard-col-right">
          <!-- Streak Widget -->
          <div class="widget-card widget-streak">
            <p class="section-label">{{ t('learning', 'Streak') }}</p>
            <div class="streak-main">
              <span class="streak-flame">&#x1F525;</span>
              <span class="streak-number">{{ streakCurrent }}</span>
              <span class="streak-unit">{{ t('learning', 'Tage') }}</span>
              <span v-if="streakActiveToday" class="streak-active-badge">{{ t('learning', 'Heute aktiv') }}</span>
            </div>
            <div class="streak-details">
              <span class="streak-detail">{{ t('learning', 'Freeze-Token: {n}', { n: streakFreezeTokens }) }}</span>
              <span class="streak-detail">{{ t('learning', 'Laengste: {n} Tage', { n: streakLongest }) }}</span>
            </div>
          </div>

          <!-- Daily Progress Widget -->
          <div class="widget-card widget-progress">
            <p class="section-label">{{ t('learning', 'Tagesfortschritt') }}</p>
            <div class="progress-stats">
              <div class="progress-stat">
                <span class="progress-value">{{ dailyXp }}</span>
                <span class="progress-label">XP {{ t('learning', 'heute') }}</span>
              </div>
              <div class="progress-stat">
                <span class="progress-value">{{ dailySessions }}</span>
                <span class="progress-label">{{ t('learning', 'Sessions') }}</span>
              </div>
              <div class="progress-stat">
                <span class="progress-value">{{ userLevel }}</span>
                <span class="progress-label">{{ t('learning', 'Level') }}</span>
              </div>
            </div>
          </div>

          <!-- Quick Links -->
          <div class="widget-card widget-links">
            <p class="section-label">{{ t('learning', 'Quick Links') }}</p>
            <div class="link-buttons">
              <button class="link-btn" @click="goToView('pools')">
                {{ t('learning', 'Meine Pools') }}
              </button>
              <button class="link-btn" @click="goToView('werkzeuge')">
                {{ t('learning', 'Werkzeuge') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Timeline Widget -->
      <div v-if="showTimeline" class="dashboard-timeline-section">
        <div class="widget-card widget-timeline">
          <div class="timeline-header">
            <p class="section-label">{{ t('learning', 'Zeitplan') }}</p>
            <select
              v-if="enrolledCourses.length > 1"
              v-model="selectedTimelineCourse"
              class="timeline-course-select">
              <option v-for="c in enrolledCourses" :key="c.id" :value="c.id">
                {{ c.title }}
              </option>
            </select>
            <span v-else-if="enrolledCourses.length === 1" class="timeline-course-name">
              {{ enrolledCourses[0].title }}
            </span>
          </div>
          <CourseTimeline v-if="timelineCourseId" :course-id="timelineCourseId" />
        </div>
      </div>

      <!-- Cheat Sheet Export -->
      <div class="dashboard-cheatsheet-section">
        <CheatSheetExport :user-name="userName" />
      </div>

      <!-- Global Feed Section -->
      <div class="dashboard-feed-section">
        <GlobalFeed />
      </div>
    </template>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import DailyChallengeCard from './DailyChallengeCard.vue'
import GlobalFeed from './GlobalFeed.vue'
import CourseTimeline from './CourseTimeline.vue'
import CheatSheetExport from './CheatSheetExport.vue'

export default {
  name: 'StudentDashboard',

  components: {
    NcLoadingIcon,
    DailyChallengeCard,
    GlobalFeed,
    CourseTimeline,
    CheatSheetExport,
  },

  data() {
    return {
      loading: false,
      queueCount: 0,
      remediationCount: 0,
      maintenanceStats: null,
      enrolledCourses: [],
      selectedTimelineCourse: null,
      userState: {
        streak: {
          current_streak: 0,
          longest_streak: 0,
          freeze_tokens: 0,
          is_active_today: false,
        },
        xp: { total_xp: 0, level: 1 },
        daily_progress: {
          cards_reviewed_today: 0,
          daily_goal: 20,
          sessions_today: 0,
        },
      },
    }
  },

  computed: {
    userName() {
      if (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function') {
        const user = OC.getCurrentUser()
        return user?.displayName || user?.uid || ''
      }
      return ''
    },
    timelineCourseId() {
      if (this.selectedTimelineCourse) return this.selectedTimelineCourse
      return this.enrolledCourses.length > 0 ? this.enrolledCourses[0].id : null
    },
    showTimeline() {
      return this.enrolledCourses.length > 0
    },
    streakCurrent() {
      return this.userState.streak.current_streak || 0
    },
    streakLongest() {
      return this.userState.streak.longest_streak || 0
    },
    streakFreezeTokens() {
      return this.userState.streak.freeze_tokens || 0
    },
    streakActiveToday() {
      return !!this.userState.streak.is_active_today
    },
    dailyXp() {
      return this.userState.daily_progress?.cards_reviewed_today || 0
    },
    dailySessions() {
      return this.userState.daily_progress?.sessions_today || 0
    },
    userLevel() {
      return this.userState.xp?.level || 1
    },
  },

  mounted() {
    this.loadAll()
  },

  methods: {
    async loadAll() {
      this.loading = true
      try {
        await Promise.all([
          this.loadQueueCount(),
          this.loadRemediationCount(),
          this.loadUserState(),
          this.loadEnrolledCourses(),
          this.loadMaintenanceStats(),
        ])
      } finally {
        this.loading = false
      }
    },

    async loadQueueCount() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/leitner/queue/count'))
        this.queueCount = r.data.count || 0
      } catch (e) { /* optional */ }
    },

    async loadRemediationCount() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/leitner/remediation/count'))
        this.remediationCount = r.data.count || 0
      } catch (e) { /* optional */ }
    },

    async loadEnrolledCourses() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/courses'))
        const courses = r.data?.courses || r.data || []
        this.enrolledCourses = Array.isArray(courses) ? courses.filter((c) => !c.is_instructor) : []
      } catch (e) { /* optional */ }
    },

    async loadUserState() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/v1/user/state'))
        if (r.data.streak) {
          this.userState.streak = r.data.streak
        }
        if (r.data.xp) {
          this.userState.xp = r.data.xp
        }
        if (r.data.daily_progress) {
          this.userState.daily_progress = r.data.daily_progress
        }
      } catch (e) { /* optional */ }
    },

    async loadMaintenanceStats() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/maintenance/stats'))
        this.maintenanceStats = r.data
      } catch (e) { /* optional */ }
    },

    startMaintenanceSession() {
      this.$emit('openSmartQueue', { maintenance: true })
    },

    goToView(view) {
      this.$emit('switchView', view)
    },
  },
}
</script>

<style scoped>
.student-dashboard {
  max-width: 1200px;
  margin: 0 auto;
}

.dashboard-header {
  margin-bottom: 24px;
}

.dashboard-header h3 {
  margin: 0;
  font-size: 1.3em;
  font-weight: 700;
  color: var(--color-main-text);
}

.loading-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 60px 20px;
  color: var(--color-text-maxcontrast);
}

.loading-container p { margin-top: 12px; }

/* Section Labels — Data Observatory Style */
.section-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: var(--color-text-maxcontrast);
  font-weight: 700;
  margin: 0 0 12px;
}

/* Grid Layout */
.dashboard-grid {
  display: grid;
  grid-template-columns: 65fr 35fr;
  gap: 20px;
  align-items: start;
}

.dashboard-col-left {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.dashboard-col-right {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

/* Widget Card Base */
.widget-card {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large, 12px);
  padding: 20px;
  transition: transform 0.2s, box-shadow 0.2s;
}

.widget-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px color-mix(in srgb, var(--color-main-text) 8%, transparent);
}

/* SmartQueue Widget */
.widget-smartqueue {
  border-left: 3px solid var(--color-primary-element);
}

.sq-widget-body {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}

.sq-widget-icon { font-size: 28px; }

.sq-widget-count {
  font-size: 2.2em;
  font-weight: 700;
  color: var(--color-primary-element);
  line-height: 1;
}

.sq-widget-label {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
  font-weight: 500;
}

.sq-widget-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}

.sq-start-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 12px 24px;
  border: none;
  border-radius: 12px;
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.sq-start-btn:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}

.sq-start-btn:disabled {
  opacity: 0.5;
  cursor: default;
}

.sq-remediation-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 12px 20px;
  border: 2px solid var(--color-warning);
  border-radius: 12px;
  background: color-mix(in srgb, var(--color-warning) 10%, var(--color-main-background));
  color: var(--color-warning);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.sq-remediation-btn:hover {
  background: color-mix(in srgb, var(--color-warning) 20%, var(--color-main-background));
  transform: translateY(-1px);
}

.sq-rem-count {
  padding: 2px 8px;
  border-radius: 10px;
  background: color-mix(in srgb, var(--color-warning) 20%, transparent);
  font-size: 12px;
}

/* Maintenance Widget */
.widget-maintenance {
  border-left: 3px solid var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 3%, var(--color-main-background));
}

.maintenance-body {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
}

.maintenance-icon { font-size: 28px; }

.maintenance-info {
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.maintenance-count {
  font-size: 2em;
  font-weight: 700;
  color: var(--color-primary-element);
  line-height: 1;
}

.maintenance-label {
  font-size: 13px;
  color: var(--color-text-maxcontrast);
  font-weight: 500;
}

.maintenance-estimate {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  margin: 0 0 12px;
}

.maintenance-start-btn {
  background: var(--color-primary-element);
}

/* Streak Widget */
.widget-streak {
  border-left: 3px solid var(--color-warning);
}

.streak-main {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}

.streak-flame { font-size: 28px; }

.streak-number {
  font-size: 2em;
  font-weight: 700;
  color: var(--color-warning);
  line-height: 1;
}

.streak-unit {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
  font-weight: 500;
}

.streak-active-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  background: color-mix(in srgb, var(--color-success) 15%, transparent);
  color: var(--color-success);
  margin-left: auto;
}

.streak-active-badge::before {
  content: '';
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-success);
}

.streak-details {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.streak-detail {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
}

/* Daily Progress Widget */
.widget-progress {
  border-left: 3px solid var(--color-success);
}

.progress-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  text-align: center;
}

.progress-stat {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.progress-value {
  font-size: 1.6em;
  font-weight: 700;
  color: var(--color-main-text);
  line-height: 1.2;
}

.progress-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--color-text-maxcontrast);
  font-weight: 500;
}

/* Quick Links */
.widget-links {
  border-left: 3px solid var(--color-primary-element);
}

.link-buttons {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.link-btn {
  display: block;
  width: 100%;
  padding: 10px 16px;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large, 12px);
  background: var(--color-main-background);
  color: var(--color-primary-element);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  text-align: left;
  transition: all 0.2s;
}

.link-btn:hover {
  border-color: var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 6%, var(--color-main-background));
}

/* Timeline Section */
.dashboard-timeline-section {
  margin-top: 24px;
  padding-top: 24px;
  border-top: 1px solid var(--color-border);
}

.widget-timeline {
  border-left: 3px solid var(--color-primary-element);
}

.timeline-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 4px;
}

.timeline-header .section-label {
  margin: 0;
}

.timeline-course-select {
  padding: 4px 8px;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  font-size: 0.85em;
}

.timeline-course-name {
  font-size: 0.85em;
  color: var(--color-text-maxcontrast);
}

/* Cheat Sheet Section */
.dashboard-cheatsheet-section {
  margin-top: 24px;
  padding-top: 24px;
  border-top: 1px solid var(--color-border);
}

/* Feed Section */
.dashboard-feed-section {
  margin-top: 24px;
  padding-top: 24px;
  border-top: 1px solid var(--color-border);
}

/* Responsive */
@media (max-width: 768px) {
  .dashboard-grid {
    grid-template-columns: 1fr;
  }
}
</style>
