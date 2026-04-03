<template>
  <div class="analytics-dashboard">
    <div class="header">
      <NcButton @click="goBack" type="tertiary">
        {{ t('learning', 'Back to Mode Selector') }}
      </NcButton>
      <NcButton @click="fetchStats" type="tertiary" :disabled="loading">
        {{ t('learning', 'Refresh Stats') }}
      </NcButton>
    </div>

    <div v-if="loading" class="loading">{{ t('learning', 'Loading statistics...') }}</div>
    <div v-else-if="error" class="error">{{ error }}</div>
    <div v-else>

      <!-- XP Hero Banner -->
      <div v-if="xp.total_xp > 0" class="xp-hero">
        <div class="xp-hero-badge">
          <span class="xp-hero-level">{{ xp.level }}</span>
        </div>
        <div class="xp-hero-content">
          <div class="xp-hero-header">
            <span class="xp-hero-title">{{ t('learning', 'Level {n}', { n: xp.level }) }}</span>
            <span class="xp-hero-total">{{ xp.total_xp }} XP</span>
          </div>
          <div class="xp-hero-bar">
            <div class="xp-hero-bar-fill" :style="{ width: xp.level_progress_pct + '%' }"></div>
          </div>
          <div class="xp-hero-sublabel">{{ xp.xp_in_level }} / {{ xp.xp_to_next_level }} XP {{ t('learning', 'to next level') }}</div>
        </div>
      </div>

      <!-- Summary Cards — Asymmetric Grid -->
      <h3 class="section-heading">{{ t('learning', 'Overview') }}</h3>
      <div class="summary-grid">
        <div v-if="streak.current_streak > 0" class="summary-card streak-card" :title="t('learning', 'Longest: {n} days', { n: streak.longest_streak })">
          <span class="card-watermark">&#x1F525;</span>
          <p class="card-label">{{ t('learning', 'Streak') }}</p>
          <p class="card-value streak-value">{{ streak.current_streak }}</p>
          <p class="card-sub streak-sub">
            <span class="streak-flame-row">
              <span v-for="i in Math.min(streak.current_streak, 7)" :key="i" class="streak-flame" :style="{ animationDelay: (i * 0.1) + 's' }">&#x1F525;</span>
            </span>
            {{ streak.current_streak === 1 ? t('learning', 'day') : t('learning', 'days') }}
            &middot; {{ t('learning', 'Best: {n}', { n: streak.longest_streak }) }}
          </p>
          <p class="card-sub">{{ t('learning', 'Freeze Tokens: {n}', { n: streak.freeze_tokens || 0 }) }}</p>
        </div>
        <div class="summary-card card-questions">
          <span class="card-watermark">&#x1F4DA;</span>
          <p class="card-label">{{ t('learning', 'Total Questions') }}</p>
          <p class="card-value">{{ stats.total }}</p>
        </div>
        <div class="summary-card card-mastered">
          <span class="card-watermark">&#x2B50;</span>
          <p class="card-label">{{ t('learning', 'Mastered') }}</p>
          <p class="card-value">{{ stats.mastered }}</p>
        </div>
        <div class="summary-card card-due">
          <span class="card-watermark">&#x23F0;</span>
          <p class="card-label">{{ t('learning', 'Due Today') }}</p>
          <p class="card-value">{{ stats.due_count }}</p>
        </div>
        <div class="summary-card card-accuracy">
          <span class="card-watermark">&#x1F3AF;</span>
          <p class="card-label">{{ t('learning', 'Accuracy') }}</p>
          <p class="card-value">{{ stats.accuracy }}%</p>
        </div>
      </div>

      <div class="missions-section" v-if="missionsData.missions && missionsData.missions.length > 0">
        <h3 class="section-heading">{{ t('learning', 'Daily Missions') }}</h3>
        <div class="missions-grid">
          <div v-for="m in missionsData.missions" :key="m.key" class="mission-card" :class="{ done: m.completed, claimed: m.claimed }">
            <div class="mission-title">{{ m.name }}</div>
            <div class="mission-desc">{{ m.description }}</div>
            <div class="mission-progress">{{ m.current }}/{{ m.target }}</div>
            <div class="mission-actions">
              <span class="mission-xp">+{{ m.xp }} XP</span>
              <NcButton v-if="m.claimable" type="primary" :disabled="claimingMissionKey === m.key" @click="claimMission(m.key)">
                {{ claimingMissionKey === m.key ? t('learning', 'Claiming...') : t('learning', 'Claim') }}
              </NcButton>
              <span v-else class="mission-status">
                {{ m.claimed ? t('learning', 'Claimed') : (m.completed ? t('learning', 'Ready') : t('learning', 'In Progress')) }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Leitner Tower — Signature Visual -->
      <h3 class="section-heading">{{ t('learning', 'Leitner Box Distribution') }}</h3>
      <div class="leitner-tower-desktop">
        <div class="tower-chart">
          <div class="tower-col" v-for="(box, idx) in [
            { count: stats.box_1, label: t('learning', 'Box 1'), sub: '1d', cls: 'tower-1' },
            { count: stats.box_2, label: t('learning', 'Box 2'), sub: '3d', cls: 'tower-2' },
            { count: stats.box_3, label: t('learning', 'Box 3'), sub: '7d', cls: 'tower-3' },
            { count: stats.box_4, label: t('learning', 'Box 4'), sub: '14d', cls: 'tower-4' },
            { count: stats.box_5, label: t('learning', 'Box 5'), sub: '30d', cls: 'tower-5' },
          ]" :key="idx">
            <span class="tower-count">{{ box.count }}</span>
            <div class="tower-bar-wrapper">
              <div :class="['tower-bar', box.cls]" :style="{ '--tower-h': towerHeight(box.count), animationDelay: (idx * 0.1) + 's' }"></div>
            </div>
            <span class="tower-label">{{ box.label }}</span>
            <span class="tower-sub">{{ box.sub }}</span>
          </div>
        </div>
      </div>
      <!-- Mobile fallback: horizontal bars -->
      <div class="leitner-tower-mobile">
        <div class="box-bar-container" v-for="(box, idx) in [
          { count: stats.box_1, label: t('learning', 'Box 1 (New)'), color: 'error' },
          { count: stats.box_2, label: t('learning', 'Box 2'), color: 'warning' },
          { count: stats.box_3, label: t('learning', 'Box 3'), color: 'primary-element' },
          { count: stats.box_4, label: t('learning', 'Box 4'), color: 'primary-element' },
          { count: stats.box_5, label: t('learning', 'Box 5 (Mastered)'), color: 'success' },
        ]" :key="'m'+idx">
          <div class="box-label">{{ box.label }}</div>
          <div class="box-visual-wrapper">
            <div class="box-bar" :style="boxBarStyle(box.count, box.color)">
              <span class="box-count">{{ box.count }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Mastery + Accuracy — Side-by-Side Panel -->
      <h3 class="section-heading">{{ t('learning', 'Performance') }}</h3>
      <div class="data-panel">
        <div class="data-panel-card">
          <p class="data-panel-label">{{ t('learning', 'Mastery Progress') }}</p>
          <div class="mastery-ring" :style="masteryCircleComputedStyle">
            <div class="mastery-inner">
              <span class="mastery-percentage">{{ stats.mastery_percentage }}%</span>
            </div>
          </div>
          <p class="data-panel-sub">{{ stats.mastered }} / {{ stats.total }} {{ t('learning', 'Mastered') }}</p>
        </div>
        <div class="data-panel-card">
          <p class="data-panel-label">{{ t('learning', 'Accuracy') }}</p>
          <p class="accuracy-big">{{ stats.accuracy }}%</p>
          <div class="accuracy-bar-segmented">
            <div class="accuracy-bar-fill" :style="accuracyProgressStyle"></div>
            <div class="accuracy-tick" style="left:25%"></div>
            <div class="accuracy-tick" style="left:50%"></div>
            <div class="accuracy-tick" style="left:75%"></div>
          </div>
          <div class="accuracy-scale">
            <span>0</span><span>25</span><span>50</span><span>75</span><span>100</span>
          </div>
          <p class="data-panel-sub">{{ totalCorrectFormatted }}</p>
        </div>
      </div>

      <!-- Achievements Section — Enhanced -->
      <div v-if="badges.length > 0" class="achievements-section">
        <div class="achievements-header">
          <h3 class="section-heading">{{ t('learning', 'Achievements') }}</h3>
          <button
            v-if="earnedLegacyBadges.length > 0"
            type="button"
            class="archive-toggle"
            :aria-expanded="legacyArchiveOpen ? 'true' : 'false'"
            @click="toggleLegacyArchive">
            {{ legacyArchiveOpen
              ? t('learning', 'Archiv ausblenden')
              : t('learning', 'Archiv anzeigen ({n})', { n: earnedLegacyBadges.length }) }}
          </button>
        </div>
        <div class="badges-grid">
          <div
            v-for="badge in activeBadges"
            :key="badge.badge_id"
            :class="['badge-item', { 'badge-locked': !badge.earned }]"
            :title="badgeTitle(badge)"
          >
            <span class="badge-emoji">{{ badge.emoji }}</span>
            <span class="badge-name">{{ badgeLabel(badge) }}</span>
            <span class="badge-meta">
              {{ badge.earned
                ? (badge.earned_at ? formatDate(badge.earned_at) : t('learning', 'Freigeschaltet'))
                : badgeTrigger(badge) }}
            </span>
          </div>
        </div>
        <div v-if="activeBadgeProgress.length > 0" class="badge-progress-section">
          <h4>{{ t('learning', 'Next Achievements') }}</h4>
          <div v-for="bp in activeBadgeProgress" :key="bp.badge_id" class="badge-progress-item">
            <span class="bp-emoji">{{ bp.emoji }}</span>
            <div class="bp-copy">
              <span class="bp-name">{{ badgeLabel(bp) }}</span>
              <span v-if="badgeTrigger(bp)" class="bp-trigger">{{ badgeTrigger(bp) }}</span>
            </div>
            <div class="bp-bar"><div class="bp-bar-fill" :style="{ width: bp.percentage + '%' }"></div></div>
            <span class="bp-label">{{ bp.current }}/{{ bp.target }}</span>
          </div>
        </div>
        <div v-if="legacyArchiveOpen && earnedLegacyBadges.length > 0" class="badge-archive">
          <h4>{{ t('learning', 'Legacy-Archiv') }}</h4>
          <div class="badges-grid badges-grid--archive">
            <div
              v-for="badge in earnedLegacyBadges"
              :key="badge.badge_id"
              class="badge-item badge-item--legacy"
              :title="badgeTitle(badge)"
            >
              <span class="badge-emoji">{{ badge.emoji }}</span>
              <span class="badge-name">{{ badgeLabel(badge) }}</span>
              <span class="badge-meta">{{ badge.earned_at ? formatDate(badge.earned_at) : t('learning', 'Freigeschaltet') }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

const ACTIVE_BADGE_ORDER = ['pioneer', 'streak_7', 'streak_14', 'mastermind', 'exam_ready', 'simulator', 'weekend', 'swarm', 'trouble_fixer', 'quick_thinker'];

export default {
  name: 'AnalyticsDashboard',
  components: {
    NcButton,
  },
  props: {
    poolId: {
      type: Number,
      required: true,
    },
  },
  data() {
    return {
      loading: true,
      error: null,
      stats: {
        box_1: 0,
        box_2: 0,
        box_3: 0,
        box_4: 0,
        box_5: 0,
        total: 0,
        mastered: 0,
        mastery_percentage: 0,
        due_count: 0,
        total_correct: 0,
        total_answered: 0,
        accuracy: 0,
      },
      streak: { current_streak: 0, longest_streak: 0, is_active_today: false, freeze_tokens: 0 },
      badges: [],
      badgeProgress: [],
      xp: { total_xp: 0, level: 1, xp_in_level: 0, xp_to_next_level: 100, level_progress_pct: 0 },
      missionsData: { date: null, missions: [] },
      claimingMissionKey: null,
      legacyArchiveOpen: false,
    };
  },
  computed: {
    totalCorrectFormatted() {
      if (this.stats.total_answered === 0) {
        return '0/0 (0%)';
      }
      return `${this.stats.total_correct}/${this.stats.total_answered} (${this.stats.accuracy}%)`;
    },
    masteryCircleComputedStyle() {
      const percentage = this.stats.mastery_percentage || 0;
      return {
        background: `conic-gradient(var(--color-success) ${percentage}%, var(--color-background-dark) ${percentage}%)`,
      };
    },
    accuracyProgressStyle() {
      return {
        width: `${this.stats.accuracy || 0}%`,
        backgroundColor: 'var(--color-primary-element)',
      };
    },
    activeBadges() {
      return this.sortBadges((this.badges || []).filter((badge) => !badge.legacy));
    },
    activeBadgeProgress() {
      return this.sortBadges((this.badgeProgress || []).filter((badge) => !badge.legacy));
    },
    earnedLegacyBadges() {
      return this.sortBadges((this.badges || []).filter((badge) => badge.legacy && badge.earned));
    },
  },
  methods: {
    async fetchStats() {
      this.loading = true;
      this.error = null;
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/leitner/stats'), {
          params: { poolId: this.poolId },
        });
        this.stats = { ...this.stats, ...response.data };
      } catch (e) {
        console.error('Failed to fetch stats:', e);
        this.error = t('learning', 'Failed to load statistics. Please try again.');
      } finally {
        this.loading = false;
      }
    },
    goBack() {
      this.$emit('back');
    },
    async fetchUserState() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/v1/user/state'));
        this.streak = r.data.streak || this.streak;
        this.badges = r.data.badges || [];
        this.xp = r.data.xp || this.xp;
        this.badgeProgress = r.data.progress || [];
        this.missionsData = r.data.missions || this.missionsData;
      } catch (e) {
        // Fallback to individual endpoints (rolling-deploy safety, handles 404 + 5xx)
        await Promise.all([
          this.fetchStreakLegacy(),
          this.fetchBadgesLegacy(),
          this.fetchBadgeProgressLegacy(),
        ]);
      }
    },
    async fetchStreakLegacy() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/streak'));
        this.streak = r.data;
      } catch (e) { /* streak is optional */ }
    },
    async fetchBadgesLegacy() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/badges'));
        this.badges = r.data.badges || [];
        this.xp = r.data.xp || this.xp;
      } catch (e) { /* badges are optional */ }
    },
    async fetchBadgeProgressLegacy() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/badges/progress'));
        this.badgeProgress = r.data?.progress || [];
      } catch (e) { /* progress is optional */ }
    },
    async claimMission(missionKey) {
      this.claimingMissionKey = missionKey;
      try {
        await axios.post(generateUrl('/apps/learning/api/v1/missions/{missionKey}/claim', { missionKey }), {});
        await this.fetchUserState();
      } catch (e) {
        // ignore toast spam
      } finally {
        this.claimingMissionKey = null;
      }
    },
    formatDate(ts) {
      if (!ts) return '';
      return new Date(ts * 1000).toLocaleDateString();
    },
    boxBarStyle(count, colorName) {
      const total = this.stats.total;
      const width = total > 0 ? (count / total) * 100 : 0;
      return {
        width: `${width}%`,
        backgroundColor: `color-mix(in srgb, var(--color-${colorName}) 25%, transparent)`,
        borderLeft: `3px solid var(--color-${colorName})`,
      };
    },
    towerHeight(count) {
      const total = this.stats.total;
      const pct = total > 0 ? (count / total) * 100 : 0;
      return pct.toFixed(1) + '%';
    },
    sortBadges(badges) {
      const rankFor = (badgeId) => {
        const rank = ACTIVE_BADGE_ORDER.indexOf(badgeId);
        return rank === -1 ? ACTIVE_BADGE_ORDER.length + 100 : rank;
      };

      return [...badges].sort((left, right) => {
        const rankDiff = rankFor(left.badge_id) - rankFor(right.badge_id);
        if (rankDiff !== 0) {
          return rankDiff;
        }

        return String(this.badgeLabel(left)).localeCompare(String(this.badgeLabel(right)));
      });
    },
    badgeLabel(badge) {
      const key = badge?.name_key || badge?.name || '';
      return key ? t('learning', key) : '';
    },
    badgeDescription(badge) {
      const key = badge?.description_key || badge?.description || '';
      return key ? t('learning', key) : '';
    },
    badgeTrigger(badge) {
      const key = badge?.trigger_key || badge?.trigger || '';
      return key ? t('learning', key) : '';
    },
    badgeTitle(badge) {
      const parts = [
        this.badgeLabel(badge),
        this.badgeDescription(badge),
      ].filter(Boolean);

      if (badge?.earned && badge?.earned_at) {
        parts.push(this.formatDate(badge.earned_at));
      }

      return parts.join(' · ');
    },
    toggleLegacyArchive() {
      this.legacyArchiveOpen = !this.legacyArchiveOpen;
    },
  },
  created() {
    this.fetchStats();
    this.fetchUserState();
  },
};
</script>

