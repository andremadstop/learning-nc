<template>
	<ul class="pool-accuracy-list">
		<li v-for="pool in pools" :key="pool.pool_id" class="pool-accuracy-item">
			<span class="pool-accuracy-name">{{ pool.pool_name }}</span>
			<span class="pool-accuracy-value">{{ pool.accuracy }}% {{ t('learning', 'accuracy') }}</span>
		</li>
	</ul>
</template>

<script>
/**
 * GitHub #36: the accuracy per pool of one learner in a course (GET /api/courses/{id}/students/{uid},
 * pools). Without Leitner it is what is left of the box cards. Shown in "Mein Fortschritt" and in
 * the instructor drill-down, and in the learners' completion area when "Mein Fortschritt" is gone.
 */
export default {
	name: 'PoolAccuracyList',

	props: {
		pools: {
			type: Array,
			default: () => [],
		},
	},
}
</script>

<style scoped>
.pool-accuracy-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.pool-accuracy-item {
	display: flex;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 12px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}

.pool-accuracy-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.pool-accuracy-value {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}
</style>
