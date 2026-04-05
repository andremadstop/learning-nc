<template>
  <div class="onb-screen onb-role-select">
    <h2 class="onb-role-select__title">{{ t('learning', 'Wer bist du?') }}</h2>
    <div
      class="onb-role-select__grid"
      role="radiogroup"
      :aria-label="t('learning', 'Rolle wählen')"
      @keydown="handleKeydown"
    >
      <button
        v-for="(card, idx) in roles"
        :key="card.id"
        :ref="'role-' + idx"
        type="button"
        role="radio"
        :aria-checked="focusedIndex === idx ? 'true' : 'false'"
        :tabindex="focusedIndex === idx ? 0 : -1"
        :class="['onb-role-card', { 'onb-role-card--focused': focusedIndex === idx }]"
        @click="selectRole(card.id)"
        @focus="focusedIndex = idx"
      >
        <span class="onb-role-card__icon">{{ card.icon }}</span>
        <span class="onb-role-card__name">{{ card.name }}</span>
        <span class="onb-role-card__desc">{{ card.desc }}</span>
      </button>
    </div>
  </div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { useOnboardingStore } from '../../stores/onboardingStore.js'

export default {
  name: 'OnbRoleSelect',
  emits: ['next'],

  setup() {
    const store = useOnboardingStore()
    return { store }
  },

  data() {
    return {
      focusedIndex: 0,
    }
  },

  computed: {
    roles() {
      return [
        {
          id: 'student',
          icon: '\uD83C\uDF93',
          name: t('learning', 'Student'),
          desc: t('learning', 'Ich lerne'),
        },
        {
          id: 'dozent',
          icon: '\uD83D\uDCDD',
          name: t('learning', 'Dozent'),
          desc: t('learning', 'Ich unterrichte'),
        },
        {
          id: 'admin',
          icon: '\u2699\uFE0F',
          name: t('learning', 'Admin'),
          desc: t('learning', 'Ich verwalte'),
        },
      ]
    },
  },

  methods: {
    t,
    selectRole(roleId) {
      this.store.setRole(roleId)
      this.$emit('next')
    },
    handleKeydown(e) {
      const len = this.roles.length
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
        e.preventDefault()
        this.focusedIndex = (this.focusedIndex + 1) % len
        this.focusCard()
      } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
        e.preventDefault()
        this.focusedIndex = (this.focusedIndex - 1 + len) % len
        this.focusCard()
      } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault()
        this.selectRole(this.roles[this.focusedIndex].id)
      }
    },
    focusCard() {
      const ref = this.$refs['role-' + this.focusedIndex]
      const el = Array.isArray(ref) ? ref[0] : ref
      if (el && el.focus) el.focus()
    },
  },
}
</script>

<style scoped>
.onb-role-select {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100%;
  padding: 24px;
}

.onb-role-select__title {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--lnc-text-primary, #e8ecf4);
  margin: 0 0 2rem;
}

.onb-role-select__grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
  max-width: 640px;
  width: 100%;
}

.onb-role-card {
  appearance: none;
  border: 2px solid rgba(255, 255, 255, 0.1);
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.04);
  padding: 2rem 1rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  cursor: pointer;
  transition: border-color 0.2s, background 0.2s, transform 0.15s;
  color: var(--lnc-text-primary, #e8ecf4);
  font: inherit;
}

.onb-role-card:hover,
.onb-role-card:focus-visible {
  border-color: var(--lnc-cyan, #06b6d4);
  background: rgba(6, 182, 212, 0.08);
}

.onb-role-card--focused {
  border-color: var(--lnc-cyan, #06b6d4);
  box-shadow: 0 0 16px rgba(6, 182, 212, 0.2);
}

.onb-role-card:active {
  transform: scale(0.97);
}

.onb-role-card__icon {
  font-size: 2.5rem;
}

.onb-role-card__name {
  font-size: 1.1rem;
  font-weight: 600;
}

.onb-role-card__desc {
  font-size: 0.85rem;
  color: var(--lnc-text-secondary, #8b95a8);
}

@media (max-width: 480px) {
  .onb-role-select__grid {
    grid-template-columns: 1fr;
    max-width: 320px;
  }

  .onb-role-card {
    flex-direction: row;
    padding: 1rem 1.25rem;
    gap: 1rem;
    text-align: start;
  }

  .onb-role-card__icon {
    font-size: 2rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .onb-role-card {
    transition-duration: 0.05s;
  }
}
</style>
