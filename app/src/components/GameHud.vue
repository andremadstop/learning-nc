<template>
	<div class="game-hud">
		<div class="game-hud-role">
			<CharacterAvatar
				class="game-hud-role-avatar"
				:characterId="avatarCharacterId"
				:size="36"
				state="idle" />
			<div class="game-hud-role-copy">
				<span class="game-hud-role-name">{{ roleLabel }}</span>
				<span class="game-hud-role-meta">{{ t('learning', 'Live-Status') }}</span>
			</div>
		</div>

		<div class="game-hud-section game-hud-items" :title="inventoryTitle">
			<span
				v-for="item in hudState.items.visible"
				:key="item"
				class="game-hud-item"
				:class="{ 'game-hud-item--new': highlightedItems.includes(item) }"
				:title="itemTooltip(item)">
				<span class="game-hud-item-icon">{{ itemIcon(item) }}</span>
			</span>
			<span
				v-if="hudState.items.overflow > 0"
				class="game-hud-item game-hud-item--overflow"
				:title="overflowTitle">
				+{{ hudState.items.overflow }}
			</span>
		</div>

		<div class="game-hud-section game-hud-reputation">
			<div
				v-for="bar in hudState.reputation"
				:key="bar.domain"
				class="game-hud-rep-track"
				:title="reputationTitle(bar)">
				<span class="game-hud-rep-key">{{ domainShort(bar.domain) }}</span>
				<div class="game-hud-rep-bar">
					<div
						class="game-hud-rep-fill"
						:style="reputationFillStyle(bar)" />
				</div>
				<span
					v-if="showReputationDelta(bar.domain)"
					class="game-hud-rep-delta"
					:class="reputationDeltaClass(bar.domain)">
					{{ reputationDeltaText(bar.domain) }}
				</span>
			</div>
		</div>

		<div class="game-hud-section game-hud-score">
			<span class="game-hud-score-label">{{ t('learning', 'Score') }}</span>
			<div class="game-hud-score-value-wrap">
				<span class="game-hud-score-value">{{ hudState.score }}</span>
				<span
					v-if="showScoreDelta"
					class="game-hud-score-delta"
					:class="scoreDeltaClass">
					{{ scoreDeltaText }}
				</span>
			</div>
		</div>

		<div class="game-hud-section game-hud-act" :title="actTitle">
			<span class="game-hud-act-label">{{ actLabel }}</span>
			<div class="game-hud-act-bar">
				<div
					class="game-hud-act-fill"
					:style="{ width: actProgressWidth }" />
			</div>
		</div>
	</div>
</template>

<script>
import CharacterAvatar from './CharacterAvatar.vue'
import {
	computeActProgress,
	computeHudState,
} from '../utils/hudEngine.js'

const ROLE_LABELS = {
	architect: 'Netzwerk-Architekt',
	security: 'Security-Analystin',
	sysadmin: 'Sysadmin',
	helpdesk: 'Helpdesk',
}

const ITEM_META = {
	server_logs: {
		icon: '📋',
		label: 'Server Logs',
		description: 'Gesammelte Log-Dateien für die Analyse.',
	},
	incident_report: {
		icon: '🧾',
		label: 'Incident Report',
		description: 'Kurzbericht zum aktuellen Vorfall.',
	},
	backup_tape: {
		icon: '💾',
		label: 'Backup Tape',
		description: 'Offline-Backup für den Notfall.',
	},
	kaffee: {
		icon: '☕',
		label: 'Kaffee',
		description: 'Verbessert die Fehlersuche vermutlich psychologisch.',
	},
	key_card: {
		icon: '🪪',
		label: 'Key Card',
		description: 'Zugang für gesicherte Bereiche.',
	},
}

function humanizeItemName(item) {
	return String(item || '')
		.split('_')
		.filter(Boolean)
		.map(part => part.charAt(0).toUpperCase() + part.slice(1))
		.join(' ')
}

