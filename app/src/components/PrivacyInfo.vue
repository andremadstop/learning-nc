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

		<NcNoteCard v-if="aiView" type="info" class="privacy-info__notice">
			<p><strong>{{ t('learning', 'KI-Hinweis') }}</strong></p>
			<div :dir="aiView.dir" :lang="aiView.lang">
				<p v-if="!aiView.active">{{ aiView.off }}</p>
				<template v-else>
					<p>{{ aiView.intro }}</p>
					<p><strong>{{ aiView.providerSentence }}</strong></p>
					<p>{{ aiView.dataHeading }}</p>
					<ul class="privacy-info__notice-list">
						<li v-for="item in aiView.dataList" :key="item">{{ item }}</li>
					</ul>
					<p>{{ aiView.identity }}</p>
					<p>{{ aiView.region }}</p>
					<p>{{ aiView.automation }}</p>
					<p>{{ aiView.retention }}</p>
					<p>{{ aiView.optOut }}</p>
				</template>
			</div>
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
		<!-- GitHub #61: one column, no unexplained entry-count badge; in the personal settings each
		category is a closed <details>, like "What happens with my data?" -->
		<div class="privacy-info__category-grid">
			<component
				:is="collapsible ? 'details' : 'section'"
				v-for="group in namedCategories"
				:key="group.id"
				class="privacy-info__category-card"
				:class="{ 'privacy-info__category-card--collapsible': collapsible }">
				<component :is="collapsible ? 'summary' : 'div'" class="privacy-info__category-header">
					<h5>{{ group.title }}</h5>
				</component>

				<div
					v-for="entry in group.entries"
					:key="entry.id"
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
			</component>
		</div>

		<hr class="section-divider">

		<h4>{{ t('learning', 'Deine Rechte') }}</h4>
		<ul class="privacy-info__rights">
			<li v-for="right in rights" :key="right.id">
				<strong>{{ right.name }}</strong>
				<!-- the space lives inside the span: the compiler drops the line break between the tags (#38) -->
				<span v-if="right.article"> ({{ right.article }})</span>
				<span v-if="right.description"> — {{ right.description }}</span>
			</li>
		</ul>

		<hr class="section-divider">

		<h4>{{ t('learning', 'Verantwortlich') }}</h4>
		<div class="privacy-info__responsible">
			<template v-if="operator">
				<p><strong>{{ operator.name }}</strong></p>
				<p class="privacy-info__address">{{ operator.address }}</p>
				<p v-if="operator.register" class="section-desc">{{ operator.register }}</p>
				<p>
					<a :href="'mailto:' + operator.email">{{ operator.email }}</a>
				</p>
			</template>
			<p v-else class="section-desc">
				{{ t('learning', 'The operator of this platform has not entered any operator details yet. Please contact the administrators of this Nextcloud.') }}
			</p>
		</div>
	</div>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { getLanguage } from '@nextcloud/l10n'
import { buildAiNotice, buildPrivacyCategoryGroups, buildRights, getPrivacyInfoData } from '../utils/privacyInfo.js'

export default {
	name: 'PrivacyInfo',
	components: { NcNoteCard },
	props: {
		/**
		 * Who receives AI data right now: {active, provider, region}. Null while unknown, then no
		 * notice is shown. The privacy page (public) gets it from the server without any address;
		 * the personal settings page from the consent API.
		 */
		aiNotice: {
			type: Object,
			default: null,
		},
		/** The reader's language; the AI notice is shown in it (consent catalog, seven languages). */
		locale: {
			type: String,
			default: () => getLanguage(),
		},
		showHeading: {
			type: Boolean,
			default: true,
		},
		/**
		 * Each data category as a closed <details> (GitHub #61): the personal settings page carries
		 * the whole Art. 13 text below the settings; the public privacy page keeps it open.
		 */
		collapsible: {
			type: Boolean,
			default: false,
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
		/**
		 * The operator an admin entered (parseOperator): {name, address, register, email,
		 * content_responsible}. Null = none, a neutral hint is shown (audit 2026-11: the app author's
		 * company used to be shipped here for every installation).
		 */
		operator: {
			type: Object,
			default: null,
		},
	},
	computed: {
		privacyData() {
			return getPrivacyInfoData()
		},
		privacyVersion() {
			return this.privacyData?.version || ''
		},
		aiView() {
			return buildAiNotice(this.privacyData, this.aiNotice, this.locale)
		},
		highlightSections() {
			return Array.isArray(this.privacyData?.sections) ? this.privacyData.sections : []
		},
		namedCategories() {
			return buildPrivacyCategoryGroups(this.aiNotice?.active ? this.aiNotice.provider : '')
		},
		rights() {
			return buildRights()
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

.privacy-info__notice-list {
	margin: 0 0 8px;
	padding-inline-start: 22px;
	list-style: disc;
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
	/* one column: two ~350px columns made every paragraph a narrow, tall block (#61) */
	grid-template-columns: minmax(0, 1fr);
	gap: 10px;
}

.privacy-info__category-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 14px 16px;
	background: var(--color-main-background);
}

.privacy-info__category-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 10px;
}

.privacy-info__category-header h5 {
	margin: 0;
	font-size: 16px;
}

.privacy-info__category-card--collapsible {
	padding: 0;
}

.privacy-info__category-card--collapsible > .privacy-info__category-header {
	margin: 0;
	padding: 12px 16px;
	cursor: pointer;
	list-style: none;
	border-radius: var(--border-radius-large, 12px);
}

.privacy-info__category-card--collapsible > .privacy-info__category-header::-webkit-details-marker {
	display: none;
}

/* Decorative: the empty alt text keeps screen readers from reading "triangle" before every
   category; the first line is the fallback for browsers without CSS alt text. */
.privacy-info__category-card--collapsible > .privacy-info__category-header::before {
	content: '▸';
	content: '▸' / '';
	color: var(--color-primary-element);
	transition: transform 0.2s;
}

.privacy-info__category-card--collapsible[open] > .privacy-info__category-header::before {
	transform: rotate(90deg);
}

[dir="rtl"] .privacy-info__category-card--collapsible:not([open]) > .privacy-info__category-header::before {
	transform: scaleX(-1);
}

.privacy-info__category-card--collapsible > .privacy-info__category-header:hover,
.privacy-info__category-card--collapsible > .privacy-info__category-header:focus-visible {
	background: var(--color-background-hover);
}

.privacy-info__category-card--collapsible > .privacy-info__entry {
	margin-inline: 16px;
}

.privacy-info__category-card--collapsible > .privacy-info__entry:last-child {
	margin-bottom: 14px;
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
	/* label | text side by side instead of stacked kickers (#61); margins explicit, the core's
	dt/dd margins must not add up */
	display: grid;
	grid-template-columns: max-content minmax(0, 1fr);
	column-gap: 16px;
	row-gap: 6px;
	margin: 0;
}

.privacy-info__facts div {
	display: contents;
}

.privacy-info__facts dt {
	margin: 0;
	font-size: 13px;
	font-weight: 700;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}

.privacy-info__facts dd {
	margin: 0;
	line-height: 1.5;
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

.privacy-info__address {
	white-space: pre-line;
}

.privacy-info__responsible a {
	color: var(--color-primary-element);
}

@media (max-width: 640px) {
	.privacy-info__intro {
		flex-direction: column;
	}

	.privacy-info__facts {
		grid-template-columns: minmax(0, 1fr);
		row-gap: 2px;
	}

	.privacy-info__facts div + div dt {
		margin-top: 8px;
	}
}
</style>
