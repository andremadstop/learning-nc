<template>
	<section class="coop-lobby" :class="{ 'coop-lobby--active': hasSession }">
		<div class="coop-lobby__card">
			<div class="coop-lobby__header">
				<div>
					<p class="coop-lobby__eyebrow">{{ t('learning', 'Coop-Session') }}</p>
					<h3 class="coop-lobby__title">
						{{ hasSession ? t('learning', 'Lobby aktiv') : t('learning', 'Gemeinsam spielen') }}
					</h3>
				</div>
				<span v-if="statusLabel" class="coop-lobby__status">{{ statusLabel }}</span>
			</div>

			<p v-if="error" class="coop-lobby__error">{{ error }}</p>

			<div v-if="!hasSession" class="coop-lobby__setup">
				<p class="coop-lobby__copy">
					{{ t('learning', 'Starte eine Coop-Session oder tritt mit einem 8-stelligen Code bei.') }}
				</p>

				<div class="coop-lobby__setup-actions">
					<button
						class="coop-btn coop-btn--primary"
						:disabled="busy"
						@click="$emit('start')">
						{{ t('learning', 'Coop starten') }}
					</button>
				</div>

				<div class="coop-lobby__join">
					<label class="coop-lobby__field-label" for="coop-join-code">
						{{ t('learning', 'Code eingeben') }}
					</label>
					<div class="coop-lobby__join-row">
						<input
							id="coop-join-code"
							v-model.trim="localJoinCode"
							class="coop-lobby__input"
							type="text"
							maxlength="8"
							autocomplete="off"
							spellcheck="false"
							:placeholder="t('learning', 'z.B. ABCD1234')"
							:disabled="busy"
							@input="emitJoinCode"
							@keydown.enter.prevent="emitJoin" />
						<button
							class="coop-btn coop-btn--secondary"
							:disabled="busy || localJoinCode.length < 4"
							@click="emitJoin">
							{{ t('learning', 'Beitreten') }}
						</button>
					</div>
				</div>
			</div>

			<template v-else>
				<div class="coop-lobby__code-block">
					<div>
						<p class="coop-lobby__field-label">{{ t('learning', 'Session-Code') }}</p>
						<div class="coop-lobby__code">{{ sessionCode }}</div>
					</div>
					<button
						class="coop-btn coop-btn--secondary"
						:disabled="busy"
						@click="$emit('copy')">
						{{ t('learning', 'Code kopieren') }}
					</button>
				</div>

				<div class="coop-lobby__players">
					<div class="coop-lobby__players-head">
						<h4>{{ t('learning', 'Teilnehmer') }}</h4>
						<span>{{ players.length }}</span>
					</div>
					<ul class="coop-lobby__player-list">
						<li
							v-for="player in players"
							:key="player.user_id"
							class="coop-lobby__player"
							:class="{
								'coop-lobby__player--ready': player.is_ready,
								'coop-lobby__player--offline': player.is_online === false,
							}">
							<div class="coop-lobby__player-meta">
								<span class="coop-lobby__player-avatar">{{ playerAvatar(player.character_id) }}</span>
								<div>
									<div class="coop-lobby__player-name">
										{{ player.display_name || player.user_id }}
										<span v-if="player.is_host" class="coop-lobby__host">{{ t('learning', 'Host') }}</span>
									</div>
									<div class="coop-lobby__player-role">{{ characterLabel(player.character_id) }}</div>
								</div>
							</div>
							<span class="coop-lobby__player-state">
								{{ player.is_ready ? t('learning', 'Bereit') : t('learning', 'Wartet') }}
							</span>
						</li>
					</ul>
				</div>

				<div class="coop-lobby__actions">
					<button
						class="coop-btn coop-btn--primary"
						:disabled="busy"
						@click="$emit('ready')">
						{{ isReady ? t('learning', 'Nicht bereit') : t('learning', 'Bereit') }}
					</button>
					<button
						v-if="isHost && canStart"
						class="coop-btn coop-btn--secondary"
						:disabled="busy"
						@click="$emit('start')">
						{{ t('learning', 'Spiel startet') }}
					</button>
					<button
						class="coop-btn coop-btn--ghost"
						:disabled="busy"
						@click="$emit('leave')">
						{{ isHost ? t('learning', 'Session beenden') : t('learning', 'Lobby verlassen') }}
					</button>
				</div>
			</template>
		</div>
	</section>
</template>

<script>
const CHARACTER_META = {
	architect: { icon: '🧭', label: 'Netzwerk-Architekt' },
	security: { icon: '🛡️', label: 'Security-Analystin' },
	sysadmin: { icon: '🖥️', label: 'Sysadmin' },
	helpdesk: { icon: '☎️', label: 'Helpdesk' },
}

export default {
	name: 'CoopLobby',

	props: {
		sessionCode: {
			type: String,
			default: '',
		},
		players: {
			type: Array,
			default: function() {
				return []
			},
		},
		isHost: {
			type: Boolean,
			default: false,
		},
		isReady: {
			type: Boolean,
			default: false,
		},
		canStart: {
			type: Boolean,
			default: false,
		},
		joinCode: {
			type: String,
			default: '',
		},
		busy: {
			type: Boolean,
			default: false,
		},
		error: {
			type: String,
			default: '',
		},
		status: {
			type: String,
			default: 'setup',
		},
	},

	emits: ['ready', 'start', 'leave', 'join', 'copy', 'update:joinCode'],

	data() {
		return {
			localJoinCode: this.joinCode || '',
		}
	},

	computed: {
		hasSession() {
			return Boolean(this.sessionCode)
		},

		statusLabel() {
			if (!this.hasSession) {
				return ''
			}

			const labels = {
				lobby: t('learning', 'Lobby'),
				playing: t('learning', 'Läuft'),
				finished: t('learning', 'Beendet'),
			}

			return labels[this.status] || this.status
		},
	},

	watch: {
		joinCode(nextValue) {
			this.localJoinCode = nextValue || ''
		},
	},

	methods: {
		characterLabel(characterId) {
			return CHARACTER_META[characterId]?.label || characterId || t('learning', 'Unbekannt')
		},

		playerAvatar(characterId) {
			return CHARACTER_META[characterId]?.icon || '👤'
		},

		emitJoinCode() {
			this.$emit('update:joinCode', this.localJoinCode.toUpperCase())
		},

		emitJoin() {
			const code = this.localJoinCode.trim().toUpperCase()
			if (!code) {
				return
			}
			this.localJoinCode = code
			this.$emit('update:joinCode', code)
			this.$emit('join', code)
		},
	},
}
</script>
