<template>
	<span class="cert-pool-result__score">
		{{ bestScore !== null && bestScore !== undefined
			? t('learning', 'Bestes Ergebnis: {score} %', { score: bestScore })
			: t('learning', 'Noch keine Prüfung') }}
	</span>
	<span class="cert-pool-result__state" :class="{ 'cert-pool-result__state--passed': passed }">
		{{ passed ? '✓ ' + t('learning', 'Bestanden') : t('learning', 'Noch nicht bestanden') }}
	</span>
</template>

<script>
/**
 * GitHub #39: a pool's certificate result (best exam score + passed / not passed), as /pass-status
 * reports it. Shared by "Completion & certificate" (CourseSummary) and the learner's Exam tab, so
 * both read the same server values and look the same. No logic of its own.
 *
 * Two root spans on purpose: CourseSummary lays them out as grid cells of its pool row.
 */
export default {
	name: 'CertPoolResult',

	props: {
		// Best counting exam score in percent; null = no counting exam yet.
		bestScore: {
			type: Number,
			default: null,
		},
		passed: {
			type: Boolean,
			default: false,
		},
	},
}
</script>

<style scoped>
.cert-pool-result__score,
.cert-pool-result__state {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.cert-pool-result__state--passed {
	color: var(--color-success-text, var(--color-success));
	font-weight: 600;
}
</style>
