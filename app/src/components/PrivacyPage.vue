<template>
	<div class="legal-page legal-page--privacy">
		<header class="legal-page__hero">
			<p class="legal-page__eyebrow">DevCloud Lernplattform</p>
			<h1>Datenschutzerklärung</h1>
			<p class="legal-page__lead">
				Diese Seite bündelt die Datenschutzinformationen der Learning-App direkt in Nextcloud.
				Die Datenbasis kommt aus <code>app/data/privacy-info.json</code>.
			</p>
			<div class="legal-page__actions">
				<a v-if="appUrl" class="legal-page__action" :href="appUrl">Zur Learning-App</a>
				<a v-if="imprintUrl" class="legal-page__action legal-page__action--secondary" :href="imprintUrl">Zum Impressum</a>
			</div>
		</header>

		<main class="legal-page__content">
			<PrivacyInfo :show-heading="false" :show-page-links="false" />
		</main>
	</div>
</template>

<script>
import PrivacyInfo from './PrivacyInfo.vue'

export default {
	name: 'PrivacyPage',
	components: {
		PrivacyInfo,
	},
	props: {
		appUrl: {
			type: String,
			default: '',
		},
		imprintUrl: {
			type: String,
			default: '',
		},
	},
}
</script>

<style scoped>
.legal-page {
	max-width: 1040px;
	margin: 0 auto;
	padding: 40px 20px 120px;
}

.legal-page__hero {
	padding: 28px;
	border: 1px solid var(--color-border);
	border-radius: 24px;
	background:
		radial-gradient(circle at top right, color-mix(in srgb, var(--color-primary-element) 12%, transparent), transparent 45%),
		var(--color-main-background);
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
	margin-bottom: 24px;
}

.legal-page__eyebrow {
	margin: 0 0 8px;
	font-size: 12px;
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.legal-page__hero h1 {
	margin: 0 0 12px;
	font-size: clamp(32px, 4vw, 48px);
	line-height: 1.05;
}

.legal-page__lead {
	max-width: 720px;
	margin: 0;
	font-size: 16px;
	line-height: 1.65;
	color: var(--color-text-maxcontrast);
}

.legal-page__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin-top: 20px;
}

.legal-page__action {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 12px 16px;
	border-radius: 999px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 700;
	text-decoration: none;
}

.legal-page__action--secondary {
	background: color-mix(in srgb, var(--color-primary-element) 10%, var(--color-main-background));
	color: var(--color-primary-element);
}

.legal-page__content {
	padding: 0 4px;
}

@media (max-width: 640px) {
	.legal-page {
		padding: 24px 14px 40px;
	}

	.legal-page__hero {
		padding: 20px;
		border-radius: 18px;
	}
}
</style>