<style scoped>
/* ── Base ── */
.analytics-dashboard {
  max-width: 800px;
  margin: 0 auto;
  padding: 20px;
  color: var(--color-main-text);
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.loading, .error {
  text-align: center;
  padding: 20px;
  font-weight: bold;
}

.error {
  color: var(--color-error);
}

/* ── Section Headings ── */
.section-heading {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: var(--color-text-maxcontrast);
  margin: 28px 0 12px;
}

/* ── XP Hero Banner ── */
.xp-hero {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 20px;
  border-radius: var(--border-radius-large, 8px);
  border: 1px solid var(--color-border);
  background:
    radial-gradient(circle, var(--color-text-maxcontrast) 1px, transparent 1px) 0 0 / 20px 20px,
    color-mix(in srgb, var(--color-primary-element) 8%, transparent);
  margin-bottom: 8px;
}

.xp-hero-badge {
  flex-shrink: 0;
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: var(--color-primary-element);
  display: flex;
  align-items: center;
  justify-content: center;
}

.missions-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 10px;
}

.mission-card {
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  padding: 12px;
  background: color-mix(in srgb, var(--color-background-dark) 20%, transparent);
}

.mission-card.done {
  border-color: var(--color-success);
}

.mission-card.claimed {
  opacity: 0.8;
}

