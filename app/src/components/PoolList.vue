<template>
  <div class="pool-list">
    <div class="pool-list-header">
      <h3>Question Pools</h3>
      <button @click="showCreateDialog" class="button primary">
        <span class="icon-add"></span>
        Create Pool
      </button>
    </div>

    <!-- Tabs -->
    <div class="pool-tabs">
      <button @click="activeTab = 'own'" :class="['tab-btn', { active: activeTab === 'own' }]">
        My Pools ({{ pools.length }})
      </button>
      <button @click="activeTab = 'shared'" :class="['tab-btn', { active: activeTab === 'shared' }]">
        Shared with me ({{ sharedPools.length }})
      </button>
    </div>

    <div v-if="loading" class="loading-indicator">
      <span class="icon-loading-small"></span> Loading pools...
    </div>

    <!-- My Pools -->
    <div v-else-if="activeTab === 'own'">
      <div v-if="pools.length === 0" class="empty-content">
        <h3>No pools yet</h3>
        <p>Create your first question pool to get started</p>
      </div>
      <div v-else class="pool-grid">
        <div v-for="pool in pools" :key="pool.id" class="pool-card" @click="selectPool(pool)">
          <div class="pool-card-header">
            <h4>{{ pool.name }}</h4>
            <div class="pool-actions" @click.stop>
              <button @click="sharePool(pool)" class="action-btn" title="Share">&#128279;</button>
              <button @click="editPool(pool)" class="icon-rename action-btn" title="Edit"></button>
              <button @click="deletePool(pool)" class="icon-delete action-btn" title="Delete"></button>
            </div>
          </div>
          <p v-if="pool.description" class="pool-description">{{ pool.description }}</p>
          <div class="pool-meta">
            <span class="icon-calendar"></span>
            Created {{ formatDate(pool.created_at) }}
          </div>
        </div>
      </div>
    </div>

    <!-- Shared Pools -->
    <div v-else-if="activeTab === 'shared'">
      <div v-if="sharedPools.length === 0" class="empty-content">
        <h3>No shared pools</h3>
        <p>When someone shares a pool with you, it will appear here</p>
      </div>
      <div v-else class="pool-grid">
        <div v-for="pool in sharedPools" :key="'s-' + pool.id" class="pool-card shared" @click="selectPool(pool)">
          <div class="pool-card-header">
            <h4>{{ pool.name }}</h4>
            <span class="permission-badge" :class="pool.permission">
              {{ pool.permission === 'edit' ? 'Can edit' : 'View only' }}
            </span>
          </div>
          <p v-if="pool.description" class="pool-description">{{ pool.description }}</p>
          <div class="pool-meta">
            Shared by {{ pool.shared_by }}
          </div>
        </div>
      </div>
    </div>

    <!-- Create/Edit Dialog -->
    <div v-if="showDialog" class="dialog-overlay" @click.self="closeDialog">
      <div class="dialog-content">
        <h3>{{ editingPool ? 'Edit Pool' : 'Create Pool' }}</h3>
        <form @submit.prevent="savePool">
          <div class="form-group">
            <label for="pool-name">Pool Name *</label>
            <input id="pool-name" v-model="form.name" type="text" required placeholder="e.g., CompTIA A+ Core 1" />
          </div>
          <div class="form-group">
            <label for="pool-description">Description</label>
            <textarea id="pool-description" v-model="form.description" rows="4" placeholder="Optional description"></textarea>
          </div>
          <div class="dialog-actions">
            <button type="button" @click="closeDialog" class="button">Cancel</button>
            <button type="submit" class="button primary" :disabled="saving">
              {{ saving ? 'Saving...' : 'Save' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Share Dialog -->
    <ShareDialog
      v-if="sharingPool"
      :poolId="sharingPool.id"
      :poolName="sharingPool.name"
      @close="sharingPool = null"
    />
  </div>
</template>

<script>
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';
import ShareDialog from './ShareDialog.vue';

export default {
  name: 'PoolList',
  components: { ShareDialog },
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
      form: { name: '', description: '' }
    };
  },
  mounted() {
    this.loadPools();
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
          // Backward compat if API returns flat array
          this.pools = Array.isArray(data) ? data : [];
          this.sharedPools = [];
        }
      } catch (error) {
        showError('Failed to load pools');
        console.error(error);
      } finally {
        this.loading = false;
      }
    },
    selectPool(pool) {
      this.$emit('selectPool', pool);
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
    sharePool(pool) {
      this.sharingPool = pool;
    },
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
          showSuccess('Pool updated successfully');
        } else {
          await axios.post(generateUrl('/apps/learning/api/pools'), this.form);
          showSuccess('Pool created successfully');
        }
        this.closeDialog();
        this.loadPools();
      } catch (error) {
        showError('Failed to save pool');
        console.error(error);
      } finally {
        this.saving = false;
      }
    },
    async deletePool(pool) {
      if (!confirm('Are you sure you want to delete "' + pool.name + '"?')) return;
      try {
        await axios.delete(generateUrl('/apps/learning/api/pools/' + pool.id));
        showSuccess('Pool deleted successfully');
        this.loadPools();
      } catch (error) {
        showError('Failed to delete pool');
        console.error(error);
      }
    },
    formatDate(timestamp) {
      return new Date(timestamp * 1000).toLocaleDateString();
    }
  }
};
</script>

