<template>
  <div class="pool-list">
    <div class="pool-list-header">
      <h3>{{ t('learning', 'Fragenpools') }}</h3>
      <div v-if="userRole === 'instructor'" class="pool-list-header__actions">
        <NcButton type="button" variant="secondary" @click="openStarterDialog">
          {{ t('learning', 'Starter-Pool hinzufügen') }}
        </NcButton>
        <!-- GitHub #70: the generator writes questions with AI; with AI off it only said that no
             provider is set up. Its CSV/JSON tab is the same as "Pools importieren" and Manage. -->
        <NcButton v-if="aiAvailable" type="button" variant="secondary" class="pool-list-generate" @click="showPoolGenerator = true">
          {{ t('learning', 'Fragen generieren') }}
        </NcButton>
        <NcButton type="button" variant="secondary" class="pool-list-import-pools" @click="openPoolImport">
          {{ t('learning', 'Pools importieren') }}
        </NcButton>
        <NcButton type="button" variant="primary" @click="showCreateDialog">
          {{ t('learning', '+ Pool erstellen') }}
        </NcButton>
      </div>
    </div>

    <!-- Instructor welcome hint -->
    <NcNoteCard v-if="userRole === 'instructor' && !hintDismissed('welcome-instructor')" type="info" class="onboarding-hint">
      {{ t('learning', 'Pools are your question collections. Create a pool, add questions (or import CSV/JSON), and assign it to a course.') }}
      <NcButton type="button" variant="tertiary" @click="dismissHint('welcome-instructor')">{{ t('learning', 'Got it') }}</NcButton>
    </NcNoteCard>

    <!-- GitHub #34: learners saw no create button, no Manage tab and no reason why. Not shown in
         the student view or after a failed role request: App.vue explains those itself. -->
    <NcNoteCard v-if="showInstructorOnlyNote && !hintDismissed('instructor-only-pools')" type="info" class="onboarding-hint instructor-only-note">
      <template v-if="instructorGroup">
        {{ t('learning', 'Only instructors can create pools. Questions can also be added and imported by anyone a pool is shared with for editing: open the pool and use the Manage tab. To create pools yourself, ask your Nextcloud administrator to add you to the group "{group}".', { group: instructorGroup }, undefined, { escape: false }) }}
      </template>
      <template v-else>
        {{ t('learning', 'Only instructors can create pools. Questions can also be added and imported by anyone a pool is shared with for editing: open the pool and use the Manage tab. To create pools yourself, ask your Nextcloud administrator to add you to the instructor group.') }}
      </template>
      <NcButton type="button" variant="tertiary" @click="dismissHint('instructor-only-pools')">{{ t('learning', 'Got it') }}</NcButton>
    </NcNoteCard>

    <!-- XP Multiplier Badge -->
    <div v-if="gamificationEnabled && xpMultiplier > 1" class="xp-multiplier-badge">
      <span class="xp-mult-value">{{ xpMultiplier }}x</span>
      <span class="xp-mult-label">{{ t('learning', 'XP Multiplier') }}</span>
    </div>

    <!-- Smart Queue + Daily Goal (only for students) -->
    <div v-if="userRole !== 'instructor'" class="smart-queue-section">
      <button v-if="queueCount > 0" class="smart-queue-btn" @click="$emit('openSmartQueue')">
        <span class="sq-icon">&#x1F4DA;</span>
        <span class="sq-text">{{ t('learning', 'Start learning') }}</span>
        <span class="sq-count">{{ queueCount }} {{ t('learning', 'due') }}</span>
      </button>

      <button v-if="remediationCount > 0" class="remediation-btn" @click="$emit('openRemediation')">
        <span class="rem-icon">&#x26A0;</span>
        <span class="rem-text">{{ t('learning', 'Trouble spots') }}</span>
        <span class="rem-count">{{ remediationCount }} {{ t('learning', 'cards') }}</span>
      </button>

    </div>

    <!-- Smart Queue hint (only for students) -->
    <NcNoteCard v-if="userRole !== 'instructor' && queueCount > 0 && !hintDismissed('smart-queue')" type="info" class="onboarding-hint">
      {{ t('learning', 'The Smart Queue picks the most important cards from all your pools — difficult and overdue ones first.') }}
      <NcButton type="button" variant="tertiary" @click="dismissHint('smart-queue')">{{ t('learning', 'Got it') }}</NcButton>
    </NcNoteCard>

    <!-- Daily Challenge, daily goal and its bonus XP (only for students). Fresh-eyes review
      (GitHub #36): game parts, gone with gamification off as on "Heute". -->
    <DailyChallengeCard v-if="userRole !== 'instructor' && gamificationEnabled" />

    <!-- Daily Goal hint (only for students) -->
    <NcNoteCard v-if="userRole !== 'instructor' && gamificationEnabled && dailyProgress && !hintDismissed('daily-goal')" type="info" class="onboarding-hint">
      {{ t('learning', 'Set a daily goal. When you reach it, you get +10 bonus XP!') }}
      <NcButton type="button" variant="tertiary" @click="dismissHint('daily-goal')">{{ t('learning', 'Got it') }}</NcButton>
    </NcNoteCard>

    <!-- Daily Goal (only for students) -->
    <div v-if="userRole !== 'instructor' && gamificationEnabled" class="smart-queue-section daily-goal-section">
      <div v-if="dailyProgress" class="daily-goal-widget" :class="{ 'goal-reached': dailyProgress.goal_reached }">
        <svg class="goal-ring" viewBox="0 0 60 60" width="56" height="56" role="img" :aria-label="t('learning', 'Daily goal progress: {pct}%', { pct: goalPercent })">
          <circle cx="30" cy="30" r="26" fill="none" stroke="var(--color-border)" stroke-width="4" />
          <circle cx="30" cy="30" r="26" fill="none" :stroke="dailyProgress.goal_reached ? 'var(--color-warning)' : 'var(--color-primary-element)'" stroke-width="4" stroke-linecap="round" :stroke-dasharray="circumference" :stroke-dashoffset="circumference - (circumference * goalPercent / 100)" transform="rotate(-90 30 30)" class="goal-ring-progress" />
          <text x="30" y="33" text-anchor="middle" font-size="12" font-weight="700" fill="var(--color-main-text)">{{ dailyProgress.cards_reviewed_today }}/{{ dailyProgress.daily_goal }}</text>
        </svg>
        <div class="daily-goal-info">
          <span class="daily-goal-pct">{{ goalPercent }}%</span>
          <span class="daily-goal-label">
            {{ dailyProgress.goal_reached ? t('learning', 'Daily goal reached!') : t('learning', '{n} more cards today', { n: dailyProgress.daily_goal - dailyProgress.cards_reviewed_today }) }}
          </span>
          <button class="daily-goal-edit-btn" @click="showGoalEdit = !showGoalEdit">{{ t('learning', 'Change goal') }}</button>
        </div>
        <div v-if="showGoalEdit" class="daily-goal-editor">
          <input type="number" v-model.number="editGoalValue" min="5" max="200" class="nc-input goal-input" :aria-label="t('learning', 'Tagesziel (Karten pro Tag)')" />
          <NcButton type="button" variant="primary" :disabled="savingGoal" @click="saveGoal">{{ t('learning', 'Save') }}</NcButton>
        </div>
      </div>
    </div>

    <!-- Search Input -->
    <div class="search-container" ref="searchContainer">
      <div class="search-input-wrapper">
        <label for="pool-search" class="icon-search-label">
          <span class="icon-search" />
        </label>
        <input
          id="pool-search"
          ref="searchInput"
          v-model="searchTerm"
          type="text"
          class="nc-input search-input"
          :placeholder="t('learning', 'Search pools and questions...')"
          @input="onSearchInput"
          @focus="showSearchResults = true"
          @keydown.esc="clearSearch"
        />
        <button v-if="searchTerm" type="button" class="clear-search-btn" :aria-label="t('learning', 'Suche leeren')" :title="t('learning', 'Suche leeren')" @click="clearSearchFromButton">
          <span class="icon-close" aria-hidden="true" />
        </button>
      </div>

      <!-- Search Results Dropdown -->
      <div v-if="showSearchResults && searchTerm.length >= 2" class="search-results-dropdown">
        <NcLoadingIcon v-if="searchLoading" :size="24" class="loading-search" />
        <div v-else>
          <div v-if="poolSearchResults.length > 0" class="search-results-section">
            <h4 class="section-title">{{ t('learning', 'POOLS') }}</h4>
            <ul class="results-list">
              <!-- Persona sweep C9b: a list item that is itself a button is no list item for a
                   screen reader (axe "list"); the button sits inside it. -->
              <li v-for="pool in poolSearchResults" :key="`pool-res-${pool.id}`">
                <button type="button" class="result-btn" @click="selectPool(pool)">
                  <span class="result-text">{{ pool.name }}</span>
                </button>
              </li>
            </ul>
          </div>

          <div v-if="questionSearchResults.length > 0" class="search-results-section">
            <h4 class="section-title">{{ t('learning', 'QUESTIONS') }}</h4>
            <ul class="results-list">
              <li v-for="question in questionSearchResults" :key="`ques-res-${question.id}`">
                <button type="button" class="result-btn" @click="selectQuestion(question)">
                  <!-- GitHub #98: "#304" read like a count; it is the question's ID (the one Pool rules take). -->
                  <span class="result-id">{{ t('learning', 'Frage-ID {id}', { id: question.id }) }}</span>
                  <span class="result-text">"{{ question.text }}"</span>
                  <span class="result-subtext">{{ t('learning', 'in {pool}', { pool: question.pool_name }, undefined, RAW) }}</span>
                </button>
              </li>
            </ul>
          </div>

          <div v-if="poolSearchResults.length === 0 && questionSearchResults.length === 0 && searchTerm.length >= 2" class="empty-search-results">
            {{ t('learning', 'No results found for "{searchTerm}"', { searchTerm: searchTerm }) }}
          </div>
        </div>
      </div>
    </div>


    <!-- Language Filter -->
    <div v-if="availableLanguages.length > 1" class="lang-filter">
      <span class="lang-label">{{ t('learning', 'Language:') }}</span>
      <button
        v-for="lang in availableLanguages"
        :key="lang"
        @click="toggleLanguage(lang)"
        :class="['lang-chip', { active: activeLangs.includes(lang) }]"
      >
        {{ lang }} ({{ langCount(lang) }})
      </button>
    </div>

    <!-- Tabs -->
    <div class="pool-tabs lnc-tablist" role="tablist" :aria-label="t('learning', 'Pool-Ansicht')" @keydown="moveTablistFocus">
      <button @click="activeTab = 'own'" :class="['tab-btn', 'lnc-tab', { active: activeTab === 'own' }]" role="tab" :aria-selected="activeTab === 'own' ? 'true' : 'false'">
        {{ t('learning', 'My Pools ({n})', { n: filteredPools.length }) }}
      </button>
      <button @click="activeTab = 'shared'" :class="['tab-btn', 'lnc-tab', { active: activeTab === 'shared' }]" role="tab" :aria-selected="activeTab === 'shared' ? 'true' : 'false'">
        {{ t('learning', 'Shared with me ({n})', { n: sharedPools.length }) }}
      </button>
      <!-- GitHub #69: the pools of the courses the viewer leads (owner or co-instructor). -->
      <button v-if="showCoursePoolsTab" @click="activeTab = 'course'" :class="['tab-btn', 'lnc-tab', 'course-pools-tab', { active: activeTab === 'course' }]" role="tab" :aria-selected="activeTab === 'course' ? 'true' : 'false'">
        {{ t('learning', 'Pools meiner Kurse ({n})', { n: coursePools.length }) }}
      </button>
    </div>

    <NcLoadingIcon v-if="loading" :size="44" class="loading-center" />

    <!-- My Pools -->
    <div v-else-if="activeTab === 'own'">
      <NcEmptyContent v-if="filteredPools.length === 0 && !searchTerm" :name="t('learning', 'No pools yet')" :description="userRole === 'instructor' ? t('learning', 'Create a pool, add questions (or import CSV/JSON) and assign it to a course.') : t('learning', 'The question pools of your courses open from the Courses tab. Only instructors can create pools; pools shared with you for editing are listed under Shared with me.')" />
      <NcEmptyContent v-else-if="filteredPools.length === 0 && searchTerm" :name="t('learning', 'No pools matching \'{searchTerm}\'', { searchTerm: searchTerm })" :description="t('learning', 'Try a different search term')" />
      <div v-else class="pool-grid">
        <div v-for="pool in filteredPools" :key="pool.id" class="pool-card" @click="selectPool(pool)">
          <div class="pool-card-header">
            <h4><button type="button" class="pool-card-open" @click.stop="selectPool(pool)">{{ pool.name }}</button></h4>
            <NcActions v-if="userRole === 'instructor'" @click.stop>
              <NcActionButton @click="sharePool(pool)" close-after-click>{{ t('learning', 'Share') }}</NcActionButton>
              <NcActionButton @click="editPool(pool)" close-after-click>{{ t('learning', 'Edit') }}</NcActionButton>
              <NcActionButton @click="deletePool(pool)" close-after-click>{{ t('learning', 'Delete') }}</NcActionButton>
            </NcActions>
          </div>
          <div v-if="pool.chapter_title || pool.handbook_title" class="pool-chapter-meta">
            <span v-if="pool.handbook_title" class="pool-meta-badge handbook">{{ pool.handbook_title }}</span>
            <span v-if="pool.chapter_title" class="pool-meta-badge chapter">
              {{ formatChapterLabel(pool) }}
            </span>
          </div>
          <p v-if="pool.description" class="pool-description">{{ pool.description }}</p>
          <div class="pool-meta">{{ t('learning', 'Created {date}', { date: formatDate(pool.created_at) }) }}</div>
        </div>
      </div>
    </div>

    <!-- Shared Pools -->
    <div v-else-if="activeTab === 'shared'">
      <NcEmptyContent v-if="sharedPools.length === 0" :name="t('learning', 'No shared pools')" :description="t('learning', 'When someone shares a pool with you, it will appear here')" />
        <div v-else class="pool-grid">
          <div v-for="pool in sharedPools" :key="'s-' + pool.id" class="pool-card shared" @click="selectPool(pool)">
          <div class="pool-card-header">
            <h4><button type="button" class="pool-card-open" @click.stop="selectPool(pool)">{{ pool.name }}</button></h4>
            <span class="permission-badge" :class="pool.permission">
              {{ pool.permission === 'edit' ? t('learning', 'Can edit') : t('learning', 'View only') }}
            </span>
          </div>
          <div v-if="pool.chapter_title || pool.handbook_title" class="pool-chapter-meta">
            <span v-if="pool.handbook_title" class="pool-meta-badge handbook">{{ pool.handbook_title }}</span>
            <span v-if="pool.chapter_title" class="pool-meta-badge chapter">
              {{ formatChapterLabel(pool) }}
            </span>
          </div>
          <p v-if="pool.description" class="pool-description">{{ pool.description }}</p>
          <div class="pool-meta">{{ t('learning', 'Shared by {name}', { name: pool.shared_by }) }}</div>
        </div>
      </div>
    </div>

    <!-- Pools of my courses (GitHub #69): read-only cards; the pool window decides what may be
         edited from the permission the server named. -->
    <div v-else-if="activeTab === 'course' && showCoursePoolsTab" class="course-pools">
      <div class="pool-grid">
        <div v-for="pool in coursePools" :key="'c-' + pool.id" class="pool-card course-pool-card" @click="selectPool(pool)">
          <div class="pool-card-header">
            <h4><button type="button" class="pool-card-open" @click.stop="selectPool(pool)">{{ pool.name }}</button></h4>
            <span class="permission-badge" :class="pool.permission === 'read' ? 'read' : 'edit'">
              {{ pool.permission === 'read' ? t('learning', 'View only') : t('learning', 'Can edit') }}
            </span>
          </div>
          <div v-if="pool.chapter_title || pool.handbook_title" class="pool-chapter-meta">
            <span v-if="pool.handbook_title" class="pool-meta-badge handbook">{{ pool.handbook_title }}</span>
            <span v-if="pool.chapter_title" class="pool-meta-badge chapter">
              {{ formatChapterLabel(pool) }}
            </span>
          </div>
          <p v-if="pool.description" class="pool-description">{{ pool.description }}</p>
          <div class="pool-meta course-pool-courses">{{ t('learning', 'Kurse: {courses}', { courses: courseTitles(pool) }) }}</div>
        </div>
      </div>
    </div>

    <!-- Create/Edit Dialog -->
    <AccessibleDialog v-if="showDialog" :name="editingPool ? t('learning', 'Edit Pool') : t('learning', 'Create Pool')" @closing="closeDialog">
      <form @submit.prevent="savePool">
        <div class="form-group">
          <label for="pool-name">{{ t('learning', 'Pool Name *') }}</label>
          <input id="pool-name" v-model="form.name" type="text" required :placeholder="t('learning', 'e.g., IT fundamentals, chapter 1')" class="nc-input" />
        </div>
        <div class="form-group">
          <label for="pool-description">{{ t('learning', 'Description') }}</label>
          <textarea id="pool-description" v-model="form.description" rows="4" :placeholder="t('learning', 'Optional description')" class="nc-input"></textarea>
        </div>
        <!-- Codeberg #6: the five metadata fields below carried placeholders but no
             explanation, and the reporter asked what belongs in each one. They are all
             optional and only pay off when a pool mirrors one chapter of a textbook. -->
        <div class="chapter-meta-intro">
          <strong>{{ t('learning', 'Textbook reference (all optional)') }}</strong>
          <p>
            {{ t('learning', 'Leave these empty unless this pool mirrors one chapter of a book or curriculum. A pool needs nothing but a name. Fill them in when you split a textbook into one pool per chapter: the keys are short internal identifiers you choose yourself, the titles are what people read, and the number is shown before the chapter title.') }}
          </p>
        </div>
        <div class="chapter-form-grid">
          <div class="form-group">
            <label for="pool-handbook-key">{{ t('learning', 'Handbook Key') }}</label>
            <small class="field-help">{{ t('learning', 'Short identifier for the book, the same in every pool that comes from it. Used to group chapters together.') }}</small>
            <input id="pool-handbook-key" v-model.trim="form.handbookKey" type="text" :placeholder="t('learning', 'z. B. handbuch-netzwerktechnik')" class="nc-input" />
          </div>
          <div class="form-group">
            <label for="pool-handbook-title">{{ t('learning', 'Handbook Title') }}</label>
            <small class="field-help">{{ t('learning', 'Full book title as it should appear to learners, including edition and year.') }}</small>
            <input id="pool-handbook-title" v-model.trim="form.handbookTitle" type="text" :placeholder="t('learning', 'e.g., Handbuch Netzwerktechnik (9. Auflage, 2024)')" class="nc-input" />
          </div>
          <div class="form-group">
            <label for="pool-chapter-key">{{ t('learning', 'Chapter Key') }}</label>
            <small class="field-help">{{ t('learning', 'Identifier for this one chapter, unique within the book. A course uses it to match pools when progress is carried over from an earlier course, and the course schedule uses it to show the progress in this pool. The topic filter of a course does not read it: that filter uses the chapter key of each question.') }}</small>
            <input id="pool-chapter-key" v-model.trim="form.chapterKey" type="text" :placeholder="t('learning', 'e.g., chapter-03')" class="nc-input" />
          </div>
          <div class="form-group">
            <label for="pool-chapter-order">{{ t('learning', 'Chapter Number') }}</label>
            <small class="field-help">{{ t('learning', 'Position of the chapter in the book, shown as “Chapter n” before the chapter title. It does not sort pools or questions and filters nothing.') }}</small>
            <input id="pool-chapter-order" v-model.number="form.chapterOrder" type="number" min="1" max="9999" :placeholder="t('learning', 'e.g., 3')" class="nc-input" />
          </div>
        </div>
        <div class="form-group">
          <label for="pool-chapter-title">{{ t('learning', 'Chapter Title') }}</label>
          <small class="field-help">{{ t('learning', 'Chapter heading shown to learners next to the pool.') }}</small>
          <input id="pool-chapter-title" v-model.trim="form.chapterTitle" type="text" :placeholder="t('learning', 'e.g., Switching Grundlagen')" class="nc-input" />
        </div>
        <div class="dialog-actions">
          <NcButton type="button" variant="tertiary" @click="closeDialog">{{ t('learning', 'Cancel') }}</NcButton>
          <NcButton type="submit" variant="primary" :disabled="saving">
            {{ saving ? t('learning', 'Saving...') : t('learning', 'Save') }}
          </NcButton>
        </div>
      </form>
    </AccessibleDialog>

    <AccessibleDialog v-if="showDeleteConfirm" :name="t('learning', 'Delete Pool')" @closing="showDeleteConfirm = false; poolToDelete = null">
      <p>{{ t('learning', 'Are you sure you want to delete "{name}"? This action cannot be undone.', { name: poolToDelete ? poolToDelete.name : '' }) }}</p>
      <template #actions>
        <NcButton type="button" variant="tertiary" @click="showDeleteConfirm = false; poolToDelete = null">{{ t('learning', 'Cancel') }}</NcButton>
        <NcButton type="button" variant="error" @click="confirmDeletePool">{{ t('learning', 'Delete') }}</NcButton>
      </template>
    </AccessibleDialog>

    <ShareDialog v-if="sharingPool" :poolId="sharingPool.id" :poolName="sharingPool.name" @close="sharingPool = null" />

    <PoolGenerator v-if="showPoolGenerator" @close="showPoolGenerator = false" @created="showPoolGenerator = false; loadPools()" @import-into="openPoolForImport" @import-pools="openPoolImport" />

    <!-- GitHub #34: a JSON file of several pools (the occ learning:import-pool-json format) creates them all. -->
    <PoolBulkImportDialog v-if="showPoolImport" @close="closePoolImport" @imported="loadPools()" @maybe-imported="loadPools()" />

    <AccessibleDialog v-if="showStarterDialog" :name="t('learning', 'Starter Pools')" @closing="closeStarterDialog">
      <div class="starter-pools-dialog">
        <p class="starter-pools-dialog__intro">
          {{ t('learning', 'Import curated starter content and activate a complete question pool with one click.') }}
        </p>

        <NcLoadingIcon v-if="starterLoading" :size="32" class="loading-center" />

        <NcEmptyContent
          v-else-if="starterPools.length === 0"
          :name="t('learning', 'No starter pools available')"
          :description="t('learning', 'Starter content files were not found on this instance.')" />

        <div v-else class="starter-pools-list">
          <div v-for="starter in starterPools" :key="starter.id" class="starter-pool-card">
            <div class="starter-pool-card__copy">
              <h4>{{ starter.title }}</h4>
              <p>{{ starter.description }}</p>
              <div class="starter-pool-card__meta">
                <span>{{ starter.question_count }} {{ t('learning', 'questions') }}</span>
                <span v-if="starter.exam">{{ starter.exam }}</span>
                <span>{{ starter.language }}</span>
              </div>
            </div>
            <NcButton
              type="button" variant="primary"
              :disabled="starterImportingId === starter.id"
              @click="importStarterPool(starter)">
              {{ starterImportingId === starter.id ? t('learning', 'Importing...') : t('learning', 'Import') }}
            </NcButton>
          </div>
        </div>
      </div>

      <template #actions>
        <NcButton type="button" variant="tertiary" @click="closeStarterDialog">
          {{ t('learning', 'Close') }}
        </NcButton>
      </template>
    </AccessibleDialog>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton';
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent';
import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import ShareDialog from './ShareDialog.vue';
import DailyChallengeCard from './DailyChallengeCard.vue';
import AccessibleDialog from './AccessibleDialog.vue';
import PoolGenerator from './PoolGenerator.vue';
import PoolBulkImportDialog from './PoolBulkImportDialog.vue';
import hintMixin from '../hintMixin.js';
import { moveTablistFocus } from '../utils/tablistKeydown.js';

// A pool name as typed: the {{ }} below escapes it once; the l10n default would escape it again (#98).
const RAW = { escape: false, sanitize: false };

// Simple debounce utility
const debounce = (func, delay) => {
  let timeout;
  return function(...args) {
    const context = this;
    clearTimeout(timeout);
    timeout = setTimeout(() => func.apply(context, args), delay);
  };
};

export default {
  name: 'PoolList',
  components: { AccessibleDialog, NcButton, NcEmptyContent, NcActions, NcActionButton, NcLoadingIcon, NcNoteCard, ShareDialog, DailyChallengeCard, PoolGenerator, PoolBulkImportDialog },
  mixins: [hintMixin],
  props: {
    userRole: { type: String, default: 'student' },
    // Name of the instructor group from /api/role (GitHub #34); '' when unknown.
    instructorGroup: { type: String, default: '' },
    // An instructor previewing the student view, or a failed role request: App.vue shows its
    // own banner then, and the "ask your admin" note would be wrong or redundant.
    studentViewActive: { type: Boolean, default: false },
    roleLoadFailed: { type: Boolean, default: false },
    // GitHub #36: the admin's global gamification switch (App.vue). Default on.
    gamificationEnabled: { type: Boolean, default: true },
  },
  data() {
    return {
      RAW,
      pools: [],
      sharedPools: [],
      // GitHub #69: GET /api/my-course-pools, the pools of the courses the viewer leads.
      coursePools: [],
      activeTab: 'own',
      loading: false,
      showDialog: false,
      editingPool: null,
      sharingPool: null,
      showStarterDialog: false,
      starterPools: [],
      starterLoading: false,
      starterImportingId: '',
      saving: false,
      form: {
        name: '',
        description: '',
        handbookKey: '',
        handbookTitle: '',
        chapterKey: '',
        chapterTitle: '',
        chapterOrder: null,
      },
      // Language filter
      activeLangs: [],
      // Search related data
      searchTerm: '',
      showSearchResults: false,
      searchLoading: false,
      poolSearchResults: [], // Client-side filtered pools
      questionSearchResults: [], // API results for questions
      showPoolGenerator: false,
      // Whether AI can generate questions (GET /api/ai/available, as QuestionList asks). Off until
      // the server says so: a failed read hides the generator rather than offering it (#70).
      aiAvailable: false,
      showPoolImport: false,
      showDeleteConfirm: false,
      poolToDelete: null,
      // Smart Queue + Remediation + Daily Goal
      queueCount: 0,
      remediationCount: 0,
      dailyProgress: null,
      showGoalEdit: false,
      editGoalValue: 20,
      savingGoal: false,
      // XP Multiplier
      xpMultiplier: 1,
    };
  },
  computed: {
    // hintDismissed() reads localStorage and is not reactive, so it stays in the template,
    // which dismissHint() re-renders with $forceUpdate().
    // GitHub #69: whoever leads a course with pools gets the tab, whatever their app role (a
    // co-instructor need not be in the instructor group). Not in the student view.
    showCoursePoolsTab() {
      return !this.studentViewActive && this.coursePools.length > 0;
    },
    showInstructorOnlyNote() {
      return this.userRole !== 'instructor'
        && !this.studentViewActive
        && !this.roleLoadFailed;
    },
    availableLanguages() {
      const langs = new Set();
      const re = /\(([A-Z]{2})\)\s*$/;
      this.pools.forEach(p => {
        const m = p.name.match(re);
        if (m) langs.add(m[1]);
      });
      const sorted = Array.from(langs).sort();
      if (this.pools.some(p => !re.test(p.name))) sorted.push('Other');
      return sorted;
    },
    circumference() { return 2 * Math.PI * 26; },
    goalPercent() {
      if (!this.dailyProgress) return 0;
      return Math.min(100, Math.round(this.dailyProgress.cards_reviewed_today / this.dailyProgress.daily_goal * 100));
    },
    filteredPools() {
      let list = this.pools;
      // Language filter
      if (this.activeLangs.length > 0 && this.activeLangs.length < this.availableLanguages.length) {
        const re = /\(([A-Z]{2})\)\s*$/;
        list = list.filter(p => {
          const m = p.name.match(re);
          if (m) return this.activeLangs.includes(m[1]);
          return this.activeLangs.includes('Other');
        });
      }
      // Search filter
      if (this.searchTerm) {
        const q = this.searchTerm.toLowerCase();
        list = list.filter(p =>
          p.name.toLowerCase().includes(q) ||
          (p.description && p.description.toLowerCase().includes(q)) ||
          (p.handbook_title && p.handbook_title.toLowerCase().includes(q)) ||
          (p.chapter_title && p.chapter_title.toLowerCase().includes(q)) ||
          (p.chapter_key && p.chapter_key.toLowerCase().includes(q))
        );
      }
      return list;
    }
  },
  watch: {
    // Entering or leaving the student view while the list is open: the course pools tab follows.
    studentViewActive() {
      this.loadCoursePools();
    },
    userRole: {
      immediate: true,
      handler(role) {
        if (role === 'instructor') this.loadAiAvailable();
      },
    },
  },
  mounted() {
    this.loadPools();
    this.loadQueueCount();
    this.loadRemediationCount();
    this.loadDailyProgress();
    document.addEventListener('click', this.handleClickOutside);
    // Restore language filter from localStorage
    try {
      const saved = localStorage.getItem('learning-lang-filter');
      if (saved) this.activeLangs = JSON.parse(saved);
    } catch (e) { /* ignore */ }
  },
  beforeUnmount() {
    document.removeEventListener('click', this.handleClickOutside);
  },
  methods: {
    async loadAiAvailable() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/ai/available'));
        this.aiAvailable = r.data?.available === true;
      } catch (e) {
        this.aiAvailable = false;
      }
    },
    moveTablistFocus,
    emptyForm() {
      return {
        name: '',
        description: '',
        handbookKey: '',
        handbookTitle: '',
        chapterKey: '',
        chapterTitle: '',
        chapterOrder: null,
      };
    },
    normalizeFormPayload() {
      return {
        name: this.form.name,
        description: this.form.description,
        handbookKey: this.form.handbookKey || null,
        handbookTitle: this.form.handbookTitle || null,
        chapterKey: this.form.chapterKey || null,
        chapterTitle: this.form.chapterTitle || null,
        chapterOrder: this.form.chapterOrder || null,
      };
    },
    formatChapterLabel(pool) {
      if (!pool.chapter_title) {
        return '';
      }
      return pool.chapter_order
        ? t('learning', 'Chapter {n}: {title}', { n: pool.chapter_order, title: pool.chapter_title })
        : pool.chapter_title
    },
    async loadPools() {
      this.loading = true;
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/pools'));
        const data = response.data;
        if (data.own !== undefined) {
          this.pools = data.own;
          this.sharedPools = data.shared || [];
        } else {
          // Fallback for older API versions or different response structures
          this.pools = Array.isArray(data) ? data : [];
          this.sharedPools = [];
        }
      } catch (error) {
        showError(t('learning', 'Failed to load pools'));
      }
      await this.loadCoursePools();
      this.loading = false;
    },
    // GitHub #69: an admin or co-instructor whose course pools belong to a colleague saw "My Pools
    // (0) — No pools yet". With nothing of their own, the list opens on their course pools.
    async loadCoursePools() {
      if (this.studentViewActive) {
        this.coursePools = [];
        return;
      }
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/my-course-pools'));
        this.coursePools = Array.isArray(response.data?.pools) ? response.data.pools : [];
      } catch (error) {
        // An optional list: without it the page keeps the own and shared pools.
        this.coursePools = [];
      }
      if (this.activeTab === 'course' && this.coursePools.length === 0) {
        this.activeTab = 'own';
      } else if (this.activeTab === 'own' && this.pools.length === 0 && this.coursePools.length > 0) {
        this.activeTab = 'course';
      }
    },
    courseTitles(pool) {
      return (Array.isArray(pool.courses) ? pool.courses : []).map((c) => c.title).filter(Boolean).join(', ');
    },
    // GitHub #34: a pool created in the generator's import tab opens right away; App opens an
    // empty pool its owner can edit in Manage, where the import is.
    // The generator closes first: two dialogs on top of each other would fight over the focus.
    openPoolImport() {
      this.showPoolGenerator = false;
      this.showPoolImport = true;
    },
    // The list is reloaded on every close, too: whatever the dialog created is then visible even
    // if its 'imported' event never arrived.
    closePoolImport() {
      this.showPoolImport = false;
      this.loadPools();
    },
    openPoolForImport(pool) {
      this.showPoolGenerator = false;
      this.loadPools();
      if (pool && pool.id) {
        this.selectPool(pool);
      }
    },
    selectPool(pool) {
      this.$emit('selectPool', pool);
      this.clearSearch(); // Clear search and close results after selecting a pool
    },
    selectQuestion(question) {
      // Find the pool associated with the question and emit it. GitHub #98: the search also finds
      // questions of the pools of the courses one leads, so those are looked up too; the question
      // goes along, so the pool opens on it (the editor, or "Fragen ansehen" for a read share).
      const targetPool = this.pools.find(p => p.id === question.pool_id) ||
                         this.sharedPools.find(p => p.id === question.pool_id) ||
                         this.coursePools.find(p => p.id === question.pool_id);
      if (targetPool) {
        this.$emit('selectPool', targetPool, { focusQuestionId: question.id });
        this.clearSearch(); // Clear search and close results after selecting a question
        return;
      }
      return this.selectQuestionFromServer(question);
    },
    // GitHub #98 (reported on 5.16.0): the search also finds the questions of the pools of the
    // courses one is enrolled in, which none of the lists above holds (and the student view
    // empties the led-course list). The server says whether and how the pool may be opened.
    // No question is handed on: whoever reaches a pool only through a course lands on Training,
    // never on "View questions" with its answer key (the deliberate limit of #98).
    async selectQuestionFromServer(question) {
      let pool;
      try {
        pool = (await axios.get(generateUrl('/apps/learning/api/pools/' + Number(question.pool_id)))).data;
      } catch (error) {
        pool = null;
      }
      if (!pool || !Number.isInteger(pool.id) || pool.id <= 0) {
        showError(t('learning', 'Could not find the pool for the selected question.'));
        return;
      }
      this.$emit('selectPool', { id: pool.id, name: pool.name || question.pool_name || '', is_shared: !!pool.is_shared, permission: pool.permission || 'read' });
      this.clearSearch();
    },
    showCreateDialog() {
      this.editingPool = null;
      this.form = this.emptyForm();
      this.showDialog = true;
    },
    async openStarterDialog() {
      this.showStarterDialog = true;
      await this.loadStarterPools();
    },
    closeStarterDialog() {
      this.showStarterDialog = false;
      this.starterImportingId = '';
    },
    editPool(pool) {
      this.editingPool = pool;
      this.form = {
        name: pool.name,
        description: pool.description || '',
        handbookKey: pool.handbook_key || '',
        handbookTitle: pool.handbook_title || '',
        chapterKey: pool.chapter_key || '',
        chapterTitle: pool.chapter_title || '',
        chapterOrder: pool.chapter_order || null,
      };
      this.showDialog = true;
    },
    sharePool(pool) { this.sharingPool = pool; },
    closeDialog() {
      this.showDialog = false;
      this.editingPool = null;
      this.form = this.emptyForm();
    },
    async savePool() {
      this.saving = true;
      const payload = this.normalizeFormPayload();
      try {
        if (this.editingPool) {
          await axios.put(generateUrl('/apps/learning/api/pools/' + this.editingPool.id), payload);
          showSuccess(t('learning', 'Pool updated'));
        } else {
          await axios.post(generateUrl('/apps/learning/api/pools'), payload);
          showSuccess(t('learning', 'Pool created'));
        }
        this.closeDialog();
        this.loadPools();
      } catch (error) {
        showError(t('learning', 'Failed to save pool'));
      } finally {
        this.saving = false;
      }
    },
    async loadStarterPools() {
      this.starterLoading = true;
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/starter-pools'));
        this.starterPools = Array.isArray(response.data?.pools) ? response.data.pools : [];
      } catch (error) {
        this.starterPools = [];
        showError(t('learning', 'Failed to load starter pools'));
      } finally {
        this.starterLoading = false;
      }
    },
    async importStarterPool(starter) {
      this.starterImportingId = starter.id
      try {
        await axios.post(generateUrl('/apps/learning/api/starter-pools/{id}/import', { id: starter.id }))
        showSuccess(t('learning', 'Starter pool imported'))
        await this.loadPools()
        this.closeStarterDialog()
      } catch (error) {
        showError(error?.response?.data?.error || t('learning', 'Failed to import starter pool'))
      } finally {
        this.starterImportingId = ''
      }
    },
    deletePool(pool) {
      this.poolToDelete = pool;
      this.showDeleteConfirm = true;
    },
    async confirmDeletePool() {
      if (!this.poolToDelete) return;
      try {
        await axios.delete(generateUrl('/apps/learning/api/pools/' + this.poolToDelete.id));
        showSuccess(t('learning', 'Pool deleted'));
        this.showDeleteConfirm = false;
        this.poolToDelete = null;
        this.loadPools();
      } catch (error) {
        showError(t('learning', 'Failed to delete pool'));
      }
    },
    formatDate(timestamp) { return new Date(timestamp * 1000).toLocaleDateString(); },
    toggleLanguage(lang) {
      const idx = this.activeLangs.indexOf(lang);
      if (idx >= 0) this.activeLangs.splice(idx, 1);
      else this.activeLangs.push(lang);
      localStorage.setItem('learning-lang-filter', JSON.stringify(this.activeLangs));
    },
    langCount(lang) {
      const re = /\(([A-Z]{2})\)\s*$/;
      if (lang === 'Other') return this.pools.filter(p => !re.test(p.name)).length;
      return this.pools.filter(p => { const m = p.name.match(re); return m && m[1] === lang; }).length;
    },

    // Search methods
    onSearchInput: debounce(function() {
      this.poolSearchResults = [];
      this.questionSearchResults = [];
      if (this.searchTerm.length >= 2) {
        const lowerCaseSearchTerm = this.searchTerm.toLowerCase();
        this.poolSearchResults = [...this.pools, ...this.sharedPools].filter(pool =>
          pool.name.toLowerCase().includes(lowerCaseSearchTerm) ||
          (pool.description && pool.description.toLowerCase().includes(lowerCaseSearchTerm)) ||
          (pool.handbook_title && pool.handbook_title.toLowerCase().includes(lowerCaseSearchTerm)) ||
          (pool.chapter_title && pool.chapter_title.toLowerCase().includes(lowerCaseSearchTerm)) ||
          (pool.chapter_key && pool.chapter_key.toLowerCase().includes(lowerCaseSearchTerm))
        );
        this.searchQuestions();
        this.showSearchResults = true;
      } else {
        this.showSearchResults = false;
      }
    }, 300),

    async searchQuestions() {
      if (this.searchTerm.length < 2) {
        this.questionSearchResults = [];
        return;
      }
      this.searchLoading = true;
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/questions/search'), {
          params: { query: this.searchTerm }
        });
        this.questionSearchResults = response.data;
      } catch (error) {
        console.error('Failed to search questions:', error);
        this.questionSearchResults = [];
        showError(t('learning', 'Search failed. Please try again.'));
      } finally {
        this.searchLoading = false;
      }
    },
    clearSearch() {
      this.searchTerm = '';
      this.poolSearchResults = [];
      this.questionSearchResults = [];
      this.showSearchResults = false;
    },
    // #98: the × disappears with the search term it clears, so focus would fall to the page.
    // It goes back to the field instead. Not in clearSearch(): picking a result clears too, and
    // focus must not jump back to the search then.
    clearSearchFromButton() {
      this.clearSearch();
      this.$nextTick(() => this.$refs.searchInput?.focus());
    },
    handleClickOutside(event) {
      if (this.$refs.searchContainer && !this.$refs.searchContainer.contains(event.target)) {
        this.showSearchResults = false;
      }
    },
    async loadQueueCount() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/leitner/queue/count'));
        this.queueCount = r.data.count || 0;
      } catch (e) { /* optional */ }
    },
    async loadRemediationCount() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/leitner/remediation/count'));
        this.remediationCount = r.data.count || 0;
      } catch (e) { /* optional */ }
    },
    async loadDailyProgress() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/v1/user/state'));
        if (r.data.daily_progress) {
          this.dailyProgress = r.data.daily_progress;
          this.editGoalValue = this.dailyProgress.daily_goal;
        }
        if (r.data.xp_multiplier) {
          this.xpMultiplier = r.data.xp_multiplier;
        }
      } catch (e) { /* optional */ }
    },
    async saveGoal() {
      this.savingGoal = true;
      try {
        const goal = Math.max(5, Math.min(200, this.editGoalValue));
        await axios.put(generateUrl('/apps/learning/api/v1/user/settings'), { daily_goal: goal });
        this.dailyProgress.daily_goal = goal;
        this.showGoalEdit = false;
        showSuccess(t('learning', 'Daily goal updated'));
      } catch (e) {
        showError(t('learning', 'Failed to update goal'));
      } finally {
        this.savingGoal = false;
      }
    },
  }
};
</script>