.mission-title {
  font-weight: 700;
  margin-bottom: 4px;
}

.mission-desc {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  min-height: 32px;
}

.mission-progress {
  font-size: 12px;
  margin: 8px 0;
}

.mission-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

.mission-xp {
  font-weight: 700;
}

.mission-status {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
}

.xp-hero-level {
  font-size: 28px;
  font-weight: 800;
  color: var(--color-primary-element-text);
  line-height: 1;
}

.xp-hero-content {
  flex: 1;
  min-width: 0;
}

.xp-hero-header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 8px;
}

.xp-hero-title {
  font-size: 1.2em;
  font-weight: 700;
  color: var(--color-primary-element);
}

.xp-hero-total {
  font-size: 0.9em;
  color: var(--color-text-maxcontrast);
  font-weight: 600;
}

.xp-hero-bar {
  width: 100%;
  height: 14px;
  background: var(--color-background-dark);
  border-radius: 7px;
  overflow: hidden;
  position: relative;
}

.xp-hero-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--color-primary-element), var(--color-success));
  border-radius: 7px;
  transition: width 0.8s ease-in-out;
  position: relative;
  overflow: hidden;
}

.xp-hero-bar-fill::after {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
  animation: shimmer 2.5s infinite;
}

.xp-hero-sublabel {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  margin-top: 4px;
  text-align: right;
}

