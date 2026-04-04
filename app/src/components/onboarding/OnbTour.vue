<template>
  <div class="onb-screen onb-tour">
    <!-- Progress dots -->
    <div class="onb-tour__progress">
      <button
        v-for="(slide, idx) in slides"
        :key="slide.id"
        type="button"
        :class="['onb-dot', { active: idx <= store.tourSlideIndex, current: idx === store.tourSlideIndex }]"
        :aria-label="(idx + 1) + ' / ' + slides.length"
        @click="goToSlide(idx)"
      />
    </div>

    <!-- Slide content -->
    <transition :name="slideDir" mode="out-in">
      <div :key="currentSlide.id" class="onb-tour__slide">
        <span class="onb-tour__icon">{{ currentSlide.icon }}</span>
        <h2 class="onb-tour__title">{{ t('learning', currentSlide.titleKey) }}</h2>
        <p class="onb-tour__text">{{ t('learning', currentSlide.textKey) }}</p>
      </div>
    </transition>

    <!-- Nav -->
    <div class="onb-tour__nav">
      <button
        v-if="store.tourSlideIndex > 0"
        type="button"
        class="onb-btn onb-btn--ghost"
        @click="prevSlide"
      >
        {{ t('learning', 'Zurueck') }}
      </button>
      <span v-else />
      <button
        type="button"
        class="onb-btn onb-btn--primary"
        @click="isLastSlide ? $emit('next') : nextSlide()"
      >
        {{ isLastSlide ? t('learning', 'Weiter') : t('learning', 'Naechste') }}
      </button>
    </div>
  </div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { useOnboardingStore } from '../../stores/onboardingStore.js'
import { getSlidesForRole } from '../../utils/onboarding-slides.js'

export default {
  name: 'OnbTour',
  emits: ['next'],

  setup() {
    const store = useOnboardingStore()
    return { store }
  },

  data() {
    return {
      slideDir: 'slide-next',
      touchStartX: 0,
      touchStartY: 0,
    }
  },

  computed: {
    slides() {
      return getSlidesForRole(this.store.role || 'student')
    },
    currentSlide() {
      return this.slides[this.store.tourSlideIndex] || this.slides[0]
    },
    isLastSlide() {
      return this.store.tourSlideIndex >= this.slides.length - 1
    },
  },

  mounted() {
    this._handleKeydown = (e) => {
      if (e.key === 'ArrowRight') {
        e.preventDefault()
        this.isLastSlide ? this.$emit('next') : this.nextSlide()
      } else if (e.key === 'ArrowLeft') {
        e.preventDefault()
        this.prevSlide()
      }
    }
    this._handleTouchStart = (e) => {
      this.touchStartX = e.touches[0].clientX
      this.touchStartY = e.touches[0].clientY
    }
    this._handleTouchEnd = (e) => {
      const dx = e.changedTouches[0].clientX - this.touchStartX
      const dy = e.changedTouches[0].clientY - this.touchStartY
      if (Math.abs(dx) < 50 || Math.abs(dy) > Math.abs(dx)) return
      if (dx < 0) {
        this.isLastSlide ? this.$emit('next') : this.nextSlide()
      } else {
        this.prevSlide()
      }
    }
    document.addEventListener('keydown', this._handleKeydown)
    document.addEventListener('touchstart', this._handleTouchStart, { passive: true })
    document.addEventListener('touchend', this._handleTouchEnd)
  },

  beforeUnmount() {
    document.removeEventListener('keydown', this._handleKeydown)
    document.removeEventListener('touchstart', this._handleTouchStart)
    document.removeEventListener('touchend', this._handleTouchEnd)
  },

  methods: {
    t,
    nextSlide() {
      if (this.store.tourSlideIndex < this.slides.length - 1) {
        this.slideDir = 'slide-next'
        this.store.setTourSlide(this.store.tourSlideIndex + 1)
      }
    },
    prevSlide() {
      if (this.store.tourSlideIndex > 0) {
        this.slideDir = 'slide-prev'
        this.store.setTourSlide(this.store.tourSlideIndex - 1)
      }
    },
    goToSlide(idx) {
      this.slideDir = idx > this.store.tourSlideIndex ? 'slide-next' : 'slide-prev'
      this.store.setTourSlide(idx)
    },
  },
}
</script>

<style scoped>
.onb-tour {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100%;
  padding: 24px;
  text-align: center;
}

.onb-tour__progress {
  display: flex;
  gap: 6px;
  justify-content: center;
  margin-block-end: 28px;
}

.onb-dot {
  appearance: none;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  border: none;
  padding: 0;
  background: rgba(255, 255, 255, 0.15);
  cursor: pointer;
  transition: background 0.2s, transform 0.2s;
}

.onb-dot.active {
  background: var(--lnc-cyan, #06b6d4);
}

.onb-dot.current {
  transform: scale(1.4);
  background: var(--lnc-cyan, #06b6d4);
}

.onb-tour__slide {
  min-height: 200px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  max-width: 460px;
}

.onb-tour__icon {
  font-size: 48px;
  margin-block-end: 16px;
  display: block;
}

.onb-tour__title {
  font-size: 1.35rem;
  font-weight: 700;
  color: var(--lnc-text-primary, #e8ecf4);
  margin: 0 0 12px;
}

.onb-tour__text {
  font-size: 0.95rem;
  line-height: 1.65;
  color: var(--lnc-text-secondary, #8b95a8);
  margin: 0;
  max-width: 420px;
  white-space: pre-line;
}

.onb-tour__nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-block-start: 32px;
  gap: 12px;
  width: 100%;
  max-width: 460px;
}

/* Slide direction transitions */
.slide-next-enter-active,
.slide-next-leave-active,
.slide-prev-enter-active,
.slide-prev-leave-active {
  transition: opacity 0.3s ease, transform 0.3s ease;
}

.slide-next-enter-from  { opacity: 0; transform: translateX(40px); }
.slide-next-leave-to    { opacity: 0; transform: translateX(-40px); }
.slide-prev-enter-from  { opacity: 0; transform: translateX(-40px); }
.slide-prev-leave-to    { opacity: 0; transform: translateX(40px); }

[dir="rtl"] .slide-next-enter-from  { transform: translateX(-40px); }
[dir="rtl"] .slide-next-leave-to    { transform: translateX(40px); }
[dir="rtl"] .slide-prev-enter-from  { transform: translateX(40px); }
[dir="rtl"] .slide-prev-leave-to    { transform: translateX(-40px); }

@media (prefers-reduced-motion: reduce) {
  .slide-next-enter-active,
  .slide-next-leave-active,
  .slide-prev-enter-active,
  .slide-prev-leave-active {
    transition-duration: 0.05s;
  }

  .slide-next-enter-from,
  .slide-next-leave-to,
  .slide-prev-enter-from,
  .slide-prev-leave-to {
    transform: none;
  }
}

@media (max-width: 480px) {
  .onb-tour__icon { font-size: 40px; }
  .onb-tour__title { font-size: 1.15rem; }
  .onb-tour__slide { min-height: 170px; }
}
</style>
