<template>
  <div class="pool-list">
    <div class="pool-list-header">
      <h3>Question Pools</h3>
      <button @click="showCreateDialog" class="button primary">
        <span class="icon-add"></span>
        Create Pool
      </button>
    </div>

    <div v-if="loading" class="loading-indicator">
      <span class="icon-loading-small"></span> Loading pools...
    </div>

    <div v-else-if="pools.length === 0" class="empty-content">
      <div class="icon-folder"></div>
      <h3>No pools yet</h3>
      <p>Create your first question pool to get started</p>
    </div>

    <div v-else class="pool-grid">
      <div v-for="pool in pools" :key="pool.id" class="pool-card" @click="selectPool(pool)">
        <div class="pool-card-header">
          <h4>{{ pool.name }}</h4>
          <div class="pool-actions" @click.stop>
            <button @click="editPool(pool)" class="icon-rename" title="Edit"></button>
            <button @click="deletePool(pool)" class="icon-delete" title="Delete"></button>
          </div>
        </div>
        <p v-if="pool.description" class="pool-description">{{ pool.description }}</p>
        <div class="pool-meta">
          <span class="icon-calendar"></span>
          Created {{ formatDate(pool.created_at) }}
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
            <input
              id="pool-name"
              v-model="form.name"
              type="text"
              required
              placeholder="e.g., CompTIA A+ Core 1"
            />
          </div>
          <div class="form-group">
            <label for="pool-description">Description</label>
            <textarea
              id="pool-description"
              v-model="form.description"
              rows="4"
              placeholder="Optional description"
            ></textarea>
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
  </div>
</template>

<script>
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';

export default {
  name: 'PoolList',
  data() {
    return {
      pools: [],
      loading: false,
      showDialog: false,
      editingPool: null,
      saving: false,
      form: {
        name: '',
        description: ''
      }
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
        this.pools = response.data;
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
      this.form = {
        name: pool.name,
        description: pool.description || ''
      };
      this.showDialog = true;
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
          await axios.put(
            generateUrl(`/apps/learning/api/pools/${this.editingPool.id}`),
            this.form
          );
          showSuccess('Pool updated successfully');
        } else {
          await axios.post(
            generateUrl('/apps/learning/api/pools'),
            this.form
          );
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
      if (!confirm(`Are you sure you want to delete "${pool.name}"?`)) {
        return;
      }
      try {
        await axios.delete(generateUrl(`/apps/learning/api/pools/${pool.id}`));
        showSuccess('Pool deleted successfully');
        this.loadPools();
      } catch (error) {
        showError('Failed to delete pool');
        console.error(error);
      }
    },
    formatDate(timestamp) {
      const date = new Date(timestamp * 1000);
      return date.toLocaleDateString();
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

.pool-list-header h3 {
  font-size: 20px;
  font-weight: 600;
  margin: 0;
}

.loading-indicator {
  text-align: center;
  padding: 40px;
  color: var(--color-text-lighter);
}

.empty-content {
  text-align: center;
  padding: 60px 20px;
  color: var(--color-text-lighter);
}

.empty-content .icon-folder {
  font-size: 64px;
  opacity: 0.3;
  margin-bottom: 20px;
}

.pool-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 20px;
}

.pool-card {
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 16px;
  background: var(--color-main-background);
  transition: box-shadow 0.2s, transform 0.2s;
  cursor: pointer;
}

.pool-card:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  transform: translateY(-2px);
}

.pool-card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 8px;
}

.pool-card-header h4 {
  font-size: 16px;
  font-weight: 600;
  margin: 0;
  flex: 1;
}

.pool-actions {
  display: flex;
  gap: 4px;
}

.pool-actions button {
  background: none;
  border: none;
  cursor: pointer;
  opacity: 0.6;
  font-size: 16px;
  padding: 4px;
  transition: opacity 0.2s;
}

.pool-actions button:hover {
  opacity: 1;
}

.pool-description {
  color: var(--color-text-lighter);
  font-size: 14px;
  margin: 8px 0;
  line-height: 1.4;
}

.pool-meta {
  font-size: 12px;
  color: var(--color-text-lighter);
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 12px;
}

/* Dialog */
.dialog-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
}

.dialog-content {
  background: var(--color-main-background);
  border-radius: 8px;
  padding: 24px;
  width: 90%;
  max-width: 500px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}

.dialog-content h3 {
  margin: 0 0 20px 0;
  font-size: 20px;
  font-weight: 600;
}

.form-group {
  margin-bottom: 16px;
}

.form-group label {
  display: block;
  margin-bottom: 4px;
  font-weight: 500;
  font-size: 14px;
}

.form-group input,
.form-group textarea {
  width: 100%;
  padding: 8px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  font-size: 14px;
}

.form-group textarea {
  resize: vertical;
}

.dialog-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 20px;
}

.button {
  padding: 8px 16px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-main-background);
  cursor: pointer;
  font-size: 14px;
  transition: all 0.2s;
}

.button:hover {
  background: var(--color-background-hover);
}

.button.primary {
  background: var(--color-primary);
  color: white;
  border-color: var(--color-primary);
}

.button.primary:hover {
  background: var(--color-primary-dark);
}

.button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