@keyframes shimmer {
  0% { left: -100%; }
  100% { left: 200%; }
}

/* ── Summary Cards — Asymmetric Grid ── */
.summary-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  grid-template-rows: auto auto;
  gap: 12px;
  margin-bottom: 8px;
}

.summary-card {
  position: relative;
  overflow: hidden;
  padding: 16px;
  border-radius: var(--border-radius-large, 8px);
  border: 1px solid var(--color-border);
  border-left: 3px solid var(--color-primary-element);
  background: var(--color-main-background);
  transition: transform 0.2s, box-shadow 0.2s;
}

.summary-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px color-mix(in srgb, var(--color-main-text) 8%, transparent);
}

.card-watermark {
  position: absolute;
  top: -4px;
  right: -2px;
  font-size: 48px;
  opacity: 0.08;
  line-height: 1;
  pointer-events: none;
}

.card-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 4px;
}

.card-value {
  font-size: 1.8em;
  font-weight: bold;
  color: var(--color-primary-element);
  line-height: 1.2;
}

.card-sub {
  font-size: 11px;
  color: var(--color-text-maxcontrast);
  margin-top: 4px;
}

/* Streak card spans 2 rows */
.streak-card {
  grid-row: 1 / 3;
  grid-column: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  border-left-color: var(--color-warning);
  background: color-mix(in srgb, var(--color-warning) 6%, transparent);
}

