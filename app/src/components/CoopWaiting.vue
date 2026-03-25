<template>
	<section class="coop-waiting">
		<div class="coop-waiting__card">
			<div class="coop-waiting__status">
				<span class="coop-waiting__pulse" aria-hidden="true"></span>
				<div>
					<p class="coop-waiting__eyebrow">{{ t('learning', 'Synchronisierte Herausforderung') }}</p>
					<h3 class="coop-waiting__title">{{ waitingText }}</h3>
				</div>
			</div>

			<p class="coop-waiting__copy">
				{{ t('learning', 'Die Szene bleibt für alle synchron. Fortschritt und Ergebnis werden nach dem nächsten Poll übernommen.') }}
			</p>

			<ul v-if="normalizedProgress.length" class="coop-waiting__progress">
				<li
					v-for="entry in normalizedProgress"
					:key="entry.user_id"
					class="coop-waiting__progress-item">
					<div>
						<div class="coop-waiting__progress-name">{{ entry.display_name || entry.user_id }}</div>
						<div class="coop-waiting__progress-meta">{{ progressMeta(entry) }}</div>
					</div>
					<span
						class="coop-waiting__progress-state"
						:class="{ 'coop-waiting__progress-state--done': entry.completed }">
						{{ entry.completed ? t('learning', 'Fertig') : t('learning', 'Aktiv') }}
					</span>
				</li>
			</ul>
		</div>
	</section>
</template>

<script>
const SIMULATOR_LABELS = {
	firewall: 'Firewall-Challenge',
	dns: 'DNS-Challenge',
	routing: 'Routing-Challenge',
	portscanner: 'Portscanner-Challenge',
	wireshark: 'Wireshark-Challenge',
	nat: 'NAT-Challenge',
	authflow: 'Authentifizierungs-Challenge',
}

export default {
	name: 'CoopWaiting',

	props: {
		activePlayer: {
			type: Object,
			default: function() {
				return null
			},
		},
		simulatorType: {
			type: String,
			default: '',
		},
		progress: {
			type: Array,
			default: function() {
				return []
			},
		},
	},

	computed: {
		normalizedProgress() {
			return Array.isArray(this.progress) ? this.progress : []
		},

		waitingText() {
			const playerName = this.activePlayer?.display_name
				|| this.activePlayer?.user_id
				|| t('learning', 'Ein Teammitglied')
			const simulatorLabel = SIMULATOR_LABELS[this.simulatorType] || this.simulatorType || t('learning', 'Challenge')
			return t('learning', '{player} löst gerade {challenge} ...', {
				player: playerName,
				challenge: simulatorLabel,
			})
		},
	},

	methods: {
		progressMeta(entry) {
			if (entry.completed && entry.passed === true) {
				return t('learning', 'Bestanden')
			}
			if (entry.completed && entry.passed === false) {
				return t('learning', 'Abgeschlossen')
			}
			if (typeof entry.score === 'number') {
				return t('learning', 'Zwischenstand: {n}', { n: entry.score })
			}
			return t('learning', 'Fortschritt wird übertragen')
		},
	},
}
</script>
