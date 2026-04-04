<template>
  <div class="onb-screen onb-hook">
    <div class="onb-hook__content">
      <template v-if="!solved">
        <h2 class="onb-hook__title">{{ t('learning', 'Deine erste Karte') }}</h2>
        <p class="onb-hook__question">{{ question }}</p>
        <div class="onb-hook__answers">
          <button
            v-for="opt in options"
            :key="opt"
            type="button"
            :class="['onb-hook__answer', {
              'onb-hook__answer--wrong': wrongAnswer === opt,
            }]"
            :disabled="solved"
            @click="checkAnswer(opt)"
          >
            {{ opt }}
          </button>
        </div>
      </template>

      <template v-else>
        <div class="onb-hook__badge" :class="{ 'onb-hook__badge--visible': badgeVisible }">
          <span class="onb-hook__badge-icon" aria-hidden="true">\u2B50</span>
          <h2 class="onb-hook__badge-text">{{ t('learning', 'Erste Karte gemeistert!') }}</h2>
        </div>
      </template>
    </div>
  </div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { celebrateBadge } from '../../confetti.js'

export default {
  name: 'OnbHook',
  emits: ['next'],

  data() {
    return {
      solved: false,
      badgeVisible: false,
      wrongAnswer: null,
      shakeTimer: null,
      doneTimer: null,
    }
  },

  computed: {
    lang() {
      const docLang = (typeof document !== 'undefined' && document.documentElement.lang) || 'de'
      return docLang.slice(0, 2).toLowerCase()
    },
    question() {
      if (this.lang === 'en') return 'How many bits in a byte?'
      if (this.lang === 'ar') return '\u0643\u0645 \u0628\u062A \u0641\u064A \u0627\u0644\u0628\u0627\u064A\u062A\u061F'
      if (this.lang === 'ru') return '\u0421\u043A\u043E\u043B\u044C\u043A\u043E \u0431\u0438\u0442 \u0432 \u0431\u0430\u0439\u0442\u0435?'
      return 'Wie viele Bits hat ein Byte?'
    },
    options() {
      return [6, 8, 10, 16]
    },
  },

  beforeUnmount() {
    if (this.shakeTimer) clearTimeout(this.shakeTimer)
    if (this.doneTimer) clearTimeout(this.doneTimer)
  },

  methods: {
    t,
    checkAnswer(value) {
      if (value === 8) {
        this.solved = true
        this.wrongAnswer = null
        celebrateBadge()
        requestAnimationFrame(() => {
          this.badgeVisible = true
        })
        this.doneTimer = setTimeout(() => {
          this.$emit('next')
        }, 2000)
      } else {
        this.wrongAnswer = value
        this.shakeTimer = setTimeout(() => {
          this.wrongAnswer = null
        }, 600)
      }
    },
  },
}
</script>

<style scoped>
.onb-hook {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100%;
  padding: 24px;
}

.onb-hook__content {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1.5rem;
  max-width: 420px;
  width: 100%;
}

.onb-hook__title {
  font-size: 1.35rem;
  font-weight: 700;
  color: var(--lnc-text-primary, #e8ecf4);
  margin: 0;
}

.onb-hook__question {
  font-size: 1.5rem;
  font-weight: 600;
  color: var(--lnc-cyan, #06b6d4);
  margin: 0;
}

.onb-hook__answers {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
  width: 100%;
  max-width: 300px;
}

.onb-hook__answer {
  appearance: none;
  border: 2px solid rgba(255, 255, 255, 0.15);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.05);
  padding: 0.75rem;
  font: inherit;
  font-size: 1.25rem;
  font-weight: 600;
  color: var(--lnc-text-primary, #e8ecf4);
  cursor: pointer;
  transition: border-color 0.2s, background 0.2s, transform 0.1s;
}

.onb-hook__answer:hover,
.onb-hook__answer:focus-visible {
  border-color: var(--lnc-cyan, #06b6d4);
  background: rgba(6, 182, 212, 0.08);
}

.onb-hook__answer:active {
  transform: scale(0.96);
}

.onb-hook__answer--wrong {
  animation: onb-shake 0.5s ease;
  border-color: #ef4444;
  background: rgba(239, 68, 68, 0.1);
}

/* Badge celebration */
.onb-hook__badge {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  opacity: 0;
  transform: scale(0.6);
  transition: opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
}

.onb-hook__badge--visible {
  opacity: 1;
  transform: scale(1);
}

.onb-hook__badge-icon {
  font-size: 4rem;
}

.onb-hook__badge-text {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--lnc-text-primary, #e8ecf4);
  margin: 0;
}

@keyframes onb-shake {
  0%, 100% { transform: translateX(0); }
  20% { transform: translateX(-6px); }
  40% { transform: translateX(6px); }
  60% { transform: translateX(-4px); }
  80% { transform: translateX(4px); }
}

@media (prefers-reduced-motion: reduce) {
  .onb-hook__answer--wrong {
    animation: none;
  }

  .onb-hook__badge {
    transition-duration: 0.1s;
  }
}
</style>
