<template>
	<div class="arena-selector" role="list">
		<button
			v-for="card in cards"
			:key="card.mode"
			class="arena-card"
			role="listitem"
			@click="$emit('select-mode', card.mode)">
			<span class="arena-icon" aria-hidden="true">{{ card.icon }}</span>
			<span class="arena-title">{{ card.title }}</span>
			<span class="arena-desc">{{ card.desc }}</span>
		</button>
	</div>
</template>

<script>
export default {
	name: 'ArenaSelector',

	emits: ['select-mode'],

	computed: {
		cards() {
			return [
				{
					mode: 'duel',
					icon: '⚔️',
					title: t('learning', 'Duell (1v1)'),
					desc: t('learning', 'Fordere einen Mitspieler zum direkten Duell heraus.'),
				},
				{
					mode: 'sprint',
					icon: '⚡',
					title: t('learning', 'Sprint (2–5)'),
					desc: t('learning', 'Schnellster korrekter Antwortgeber gewinnt.'),
				},
				{
					mode: 'elimination',
					icon: '💀',
					title: t('learning', 'Elimination (2–5)'),
					desc: t('learning', 'Falsche Antwort kostet ein Leben — wer zuletzt steht, gewinnt.'),
				},
			]
		},
	},
}
</script>

<style scoped>
.arena-selector {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 16px;
	padding: 8px 0;
}

.arena-card {
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 24px 16px;
	cursor: pointer;
	text-align: center;
	background: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0;
	transition: background 0.2s;
}

@media (prefers-reduced-motion: reduce) {
	.arena-card {
		transition: none;
	}
}

.arena-card:hover {
	background: var(--color-primary-light, #e8f4ff);
}

.arena-icon {
	font-size: 2.5rem;
	display: block;
	margin-bottom: 8px;
}

.arena-title {
	font-weight: bold;
	font-size: 1.1rem;
	margin-bottom: 4px;
	display: block;
}

.arena-desc {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	display: block;
}

@media (max-width: 600px) {
	.arena-selector {
		grid-template-columns: 1fr;
	}
}
</style>