<style scoped>
.pool-list-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.pool-list-header h3 { font-size: 22px; font-weight: 600; margin: 0; color: var(--color-main-text); }
.pool-list-header__actions { display: flex; gap: 8px; align-items: center; }
/* look and width of the tabs: .lnc-tablist / .lnc-tab in styles/tabs.css (#46) */
.pool-tabs { margin-bottom: 24px; }
.pool-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; }
.pool-card { border: 1px solid var(--color-border); border-radius: 12px; padding: 20px 24px; background: var(--color-main-background); transition: box-shadow 0.2s, transform 0.15s; cursor: pointer; }
.pool-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.1); transform: translateY(-2px); }
.pool-card.shared { border-left: 4px solid var(--color-primary-element); }
.pool-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.pool-card-header h4 { font-size: 16px; font-weight: 600; margin: 0; flex: 1; line-height: 1.4; color: var(--color-main-text); }
/* GitHub #64: the pool name is the card's button (the card holds the action menu); it looks like the heading text. */
.pool-card-open { min-height: 0; margin: 0; padding: 0; border: 0; border-radius: 0; background: none; color: inherit; font: inherit; text-align: start; cursor: pointer; }
/* GitHub #79: Nextcloud's core button rule (button:not(.button-vue):hover/:focus) painted a square block
   behind the title; the whole card reacts to the pointer already, so the title has no hover background. */
