<template>
  <div class="onb-screen onb-jumpstart">
    <div class="onb-jumpstart__content">
      <h2 class="onb-jumpstart__title">{{ t('learning', 'Schnellstart') }}</h2>

      <template v-if="loading">
        <p class="onb-jumpstart__status">{{ t('learning', 'Lade Starter-Pools...') }}</p>
      </template>

      <template v-else-if="pools.length === 0">
        <p class="onb-jumpstart__status">{{ t('learning', 'Kein Starter-Pool verfuegbar') }}</p>
      </template>

      <template v-else>
        <p class="onb-jumpstart__subtitle">{{ t('learning', 'Waehle einen Pool zum Starten:') }}</p>
        <div class="onb-jumpstart__grid">
          <button
            v-for="pool in pools"
            :key="pool.id"
            type="button"
            :class="['onb-pool-card', { 'onb-pool-card--selected': store.selectedStarterPoolId === pool.id }]"
            @click="selectPool(pool)"
          >
            <span class="onb-pool-card__title">{{ pool.title }}</span>
            <span class="onb-pool-card__count">
              {{ t('learning', '{count} Karten', { count: pool.question_count || 0 }) }}
            </span>
          </button>
        </div>
      </template>

      <div class="onb-jumpstart__actions">
        <button
          v-if="pools.length > 0"
          type="button"
          class="onb-btn onb-btn--primary"
          :disabled="!store.selectedStarterPoolId"
          @click="$emit('next')"
        >
          {{ t('learning', 'Weiter') }}
        </button>
        <button
          type="button"
          class="onb-btn onb-btn--ghost"
          @click="$emit('next')"
        >
          {{ t('learning', 'Ueberspringen') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useOnboardingStore } from '../../stores/onboardingStore.js'

export default {
  name: 'OnbJumpstart',
  emits: ['next'],

  setup() {
    const store = useOnboardingStore()
    return { store }
  },

  data() {
    return {
      pools: [],
      loading: true,
      autoTimer: null,
    }
  },

  async mounted() {
    try {
      const res = await axios.get(generateUrl('/apps/learning/api/starter-pools'))
      this.pools = Array.isArray(res.data) ? res.data : (res.data?.pools || [])
    } catch {
      this.pools = []
    }
    this.loading = false

    if (this.pools.length === 0) {
      this.autoTimer = setTimeout(() => {
        this.$emit('next')
      }, 2000)
    }
  },

  beforeUnmount() {
    if (this.autoTimer) clearTimeout(this.autoTimer)
  },

  methods: {
    t,
    selectPool(pool) {
      this.store.setStarterPool(pool.id)
    },
  },
}
</script>

<style scoped>
.onb-jumpstart {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100%;
  padding: 24px;
}

.onb-jumpstart__content {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  max-width: 540px;
  width: 100%;
}

.onb-jumpstart__title {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--lnc-text-primary, #e8ecf4);
  margin: 0;
}

.onb-jumpstart__subtitle {
  font-size: 0.95rem;
  color: var(--lnc-text-secondary, #8b95a8);
  margin: 0;
}

.onb-jumpstart__status {
  font-size: 1rem;
  color: var(--lnc-text-secondary, #8b95a8);
  margin: 0;
}

.onb-jumpstart__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 0.75rem;
  width: 100%;
}

.onb-pool-card {
  appearance: none;
  border: 2px solid rgba(255, 255, 255, 0.1);
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.04);
  padding: 1.25rem 1rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
  transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
  color: var(--lnc-text-primary, #e8ecf4);
  font: inherit;
}

.onb-pool-card:hover,
.onb-pool-card:focus-visible {
  border-color: rgba(6, 182, 212, 0.4);
  background: rgba(6, 182, 212, 0.06);
}

.onb-pool-card--selected {
  border-color: var(--lnc-cyan, #06b6d4);
  background: rgba(6, 182, 212, 0.1);
  box-shadow: 0 0 12px rgba(6, 182, 212, 0.15);
}

.onb-pool-card__title {
  font-size: 1rem;
  font-weight: 600;
}

.onb-pool-card__count {
  font-size: 0.85rem;
  color: var(--lnc-text-secondary, #8b95a8);
}

.onb-jumpstart__actions {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  margin-block-start: 0.5rem;
}

@media (prefers-reduced-motion: reduce) {
  .onb-pool-card {
    transition-duration: 0.05s;
  }
}
</style>
