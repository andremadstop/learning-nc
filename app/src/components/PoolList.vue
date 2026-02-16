<template>
  <div class="pool-list">
    <div class="pool-list-header">
      <h3>{{ t('learning', 'Question Pools') }}</h3>
      <NcButton type="primary" @click="showCreateDialog">
        {{ t('learning', '+ Create Pool') }}
      </NcButton>
    </div>

    <!-- Search Input -->
    <div class="search-container" ref="searchContainer">
      <div class="search-input-wrapper">
        <label for="pool-search" class="icon-search-label">
          <span class="icon-search" />
        </label>
        <input
          id="pool-search"
          v-model="searchTerm"
          type="text"
          class="nc-input search-input"
          :placeholder="t('learning', 'Search pools and questions...')"
          @input="onSearchInput"
          @focus="showSearchResults = true"
          @keydown.esc="clearSearch"
        />
        <button v-if="searchTerm" class="clear-search-btn" @click="clearSearch">
          <span class="icon-close" />
        </button>
      </div>

      <!-- Search Results Dropdown -->
      <div v-if="showSearchResults && searchTerm.length >= 2" class="search-results-dropdown">
        <NcLoadingIcon v-if="searchLoading" :size="24" class="loading-search" />
        <div v-else>
          <div v-if="poolSearchResults.length > 0" class="search-results-section">
            <h4 class="section-title">{{ t('learning', 'POOLS') }}</h4>
            <ul class="results-list">
              <li v-for="pool in poolSearchResults" :key="`pool-res-${pool.id}`" @click="selectPool(pool)">
                <span class="result-text">{{ pool.name }}</span>
              </li>
            </ul>
          </div>

          <div v-if="questionSearchResults.length > 0" class="search-results-section">
            <h4 class="section-title">{{ t('learning', 'QUESTIONS') }}</h4>
            <ul class="results-list">
              <li v-for="question in questionSearchResults" :key="`ques-res-${question.id}`" @click="selectQuestion(question)">
                <span class="result-text">"{{ question.text }}"</span>
                <span class="result-subtext">in {{ question.pool_name }}</span>
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
    <div class="pool-tabs" role="tablist">
      <button @click="activeTab = 'own'" :class="['tab-btn', { active: activeTab === 'own' }]" role="tab" :aria-selected="activeTab === 'own' ? 'true' : 'false'">
        {{ t('learning', 'My Pools ({n})', { n: filteredPools.length }) }}
      </button>
      <button @click="activeTab = 'shared'" :class="['tab-btn', { active: activeTab === 'shared' }]" role="tab" :aria-selected="activeTab === 'shared' ? 'true' : 'false'">
        {{ t('learning', 'Shared with me ({n})', { n: sharedPools.length }) }}
      </button>
    </div>

    <NcLoadingIcon v-if="loading" :size="44" class="loading-center" />

    <!-- My Pools -->
    <div v-else-if="activeTab === 'own'">
      <NcEmptyContent v-if="filteredPools.length === 0 && !searchTerm" :name="t('learning', 'No pools yet')" :description="t('learning', 'Create your first question pool to get started')" />
      <NcEmptyContent v-else-if="filteredPools.length === 0 && searchTerm" :name="t('learning', 'No pools matching \'{searchTerm}\'', { searchTerm: searchTerm })" :description="t('learning', 'Try a different search term')" />
      <div v-else class="pool-grid">
        <div v-for="pool in filteredPools" :key="pool.id" class="pool-card" @click="selectPool(pool)">
          <div class="pool-card-header">
            <h4>{{ pool.name }}</h4>
            <NcActions @click.native.stop>
              <NcActionButton @click="sharePool(pool)" close-after-click>{{ t('learning', 'Share') }}</NcActionButton>
              <NcActionButton @click="editPool(pool)" close-after-click>{{ t('learning', 'Edit') }}</NcActionButton>
              <NcActionButton @click="deletePool(pool)" close-after-click>{{ t('learning', 'Delete') }}</NcActionButton>
            </NcActions>
          </div>
          <p v-if="pool.description" class="pool-description">{{ pool.description }}</p>
          <div class="pool-meta">Created {{ formatDate(pool.created_at) }}</div>
        </div>
      </div>
    </div>

    <!-- Shared Pools -->
    <div v-else-if="activeTab === 'shared'">
      <NcEmptyContent v-if="sharedPools.length === 0" :name="t('learning', 'No shared pools')" :description="t('learning', 'When someone shares a pool with you, it will appear here')" />
      <div v-else class="pool-grid">
        <div v-for="pool in sharedPools" :key="'s-' + pool.id" class="pool-card shared" @click="selectPool(pool)">
          <div class="pool-card-header">
            <h4>{{ pool.name }}</h4>
            <span class="permission-badge" :class="pool.permission">
              {{ pool.permission === 'edit' ? t('learning', 'Can edit') : t('learning', 'View only') }}
            </span>
          </div>
          <p v-if="pool.description" class="pool-description">{{ pool.description }}</p>
          <div class="pool-meta">{{ t('learning', 'Shared by {name}', { name: pool.shared_by }) }}</div>
        </div>
      </div>
    </div>

    <!-- Create/Edit Dialog -->
    <NcDialog v-if="showDialog" :name="editingPool ? t('learning', 'Edit Pool') : t('learning', 'Create Pool')" @closing="closeDialog">
      <form @submit.prevent="savePool">
        <div class="form-group">
          <label for="pool-name">{{ t('learning', 'Pool Name *') }}</label>
          <input id="pool-name" v-model="form.name" type="text" required :placeholder="t('learning', 'e.g., CompTIA A+ Core 1')" class="nc-input" />
        </div>
        <div class="form-group">
          <label for="pool-description">{{ t('learning', 'Description') }}</label>
          <textarea id="pool-description" v-model="form.description" rows="4" :placeholder="t('learning', 'Optional description')" class="nc-input"></textarea>
        </div>
        <div class="dialog-actions">
          <NcButton type="tertiary" @click="closeDialog">{{ t('learning', 'Cancel') }}</NcButton>
          <NcButton type="primary" native-type="submit" :disabled="saving">
            {{ saving ? t('learning', 'Saving...') : t('learning', 'Save') }}
          </NcButton>
        </div>
      </form>
    </NcDialog>

    <ShareDialog v-if="sharingPool" :poolId="sharingPool.id" :poolName="sharingPool.name" @close="sharingPool = null" />
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js';
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js';
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';
import ShareDialog from './ShareDialog.vue';

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
  components: { NcButton, NcDialog, NcEmptyContent, NcActions, NcActionButton, NcLoadingIcon, ShareDialog },
  data() {
    return {
      pools: [],
      sharedPools: [],
      activeTab: 'own',
      loading: false,
      showDialog: false,
      editingPool: null,
      sharingPool: null,
      saving: false,
      form: { name: '', description: '' },
      // Language filter
      activeLangs: [],
      // Search related data
      searchTerm: '',
      showSearchResults: false,
      searchLoading: false,
      poolSearchResults: [], // Client-side filtered pools
      questionSearchResults: [], // API results for questions
    };
  },
  computed: {
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
        list = list.filter(p => p.name.toLowerCase().includes(q) || (p.description && p.description.toLowerCase().includes(q)));
      }
      return list;
    }
  },
  mounted() {
    this.loadPools();
    document.addEventListener('click', this.handleClickOutside);
    // Restore language filter from localStorage
    try {
      const saved = localStorage.getItem('learning-lang-filter');
      if (saved) this.activeLangs = JSON.parse(saved);
    } catch (e) { /* ignore */ }
  },
  beforeDestroy() {
    document.removeEventListener('click', this.handleClickOutside);
  },
  methods: {
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
      } finally {
        this.loading = false;
      }
    },
    selectPool(pool) {
      this.$emit('selectPool', pool);
      this.clearSearch(); // Clear search and close results after selecting a pool
    },
    selectQuestion(question) {
      // Find the pool associated with the question and emit it
      const targetPool = this.pools.find(p => p.id === question.pool_id) ||
                         this.sharedPools.find(p => p.id === question.pool_id);
      if (targetPool) {
        this.$emit('selectPool', targetPool);
        this.clearSearch(); // Clear search and close results after selecting a question
      } else {
        showError(t('learning', 'Could not find the pool for the selected question.'));
      }
    },
    showCreateDialog() {
      this.editingPool = null;
      this.form = { name: '', description: '' };
      this.showDialog = true;
    },
    editPool(pool) {
      this.editingPool = pool;
      this.form = { name: pool.name, description: pool.description || '' };
      this.showDialog = true;
    },
    sharePool(pool) { this.sharingPool = pool; },
    closeDialog() {
      this.showDialog = false;
      this.editingPool = null;
      this.form = { name: '', description: '' };
    },
    async savePool() {
      this.saving = true;
      try {
        if (this.editingPool) {
          await axios.put(generateUrl('/apps/learning/api/pools/' + this.editingPool.id), this.form);
          showSuccess(t('learning', 'Pool updated'));
        } else {
          await axios.post(generateUrl('/apps/learning/api/pools'), this.form);
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
    async deletePool(pool) {
      if (!confirm(t('learning', 'Delete "' + pool.name + '"?'))) return;
      try {
        await axios.delete(generateUrl('/apps/learning/api/pools/' + pool.id));
        showSuccess(t('learning', 'Pool deleted'));
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
        // FIX-ME-4: Client-side pool filtering includes shared pools
        const lowerCaseSearchTerm = this.searchTerm.toLowerCase();
        this.poolSearchResults = [...this.pools, ...this.sharedPools].filter(pool =>
          pool.name.toLowerCase().includes(lowerCaseSearchTerm) ||
          (pool.description && pool.description.toLowerCase().includes(lowerCaseSearchTerm))
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
    handleClickOutside(event) {
      if (this.$refs.searchContainer && !this.$refs.searchContainer.contains(event.target)) {
        this.showSearchResults = false;
      }
    }
  }
};
</script>

<style scoped>
.pool-list-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.pool-list-header h3 { font-size: 22px; font-weight: 600; margin: 0; color: var(--color-main-text); }
.pool-tabs { display: flex; gap: 4px; margin-bottom: 24px; padding: 5px; background: var(--color-background-hover); border-radius: 10px; max-width: 440px; }
.tab-btn { flex: 1; padding: 10px 20px; border: none; background: transparent; border-radius: 8px; cursor: pointer; font-weight: 500; font-size: 14px; transition: all 0.2s; color: var(--color-main-text); }
.tab-btn:hover { background: var(--color-background-dark); }
.tab-btn.active { background: var(--color-primary-element); color: var(--color-primary-element-text); }
.pool-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; }
.pool-card { border: 1px solid var(--color-border); border-radius: 12px; padding: 20px 24px; background: var(--color-main-background); transition: box-shadow 0.2s, transform 0.15s; cursor: pointer; }
.pool-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.1); transform: translateY(-2px); }
.pool-card.shared { border-left: 4px solid var(--color-primary-element); }
.pool-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.pool-card-header h4 { font-size: 16px; font-weight: 600; margin: 0; flex: 1; line-height: 1.4; color: var(--color-main-text); }
.permission-badge { padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; flex-shrink: 0; }
.permission-badge.read { background: var(--color-background-hover); color: var(--color-primary-element); border: 1px solid var(--color-primary-element); }
.permission-badge.edit { background: color-mix(in srgb, var(--color-success) 15%, transparent); color: var(--color-success); border: 1px solid var(--color-success); }
.pool-description { color: var(--color-text-maxcontrast); font-size: 13px; margin: 6px 0; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.pool-meta { font-size: 12px; color: var(--color-text-maxcontrast); margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--color-border); }
.loading-center { display: block; margin: 60px auto; }
.form-group { margin-bottom: 18px; }
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
  color: var(--color-text-maxcontrast);
  pointer-events: none;
  line-height: 1; /* Ensure icon doesn't affect input height */
}
.search-input {
  padding-left: 40px; /* Make space for the search icon */
  padding-right: 40px; /* Make space for the clear button */
}
.clear-search-btn {
  position: absolute;
  right: 12px;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--color-text-maxcontrast);
  font-size: 16px;
  line-height: 1;
  padding: 0;
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
  padding: 8px 20px;
  cursor: pointer;
  transition: background-color 0.15s;
  display: flex;
  flex-direction: column;
}
.results-list li:hover {
  background-color: var(--color-background-hover);
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
</style>
