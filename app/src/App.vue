<template>
  <div id="app-learning">
    <div class="app-content-header">
      <h2>Learning - Spaced Repetition</h2>
    </div>

    <PoolList
      v-if="currentView === 'pools'"
      @selectPool="selectPool"
    />

    <div v-else-if="currentView === 'questions'" class="pool-view">
      <div class="pool-view-header">
        <button @click="backToPools" class="button back-btn">
          ← Back to Pools
        </button>
        <h3 class="pool-title">{{ selectedPool.name }}</h3>
      </div>

      <!-- Read-only banner for shared pools -->
      <div v-if="poolPermission === 'read'" class="readonly-banner">
        🔒 This pool is shared with you (view only)
      </div>

      <div class="mode-selector">
        <button @click="setMode('manage')" :class="['mode-btn', { active: mode === 'manage' }]">
          {{ poolPermission === 'read' ? 'View Questions' : 'Manage Questions' }}
        </button>
        <button @click="setMode('train')" :class="['mode-btn', { active: mode === 'train' }]">
          Training Mode
        </button>
        <button @click="setMode('leitner')" :class="['mode-btn', { active: mode === 'leitner' }]">
          Leitner System
        </button>
      </div>

      <!-- Error banner -->
      <div v-if="error" class="error-banner">
        <span>{{ error }}</span>
        <button @click="error = null" class="button">Dismiss</button>
      </div>

      <QuestionList
        v-if="mode === 'manage'"
        :poolId="selectedPool.id"
        :poolName="selectedPool.name"
        :readonly="poolPermission === 'read'"
        @back="backToPools"
      />

      <TrainingMode
        v-else-if="mode === 'train'"
        :poolId="selectedPool.id"
        :totalQuestions="questionCount"
        @back="setMode('manage')"
      />

      <LeitnerMode
        v-else-if="mode === 'leitner'"
        :poolId="selectedPool.id"
        @back="setMode('manage')"
      />
    </div>
  </div>
</template>

<script>
import PoolList from './components/PoolList.vue';
import QuestionList from './components/QuestionList.vue';
import TrainingMode from './components/TrainingMode.vue';
import LeitnerMode from './components/LeitnerMode.vue';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

export default {
  name: 'App',
  components: {
    PoolList,
    QuestionList,
    TrainingMode,
    LeitnerMode
  },
  data() {
    return {
      currentView: 'pools',
      selectedPool: null,
      mode: 'manage',
      questionCount: 0,
      poolPermission: 'owner',
      error: null
    };
  },
  methods: {
    async selectPool(pool) {
      this.selectedPool = pool;
      this.currentView = 'questions';
      this.error = null;

      // Determine permission level
      if (pool.is_shared) {
        this.poolPermission = pool.permission || 'read';
      } else {
        this.poolPermission = 'owner';
      }

      try {
        const response = await axios.get(
          generateUrl(`/apps/learning/api/pools/${pool.id}/questions`)
        );
        this.questionCount = response.data.length;
      } catch (err) {
        this.error = 'Failed to load question count';
        this.questionCount = 0;
      }
    },
    backToPools() {
      this.currentView = 'pools';
      this.selectedPool = null;
      this.mode = 'manage';
      this.poolPermission = 'owner';
      this.error = null;
    },
    setMode(newMode) {
      this.mode = newMode;
      this.error = null;
    }
  }
};
</script>

<style scoped>
#app-learning {
  padding: 24px 40px;
  max-width: 1400px;
  margin: 0 auto;
}

.app-content-header {
  margin-bottom: 28px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--color-border);
}

.app-content-header h2 {
  font-size: 26px;
  font-weight: 700;
  letter-spacing: -0.3px;
  margin: 0;
}

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
}

.back-btn {
  white-space: nowrap;
  padding: 8px 16px;
  border-radius: 6px;
}

.mode-selector {
  display: flex;
  gap: 6px;
  margin-bottom: 28px;
  padding: 5px;
  background: var(--color-background-hover);
  border-radius: 10px;
  max-width: 560px;
}

.mode-btn {
  flex: 1;
  padding: 12px 20px;
  border: none;
  background: transparent;
  border-radius: 8px;
  cursor: pointer;
  font-weight: 500;
  transition: all 0.2s;
  font-size: 14px;
  text-align: center;
}

.mode-btn:hover {
  background: var(--color-background-dark);
}

.mode-btn.active {
  background: var(--color-primary);
  color: white;
  box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}

@media (max-width: 768px) {
  #app-learning { padding: 16px; }
  .pool-view-header { flex-direction: column; align-items: flex-start; }
  .mode-selector { flex-direction: column; max-width: 100%; }
}
</style>
