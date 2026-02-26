<template>
  <div>
    <div aria-live="polite" class="sr-only">
      <span v-if="visible">{{ t('learning', 'Level Up! Level {n}', { n: level }) }}</span>
    </div>
    <transition name="levelup-pop">
      <div v-if="visible" ref="overlay" class="levelup-overlay" role="dialog" aria-modal="true" tabindex="-1" :aria-label="t('learning', 'Level Up!')" @click.self="dismiss" @keydown.escape="dismiss">
        <div class="levelup-card">
          <div class="levelup-glow"></div>
          <div class="levelup-icon">&#x2B50;</div>
          <div class="levelup-title">{{ t('learning', 'Level Up!') }}</div>
          <div class="levelup-level">{{ t('learning', 'Level {n}', { n: level }) }}</div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script>
export default {
  name: 'LevelUpOverlay',
  props: {
    levelBefore: { type: Number, default: 0 },
    levelAfter: { type: Number, default: 0 },
  },
  data() {
    return {
      visible: false,
      level: 0,
      timer: null,
    };
  },
  watch: {
    levelAfter: {
      handler() {
        if (this.levelAfter > this.levelBefore && this.levelBefore > 0) {
          this.level = this.levelAfter;
          this.visible = true;
          this.$nextTick(() => this.$refs.overlay?.focus());
          this.timer = setTimeout(() => this.dismiss(), 2500);
        }
      },
      immediate: false,
    },
  },
  methods: {
    dismiss() {
      if (this.timer) {
        clearTimeout(this.timer);
        this.timer = null;
      }
      this.visible = false;
    },
  },
  beforeDestroy() {
    if (this.timer) clearTimeout(this.timer);
  },
};
</script>

<style scoped>
.levelup-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 10001;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.45);
  cursor: pointer;
}

.levelup-card {
  position: relative;
  background: var(--color-main-background);
  border-radius: 24px;
  padding: 48px 56px;
  text-align: center;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
  max-width: 320px;
  overflow: hidden;
}

.levelup-glow {
  position: absolute;
  top: -50%;
  left: -50%;
  width: 200%;
  height: 200%;
  background: radial-gradient(circle, rgba(255, 215, 0, 0.2) 0%, transparent 70%);
  animation: lvlGlowPulse 1.5s ease-in-out infinite;
  pointer-events: none;
}

@keyframes lvlGlowPulse {
  0%, 100% { opacity: 0.4; transform: scale(0.9); }
  50% { opacity: 1; transform: scale(1.15); }
}

.levelup-icon {
  font-size: 72px;
  margin-bottom: 8px;
  animation: lvlStarPop 0.6s ease-out;
}

@keyframes lvlStarPop {
  0% { transform: scale(0) rotate(-30deg); opacity: 0; }
  60% { transform: scale(1.2) rotate(5deg); }
  100% { transform: scale(1) rotate(0deg); opacity: 1; }
}

.levelup-title {
  font-size: 14px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 2px;
  color: var(--color-warning);
  margin-bottom: 6px;
}

.levelup-level {
  font-size: 36px;
  font-weight: 800;
  color: var(--color-primary-element);
  animation: lvlNumberZoom 0.5s ease-out 0.2s both;
}

@keyframes lvlNumberZoom {
  0% { transform: scale(0); opacity: 0; }
  60% { transform: scale(1.2); }
  100% { transform: scale(1); opacity: 1; }
}

.levelup-pop-enter-active {
  animation: lvlIn 0.4s ease-out;
}
.levelup-pop-leave-active {
  animation: lvlOut 0.3s ease-in;
}

@keyframes lvlIn {
  0% { opacity: 0; transform: scale(0.6); }
  100% { opacity: 1; transform: scale(1); }
}
@keyframes lvlOut {
  0% { opacity: 1; transform: scale(1); }
  100% { opacity: 0; transform: scale(0.6); }
}

@media (prefers-reduced-motion: reduce) {
  .levelup-glow { animation: none; }
  .levelup-icon { animation: none; }
  .levelup-level { animation: none; opacity: 1; }
  .levelup-pop-enter-active { animation: none; opacity: 1; }
  .levelup-pop-leave-active { animation: none; opacity: 0; }
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
  .levelup-card { padding: 36px 32px; max-width: 260px; }
  .levelup-icon { font-size: 56px; }
  .levelup-level { font-size: 28px; }
}
</style>