<style scoped>
.pool-list-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.pool-list-header h3 { font-size: 22px; font-weight: 600; margin: 0; }

/* Tabs */
.pool-tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 24px;
  padding: 5px;
  background: var(--color-background-hover);
  border-radius: 10px;
  max-width: 440px;
}

.tab-btn {
  flex: 1;
  padding: 10px 20px;
  border: none;
  background: transparent;
  border-radius: 8px;
  cursor: pointer;
  font-weight: 500;
  font-size: 14px;
  transition: all 0.2s;
}

.tab-btn:hover { background: var(--color-background-dark); }
.tab-btn.active { background: var(--color-primary); color: white; box-shadow: 0 1px 3px rgba(0,0,0,0.12); }

/* Grid - wider cards, more columns */
.pool-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 20px;
}

.pool-card {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 20px 24px;
  background: var(--color-main-background);
  transition: box-shadow 0.2s, transform 0.15s;
  cursor: pointer;
}

.pool-card:hover {
  box-shadow: 0 6px 20px rgba(0,0,0,0.1);
  transform: translateY(-2px);
}

.pool-card.shared { border-left: 4px solid #3b82f6; }

.pool-card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 10px;
}

.pool-card-header h4 {
  font-size: 16px;
  font-weight: 600;
  margin: 0;
  flex: 1;
  line-height: 1.4;
}

.pool-actions { display: flex; gap: 4px; flex-shrink: 0; }

.action-btn {
  background: none;
  border: none;
  cursor: pointer;
  opacity: 0.5;
  font-size: 14px;
  padding: 4px 6px;
  border-radius: 4px;
  transition: opacity 0.2s, background 0.2s;
}

.action-btn:hover { opacity: 1; background: var(--color-background-hover); }

.permission-badge {
  padding: 3px 10px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  flex-shrink: 0;
}

.permission-badge.read { background: #dbeafe; color: #1d4ed8; }
.permission-badge.edit { background: #d1fae5; color: #065f46; }

.pool-description {
  color: var(--color-text-lighter);
  font-size: 13px;
  margin: 6px 0;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.pool-meta {
  font-size: 12px;
  color: var(--color-text-lighter);
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--color-border-dark, #eee);
}

.loading-indicator { text-align: center; padding: 60px; color: var(--color-text-lighter); }
.empty-content { text-align: center; padding: 80px 20px; color: var(--color-text-lighter); }

/* Dialog */
.dialog-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
}

.dialog-content {
  background: var(--color-main-background);
  border-radius: 12px;
  padding: 28px;
  width: 90%;
  max-width: 540px;
  box-shadow: 0 8px 30px rgba(0,0,0,0.2);
}

.dialog-content h3 { margin: 0 0 24px 0; font-size: 20px; font-weight: 600; }

.form-group { margin-bottom: 18px; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px; }
.form-group input, .form-group textarea {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  font-size: 14px;
  transition: border-color 0.2s;
}
.form-group input:focus, .form-group textarea:focus {
  border-color: var(--color-primary);
  outline: none;
  box-shadow: 0 0 0 2px rgba(0,130,201,0.15);
}

.dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }

.button {
  padding: 10px 20px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-main-background);
  cursor: pointer;
  font-size: 14px;
}

.button:hover { background: var(--color-background-hover); }
.button.primary { background: var(--color-primary); color: white; border-color: var(--color-primary); }
.button:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 768px) {
  .pool-grid { grid-template-columns: 1fr; }
  .pool-card { padding: 16px; }
}
</style>