.pool-card-open:hover, .pool-card-open:focus, .pool-card-open:active { background: none; }
.pool-card:has(.pool-card-open:focus-visible) { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
.permission-badge { padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; flex-shrink: 0; }
.permission-badge.read { background: var(--color-background-hover); color: var(--color-primary-element); border: 1px solid var(--color-primary-element); }
.permission-badge.edit { background: var(--color-success); color: var(--color-success-text, var(--color-success)); border: 1px solid var(--color-border-success); }
.pool-description { color: var(--color-text-maxcontrast); font-size: 13px; margin: 6px 0; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.pool-chapter-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
.pool-meta-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 4px 10px; font-size: 12px; font-weight: 600; }
.pool-meta-badge.handbook { background: color-mix(in srgb, var(--color-primary-element) 10%, var(--color-main-background)); color: var(--color-primary-element); }
.pool-meta-badge.chapter { background: var(--color-success); color: var(--color-success-text, var(--color-success)); }
.pool-meta { font-size: 12px; color: var(--color-text-maxcontrast); margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--color-border); }
.loading-center { display: block; margin: 60px auto; }
.form-group { margin-bottom: 18px; }
.chapter-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.chapter-meta-intro { margin: 18px 0 10px; padding: 10px 12px; border-radius: var(--border-radius, 8px); background: var(--color-background-hover); }
.chapter-meta-intro strong { display: block; margin-bottom: 4px; }
.chapter-meta-intro p { margin: 0; font-size: 0.85em; color: var(--color-text-maxcontrast); line-height: 1.45; }
.form-group .field-help { display: block; margin: 2px 0 4px; font-size: 0.8em; color: var(--color-text-maxcontrast); line-height: 1.35; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px; color: var(--color-main-text); }
.nc-input { width: 100%; padding: 10px 12px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); font-size: 14px; background: var(--color-main-background); color: var(--color-main-text); transition: border-color 0.2s; box-sizing: border-box; }
.nc-input:focus { border-color: var(--color-primary-element); outline: none; }
.dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }
 @media (max-width: 768px) {
  .pool-grid { grid-template-columns: 1fr; }
  .pool-card { padding: 16px; }
}

