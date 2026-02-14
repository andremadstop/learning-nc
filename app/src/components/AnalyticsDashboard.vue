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
      <h2>{{ t('learning', 'Learning Statistics') }}</h2>

      <!-- Summary Cards Row -->
      <div class="summary-cards">
        <div class="summary-card">
          <p class="card-label">{{ t('learning', 'Total Questions') }}</p>
          <p class="card-value">{{ stats.total }}</p>
        </div>
        <div class="summary-card">
          <p class="card-label">{{ t('learning', 'Mastered') }}</p>
          <p class="card-value">{{ stats.mastered }}</p>
        </div>
        <div class="summary-card">
          <p class="card-label">{{ t('learning', 'Due Today') }}</p>
          <p class="card-value">{{ stats.due_count }}</p>
        </div>
        <div class="summary-card">
          <p class="card-label">{{ t('learning', 'Accuracy') }}</p>
          <p class="card-value">{{ stats.accuracy }}%</p>
        </div>
      </div>

      <!-- Leitner Box Distribution -->
      <div class="leitner-distribution">
        <h3>{{ t('learning', 'Leitner Box Distribution') }}</h3>
        <div class="box-bar-container">
          <div class="box-label">{{ t('learning', 'Box 1 (New)') }}</div>
          <div class="box-visual-wrapper">
            <div class="box-bar" :style="boxBarStyle(stats.box_1, 'error')">
              <span class="box-count">{{ stats.box_1 }}</span>
            </div>
          </div>
        </div>
        <div class="box-bar-container">
          <div class="box-label">{{ t('learning', 'Box 2') }}</div>
          <div class="box-visual-wrapper">
            <div class="box-bar" :style="boxBarStyle(stats.box_2, 'warning')">
              <span class="box-count">{{ stats.box_2 }}</span>
            </div>
          </div>
        </div>
        <div class="box-bar-container">
          <div class="box-label">{{ t('learning', 'Box 3') }}</div>
          <div class="box-visual-wrapper">
            <div class="box-bar" :style="boxBarStyle(stats.box_3, 'primary-element')">
              <span class="box-count">{{ stats.box_3 }}</span>
            </div>
          </div>
        </div>
        <div class="box-bar-container">
          <div class="box-label">{{ t('learning', 'Box 4') }}</div>
          <div class="box-visual-wrapper">
            <div class="box-bar" :style="boxBarStyle(stats.box_4, 'primary-element')">
              <span class="box-count">{{ stats.box_4 }}</span>
            </div>
          </div>
        </div>
        <div class="box-bar-container">
          <div class="box-label">{{ t('learning', 'Box 5 (Mastered)') }}</div>
          <div class="box-visual-wrapper">
            <div class="box-bar" :style="boxBarStyle(stats.box_5, 'success')">
              <span class="box-count">{{ stats.box_5 }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Mastery Progress Circle -->
      <div class="mastery-section">
        <h3>{{ t('learning', 'Mastery Progress') }}</h3>
        <div class="mastery-ring" :style="masteryCircleComputedStyle">
          <div class="mastery-inner">
            <span class="mastery-percentage">{{ stats.mastery_percentage }}%</span>
          </div>
        </div>
      </div>

      <!-- Accuracy Section -->
      <div class="accuracy-section">
        <h3>{{ t('learning', 'Accuracy') }}</h3>
        <p class="accuracy-value">{{ totalCorrectFormatted }}</p>
        <div class="progress-bar">
          <div class="progress-fill" :style="accuracyProgressStyle"></div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

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
    boxBarStyle(count, colorName) {
      const total = this.stats.total;
      const width = total > 0 ? (count / total) * 100 : 0;
      return {
        width: `${width}%`,
        backgroundColor: `color-mix(in srgb, var(--color-${colorName}) 25%, transparent)`,
        borderLeft: `3px solid var(--color-${colorName})`,
      };
    },
  },
  created() {
    this.fetchStats();
  },
};
</script>

<style scoped>
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

h2, h3 {
  color: var(--color-main-text);
  margin-top: 20px;
  margin-bottom: 15px;
}

.loading, .error {
  text-align: center;
  padding: 20px;
  font-weight: bold;
}

.error {
  color: var(--color-error);
}

.summary-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 15px;
  margin-bottom: 30px;
}

.summary-card {
  background-color: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
  padding: 15px;
  border-radius: var(--border-radius-large, 8px);
  text-align: center;
  border: 1px solid var(--color-border);
}

.card-label {
  font-size: 0.9em;
  color: var(--color-text-maxcontrast);
  margin-bottom: 5px;
}

.card-value {
  font-size: 1.8em;
  font-weight: bold;
  color: var(--color-primary-element);
}

.leitner-distribution {
  margin-bottom: 30px;
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

.mastery-section {
  text-align: center;
  margin-bottom: 30px;
}

.mastery-ring {
  width: 180px;
  height: 180px;
  margin: 20px auto 0;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.mastery-inner {
  width: 148px;
  height: 148px;
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

.accuracy-section {
  margin-bottom: 30px;
}

.accuracy-value {
  font-size: 1.2em;
  font-weight: bold;
  margin-bottom: 10px;
  color: var(--color-main-text);
}

.progress-bar {
  width: 100%;
  height: 20px;
  background-color: var(--color-background-dark);
  border-radius: var(--border-radius, 4px);
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  border-radius: var(--border-radius, 4px);
  transition: width 0.5s ease-in-out;
}

@media (max-width: 480px) {
  .summary-cards { grid-template-columns: repeat(2, 1fr); }
  .box-label { flex-basis: 90px; min-width: 90px; font-size: 0.8em; }
  .mastery-ring { width: 140px; height: 140px; }
  .mastery-inner { width: 112px; height: 112px; }
  .mastery-percentage { font-size: 1.6em; }
}
</style>
