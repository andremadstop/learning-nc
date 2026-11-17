<template>
	<!-- GitHub #82: what a link leads to when its target is gone, archived, not shared or never
		existed. One root (the build-fragment gate); role="alert" so a screen reader announces it
		when it appears after the page has loaded. It never names the target: "no access" and "does
		not exist" look the same. -->
	<div class="dead-link" role="alert" data-test="dead-link">
		<h3 class="dead-link__title">
			{{ title }}
		</h3>
		<p class="dead-link__text">
			{{ text }}
		</p>
		<p v-if="hint" class="dead-link__hint">
			{{ hint }}
		</p>
		<div class="dead-link__actions">
			<NcButton variant="primary" data-test="dead-link-primary" @click="$emit('primary')">
				{{ primaryLabel }}
			</NcButton>
			<NcButton v-if="secondaryLabel"
				variant="secondary"
				data-test="dead-link-secondary"
				@click="$emit('secondary')">
				{{ secondaryLabel }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'

export default {
	name: 'DeadLinkNotice',
	components: { NcButton },
	props: {
		title: { type: String, required: true },
		text: { type: String, required: true },
		hint: { type: String, default: '' },
		primaryLabel: { type: String, required: true },
		secondaryLabel: { type: String, default: '' },
	},
	emits: ['primary', 'secondary'],
}
</script>

<style scoped>
.dead-link {
	max-width: 640px;
	margin: 24px auto;
	padding: 20px 24px;
	border: 1px solid var(--color-border);
	border-inline-start: 4px solid var(--color-warning);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.dead-link__title {
	margin: 0 0 8px;
	font-size: 1.15em;
	font-weight: bold;
}

.dead-link__text,
.dead-link__hint {
	margin: 0 0 8px;
}

.dead-link__hint {
	color: var(--color-text-maxcontrast);
}

.dead-link__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 12px;
}
</style>