.streak-value {
  color: var(--color-warning) !important;
  font-size: 2.8em !important;
  text-shadow: 0 0 20px color-mix(in srgb, var(--color-warning) 30%, transparent);
}

.streak-sub {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.streak-flame-row {
  display: flex;
  gap: 1px;
  font-size: 1.1em;
  line-height: 1;
}

.streak-flame {
  display: inline-block;
  animation: flame-dance 0.8s ease-in-out infinite alternate;
}

@keyframes flame-dance {
  0% { transform: translateY(0) scale(1); }
  100% { transform: translateY(-2px) scale(1.1); }
}

@media (prefers-reduced-motion: reduce) {
  .streak-flame { animation: none; }
}

.card-questions { border-left-color: var(--color-primary-element); }
.card-mastered { border-left-color: var(--color-success); }
.card-due { border-left-color: var(--color-error); }
.card-accuracy { border-left-color: var(--color-primary-element); }

/* When no streak, use 2x2 grid */
.summary-grid:not(:has(.streak-card)) {
  grid-template-columns: 1fr 1fr;
}

/* ── Leitner Tower — Desktop ── */
.leitner-tower-desktop {
  margin-bottom: 8px;
}

.tower-chart {
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 16px;
  height: 200px;
  padding: 16px 0;
}

.tower-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 1;
  max-width: 80px;
  height: 100%;
  justify-content: flex-end;
}

