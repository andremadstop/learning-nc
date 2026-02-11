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
      <div class="mode-selector">
        <button @click="setMode('manage')" :class="['mode-btn', { active: mode === 'manage' }]">
          Manage Questions
        </button>
        <button @click="setMode('train')" :class="['mode-btn', { active: mode === 'train' }]">
          Training Mode
        </button>
        <button @click="setMode('leitner')" :class="['mode-btn', { active: mode === 'leitner' }]">
          Leitner System
        </button>
      </div>

      <QuestionList
        v-if="mode === 'manage'"
        :poolId="selectedPool.id"
        :poolName="selectedPool.name"
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
      questionCount: 0
    };
  },
  methods: {
    async selectPool(pool) {
      this.selectedPool = pool;
      this.currentView = 'questions';
      
      // Load question count
      try {
        const response = await axios.get(
          generateUrl(`/apps/learning/api/pools/${pool.id}/questions`)
        );
        this.questionCount = response.data.length;
      } catch (error) {
        console.error(error);
      }
    },
    backToPools() {
      this.currentView = 'pools';
      this.selectedPool = null;
      this.mode = 'manage';
    },
    setMode(newMode) {
      this.mode = newMode;
    }
  }
};
</script>

<style scoped>
#app-learning {
  padding: 20px;
}

.app-content-header {
  margin-bottom: 20px;
}

.app-content-header h2 {
  font-size: 24px;
  font-weight: 600;
}

.mode-selector {
  display: flex;
  gap: 8px;
  margin-bottom: 24px;
  padding: 4px;
  background: var(--color-background-hover);
  border-radius: 8px;
  max-width: 600px;
}

.mode-btn {
  flex: 1;
  padding: 10px 16px;
  border: none;
  background: transparent;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 500;
  transition: all 0.2s;
}

.mode-btn:hover {
  background: var(--color-background-dark);
}

.mode-btn.active {
  background: var(--color-primary);
  color: white;
}
</style>
