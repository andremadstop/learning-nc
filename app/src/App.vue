<template>
  <NcAppContent id="app-learning">
    <div class="app-content-header">
      <h2>{{ userRole === 'student' ? t('learning', 'Learning') : t('learning', 'Learning - Spaced Repetition') }}</h2>
    </div>

    <!-- Top-level navigation: Pools | Courses (Instructor only) -->
    <div v-if="userRole === 'instructor'" class="main-nav" role="tablist">
      <button
        :class="['main-nav-btn', { active: mainView === 'pools' }]"
        role="tab"
        :aria-selected="mainView === 'pools' ? 'true' : 'false'"
        @click="switchMainView('pools')"
      >
        {{ t('learning', 'Pools') }}
      </button>
      <button
        :class="['main-nav-btn', { active: mainView === 'courses' }]"
        role="tab"
        :aria-selected="mainView === 'courses' ? 'true' : 'false'"
        @click="switchMainView('courses')"
      >
        {{ t('learning', 'Courses') }}
      </button>
    </div>

    <!-- ==================== POOLS VIEW ==================== -->
    <template v-if="mainView === 'pools'">
      <SmartQueue
        v-if="currentView === 'smartQueue'"
        :mode="smartQueueMode"
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

        <div class="mode-selector" role="tablist">
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
          @back="backToPools"
        />

        <LeitnerMode
          v-else-if="mode === 'leitner'"
          :poolId="selectedPool.id"
          @back="setMode('train')"
        />

        <SwipeMode
          v-else-if="mode === 'swipe'"
          :poolId="selectedPool.id"
          :totalQuestions="questionCount"
          @back="setMode('train')"
        />

        <ExamMode
          v-else-if="mode === 'exam'"
          :poolId="selectedPool.id"
          :totalQuestions="questionCount"
          @back="setMode('train')"
        />

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

    <!-- ==================== COURSES VIEW ==================== -->
    <template v-if="mainView === 'courses'">
      <!-- Instructor sub-navigation: List | Dashboard -->
      <div v-if="userRole === 'instructor' && !selectedCourse" class="course-sub-nav" role="tablist">
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
        @back="selectedCourse = null"
        @openPool="openPoolFromCourse"
        @selectStudent="selectStudent"
      />
    </template>
  </NcAppContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import PoolList from './components/PoolList.vue';