.tower-count {
  font-size: 13px;
  font-weight: 700;
  color: var(--color-main-text);
  margin-bottom: 4px;
}

.tower-bar-wrapper {
  width: 100%;
  flex: 1;
  display: flex;
  align-items: flex-end;
}

.tower-bar {
  width: 100%;
  min-height: 4px;
  border-radius: 4px 4px 0 0;
  height: var(--tower-h, 0%);
  animation: towerGrow 0.8s ease-out both;
  position: relative;
  overflow: hidden;
}

.tower-bar::after {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
  animation: towerShine 1.8s ease-out 0.8s both;
}

.tower-1 { background: var(--color-error); }
.tower-2 { background: var(--color-warning); }
.tower-3 { background: color-mix(in srgb, var(--color-primary-element) 80%, var(--color-warning)); }
.tower-4 { background: var(--color-primary-element); }
.tower-5 { background: var(--color-success); }

.tower-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-main-text);
  margin-top: 8px;
}

.tower-sub {
  font-size: 10px;
  color: var(--color-text-maxcontrast);
}

@keyframes towerGrow {
  from { height: 0; }
  to { height: var(--tower-h, 0%); }
}

@keyframes towerShine {
  0% { left: -100%; }
  100% { left: 200%; }
}

/* Mobile fallback: horizontal bars */
.leitner-tower-mobile {
  display: none;
  margin-bottom: 8px;
}