/* Language filter */
.lang-filter { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.lang-label { font-size: 13px; font-weight: 500; color: var(--color-text-maxcontrast); }
.lang-chip { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.15s; border: 1px solid var(--color-border); background: var(--color-main-background); color: var(--color-text-maxcontrast); min-height: 28px; }
.lang-chip:hover { border-color: var(--color-primary-element); }
.lang-chip.active { background: var(--color-primary-element); color: var(--color-primary-element-text); border-color: var(--color-primary-element); }

/* Search specific styles */
.search-container {
  position: relative;
  margin-bottom: 20px;
}
.search-input-wrapper {
  position: relative;
  display: flex;
  align-items: center;
}
.icon-search-label {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  color: var(--color-text-maxcontrast);
  pointer-events: none;
  line-height: 1; /* Ensure icon doesn't affect input height */
}
/* The magnifier is Nextcloud's background-image icon; an empty inline span has no size, so it never
   showed and the field kept an empty 40 px gap on the left (persona sweep C10). */
.icon-search-label .icon-search {
  display: block;
  width: 16px;
  height: 16px;
  background-size: 16px;
  background-position: center;
  background-repeat: no-repeat;
  opacity: 0.7;
}
.search-input {
  padding-left: 40px; /* Make space for the search icon */
  padding-right: 40px; /* Make space for the clear button */
}
/* #98: Nextcloud and the app give every button a minimum height and width (the clickable area),
   taller than this field, so the × stuck out above and below it. It is now a 28 px square (WCAG 2.5.8 asks
   for 24), centred on the field and inside it. */
.clear-search-btn {
  position: absolute;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  box-sizing: border-box;
  width: 28px;
  height: 28px;
  min-width: 0;
  min-height: 0;
  margin: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: none;
  border: none;
  border-radius: var(--border-radius, 3px);
  cursor: pointer;
  color: var(--color-text-maxcontrast);
  font-size: 16px;
  line-height: 1;
  padding: 0;
}
/* css/style.css gives every button in the app a 44 px minimum ("#app-learning button"); an id
   selector outranks the class above, so the reset needs one too (persona station measured 44 px). */
#app-learning .clear-search-btn {
  min-width: 0;
  min-height: 0;
}
.clear-search-btn:focus-visible {
  outline: 2px solid var(--color-primary-element);
  outline-offset: 0;
}
.clear-search-btn:hover {
  color: var(--color-primary-element);
}
.search-results-dropdown {
  position: absolute;
  top: 100%; /* Position below the search input */
  left: 0;
  right: 0;
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  z-index: 10;
  max-height: 300px;
  overflow-y: auto;
  margin-top: 8px;
  padding: 10px 0;
}
.loading-search {
  display: block;
  margin: 20px auto;
}
.search-results-section {
  margin-bottom: 10px;
}
.search-results-section:last-of-type {
  margin-bottom: 0;
}
.section-title {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
  text-transform: uppercase;
  padding: 5px 20px;
  margin: 0;
}
.results-list {
  list-style: none;
  padding: 0;
  margin: 0;
}
.results-list li {
  margin: 0;
}
/* The hit is a plain button over the whole row: no Nextcloud button frame, the row's own hover. */
.results-list .result-btn {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  width: 100%;
  min-height: auto;
  margin: 0;
  padding: 8px 20px;
  border: none;
  border-radius: 0;
  background: none;
  font: inherit;
  font-weight: normal;
  text-align: start;
  cursor: pointer;
  transition: background-color 0.15s;
}
.results-list .result-btn:hover,
.results-list .result-btn:focus {
  background-color: var(--color-background-hover);
}
.results-list .result-btn:focus-visible {
  outline: 2px solid var(--color-primary-element);
  outline-offset: -2px;
}
.result-id {
  color: var(--color-text-maxcontrast);
  font-size: 12px;
  font-family: monospace;
  margin-right: 6px;
  flex-shrink: 0;
}
.result-text {
  color: var(--color-main-text);
  font-size: 14px;
}
.result-subtext {
  color: var(--color-text-maxcontrast);
  font-size: 12px;
}
.empty-search-results {
  padding: 10px 20px;
  color: var(--color-text-maxcontrast);
  font-size: 14px;
  text-align: center;
}

