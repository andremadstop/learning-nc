<template>
  <div class="dialog-overlay" @click.self="$emit('close')">
    <div class="dialog-content share-dialog">
      <h3>Share "{{ poolName }}"</h3>

      <!-- Error state -->
      <div v-if="loadError" class="error-banner">
        <span>{{ loadError }}</span>
        <button @click="loadShares" class="button-sm">Retry</button>
      </div>

      <!-- Add Share Form -->
      <form @submit.prevent="addShare" class="share-form">
        <div class="share-input-row">
          <input
            v-model="newShareUser"
            type="text"
            placeholder="Username..."
            required
            class="share-input"
          />
          <select v-model="newSharePermission" class="share-select">
            <option value="read">Can view</option>
            <option value="edit">Can edit</option>
          </select>
          <button type="submit" class="button primary" :disabled="sharing">
            {{ sharing ? '...' : 'Share' }}
          </button>
        </div>
      </form>

      <!-- Existing Shares -->
      <div v-if="loading" class="loading-hint">Loading shares...</div>
      <div v-else-if="shares.length > 0" class="shares-list">
        <h4>Shared with</h4>
        <div v-for="share in shares" :key="share.id" class="share-item">
          <span class="share-user">{{ share.shared_with }}</span>
          <select :value="share.permission" @change="updatePermission(share, $event.target.value)" class="share-select-small">
            <option value="read">Can view</option>
            <option value="edit">Can edit</option>
          </select>
          <button @click="removeShare(share)" class="button-icon delete" title="Remove">✕</button>
        </div>
      </div>
      <div v-else class="no-shares">Not shared with anyone yet</div>

      <div class="dialog-actions">
        <button @click="$emit('close')" class="button">Done</button>
      </div>
    </div>
  </div>
</template>

<script>
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';

export default {
  name: 'ShareDialog',
  props: {
    poolId: { type: Number, required: true },
    poolName: { type: String, required: true }
  },
  data() {
    return {
      shares: [],
      newShareUser: '',
      newSharePermission: 'read',
      sharing: false,
      loading: false,
      loadError: null
    };
  },
  mounted() {
    this.loadShares();
  },
  methods: {
    async loadShares() {
      this.loading = true;
      this.loadError = null;
      try {
        const response = await axios.get(
          generateUrl('/apps/learning/api/pools/' + this.poolId + '/shares')
        );
        this.shares = response.data;
      } catch (error) {
        this.loadError = 'Failed to load shares';
      } finally {
        this.loading = false;
      }
    },
    async addShare() {
      this.sharing = true;
      try {
        await axios.post(
          generateUrl('/apps/learning/api/pools/' + this.poolId + '/shares'),
          {
            sharedWith: this.newShareUser,
            shareType: 'user',
            permission: this.newSharePermission
          }
        );
        showSuccess('Pool shared with ' + this.newShareUser);
        this.newShareUser = '';
        this.loadShares();
      } catch (error) {
        const msg = error.response?.data?.error || 'Failed to share pool';
        showError(msg);
      } finally {
        this.sharing = false;
      }
    },
    async updatePermission(share, permission) {
      try {
        await axios.put(
          generateUrl('/apps/learning/api/pools/' + this.poolId + '/shares/' + share.shared_with),
          { permission }
        );
        share.permission = permission;
        showSuccess('Permission updated');
      } catch (error) {
        showError('Failed to update permission');
      }
    },
    async removeShare(share) {
      try {
        await axios.delete(
          generateUrl('/apps/learning/api/pools/' + this.poolId + '/shares/' + share.shared_with)
        );
        showSuccess('Share removed');
        this.loadShares();
      } catch (error) {
        showError('Failed to remove share');
      }
    }
  }
};
</script>

<style scoped>
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
  border-radius: 8px;
  padding: 24px;
  width: 90%;
  max-width: 500px;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 4px 16px rgba(0,0,0,0.2);
}

.dialog-content h3 { margin: 0 0 16px 0; font-size: 18px; font-weight: 600; }
.share-dialog { max-width: 500px; }
.share-form { margin-bottom: 20px; }

.share-input-row { display: flex; gap: 8px; }
.share-input {
  flex: 1;
  padding: 10px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  font-size: 14px;
  min-width: 0;
}

.share-select, .share-select-small {
  padding: 8px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
  font-size: 14px;
}

.share-select-small { padding: 4px 8px; font-size: 13px; }

.shares-list h4 { font-size: 14px; font-weight: 600; margin-bottom: 8px; }

.share-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 0;
  border-bottom: 1px solid var(--color-border);
}

.share-user { flex: 1; font-weight: 500; }

.button-icon {
  background: none;
  border: none;
  cursor: pointer;
  padding: 8px;
  border-radius: 4px;
  font-size: 14px;
  min-width: 36px;
  min-height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.button-icon.delete:hover { background: #fee2e2; color: #ef4444; }

.no-shares { color: var(--color-text-lighter); text-align: center; padding: 16px; }
.loading-hint { color: var(--color-text-lighter); text-align: center; padding: 16px; }
.dialog-actions { margin-top: 20px; text-align: right; }

.error-banner {
  padding: 10px 14px;
  background: #fee2e2;
  border: 1px solid #fca5a5;
  border-radius: 6px;
  color: #991b1b;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  font-size: 13px;
}

.button-sm { padding: 4px 10px; border: 1px solid var(--color-border); border-radius: 4px; background: white; cursor: pointer; font-size: 12px; }

.button {
  padding: 10px 20px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
  cursor: pointer;
  font-size: 14px;
  min-height: 44px;
}

.button:hover { background: var(--color-background-hover); }
.button.primary { background: var(--color-primary); color: white; border-color: var(--color-primary); }
.button:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 480px) {
  .share-input-row { flex-direction: column; }
  .dialog-content { padding: 16px; }
}
</style>