.box-bar-container {
  display: flex;
  align-items: center;
  margin-bottom: 10px;
  gap: 10px;
}

.box-label {
  flex-basis: 120px;
  min-width: 120px;
  font-size: 0.9em;
  color: var(--color-main-text);
}

.box-visual-wrapper {
  flex-grow: 1;
  height: 28px;
  background-color: var(--color-background-dark);
  border-radius: var(--border-radius, 4px);
  overflow: hidden;
  position: relative;
}

.box-bar {
  height: 100%;
  border-radius: var(--border-radius, 4px);
  transition: width 0.5s ease-in-out;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  padding-right: 8px;
  min-width: fit-content;
}

.box-count {
  color: var(--color-main-text);
  font-weight: bold;
  font-size: 0.8em;
  white-space: nowrap;
  line-height: 1;
}

/* ── Data Panel — Mastery + Accuracy side by side ── */
.data-panel {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 8px;
}

.data-panel-card {
  padding: 20px;
  border-radius: var(--border-radius-large, 8px);
  border: 1px solid var(--color-border);
  background: var(--color-main-background);
  text-align: center;
}

.data-panel-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 12px;
}

.data-panel-sub {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  margin-top: 8px;
}

/* Mastery Ring */
.mastery-ring {
  width: 140px;
  height: 140px;
  margin: 0 auto;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  animation: masteryReveal 0.8s ease-out both;
}

.mastery-inner {
  width: 108px;
  height: 108px;
  border-radius: 50%;
  background: var(--color-main-background);
  display: flex;
  align-items: center;
  justify-content: center;
}

.mastery-percentage {
  font-size: 2em;
  font-weight: bold;
  color: var(--color-main-text);
}

@keyframes masteryReveal {
  from { transform: rotate(-90deg); opacity: 0.3; }
  to { transform: rotate(0deg); opacity: 1; }
}

/* Accuracy */
.accuracy-big {
  font-size: 3em;
  font-weight: 800;
  color: var(--color-primary-element);
  line-height: 1;
  margin-bottom: 12px;
}

.accuracy-bar-segmented {
  width: 100%;
  height: 12px;
  background: var(--color-background-dark);
  border-radius: 6px;
  overflow: hidden;
  position: relative;
}

.accuracy-bar-fill {
  height: 100%;
  border-radius: 6px;
  transition: width 0.5s ease-in-out;
}

.accuracy-tick {
  position: absolute;
  top: 0;
  width: 1px;
  height: 100%;
  background: color-mix(in srgb, var(--color-main-text) 20%, transparent);
}

.accuracy-scale {
  display: flex;
  justify-content: space-between;
  font-size: 9px;
  color: var(--color-text-maxcontrast);
  margin-top: 2px;
  padding: 0 1px;
}

/* ── Achievements — Enhanced ── */
.achievements-section {
  margin-bottom: 30px;
}

.achievements-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.archive-toggle {
  border: 1px solid var(--color-border);
  border-radius: 999px;
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}

.badges-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
  gap: 12px;
  margin-bottom: 20px;
}

.badge-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 14px 8px;
  border-radius: var(--border-radius-large, 8px);
  border: 1px solid var(--color-border);
  background: var(--color-main-background);
  text-align: center;
  transition: transform 0.2s;
  cursor: default;
  position: relative;
  overflow: hidden;
  min-height: 116px;
}

.badge-item:not(.badge-locked) {
  z-index: 0;
}

