<template>
	<div class="privacy-info">
		<div v-if="showHeading" class="privacy-info__intro">
			<div>
				<h3>{{ t('learning', 'Datenschutz') }}</h3>
				<p class="section-desc">
					{{ t('learning', 'Informationen zur Datenverarbeitung in dieser App gemäß Art. 13 DSGVO.') }}
				</p>
			</div>
			<span v-if="privacyVersion" class="privacy-info__version">
				{{ t('learning', 'Version') }} {{ privacyVersion }}
			</span>
		</div>

		<div v-if="showPageLinks && hasPageLinks" class="privacy-info__links">
			<a v-if="appUrl" class="privacy-info__link" :href="appUrl">
				{{ t('learning', 'Zur Learning-App') }}
			</a>
			<a v-if="imprintUrl" class="privacy-info__link privacy-info__link--secondary" :href="imprintUrl">
				{{ t('learning', 'Zum Impressum') }}
			</a>
			<a v-if="privacyUrl" class="privacy-info__link privacy-info__link--secondary" :href="privacyUrl">
				{{ t('learning', 'Direktlink') }}
			</a>
		</div>

		<NcNoteCard v-if="aiNotice.provider" type="warning" class="privacy-info__notice">
			<p><strong>{{ t('learning', 'KI-Hinweis') }}</strong></p>
			<p>{{ t('learning', 'Diese App nutzt {provider} für KI-gestützte Erklärungen.', { provider: aiNotice.provider }) }}</p>
			<p v-if="aiNotice.what_is_sent">{{ aiNotice.what_is_sent }}</p>
			<p v-if="aiNotice.data_location">{{ aiNotice.data_location }}</p>
			<p v-if="aiNotice.what_is_not_sent">{{ aiNotice.what_is_not_sent }}</p>
			<p v-if="aiNotice.opt_out">{{ aiNotice.opt_out }}</p>
		</NcNoteCard>

		<div v-if="highlightSections.length > 0" class="privacy-info__section-grid">
			<NcNoteCard
				v-for="section in highlightSections"
				:key="section.id || section.title"
				type="info"
				class="privacy-info__section-card">
				<p><strong>{{ section.title }}</strong></p>
				<p>{{ section.content }}</p>
			</NcNoteCard>
		</div>

		<h4>{{ t('learning', 'Datenkategorien') }}</h4>
		<div class="privacy-info__category-grid">
			<section
				v-for="group in namedCategories"
				:key="group.id"
				class="privacy-info__category-card">
				<div class="privacy-info__category-header">
					<h5>{{ group.title }}</h5>
					<span v-if="group.entries.length > 1" class="privacy-info__category-count">{{ group.entries.length }}</span>
				</div>

				<div
					v-for="entry in group.entries"
					:key="group.id + '-' + entry.name"
					class="privacy-info__entry">
					<h6 v-if="showEntryHeading(group, entry)">{{ entry.name }}</h6>
					<p v-if="entry.description" class="privacy-info__entry-description">{{ entry.description }}</p>

					<dl class="privacy-info__facts">
						<div v-if="entry.items">
							<dt>{{ t('learning', 'Daten') }}</dt>
							<dd>{{ entry.items }}</dd>
						</div>
						<div v-if="entry.purpose">
							<dt>{{ t('learning', 'Zweck') }}</dt>
							<dd>{{ entry.purpose }}</dd>
						</div>
						<div v-if="entry.who_sees">
							<dt>{{ t('learning', 'Wer sieht es') }}</dt>
							<dd>{{ entry.who_sees }}</dd>
						</div>
						<div v-if="entry.retention">
							<dt>{{ t('learning', 'Speicherdauer') }}</dt>
							<dd>{{ entry.retention }}</dd>
						</div>
						<div v-if="entry.legal_basis">
							<dt>{{ t('learning', 'Rechtsgrundlage') }}</dt>
							<dd>{{ entry.legal_basis }}</dd>
						</div>
					</dl>
				</div>
			</section>
		</div>

		<hr class="section-divider">

		<h4>{{ t('learning', 'Deine Rechte') }}</h4>
		<ul class="privacy-info__rights">
			<li v-for="right in rights" :key="right.name">
				<strong>{{ right.name }}</strong>
				<span v-if="right.article">({{ right.article }})</span>
				<span v-if="right.description"> — {{ right.description }}</span>
			</li>
		</ul>

		<hr class="section-divider">

		<h4>{{ t('learning', 'Verantwortlich') }}</h4>
		<div class="privacy-info__responsible">
			<p><strong>{{ responsible.name }}</strong></p>
			<p>
				<a :href="'mailto:' + responsible.email">{{ responsible.email }}</a>
			</p>
			<p v-if="responsible.note" class="section-desc">{{ responsible.note }}</p>
		</div>
	</div>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import { buildPrivacyCategoryGroups, getPrivacyInfoData } from '../utils/privacyInfo.js'