/* Smart Queue + Daily Goal */
.smart-queue-section {
  display: flex;
  align-items: center;
  gap: 20px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.smart-queue-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 24px;
  border: none;
  border-radius: 12px;
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

/* GitHub #76: Nextcloud core gives every plain button a light primary background on :hover/:focus
   at (0,2,1), which beats the scoped rule above (0,2,0): white text on light blue. */
.smart-queue-btn:hover,
.smart-queue-btn:focus {
  background: var(--color-primary-element-hover);
}

.smart-queue-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}

.remediation-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 24px;
  border: 2px solid var(--color-warning);
  border-radius: 12px;
  background: color-mix(in srgb, var(--color-warning) 10%, var(--color-main-background));
  color: var(--color-warning-text);
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}
.remediation-btn:hover {
  background: color-mix(in srgb, var(--color-warning) 20%, var(--color-main-background));
  transform: translateY(-1px);
}
.rem-icon { font-size: 18px; }
.rem-count {
  padding: 2px 10px;
  border-radius: 12px;
  background: color-mix(in srgb, var(--color-warning) 20%, transparent);
  font-size: 13px;
}
.swipe-mode-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 24px;
  background: var(--color-main-background);
  border: 2px solid var(--color-border);
  border-radius: var(--border-radius-large);
  color: var(--color-main-text);
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}
.swipe-mode-btn:hover {
  border-color: var(--color-primary-element);
  background: var(--color-background-hover);
  transform: translateY(-1px);
}

