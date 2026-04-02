<template>
  <AccessibleDialog :name="t('learning', 'Share: {poolName}', { poolName: poolName })" @closing="$emit('close')" size="normal">
    <NcNoteCard v-if="loadError" type="error">{{ loadError }}</NcNoteCard>

    <form @submit.prevent="addShare" class="share-form">
      <div class="share-input-row">
        <input v-model="newShareUser" type="text" :placeholder="t('learning', 'Username...')" required class="nc-input share-input" />
        <select v-model="newSharePermission" class="permission-select">
          <option value="read">{{ t('learning', 'Can view') }}</option>
          <option value="edit">{{ t('learning', 'Can edit') }}</option>
        </select>
        <NcButton type="primary" native-type="submit" :disabled="sharing">{{ sharing ? '...' : t('learning', 'Share') }}</NcButton>
      </div>
    </form>

    <NcLoadingIcon v-if="loading" :size="32" class="loading-center" />

    <div v-else-if="shares.length > 0" class="shares-list">
      <h4 class="section-label">{{ t('learning', 'Shared with') }}</h4>
      <div v-for="share in shares" :key="share.id" class="share-item">
        <span class="share-avatar">{{ share.shared_with.charAt(0).toUpperCase() }}</span>
        <span class="share-user">{{ share.shared_with }}</span>
        <select :value="share.permission" @change="updatePermission(share, $event.target.value)" class="permission-select permission-select-sm">
          <option value="read">{{ t('learning', 'Can view') }}</option>
          <option value="edit">{{ t('learning', 'Can edit') }}</option>
        </select>
        <NcButton type="error" @click="removeShare(share)" :aria-label="t('learning', 'Remove')">&#10005;</NcButton>
      </div>
    </div>
    <div v-else class="no-shares">
      <span class="no-shares-icon">&#x1F465;</span>
      <p>{{ t('learning', 'Not shared with anyone yet') }}</p>
    </div>

    <template #actions>
      <NcButton type="primary" @click="$emit('close')">{{ t('learning', 'Done') }}</NcButton>
    </template>
  </AccessibleDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';
import AccessibleDialog from './AccessibleDialog.vue';

export default {
  name: 'ShareDialog',
  components: { AccessibleDialog, NcButton, NcNoteCard, NcLoadingIcon },
  props: {
    poolId: { type: Number, required: true },
    poolName: { type: String, required: true }
  },
  data() {
    return { shares: [], newShareUser: '', newSharePermission: 'read', sharing: false, loading: false, loadError: null };
  },
  mounted() { this.loadShares(); },
  methods: {
    async loadShares() {
      this.loading = true; this.loadError = null;
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/pools/' + this.poolId + '/shares'));
        this.shares = response.data;
      } catch (error) { this.loadError = t('learning', 'Failed to load shares'); }
      finally { this.loading = false; }
    },
    async addShare() {
      this.sharing = true;
      try {
        await axios.post(generateUrl('/apps/learning/api/pools/' + this.poolId + '/shares'), {
          sharedWith: this.newShareUser, shareType: 'user', permission: this.newSharePermission
        });
        showSuccess(t('learning', 'Pool shared with {user}', { user: this.newShareUser }));
        this.newShareUser = ''; this.loadShares();
      } catch (error) { showError(error.response?.data?.error || t('learning', 'Failed to share pool')); }
      finally { this.sharing = false; }
    },
    async updatePermission(share, permission) {
      try {
        await axios.put(generateUrl('/apps/learning/api/pools/' + this.poolId + '/shares/' + share.shared_with), { permission });
        share.permission = permission; showSuccess(t('learning', 'Permission updated'));
      } catch (error) { showError(t('learning', 'Failed to update permission')); }
    },
    async removeShare(share) {
      try {
        await axios.delete(generateUrl('/apps/learning/api/pools/' + this.poolId + '/shares/' + share.shared_with));
        showSuccess(t('learning', 'Share removed')); this.loadShares();
      } catch (error) { showError(t('learning', 'Failed to remove share')); }
    }
  }
};
</script>

<style scoped>
/* Share Form */
.share-form {
  margin-bottom: 24px;
  padding: 16px;
  background: color-mix(in srgb, var(--color-primary-element) 5%, transparent);
  border-radius: var(--border-radius-large, 8px);
  border: 1px dashed color-mix(in srgb, var(--color-primary-element) 20%, transparent);
}

.share-input-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.share-input { flex: 1; min-width: 0; }

.nc-input {
  padding: 10px 12px;
  border: 2px solid var(--color-border);
  border-radius: var(--border-radius-large, 8px);
  font-size: 14px;
  background: var(--color-main-background);
  color: var(--color-main-text);
  transition: border-color 0.2s, box-shadow 0.2s;
  box-sizing: border-box;
}

.nc-input:focus {
  border-color: var(--color-primary-element);
  outline: none;
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-primary-element) 15%, transparent);
}

/* Permission Select — Pill Shape */
.permission-select {
  padding: 8px 14px;
  border: 2px solid var(--color-border);
  border-radius: 20px;
  font-size: 13px;
  font-weight: 600;
  background: var(--color-main-background);
  color: var(--color-main-text);
  cursor: pointer;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.permission-select:hover { border-color: var(--color-primary-element); }
.permission-select:focus {
  border-color: var(--color-primary-element);
  outline: none;
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-primary-element) 15%, transparent);
}

.permission-select-sm { padding: 5px 10px; font-size: 12px; }

/* Section Label — Data Observatory Style */
.section-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: var(--color-text-maxcontrast);
  font-weight: 700;
  margin: 0 0 12px;
}

/* Shares List */
.shares-list { margin-top: 4px; }

.share-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: var(--border-radius-large, 8px);
  border: 1px solid var(--color-border);
  margin-bottom: 8px;
  background: var(--color-main-background);
  transition: transform 0.2s, box-shadow 0.2s;
}

.share-item:hover {
  transform: translateY(-1px);
  box-shadow: 0 3px 10px color-mix(in srgb, var(--color-main-text) 8%, transparent);
}

/* Avatar Circle */
.share-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
  color: var(--color-primary-element);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 15px;
  flex-shrink: 0;
  text-transform: uppercase;
}

.share-user {
  flex: 1;
  font-weight: 600;
  font-size: 14px;
  color: var(--color-main-text);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Empty State */
.no-shares {
  text-align: center;
  padding: 36px 16px;
  color: var(--color-text-maxcontrast);
}

.no-shares-icon { font-size: 36px; display: block; margin-bottom: 10px; opacity: 0.4; }
.no-shares p { margin: 0; font-size: 14px; }

.loading-center { display: block; margin: 16px auto; }

@media (max-width: 480px) {
  .share-input-row { flex-direction: column; }
  .share-item { gap: 8px; padding: 10px; }
  .share-avatar { width: 32px; height: 32px; font-size: 13px; }
}
</style>
