<template>
  <NcDialog :name="t('learning', 'Share: {poolName}', { poolName: poolName })" @closing="$emit('close')" size="normal">
    <NcNoteCard v-if="loadError" type="error">{{ loadError }}</NcNoteCard>

    <form @submit.prevent="addShare" class="share-form">
      <div class="share-input-row">
        <input v-model="newShareUser" type="text" :placeholder="t('learning', 'Username...')" required class="nc-input share-input" />
        <select v-model="newSharePermission" class="nc-input share-select">
          <option value="read">{{ t('learning', 'Can view') }}</option>
          <option value="edit">{{ t('learning', 'Can edit') }}</option>
        </select>
        <NcButton type="primary" native-type="submit" :disabled="sharing">{{ sharing ? '...' : t('learning', 'Share') }}</NcButton>
      </div>
    </form>

    <NcLoadingIcon v-if="loading" :size="32" class="loading-center" />

    <div v-else-if="shares.length > 0" class="shares-list">
      <h4>{{ t('learning', 'Shared with') }}</h4>
      <div v-for="share in shares" :key="share.id" class="share-item">
        <span class="share-user">{{ share.shared_with }}</span>
        <select :value="share.permission" @change="updatePermission(share, $event.target.value)" class="nc-input share-select-sm">
          <option value="read">{{ t('learning', 'Can view') }}</option>
          <option value="edit">{{ t('learning', 'Can edit') }}</option>
        </select>
        <NcButton type="error" @click="removeShare(share)" :aria-label="t('learning', 'Remove')">&#10005;</NcButton>
      </div>
    </div>
    <div v-else class="no-shares">{{ t('learning', 'Not shared with anyone yet') }}</div>

    <template #actions>
      <NcButton type="primary" @click="$emit('close')">{{ t('learning', 'Done') }}</NcButton>
    </template>
  </NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';

export default {
  name: 'ShareDialog',
  components: { NcDialog, NcButton, NcNoteCard, NcLoadingIcon },
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
.share-form { margin-bottom: 20px; }
.share-input-row { display: flex; gap: 8px; }
.share-input { flex: 1; min-width: 0; }
.nc-input { padding: 10px 12px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); font-size: 14px; background: var(--color-main-background); color: var(--color-main-text); transition: border-color 0.2s; box-sizing: border-box; }
.nc-input:focus { border-color: var(--color-primary-element); outline: none; }
.share-select { width: auto; }
.share-select-sm { padding: 6px 8px; font-size: 13px; width: auto; }
.shares-list h4 { font-size: 14px; font-weight: 600; margin-bottom: 8px; color: var(--color-main-text); }
.share-item { display: flex; align-items: center; gap: 8px; padding: 10px 0; border-bottom: 1px solid var(--color-border); }
.share-user { flex: 1; font-weight: 500; color: var(--color-main-text); }
.no-shares { color: var(--color-text-maxcontrast); text-align: center; padding: 16px; }
.loading-center { display: block; margin: 16px auto; }
@media (max-width: 480px) { .share-input-row { flex-direction: column; } }
</style>