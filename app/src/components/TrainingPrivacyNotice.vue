<template>
	<!--
		DSGVO Art.13 TRAINING-START transparency notice (DSGVO-04).
		This is a SEPARATE surface from the third-party embed consent
		(VideoConsentOverlay): it is shown at the start of a Pflichtschulung course
		and states, in plain language, which data is processed, on which legal
		basis, and — mirroring the VIDEO-06 transient-segment design — that detailed
		playback patterns are NOT permanently stored.
	-->
	<section class="training-privacy" aria-labelledby="training-privacy-heading">
		<h4 id="training-privacy-heading" class="training-privacy__heading">
			<span class="training-privacy__icon" aria-hidden="true">🔒</span>
			{{ t('learning', 'Datenschutzhinweis zur Pflichtschulung') }}
		</h4>

		<dl class="training-privacy__list">
			<div class="training-privacy__row">
				<dt>{{ t('learning', 'Welche Daten') }}</dt>
				<dd>{{ t('learning', 'Gespeichert werden ausschließlich dein Abschlussstatus und der Zeitpunkt des Abschlusses.') }}</dd>
			</div>
			<div class="training-privacy__row">
				<dt>{{ t('learning', 'Zweck & Rechtsgrundlage') }}</dt>
				<dd>{{ t('learning', 'Nachweis der Pflichtschulung zur Erfüllung einer rechtlichen Verpflichtung (Art. 6 Abs. 1 lit. c DSGVO). Es werden nur die dafür erforderlichen Daten verarbeitet (Datenminimierung, Art. 5 Abs. 1 lit. c DSGVO).') }}</dd>
			</div>
			<div class="training-privacy__row">
				<dt>{{ t('learning', 'Kein Tracking') }}</dt>
				<dd class="training-privacy__emphasis">{{ t('learning', 'Wiedergabemuster (welche Sekunden, wie oft) werden NICHT dauerhaft gespeichert.') }}</dd>
			</div>
		</dl>

		<div v-if="showAcknowledge" class="training-privacy__actions">
			<button
				type="button"
				class="training-privacy__ack"
				@click="$emit('acknowledged')">
				{{ acknowledgeLabel || t('learning', 'Verstanden') }}
			</button>
		</div>
	</section>
</template>

<script>
export default {
	name: 'TrainingPrivacyNotice',

	props: {
		// When true, render a "Verstanden" button emitting `acknowledged`.
		showAcknowledge: {
			type: Boolean,
			default: false,
		},
		acknowledgeLabel: {
			type: String,
			default: '',
		},
	},
}
</script>

<style scoped>
.training-privacy {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px 20px;
	border: 1px solid var(--color-border);
	border-inline-start: 4px solid var(--color-primary-element);
	border-radius: var(--border-radius-large, 12px);
	background: color-mix(in srgb, var(--color-primary-element) 5%, var(--color-main-background));
}

.training-privacy__heading {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	font-size: 1em;
	font-weight: 700;
	color: var(--color-main-text);
}

.training-privacy__list {
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.training-privacy__row {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.training-privacy__row dt {
	font-size: 0.78em;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.training-privacy__row dd {
	margin: 0;
	font-size: 0.9em;
	line-height: 1.5;
	color: var(--color-main-text);
}

.training-privacy__emphasis {
	font-weight: 600;
}

.training-privacy__actions {
	margin-top: 4px;
}

.training-privacy__ack {
	min-height: 44px;
	padding: 0 18px;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
	cursor: pointer;
}

.training-privacy__ack:focus-visible {
	outline: 3px solid var(--color-primary-element);
	outline-offset: 2px;
}
</style>