.badge-item:not(.badge-locked)::before {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: var(--border-radius-large, 8px);
  opacity: 0;
  background: radial-gradient(circle at center, color-mix(in srgb, var(--color-primary-element) 15%, transparent), transparent 70%);
  transition: opacity 0.2s;
  z-index: -1;
}

.badge-item:not(.badge-locked):hover::before {
  opacity: 1;
}

.badge-item:not(.badge-locked)::after {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 50%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
  animation: badgeShimmer 4s infinite;
}

.badge-item:not(.badge-locked):hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.badge-locked {
  opacity: 0.28;
  filter: grayscale(1);
}

.badge-emoji {
  font-size: 28px;
  margin-bottom: 6px;
}

.badge-name {
  font-size: 11px;
  font-weight: 600;
  color: var(--color-main-text);
  line-height: 1.3;
}

.badge-meta {
  font-size: 10px;
  line-height: 1.35;
  color: var(--color-text-maxcontrast);
}

@keyframes badgeShimmer {
  0%, 75% { left: -100%; }
  100% { left: 250%; }
}

/* Badge Progress */
.badge-progress-section h4 {
  font-size: 14px;
  margin-bottom: 10px;
  color: var(--color-text-maxcontrast);
}

.badge-archive {
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px dashed var(--color-border);
}

.badge-archive h4 {
  font-size: 14px;
  margin-bottom: 10px;
  color: var(--color-text-maxcontrast);
}

.badges-grid--archive .badge-item {
  background: color-mix(in srgb, var(--color-background-dark) 70%, transparent);
}

.badge-item--legacy {
  opacity: 0.78;
}

.badge-progress-item {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 8px;
}

.bp-emoji {
  font-size: 20px;
  flex-shrink: 0;
}

.bp-copy {
  min-width: 132px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.bp-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-main-text);
}

.bp-trigger {
  font-size: 11px;
  line-height: 1.35;
  color: var(--color-text-maxcontrast);
}

.bp-bar {
  flex: 1;
  height: 10px;
  background: var(--color-background-dark);
  border-radius: 5px;
  overflow: hidden;
}

.bp-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--color-primary-element), var(--color-success));
  border-radius: 5px;
  transition: width 0.5s ease;
}

.bp-label {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  font-weight: 600;
  min-width: 50px;
  text-align: right;
}

/* ── Reduced Motion ── */
@media (prefers-reduced-motion: reduce) {
  .xp-hero-bar-fill::after,
  .tower-bar::after,
  .badge-item:not(.badge-locked)::after {
    animation: none;
  }
  .tower-bar {
    animation: none;
    height: var(--tower-h, 0%);
  }
  .mastery-ring {
    animation: none;
  }
  .summary-card {
    transition: none;
  }
  .badge-item {
    transition: none;
  }
}

/* ── Responsive ── */
@media (max-width: 768px) {
  .summary-grid {
    grid-template-columns: 1fr 1fr;
  }
  .streak-card {
    grid-row: auto;
    grid-column: 1 / -1;
  }
  .tower-chart {
    height: 160px;
  }
}

@media (max-width: 480px) {
  .summary-grid {
    grid-template-columns: 1fr 1fr;
  }
  .streak-card {
    grid-row: auto;
    grid-column: 1 / -1;
  }
  .leitner-tower-desktop {
    display: none;
  }
  .leitner-tower-mobile {
    display: block;
  }
  .box-label {
    flex-basis: 90px;
    min-width: 90px;
    font-size: 0.8em;
  }
  .data-panel {
    grid-template-columns: 1fr;
  }
  .mastery-ring {
    width: 120px;
    height: 120px;
  }
  .mastery-inner {
    width: 92px;
    height: 92px;
  }
  .mastery-percentage {
    font-size: 1.6em;
  }
  .accuracy-big {
    font-size: 2.4em;
  }
  .badges-grid {
    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
  }
  .xp-hero {
    flex-direction: column;
    text-align: center;
  }
  .xp-hero-header {
    justify-content: center;
    gap: 12px;
  }
  .xp-hero-sublabel {
    text-align: center;
  }
}
</style>
