<template>
	<div class="simulator-shell">
		<component
			v-if="activeComponent"
			:is="activeComponent"
			:key="nodeId"
			mode="embedded"
			:scenario="resolvedScenario"
			@result="onResult"
		/>
		<div v-else class="simulator-shell__error">
			Unbekannter Simulator-Typ: {{ type }}
		</div>
	</div>
</template>

<script>
import { SIMULATOR_MAP, resolveScenario, normalizeResult } from '../utils/simulatorShellLogic'

export { SIMULATOR_MAP }

export default {
	name: 'SimulatorShell',
	props: {
		type: { type: String, required: true },
		scenarioId: { type: String, default: '' },
		scenarioOverride: { type: Object, default: null },
		nodeId: { type: String, default: '' },
	},
	computed: {
		activeComponent() {
			return SIMULATOR_MAP[this.type] || null
		},
		resolvedScenario() {
			return resolveScenario(this.type, this.scenarioId, this.scenarioOverride)
		},
	},
	methods: {
		onResult(rawResult) {
			const normalized = normalizeResult(rawResult)
			if (!normalized) return
			this.$emit('complete', normalized.passed, normalized.score, normalized.rawResult)
		},
	},
}
</script>

<style scoped>
.simulator-shell {
	width: 100%;
}

.simulator-shell__error {
	color: var(--color-error, #e53935);
	padding: 16px;
	text-align: center;
}
</style>
