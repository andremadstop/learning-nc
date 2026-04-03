<template>
  <div>
    <div aria-live="polite" class="sr-only">
      <span v-if="visible">{{ t('learning', 'Achievement Unlocked!') }}: {{ badgeLabel(badge) }}</span>
    </div>
    <transition name="badge-pop">
      <div v-if="visible" ref="overlay" class="badge-overlay" role="dialog" aria-modal="true" tabindex="-1" :aria-label="badgeLabel(badge)" @click.self="dismiss" @keydown.escape="dismiss">
        <div class="badge-card">
          <div class="badge-glow"></div>
          <div class="badge-emoji">{{ badge.emoji }}</div>
          <div class="badge-title">{{ t('learning', 'Achievement Unlocked!') }}</div>
          <div class="badge-name">{{ badgeLabel(badge) }}</div>
          <div class="badge-description">{{ badgeDescription(badge) }}</div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script>
import { celebrateBadge } from '../confetti.js'

export default {
  name: 'BadgeUnlock',
  props: {
    badges: {
      type: Array,
      default: () => [],
    },
  },
  data() {
    return {
      visible: false,
      badge: { emoji: '', name: '', description: '' },
      queue: [],
      timer: null,
    }
  },
  watch: {
    badges: {
      handler(newBadges) {
        if (newBadges && newBadges.length > 0) {
          this.queue.push(...newBadges)
          if (!this.visible) {
            this.showNext()
          }
        }
      },
      immediate: true,
    },
  },
  methods: {
    badgeLabel(badge) {
      const key = badge?.name_key || badge?.name || ''
      return key ? t('learning', key) : ''
    },
    badgeDescription(badge) {
      const key = badge?.description_key || badge?.description || ''
      return key ? t('learning', key) : ''
    },
    showNext() {
      if (this.queue.length === 0) {
        this.visible = false
        return
      }
      this.badge = this.queue.shift()
      this.visible = true
      this.$nextTick(() => this.$refs.overlay?.focus())
      celebrateBadge()
      this.timer = setTimeout(() => this.dismiss(), 4000)
    },
    dismiss() {
      if (this.timer) {
        clearTimeout(this.timer)
        this.timer = null
      }
      this.visible = false
      setTimeout(() => this.showNext(), 300)
    },
  },
  beforeUnmount() {
    if (this.timer) clearTimeout(this.timer)
  },
}
</script>

<style scoped>
.badge-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 10000;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.4);
  cursor: pointer;
}

.badge-card {
  position: relative;
  background: var(--color-main-background);
  border-radius: 20px;
  padding: 40px 48px;
  text-align: center;
  box-shadow: 0 16px 48px rgba(0, 0, 0, 0.3);
  max-width: 340px;
  overflow: hidden;
}

.badge-glow {
  position: absolute;
  top: -50%;
  left: -50%;
  width: 200%;
  height: 200%;
  background: radial-gradient(circle, rgba(255, 215, 0, 0.15) 0%, transparent 70%);
  animation: glowPulse 2s ease-in-out infinite;
  pointer-events: none;
}

@keyframes glowPulse {
  0%, 100% { opacity: 0.5; transform: scale(0.9); }
  50% { opacity: 1; transform: scale(1.1); }
}

.badge-emoji {
  font-size: 64px;
  margin-bottom: 12px;
  animation: emojiPop 0.5s ease-out;
}

@keyframes emojiPop {
  0% { transform: scale(0); opacity: 0; }
  50% { transform: scale(1.3); }
  100% { transform: scale(1); opacity: 1; }
}

.badge-title {
  font-size: 13px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: var(--color-primary-element);
  margin-bottom: 8px;
}

.badge-name {
  font-size: 22px;
  font-weight: 700;
  color: var(--color-main-text);
  margin-bottom: 8px;
}

.badge-description {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
  line-height: 1.5;
}

.badge-pop-enter-active {
  animation: badgeIn 0.4s ease-out;
}
.badge-pop-leave-active {
  animation: badgeOut 0.3s ease-in;
}

@keyframes badgeIn {
  0% { opacity: 0; transform: scale(0.7); }
  100% { opacity: 1; transform: scale(1); }
}
@keyframes badgeOut {
  0% { opacity: 1; transform: scale(1); }
  100% { opacity: 0; transform: scale(0.7); }
}

@media (prefers-reduced-motion: reduce) {
  .badge-glow { animation: none; }
  .badge-emoji { animation: none; }
  .badge-pop-enter-active { animation: none; opacity: 1; }
  .badge-pop-leave-active { animation: none; opacity: 0; }
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

@media (max-width: 480px) {
  .badge-card { padding: 28px 24px; max-width: 280px; }
  .badge-emoji { font-size: 48px; }
  .badge-name { font-size: 18px; }
}
</style>