export default {
	name: 'PrivacyInfo',
	components: { NcNoteCard },
	props: {
		showHeading: {
			type: Boolean,
			default: true,
		},
		showPageLinks: {
			type: Boolean,
			default: false,
		},
		appUrl: {
			type: String,
			default: '',
		},
		imprintUrl: {
			type: String,
			default: '',
		},
		privacyUrl: {
			type: String,
			default: '',
		},
	},
	computed: {
		privacyData() {
			return getPrivacyInfoData()
		},
		privacyVersion() {
			return this.privacyData?.version || ''
		},
		aiNotice() {
			return this.privacyData?.ai_notice || {}
		},
		highlightSections() {
			return Array.isArray(this.privacyData?.sections) ? this.privacyData.sections : []
		},
		namedCategories() {
			return buildPrivacyCategoryGroups(this.privacyData)
		},
		rights() {
			return Array.isArray(this.privacyData?.rights) ? this.privacyData.rights : []
		},
		responsible() {
			return this.privacyData?.responsible || {}
		},
		hasPageLinks() {
			return !!(this.appUrl || this.imprintUrl || this.privacyUrl)
		},
	},
	methods: {
		showEntryHeading(group, entry) {
			return group.entries.length > 1 || entry.name !== group.title
		},
	},
}
</script>

<style scoped>
.privacy-info {
	margin-top: 4px;
}

.privacy-info__intro {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
	margin-bottom: 16px;
}

.privacy-info__intro h3 {
	margin: 0 0 4px;
}

.privacy-info__version {
	display: inline-flex;
	align-items: center;
	padding: 6px 10px;
	border-radius: 999px;
	background: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
	color: var(--color-primary-element);
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.privacy-info__links {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	margin-bottom: 16px;
}

.privacy-info__link {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 10px 14px;
	border-radius: 999px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	text-decoration: none;
	font-weight: 600;
}

.privacy-info__link--secondary {
	background: color-mix(in srgb, var(--color-primary-element) 10%, var(--color-main-background));
	color: var(--color-primary-element);
}

.privacy-info__notice {
	margin-bottom: 18px;
}

.privacy-info__section-grid,
.privacy-info__category-grid {
	display: grid;
	gap: 14px;
}

.privacy-info__section-grid {
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	margin-bottom: 18px;
}

.privacy-info__category-grid {
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}

.privacy-info__category-card {
	border: 1px solid var(--color-border);
	border-radius: 18px;
	padding: 18px;
	background: var(--color-main-background);
	box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
}

.privacy-info__category-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	margin-bottom: 14px;
}

.privacy-info__category-header h5 {
	margin: 0;
	font-size: 16px;
}

.privacy-info__category-count {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 28px;
	height: 28px;
	padding: 0 8px;
	border-radius: 999px;
	background: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
	color: var(--color-primary-element);
	font-size: 12px;
	font-weight: 700;
}

.privacy-info__entry + .privacy-info__entry {
	margin-top: 16px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.privacy-info__entry h6 {
	margin: 0 0 8px;
	font-size: 14px;
	font-weight: 700;
}

.privacy-info__entry-description,
.section-desc {
	color: var(--color-text-maxcontrast);
	margin: 0 0 12px;
	font-size: 0.92em;
	line-height: 1.5;
}

.privacy-info__facts {
	display: grid;
	gap: 10px;
	margin: 0;
}

.privacy-info__facts div {
	display: grid;
	gap: 4px;
}

.privacy-info__facts dt {
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.privacy-info__facts dd {
	margin: 0;
	line-height: 1.55;
	color: var(--color-main-text);
}

.section-divider {
	border: none;
	border-top: 1px solid var(--color-border);
	margin: 24px 0 16px;
}

.privacy-info__rights {
	padding-inline-start: 18px;
	line-height: 1.8;
}

.privacy-info__rights li {
	margin-bottom: 4px;
}

.privacy-info__responsible a {
	color: var(--color-primary-element);
}

@media (max-width: 640px) {
	.privacy-info__intro {
		flex-direction: column;
	}
}
</style>
