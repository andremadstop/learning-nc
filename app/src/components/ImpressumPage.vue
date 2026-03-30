<template>
	<div class="legal-page legal-page--imprint">
		<header class="legal-page__hero">
			<p class="legal-page__eyebrow">DevCloud Lernplattform</p>
			<h1>Impressum</h1>
			<p class="legal-page__lead">
				Betreiberangaben für die Learning-App innerhalb der DevCloud-Instanz.
			</p>
			<div class="legal-page__actions">
				<a v-if="appUrl" class="legal-page__action" :href="appUrl">Zur Learning-App</a>
				<a v-if="privacyUrl" class="legal-page__action legal-page__action--secondary" :href="privacyUrl">Zur Datenschutzerklärung</a>
			</div>
		</header>

		<main class="legal-page__content">
			<section class="legal-card">
				<h2>Anbieter</h2>
				<p><strong>{{ responsible.name }}</strong></p>
				<p>{{ responsible.note }}</p>
			</section>

			<section class="legal-card">
				<h2>Kontakt</h2>
				<p>
					E-Mail:
					<a :href="'mailto:' + responsible.email">{{ responsible.email }}</a>
				</p>
			</section>

			<section class="legal-card">
				<h2>Verantwortlich für Inhalte</h2>
				<p>{{ responsible.name }}</p>
				<p>{{ responsible.note }}</p>
			</section>
		</main>
	</div>
</template>

<script>
import { getPrivacyInfoData } from '../utils/privacyInfo.js'

export default {
	name: 'ImpressumPage',
	props: {
		appUrl: {
			type: String,
			default: '',
		},
		privacyUrl: {
			type: String,
			default: '',
		},
	},
	computed: {
		responsible() {
			return getPrivacyInfoData().responsible || {}
		},
	},
}
</script>

<style scoped>
.legal-page {
	max-width: 960px;
	margin: 0 auto;
	padding: 40px 20px 56px;
}

.legal-page__hero {
	padding: 28px;
	border: 1px solid var(--color-border);
	border-radius: 24px;
	background:
		linear-gradient(135deg, color-mix(in srgb, var(--color-primary-element) 12%, transparent), transparent 42%),
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
	font-size: clamp(30px, 4vw, 44px);
	line-height: 1.05;
}

.legal-page__lead {
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
	display: grid;
	gap: 16px;
}

.legal-card {
	padding: 22px;
	border: 1px solid var(--color-border);
	border-radius: 20px;
	background: var(--color-main-background);
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
}

.legal-card h2 {
	margin: 0 0 12px;
	font-size: 18px;
}

.legal-card p {
	margin: 0 0 8px;
	line-height: 1.6;
}

.legal-card a {
	color: var(--color-primary-element);
}

@media (max-width: 640px) {
	.legal-page {
		padding: 24px 14px 40px;
	}

	.legal-page__hero,
	.legal-card {
		padding: 20px;
		border-radius: 18px;
	}
}
</style>