.sq-icon { font-size: 20px; }
.sq-count {
  padding: 1px 9px;
  border-radius: 12px;
  /* GitHub #64: a white veil lightened the button colour under the count (4.09:1); an outline
     keeps the button's own text/background pair in light and dark */
  border: 1px solid currentColor;
  background: transparent;
  font-size: 13px;
}

.daily-goal-widget {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  border-radius: 12px;
  background: var(--color-background-hover);
  flex-wrap: wrap;
}

.daily-goal-widget.goal-reached {
  background: color-mix(in srgb, var(--color-warning) 10%, var(--color-background-hover));
}

.daily-goal-widget.goal-reached .goal-ring-progress {
  filter: drop-shadow(0 0 4px var(--color-warning));
}

.goal-ring { flex-shrink: 0; }
.goal-ring-progress { transition: stroke-dashoffset 0.5s ease-out; }

.daily-goal-info { display: flex; flex-direction: column; gap: 6px; }
.daily-goal-pct { font-size: 14px; font-weight: 700; color: var(--color-primary-element); }
.daily-goal-label { font-size: 12px; color: var(--color-text-maxcontrast); }
.daily-goal-edit-btn {
  background: none;
  border: none;
  font-size: 11px;
  color: var(--color-primary-element);
  cursor: pointer;
  padding: 0;
  text-align: left;
}
.daily-goal-edit-btn:hover { text-decoration: underline; }

.daily-goal-editor {
  display: flex;
  gap: 8px;
  align-items: center;
  width: 100%;
  margin-top: 4px;
}
.goal-input { width: 80px; padding: 6px 8px; font-size: 14px; }

/* Daily Challenge card styles now in DailyChallengeCard.vue */

.onboarding-hint {
  margin-bottom: 16px;
}

/* XP Multiplier Badge */
.xp-multiplier-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 14px;
  border-radius: 20px;
  background: color-mix(in srgb, var(--color-warning) 12%, var(--color-main-background));
  border: 2px solid var(--color-warning);
  margin-bottom: 16px;
}
.xp-mult-value {
  font-size: 18px;
  font-weight: 800;
  color: var(--color-warning-text);
}
.xp-mult-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-warning-text);
}
</style>
