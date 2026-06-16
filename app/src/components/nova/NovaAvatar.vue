<template>
  <div class="nova-avatar-wrapper" :style="sizeStyle" @click="$emit('click')">
    <div class="nova-avatar" :class="[stateClass, { 'has-message': hasMessage }]" :style="sizeStyle">
      <NovaBitOrbiters :state="novaState" :color="coreColor" />
      <NovaBaseCore :state="novaState" :color="coreColor" />
      <NovaEyeDisplay :emotion="novaEmotion" :color="coreColor" />
      <NovaAccessorySlot :outfit="outfit" />
    </div>
    <div v-if="inviteCount > 0" class="nova-invite-badge">
      {{ inviteCount > 9 ? '9+' : inviteCount }}
    </div>
  </div>
</template>

<script>
import './nova-design-tokens.css'
import { STATES, mapLegacyAnimation } from './nova-states.js'
import NovaBaseCore from './NovaBaseCore.vue'
import NovaEyeDisplay from './NovaEyeDisplay.vue'
import NovaBitOrbiters from './NovaBitOrbiters.vue'
import NovaAccessorySlot from './NovaAccessorySlot.vue'

export default {
	name: 'NovaAvatar',
	components: { NovaBaseCore, NovaEyeDisplay, NovaBitOrbiters, NovaAccessorySlot },
	props: {
		animation: { type: String, default: 'idle' },
		hasMessage: { type: Boolean, default: false },
		inviteCount: { type: Number, default: 0 },
		emotion: { type: String, default: null },
		outfit: { type: String, default: 'standard' },
		size: { type: Number, default: 64 },
	},
	computed: {
		novaState() {
			return mapLegacyAnimation(this.animation)
		},
		novaEmotion() {
			if (this.emotion) {
				return this.emotion
			}
			const state = STATES[this.novaState]
			return state ? state.defaultEmotion : 'neutral'
		},
		stateClass() {
			return 'nova-avatar--' + this.novaState
		},
		coreColor() {
			return 'var(--nova-accent-cyan)'
		},
		sizeStyle() {
			return { width: this.size + 'px', height: this.size + 'px' }
		},
	},
}
</script>

<style scoped>
.nova-avatar-wrapper {
  position: relative;
  width: 64px;
  height: 64px;
  cursor: pointer;
  flex-shrink: 0;
}

.nova-avatar {
  position: relative;
  width: 64px;
  height: 64px;
  display: flex;
  align-items: center;
  justify-content: center;
  animation: nova-idle-bob var(--nova-idle-duration, 3s) ease-in-out infinite;
}

.nova-avatar--thinking {
  animation-name: nova-idle-bob;
  animation-duration: 1.5s;
}

.nova-avatar--celebrate {
  animation: nova-celebrate-bounce 0.6s ease-out;
}

.nova-invite-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  min-width: 18px;
  height: 18px;
  border-radius: 9px;
  background: var(--nova-warning-rose, #f43f5e);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 4px;
  line-height: 1;
}

@keyframes nova-idle-bob {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}

@keyframes nova-celebrate-bounce {
  0% { transform: scale(1); }
  40% { transform: scale(1.15); }
  100% { transform: scale(1); }
}

@media (prefers-reduced-motion: reduce) {
  .nova-avatar,
  .nova-avatar--thinking,
  .nova-avatar--celebrate {
    animation: none;
  }
}
</style>