import QuestionList from './components/QuestionList.vue';
import TrainingMode from './components/TrainingMode.vue';
import LeitnerMode from './components/LeitnerMode.vue';
import SwipeMode from './components/SwipeMode.vue';
import ExamMode from './components/ExamMode.vue';
import AnalyticsDashboard from './components/AnalyticsDashboard.vue';
import CourseList from './components/CourseList.vue';
import CourseDetail from './components/CourseDetail.vue';
import StudentDetail from './components/StudentDetail.vue';
import InstructorDashboard from './components/InstructorDashboard.vue';
import SmartQueue from './components/SmartQueue.vue';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

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
    SwipeMode,
    ExamMode,
    AnalyticsDashboard,
    CourseList,
    CourseDetail,
    StudentDetail,
    InstructorDashboard,
    SmartQueue
  },
  data() {
    return {
      // Top-level navigation
      mainView: 'pools',
      userRole: 'student',

      // Pools view state
      currentView: 'pools',
      smartQueueMode: 'queue',
      selectedPool: null,
      mode: 'train',
      questionCount: 0,
      poolPermission: 'owner',
      error: null,
      poolFromCourse: false,
      poolFromCourseObj: null,

      // Courses view state
      selectedCourse: null,
      selectedStudent: null,
      courseView: 'list'
    };
  },
  computed: {
    modes() {
      return [
        { id: 'train', label: t('learning', 'Training') },
        { id: 'leitner', label: t('learning', 'Leitner') },
        { id: 'swipe', label: t('learning', 'Wahr/Falsch') },
        { id: 'exam', label: t('learning', 'Exam') },
        { id: 'stats', label: t('learning', 'Stats') },
        { id: 'manage', label: this.poolPermission === 'read' ? t('learning', 'View Questions') : t('learning', 'Manage') }
      ];
    },
    filteredModes() {
      if (this.userRole === 'student') {
        return this.modes.filter(m => ['train', 'leitner', 'swipe', 'exam'].includes(m.id));
      }
      return this.modes;
    },
    modeDescriptions() {
      return {
        train: t('learning', 'Quick quiz — test your knowledge with random questions.'),
        leitner: t('learning', 'Spaced repetition — difficult questions come back more often. 5 boxes, step by step.'),
        swipe: t('learning', 'True or false — tap quickly to classify statements.'),
        exam: t('learning', 'Exam mode — no feedback until the end, like a real exam.'),
        stats: t('learning', 'Learning statistics and box distribution for this pool.'),
        manage: t('learning', 'Add, edit, delete and import questions.'),
      };
    },
  },
  created() {
    this.fetchRole();
  },
  methods: {
    async fetchRole() {
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/role'));
        this.userRole = response.data.role || 'student';
      } catch (err) {
        this.userRole = 'student';
      }
      if (this.userRole === 'student') {
        this.mainView = 'courses';
      }
    },

    switchMainView(view) {
      this.mainView = view;
      if (view === 'pools') {
        this.selectedCourse = null;
        this.selectedStudent = null;
        this.courseView = 'list';
      } else if (view === 'courses') {
        this.backToPools();
      }
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
      this.poolPermission = 'owner';
      this.error = null;
      this.poolFromCourse = false;
      this.poolFromCourseObj = null;
    },
    backToCourse() {
      const course = this.poolFromCourseObj;
      this.currentView = 'pools';
      this.selectedPool = null;
      this.mode = 'train';
      this.poolPermission = 'owner';
      this.error = null;
      this.poolFromCourse = false;
      this.poolFromCourseObj = null;
      if (course) {
        this.mainView = 'courses';
        this.selectedCourse = course;
      } else {
        this.mainView = 'courses';
      }
    },
    openSmartQueue() {
      this.smartQueueMode = 'queue';
      this.currentView = 'smartQueue';
    },
    openRemediation() {
      this.smartQueueMode = 'remediation';
      this.currentView = 'smartQueue';
    },
    setMode(newMode) {
      this.mode = newMode;
      this.error = null;
    },

    // --- Courses methods ---
    selectCourse(course) {
      this.selectedCourse = course;
      this.selectedStudent = null;
    },
    selectStudent(studentInfo) {
      this.selectedStudent = studentInfo;
    },
    async openPoolFromCourse(poolId) {
      this.poolFromCourse = true;
      this.poolFromCourseObj = this.selectedCourse;
      this.mainView = 'pools';
      this.selectedCourse = null;
      try {
        const response = await axios.get(
          generateUrl('/apps/learning/api/pools/' + poolId)
        );
        const pool = response.data;
        this.selectPool({ id: pool.id, name: pool.name, is_shared: !!pool.is_shared, permission: pool.permission });
      } catch {
        this.selectPool({ id: poolId, name: '', is_shared: true, permission: 'read' });
      }
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
  gap: 0;
  margin-bottom: 24px;
  border-bottom: 2px solid var(--color-border);
  max-width: 300px;
}

.main-nav-btn {
  padding: 10px 28px;
  border: none;
  background: transparent;
  cursor: pointer;
  font-weight: 600;
  font-size: 15px;
  color: var(--color-text-maxcontrast);
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: color 0.2s, border-color 0.2s;
}

.main-nav-btn:hover {
  color: var(--color-main-text);
}

.main-nav-btn.active {
  color: var(--color-primary-element);
  border-bottom-color: var(--color-primary-element);
}

/* Course sub-navigation */
.course-sub-nav {
  display: flex;
  gap: 6px;
  margin-bottom: 20px;
  padding: 5px;
  background: var(--color-background-hover);
  border-radius: 10px;
  max-width: 300px;
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

@media (max-width: 768px) {
  #app-learning { padding: 16px; }
  .pool-view-header { flex-direction: column; align-items: flex-start; }
  .mode-selector { flex-direction: column; max-width: 100%; }
  .main-nav { max-width: 100%; }
}
</style>
