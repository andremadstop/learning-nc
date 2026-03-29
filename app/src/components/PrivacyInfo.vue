<template>
  <div class="privacy-info">
    <h3>{{ t('learning', 'Privacy') }}</h3>
    <p class="section-desc">{{ t('learning', 'Information about data processing in this app according to Art. 13 GDPR.') }}</p>

    <!-- AI Notice -->
    <NcNoteCard type="warning">
      <p><strong>{{ t('learning', 'AI Notice') }}</strong></p>
      <p>{{ t('learning', 'This app uses {provider} for AI-powered explanations.', { provider: aiNotice.provider }) }}</p>
      <p>{{ privacyData.ai_notice.what_is_sent }}</p>
      <p>{{ privacyData.ai_notice.data_location }}</p>
      <p>{{ privacyData.ai_notice.what_is_not_sent }}</p>
      <p>{{ privacyData.ai_notice.opt_out }}</p>
    </NcNoteCard>

    <!-- Data categories table -->
    <h4>{{ t('learning', 'Data categories') }}</h4>
    <div class="privacy-info__table-wrap">
      <table class="privacy-info__table">
        <thead>
          <tr>
            <th>{{ t('learning', 'Category') }}</th>
            <th>{{ t('learning', 'Data') }}</th>
            <th>{{ t('learning', 'Purpose') }}</th>
            <th>{{ t('learning', 'Who sees it') }}</th>
            <th>{{ t('learning', 'Retention') }}</th>
            <th>{{ t('learning', 'Legal basis') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="cat in privacyData.categories" :key="cat.name">
            <td><strong>{{ cat.name }}</strong></td>
            <td>{{ cat.items }}</td>
            <td>{{ cat.purpose }}</td>
            <td>{{ cat.who_sees }}</td>
            <td>{{ cat.retention }}</td>
            <td>{{ cat.legal_basis }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <hr class="section-divider" />

    <!-- Rights -->
    <h4>{{ t('learning', 'Your Rights') }}</h4>
    <ul class="privacy-info__rights">
      <li v-for="right in privacyData.rights" :key="right.name">
        <strong>{{ right.name }}</strong> ({{ right.article }})
        — {{ right.description }}
      </li>
    </ul>

    <hr class="section-divider" />

    <!-- Responsible -->
    <h4>{{ t('learning', 'Responsible') }}</h4>
    <p>{{ privacyData.responsible.name }}</p>
    <p>{{ privacyData.responsible.email }}</p>
    <p class="section-desc">{{ privacyData.responsible.note }}</p>
  </div>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import privacyData from '../../data/privacy-info.json'

export default {
	name: 'PrivacyInfo',
	components: { NcNoteCard },
	computed: {
		privacyData() {
			return privacyData
		},
		aiNotice() {
			return privacyData.ai_notice
		},
	},
}
</script>

<style scoped>
.privacy-info {
  margin-top: 4px;
}

.section-desc {
  color: var(--color-text-maxcontrast);
  margin: 0 0 12px;
  font-size: 0.9em;
}

.section-divider {
  border: none;
  border-top: 1px solid var(--color-border);
  margin: 20px 0 16px;
}

.privacy-info__table-wrap {
  overflow-x: auto;
  margin-bottom: 12px;
}

.privacy-info__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.privacy-info__table th {
  text-align: left;
  font-weight: 600;
  padding: 8px 10px;
  border-bottom: 2px solid var(--color-border);
  color: var(--color-text-maxcontrast);
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.privacy-info__table td {
  padding: 8px 10px;
  border-bottom: 1px solid var(--color-border);
  vertical-align: top;
}

.privacy-info__rights {
  padding-inline-start: 18px;
  line-height: 1.8;
}

.privacy-info__rights li {
  margin-bottom: 4px;
}
</style>
