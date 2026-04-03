<template>
  <div v-if="dailyChallenge && dailyChallenge.available" class="daily-challenge-card" :class="{ completed: dailyChallenge.completed }">
    <div class="dc-header">
      <span class="dc-icon">&#x2B50;</span>
      <span class="dc-title">{{ t('learning', 'Daily Challenge') }}</span>
      <span v-if="!dailyChallenge.completed" class="dc-xp">+{{ dailyChallenge.xp_reward }} XP</span>
      <span v-else class="dc-done-badge">{{ dailyChallenge.was_correct ? t('learning', 'Correct!') : t('learning', 'Tried!') }}</span>
    </div>
    <div v-if="dailyChallenge.completed" class="dc-next-time">
      {{ t('learning', 'Next challenge in {time}', { time: formatCountdown(challengeCountdownSec) }) }}
    </div>
    <div class="dc-pool-tag">{{ dailyChallenge.pool_name }}</div>
    <div class="dc-question">{{ dailyChallenge.question.text }}</div>
    <div v-if="!dailyChallenge.completed && dailyChallenge.question.question_type === 'open'" class="dc-answers">
      <textarea v-model="challengeOpenAnswer" :placeholder="t('learning', 'Type your answer...')" rows="2" class="nc-input dc-open-textarea" :disabled="challengeSubmitting"></textarea>
      <div class="dc-submit-area">
        <NcButton type="primary" :disabled="challengeSubmitting || !challengeOpenAnswer.trim()" @click="submitChallenge">
          {{ challengeSubmitting ? t('learning', 'Submitting...') : t('learning', 'Submit') }}
        </NcButton>
      </div>
    </div>
    <div v-else-if="!dailyChallenge.completed" class="dc-answers">
      <button v-for="answer in dailyChallenge.question.answers" :key="answer.id"
        class="dc-answer-btn" :class="{ selected: challengeSelectedIds.includes(answer.id) }"
        :disabled="challengeSubmitting"
        @click="toggleChallengeAnswer(answer.id)">
        {{ answer.text }}
      </button>
      <div class="dc-submit-area">
        <NcButton type="primary" :disabled="challengeSubmitting || challengeSelectedIds.length === 0" @click="submitChallenge">
          {{ challengeSubmitting ? t('learning', 'Submitting...') : t('learning', 'Submit') }}
        </NcButton>
      </div>
    </div>
    <div v-else class="dc-result">
      <div class="dc-answers-review">
        <div v-for="answer in dailyChallenge.question.answers" :key="'dcr-' + answer.id"
          class="dc-answer-review" :class="{ correct: answer.is_correct }">
          {{ answer.text }}
        </div>
      </div>
      <div v-if="dailyChallenge.question.explanation" class="dc-explanation">
        {{ dailyChallenge.question.explanation }}
      </div>
      <div v-if="challengeXpEarned > 0" class="dc-xp-earned">+{{ challengeXpEarned }} XP</div>
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
  name: 'DailyChallengeCard',

  components: {
    NcButton,
  },

  data() {
    return {
      dailyChallenge: null,
      challengeSelectedIds: [],
      challengeSubmitting: false,
      challengeXpEarned: 0,
      challengeOpenAnswer: '',
      challengeCountdownSec: 0,
      challengeCountdownTimer: null,
      challengeRefreshInFlight: false,
    }
  },

  mounted() {
    this.loadDailyChallenge()
    this.startChallengeCountdown()
  },

  beforeUnmount() {
    this.stopChallengeCountdown()
  },

  methods: {
    async loadDailyChallenge() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/v1/daily-challenge'))
        this.dailyChallenge = r.data
        if (r.data.was_correct && r.data.completed) {
          this.challengeXpEarned = r.data.xp_reward
        }
      } catch (e) { /* optional */ }
    },

    toggleChallengeAnswer(answerId) {
      const idx = this.challengeSelectedIds.indexOf(answerId)
      if (idx >= 0) {
        this.challengeSelectedIds.splice(idx, 1)
      } else {
        this.challengeSelectedIds.push(answerId)
      }
    },

    async submitChallenge() {
      this.challengeSubmitting = true
      try {
        const payload = {}
        if (this.dailyChallenge.question.question_type === 'open') {
          payload.answer_text = this.challengeOpenAnswer
        } else {
          payload.answer_ids = this.challengeSelectedIds
        }
        const r = await axios.post(generateUrl('/apps/learning/api/v1/daily-challenge/answer'), payload)
        this.challengeXpEarned = r.data.xp_earned || 0
        await this.loadDailyChallenge()
        if (r.data.correct) {
          showSuccess(t('learning', 'Daily Challenge completed! +{n} XP', { n: r.data.xp_earned }))
        }
      } catch (e) {
        const msg = e.response?.data?.error || t('learning', 'Failed to submit answer')
        showError(msg)
      } finally {
        this.challengeSubmitting = false
      }
    },

    formatCountdown(seconds) {
      const s = Math.max(0, Number(seconds || 0))
      const h = Math.floor(s / 3600)
      const m = Math.floor((s % 3600) / 60)
      const sec = s % 60
      return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`
    },

    startChallengeCountdown() {
      this.updateChallengeCountdown()
      if (this.challengeCountdownTimer) clearInterval(this.challengeCountdownTimer)
      this.challengeCountdownTimer = setInterval(() => this.updateChallengeCountdown(), 1000)
    },

    stopChallengeCountdown() {
      if (this.challengeCountdownTimer) {
        clearInterval(this.challengeCountdownTimer)
        this.challengeCountdownTimer = null
      }
    },

    updateChallengeCountdown() {
      const previous = this.challengeCountdownSec
      const now = new Date()
      const next = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() + 1, 0, 0, 0))
      this.challengeCountdownSec = Math.max(0, Math.floor((next.getTime() - now.getTime()) / 1000))
      if (
        previous > 0
        && this.challengeCountdownSec === 0
        && this.dailyChallenge
        && this.dailyChallenge.completed
        && !this.challengeRefreshInFlight
      ) {
        this.challengeRefreshInFlight = true
        this.loadDailyChallenge().finally(() => {
          this.challengeSelectedIds = []
          this.challengeOpenAnswer = ''
          this.challengeRefreshInFlight = false
        })
      }
    },
  },
}
</script>

<style scoped>
.daily-challenge-card {
  border: 2px solid var(--color-primary-element);
  border-radius: 12px;
  padding: 16px 20px;
  background: color-mix(in srgb, var(--color-primary-element) 4%, var(--color-main-background));
}
.daily-challenge-card.completed {
  border-color: var(--color-success);
  background: color-mix(in srgb, var(--color-success) 4%, var(--color-main-background));
}
.dc-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}
.dc-icon { font-size: 20px; }
.dc-title { font-size: 16px; font-weight: 700; color: var(--color-main-text); }
.dc-xp {
  margin-left: auto;
  padding: 2px 10px;
  border-radius: 12px;
  font-size: 13px;
  font-weight: 700;
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
}
.dc-done-badge {
  margin-left: auto;
  padding: 2px 10px;
  border-radius: 12px;
  font-size: 13px;
  font-weight: 700;
  background: var(--color-success);
  color: #fff;
}
.dc-next-time {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 8px;
}
.dc-pool-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  background: var(--color-background-hover);
  color: var(--color-text-maxcontrast);
  margin-bottom: 8px;
}
.dc-question {
  font-size: 15px;
  line-height: 1.5;
  font-weight: 500;
  color: var(--color-main-text);
  margin-bottom: 12px;
}
.dc-answers { display: grid; gap: 8px; }
.dc-answer-btn {
  padding: 10px 14px;
  border: 2px solid var(--color-border);
  border-radius: var(--border-radius-large);
  background: var(--color-main-background);
  cursor: pointer;
  text-align: left;
  font-size: 14px;
  transition: all 0.2s;
  color: var(--color-main-text);
}
.dc-answer-btn:hover:not(:disabled) { border-color: var(--color-primary-element); }
.dc-answer-btn.selected {
  border-color: var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background));
  font-weight: 600;
}
.dc-submit-area { text-align: center; margin-top: 8px; }
.dc-answers-review { display: grid; gap: 6px; margin-bottom: 10px; }
.dc-answer-review {
  padding: 8px 12px;
  border-radius: var(--border-radius-large);
  font-size: 13px;
  border: 2px solid var(--color-border);
  color: var(--color-text-maxcontrast);
}
.dc-answer-review.correct {
  border-color: var(--color-success);
  background: color-mix(in srgb, var(--color-success) 10%, var(--color-main-background));
  color: var(--color-success);
  font-weight: 600;
}
.dc-explanation {
  padding: 10px 12px;
  background: color-mix(in srgb, var(--color-warning) 10%, var(--color-main-background));
  border-radius: var(--border-radius);
  font-size: 13px;
  color: var(--color-main-text);
  margin-bottom: 8px;
}
.dc-xp-earned {
  text-align: center;
  font-size: 18px;
  font-weight: 700;
  color: var(--color-success);
}
.dc-open-textarea {
  width: 100%;
  min-height: 60px;
  resize: vertical;
}
.nc-input {
  width: 100%;
  padding: 10px 12px;
  border: 2px solid var(--color-border);
  border-radius: var(--border-radius-large);
  font-size: 14px;
  background: var(--color-main-background);
  color: var(--color-main-text);
  transition: border-color 0.2s;
  box-sizing: border-box;
}
.nc-input:focus { border-color: var(--color-primary-element); outline: none; }
</style>