export default {
	name: 'GameHud',

	components: {
		CharacterAvatar,
	},

	props: {
		stateBag: {
			type: Object,
			default: function() {
				return {}
			},
		},
		gameState: {
			type: Object,
			default: function() {
				return {}
			},
		},
		fullGraph: {
			type: Object,
			default: function() {
				return { nodes: [] }
			},
		},
		scoreDelta: {
			type: Object,
			default: null,
		},
		reputationDeltas: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			highlightedItems: [],
		}
	},

	computed: {
		hudState() {
			return computeHudState(this.stateBag, this.gameState)
		},

		actProgress() {
			return computeActProgress(this.fullGraph, this.hudState.act)
		},

		avatarCharacterId() {
			return this.hudState.role || 'unknown'
		},

		roleLabel() {
			return ROLE_LABELS[this.hudState.role] || this.hudState.role || t('learning', 'Unbekannt')
		},

		inventoryTitle() {
			if (!this.hudState.items.all.length) {
				return t('learning', 'Keine Items gesammelt')
			}
			return this.hudState.items.all.map(item => this.itemLabel(item)).join(', ')
		},

		overflowTitle() {
			const hiddenItems = this.hudState.items.all.slice(this.hudState.items.visible.length)
			return hiddenItems.map(item => this.itemLabel(item)).join(', ')
		},

		showScoreDelta() {
			return this.scoreDelta && this.scoreDelta.direction && this.scoreDelta.direction !== 'none'
		},

		scoreDeltaClass() {
			return this.scoreDelta && this.scoreDelta.direction
				? `game-hud-score-delta--${this.scoreDelta.direction}`
				: ''
		},

		scoreDeltaText() {
			if (!this.showScoreDelta) {
				return ''
			}
			const delta = Number(this.scoreDelta.delta) || 0
			return delta > 0 ? `+${delta}` : `${delta}`
		},

		actLabel() {
			return `Akt ${this.actProgress.current}/${this.actProgress.total}`
		},

		actTitle() {
			return this.actProgress.actNames.join(' • ')
		},

		actProgressWidth() {
			if (!this.actProgress.total) {
				return '0%'
			}
			return `${Math.min(100, Math.max(0, (this.actProgress.current / this.actProgress.total) * 100))}%`
		},
	},

	watch: {
		'stateBag.items': {
			deep: true,
			immediate: true,
			handler(newItems, oldItems) {
				const nextItems = Array.isArray(newItems) ? newItems : []
				const previousItems = Array.isArray(oldItems) ? oldItems : []
				const previousSet = new Set(previousItems)
				const addedItems = nextItems.filter(item => !previousSet.has(item))

				this.highlightedItems = addedItems
				if (this._itemHighlightTimer) {
					clearTimeout(this._itemHighlightTimer)
				}
				if (addedItems.length) {
					this._itemHighlightTimer = setTimeout(() => {
						this.highlightedItems = []
						this._itemHighlightTimer = null
					}, 1000)
				}
			},
		},
	},

	beforeDestroy() {
		if (this._itemHighlightTimer) {
			clearTimeout(this._itemHighlightTimer)
			this._itemHighlightTimer = null
		}
	},

	methods: {
		itemMeta(item) {
			const known = ITEM_META[item]
			if (known) {
				return known
			}

			return {
				icon: '📦',
				label: humanizeItemName(item),
				description: t('learning', 'Gesammeltes Kampagnen-Item'),
			}
		},

		itemIcon(item) {
			return this.itemMeta(item).icon
		},

		itemLabel(item) {
			return this.itemMeta(item).label
		},

		itemTooltip(item) {
			const meta = this.itemMeta(item)
			return `${meta.label} • ${meta.description}`
		},

		domainShort(domain) {
			if (domain === 'security') return 'SEC'
			if (domain === 'management') return 'MGT'
			if (domain === 'team') return 'TEAM'
			return String(domain || '').toUpperCase()
		},

		reputationTitle(bar) {
			return `${bar.label}: ${bar.value}`
		},

		reputationFillStyle(bar) {
			return {
				width: `${Math.min(100, Math.max(0, (Number(bar.value) || 0) * 10))}%`,
				backgroundColor: bar.color,
			}
		},

		showReputationDelta(domain) {
			const delta = Number(this.reputationDeltas?.[domain]) || 0
			return delta !== 0
		},

		reputationDeltaText(domain) {
			const delta = Number(this.reputationDeltas?.[domain]) || 0
			return delta > 0 ? `+${delta}` : `${delta}`
		},

		reputationDeltaClass(domain) {
			const delta = Number(this.reputationDeltas?.[domain]) || 0
			return delta > 0 ? 'game-hud-rep-delta--up' : 'game-hud-rep-delta--down'
		},
	},
}
</script>
