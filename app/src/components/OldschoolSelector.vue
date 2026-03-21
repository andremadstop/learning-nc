<template>
	<div class="oldschool-selector" role="list">
		<button
			v-for="card in cards"
			:key="card.mode"
			class="oldschool-card"
			role="listitem"
			@click="$emit('select-mode', card.mode)">
			<span class="oldschool-icon" aria-hidden="true">{{ card.icon }}</span>
			<span class="oldschool-title">{{ card.title }}</span>
			<span class="oldschool-desc">{{ card.desc }}</span>
		</button>
	</div>
</template>

<script>
export default {
	name: 'OldschoolSelector',

	emits: ['select-mode'],

	computed: {
		cards() {
			return [
				{
					mode: 'lernwuerfel',
					icon: '🎲',
					title: t('learning', 'Lernwürfel'),
					desc: t('learning', '2-4 Spieler, Würfeln + Fragen, Rauswerfen!'),
				},
				{
					mode: 'wissensturm',
					icon: '🏰',
					title: t('learning', 'Wissensturm'),
					desc: t('learning', '2-4 Spieler, 5 Kategorien, Steal-Mechanik'),
				},
			]
		},
	},
}
</script>

<style scoped>
.oldschool-selector {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
	padding: 8px 0;
}

.oldschool-card {
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
	.oldschool-card {
		transition: none;
	}
}

.oldschool-card:hover {
	background: var(--color-primary-light, #e8f4ff);
}

.oldschool-icon {
	font-size: 2.5rem;
	display: block;
	margin-bottom: 8px;
}

.oldschool-title {
	font-weight: bold;
	font-size: 1.1rem;
	margin-bottom: 4px;
	display: block;
}

.oldschool-desc {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	display: block;
}

@media (max-width: 600px) {
	.oldschool-selector {
		grid-template-columns: 1fr;
	}
}
</style>
