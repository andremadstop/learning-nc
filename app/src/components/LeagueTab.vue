<template>
  <div class="league-tab">
    <DuelMode
      v-if="activeLeagueDuelCode"
      :coursePools="coursePools"
      :presetDuelCode="activeLeagueDuelCode"
      :hideJoinScreen="true"
      @back="closeLeagueDuel" />

    <template v-else>
      <div class="section-header">
        <h4>{{ t('learning', 'Liga') }}</h4>
        <NcButton type="tertiary" :disabled="loading" @click="fetchOverview">
          {{ t('learning', 'Refresh') }}
        </NcButton>
      </div>

      <div v-if="loading" class="loading-container">
        <NcLoadingIcon :size="44" />
        <p>{{ t('learning', 'Loading league data...') }}</p>
      </div>

      <NcNoteCard v-if="error" type="error" class="league-error">
        {{ error }}
      </NcNoteCard>

      <div v-if="!loading && !season && isInstructor" class="league-create">
        <p>{{ t('learning', 'Create a two-week duel league for this course.') }}</p>
        <input v-model.trim="seasonName" class="league-input" :placeholder="t('learning', 'Season name')" />
        <div class="league-grid">
          <div>
            <label class="league-label">{{ t('learning', 'Liga Pool') }}</label>
            <select v-model.number="selectedPoolId" class="league-select">
              <option :value="0" disabled>{{ t('learning', 'Choose pool') }}</option>
              <option v-for="pool in normalizedPools" :key="'league-' + pool.id" :value="pool.id">{{ pool.name }}</option>
            </select>
            <div v-if="selectedLeaguePool" class="pool-context-card">
              <div v-if="selectedLeaguePool.handbook_title" class="context-line">
                <strong>{{ t('learning', 'Handbook') }}:</strong> {{ selectedLeaguePool.handbook_title }}
              </div>
              <div v-if="selectedLeaguePool.chapter_title" class="context-line">
                <strong>{{ t('learning', 'Chapter Focus') }}:</strong> {{ formatChapterLabel(selectedLeaguePool) }}
              </div>
              <div v-if="!selectedLeaguePool.chapter_title" class="context-line muted">
                {{ t('learning', 'No handbook chapter is linked to this pool yet.') }}
              </div>
            </div>
          </div>
          <div>
            <label class="league-label">{{ t('learning', 'CL Pool') }}</label>
            <select v-model.number="selectedClPoolId" class="league-select">
              <option :value="0" disabled>{{ t('learning', 'Choose pool') }}</option>
              <option v-for="pool in normalizedPools" :key="'cl-' + pool.id" :value="pool.id">{{ pool.name }}</option>
            </select>
            <div v-if="selectedClPool" class="pool-context-card">
              <div v-if="selectedClPool.handbook_title" class="context-line">
                <strong>{{ t('learning', 'Handbook') }}:</strong> {{ selectedClPool.handbook_title }}
              </div>
              <div v-if="selectedClPool.chapter_title" class="context-line">
                <strong>{{ t('learning', 'CL Chapter') }}:</strong> {{ formatChapterLabel(selectedClPool) }}
              </div>
              <div v-if="!selectedClPool.chapter_title" class="context-line muted">
                {{ t('learning', 'No handbook chapter is linked to this CL pool yet.') }}
              </div>
            </div>
          </div>
        </div>
        <NcNoteCard type="info">
          {{ t('learning', 'League duels use 10 questions. Champions League uses 15 questions. Minimum 4 participants.') }}
        </NcNoteCard>
        <div class="league-actions">
          <NcButton type="primary" :disabled="saving || !selectedPoolId || !selectedClPoolId" @click="createSeason">
            {{ saving ? t('learning', 'Creating...') : t('learning', 'Create Season') }}
          </NcButton>
        </div>
      </div>

      <NcEmptyContent
        v-else-if="!loading && !season"
        :name="t('learning', 'No league season yet')">
        <template #description>
          {{ t('learning', 'An instructor can create the next season here.') }}
        </template>
      </NcEmptyContent>

      <template v-else-if="season">
        <div class="league-summary">
          <div class="league-summary-main">
            <div class="league-pill" :class="'status-' + season.status">{{ season.status }}</div>
            <h3>{{ season.name }}</h3>
            <p>{{ t('learning', 'Liga Pool: {pool}', { pool: season.pool_name }) }}</p>
            <p v-if="season.chapter_title">{{ t('learning', 'Chapter Focus: {chapter}', { chapter: seasonChapterLabel }) }}</p>
            <p v-if="season.handbook_title">{{ t('learning', 'Handbook: {title}', { title: season.handbook_title }) }}</p>
            <p>{{ t('learning', 'Champions League Pool: {pool}', { pool: season.cl_pool_name }) }}</p>
            <p v-if="season.cl_chapter_title">{{ t('learning', 'CL Chapter: {chapter}', { chapter: clSeasonChapterLabel }) }}</p>
            <p v-if="season.ends_at">{{ t('learning', 'Ends: {date}', { date: formatDateTime(season.ends_at) }) }}</p>
          </div>
          <div v-if="isInstructor" class="league-actions">
            <NcButton
              v-if="season.status === 'open'"
              type="primary"
              :disabled="saving"
              @click="startSeason">
              {{ saving ? t('learning', 'Starting...') : t('learning', 'Start Season') }}
            </NcButton>
            <NcButton
              v-if="season.status === 'active'"
              type="secondary"
              :disabled="saving"
              @click="finishSeason">
              {{ saving ? t('learning', 'Finishing...') : t('learning', 'Finish Season') }}
            </NcButton>
          </div>
        </div>

        <div v-if="isInstructor && canCreateSeason" class="league-create">
          <p>{{ t('learning', 'Create the next season once the current one is fully closed.') }}</p>
          <input v-model.trim="seasonName" class="league-input" :placeholder="t('learning', 'Season name')" />
          <div class="league-grid">
            <div>
              <label class="league-label">{{ t('learning', 'Liga Pool') }}</label>
              <select v-model.number="selectedPoolId" class="league-select">
                <option :value="0" disabled>{{ t('learning', 'Choose pool') }}</option>
                <option v-for="pool in normalizedPools" :key="'next-league-' + pool.id" :value="pool.id">{{ pool.name }}</option>
              </select>
              <div v-if="selectedLeaguePool" class="pool-context-card">
                <div v-if="selectedLeaguePool.handbook_title" class="context-line">
                  <strong>{{ t('learning', 'Handbook') }}:</strong> {{ selectedLeaguePool.handbook_title }}
                </div>
                <div v-if="selectedLeaguePool.chapter_title" class="context-line">
                  <strong>{{ t('learning', 'Chapter Focus') }}:</strong> {{ formatChapterLabel(selectedLeaguePool) }}
                </div>
              </div>
            </div>
            <div>
              <label class="league-label">{{ t('learning', 'CL Pool') }}</label>
              <select v-model.number="selectedClPoolId" class="league-select">
                <option :value="0" disabled>{{ t('learning', 'Choose pool') }}</option>
                <option v-for="pool in normalizedPools" :key="'next-cl-' + pool.id" :value="pool.id">{{ pool.name }}</option>
              </select>
              <div v-if="selectedClPool" class="pool-context-card">
                <div v-if="selectedClPool.handbook_title" class="context-line">
                  <strong>{{ t('learning', 'Handbook') }}:</strong> {{ selectedClPool.handbook_title }}
                </div>
                <div v-if="selectedClPool.chapter_title" class="context-line">
                  <strong>{{ t('learning', 'CL Chapter') }}:</strong> {{ formatChapterLabel(selectedClPool) }}
                </div>
              </div>
            </div>
          </div>
          <div class="league-actions">
            <NcButton type="primary" :disabled="saving || !selectedPoolId || !selectedClPoolId" @click="createSeason">
              {{ saving ? t('learning', 'Creating...') : t('learning', 'Create Next Season') }}
            </NcButton>
          </div>
        </div>

        <div v-if="incomingChallenges.length > 0 || outgoingChallenges.length > 0" class="league-challenges">
          <div v-if="incomingChallenges.length > 0" class="challenge-column">
            <h5>{{ t('learning', 'Incoming Challenges') }}</h5>
            <div v-for="challenge in incomingChallenges" :key="'in-' + challenge.id" class="challenge-card">
              <strong>{{ challenge.title }}</strong>
              <p>{{ challenge.challenger_uid }} {{ t('learning', 'challenged you') }}</p>
              <p>{{ t('learning', 'Deadline: {date}', { date: formatDateTime(challenge.deadline_at) }) }}</p>
              <div class="league-actions">
                <NcButton
                  v-if="challenge.can_accept"
                  type="primary"
                  :disabled="saving"
                  @click="acceptChallenge(challenge.id)">
                  {{ t('learning', 'Accept') }}
                </NcButton>
                <NcButton
                  v-if="challenge.can_open_duel && challenge.duel_code"
                  type="secondary"
                  @click="openLeagueDuel(challenge.duel_code)">
                  {{ t('learning', 'Open Duel') }}
                </NcButton>
              </div>
            </div>
          </div>

          <div v-if="outgoingChallenges.length > 0" class="challenge-column">
            <h5>{{ t('learning', 'Outgoing Challenges') }}</h5>
            <div v-for="challenge in outgoingChallenges" :key="'out-' + challenge.id" class="challenge-card">
              <strong>{{ challenge.title }}</strong>
              <p>{{ t('learning', 'Opponent: {user}', { user: challenge.opponent_uid }) }}</p>
              <p>{{ t('learning', 'Deadline: {date}', { date: formatDateTime(challenge.deadline_at) }) }}</p>
              <div class="league-actions">
                <NcButton
                  v-if="challenge.can_open_duel && challenge.duel_code"
                  type="secondary"
                  @click="openLeagueDuel(challenge.duel_code)">
                  {{ t('learning', 'Open Duel') }}
                </NcButton>
                <span v-else class="challenge-status">{{ challenge.status }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="league-table-wrap">
          <table v-if="standings.length > 0" class="league-table">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ t('learning', 'Player') }}</th>
                <th>{{ t('learning', 'Played') }}</th>
                <th>{{ t('learning', 'Wins') }}</th>
                <th>{{ t('learning', 'Losses') }}</th>
                <th>{{ t('learning', 'Points') }}</th>
                <th>{{ t('learning', 'Diff') }}</th>
                <th>{{ t('learning', 'Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in standings" :key="row.user_id" :class="{ 'my-row': row.user_id === myUserId }">
                <td>{{ row.rank }}</td>
                <td>{{ row.display_name }}</td>
                <td>{{ row.played }}</td>
                <td>{{ row.wins }}</td>
                <td>{{ row.losses }}</td>
                <td>{{ row.points }}</td>
                <td>{{ row.point_difference }}</td>
                <td>
                  <NcButton
                    v-if="row.can_challenge"
                    type="primary"
                    :disabled="saving"
                    @click="challengeOpponent(row.user_id)">
                    {{ t('learning', 'Challenge') }}
                  </NcButton>
                  <NcButton
                    v-else-if="row.pairing_state === 'open_incoming' && row.pairing_challenge_id"
                    type="primary"
                    :disabled="saving"
                    @click="acceptChallenge(row.pairing_challenge_id)">
                    {{ t('learning', 'Accept') }}
                  </NcButton>
                  <NcButton
                    v-else-if="row.pairing_state === 'accepted' && row.duel_code"
                    type="secondary"
                    @click="openLeagueDuel(row.duel_code)">
                    {{ t('learning', 'Open Duel') }}
                  </NcButton>
                  <span v-else class="challenge-status">{{ pairingLabel(row.pairing_state) }}</span>
                </td>
              </tr>
            </tbody>
          </table>

          <NcEmptyContent v-else :name="t('learning', 'No league table yet')">
            <template #description>
              {{ t('learning', 'The table fills once duels are played or forfeits are applied.') }}
            </template>
          </NcEmptyContent>
        </div>

        <div v-if="cl" class="league-cl">
          <h5>{{ t('learning', 'Champions League') }}</h5>
          <NcNoteCard v-if="cl.status === 'unavailable'" type="info">
            {{ cl.message }}
          </NcNoteCard>
          <NcNoteCard v-else-if="cl.status === 'pending'" type="info">
            {{ t('learning', 'The season is finished. The Champions League bracket is ready.') }}
          </NcNoteCard>
          <div v-if="Array.isArray(cl.rounds) && cl.rounds.length > 0" class="cl-rounds">
            <div v-for="round in cl.rounds" :key="'round-' + round.round_number" class="cl-round">
              <h6>{{ round.title }}</h6>
              <div v-for="match in round.matches" :key="'match-' + match.id" class="challenge-card">
                <p>{{ match.challenger_uid }} vs {{ match.opponent_uid }}</p>
                <p>{{ t('learning', 'Status: {status}', { status: match.status }) }}</p>
                <p v-if="match.winner_uid">{{ t('learning', 'Winner: {user}', { user: match.winner_uid }) }}</p>
                <div class="league-actions">
                  <NcButton
                    v-if="match.can_accept"
                    type="primary"
                    :disabled="saving"
                    @click="acceptChallenge(match.id)">
                    {{ t('learning', 'Accept') }}
                  </NcButton>
                  <NcButton
                    v-if="match.can_open_duel && match.duel_code"
                    type="secondary"
                    @click="openLeagueDuel(match.duel_code)">
                    {{ t('learning', 'Open Duel') }}
                  </NcButton>
                </div>
              </div>
            </div>
          </div>
          <p v-if="cl.champion_uid" class="league-champion">
            {{ t('learning', 'Champion: {user}', { user: cl.champion_uid }) }}
          </p>
        </div>
      </template>
    </template>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import DuelMode from './DuelMode.vue'

export default {
  name: 'LeagueTab',
  components: {
    DuelMode,
    NcButton,
    NcEmptyContent,
    NcLoadingIcon,
    NcNoteCard,
  },
  props: {
    courseId: {
      type: Number,
      required: true,
    },
    coursePools: {
      type: Array,
      default: () => [],
    },
    isInstructor: {
      type: Boolean,
      default: false,
    },
  },
  data() {
    return {
      loading: false,
      saving: false,
      error: '',
      overview: null,
      seasonName: '',
      selectedPoolId: 0,
      selectedClPoolId: 0,
      activeLeagueDuelCode: '',
    }
  },
  computed: {
    myUserId() {
      const user = getCurrentUser()
      return user ? user.uid : ''
    },
    normalizedPools() {
      return this.coursePools.map(pool => ({
        id: pool.pool_id ?? pool.id,
        name: pool.pool_name ?? pool.name,
        handbook_key: pool.handbook_key || null,
        handbook_title: pool.handbook_title || null,
        chapter_key: pool.chapter_key || null,
        chapter_title: pool.chapter_title || null,
        chapter_order: pool.chapter_order || null,
      }))
    },
    selectedLeaguePool() {
      return this.normalizedPools.find(pool => pool.id === this.selectedPoolId) || null
    },
    selectedClPool() {
      return this.normalizedPools.find(pool => pool.id === this.selectedClPoolId) || null
    },
    season() {
      return this.overview ? this.overview.season : null
    },
    seasonChapterLabel() {
      return this.season ? this.formatChapterLabel(this.season) : ''
    },
    clSeasonChapterLabel() {
      if (!this.season) {
        return ''
      }
      return this.formatChapterLabel({
        chapter_title: this.season.cl_chapter_title,
        chapter_order: this.season.cl_chapter_order,
      })
    },
    standings() {
      return this.overview && Array.isArray(this.overview.standings) ? this.overview.standings : []
    },
    incomingChallenges() {
      return this.overview && Array.isArray(this.overview.incoming_challenges) ? this.overview.incoming_challenges : []
    },
    outgoingChallenges() {
      return this.overview && Array.isArray(this.overview.outgoing_challenges) ? this.overview.outgoing_challenges : []
    },
    cl() {
      return this.overview ? this.overview.cl : null
    },
    canCreateSeason() {
      return Boolean(this.overview && this.overview.can_create_season)
    },
  },
  watch: {
    courseId: {
      immediate: true,
      handler() {
        this.fetchOverview()
      },
    },
    coursePools: {
      immediate: true,
      handler(pools) {
        if (!this.selectedPoolId && pools.length > 0) {
          this.selectedPoolId = pools[0].pool_id ?? pools[0].id
        }
        if (!this.selectedClPoolId && pools.length > 0) {
          this.selectedClPoolId = pools[Math.min(1, pools.length - 1)].pool_id ?? pools[Math.min(1, pools.length - 1)].id
        }
      },
    },
  },
  methods: {
    formatChapterLabel(source) {
      if (!source || !source.chapter_title) {
        return ''
      }
      return source.chapter_order
        ? t('learning', 'Chapter {n}: {title}', { n: source.chapter_order, title: source.chapter_title })
        : source.chapter_title
    },
    formatDateTime(timestamp) {
      if (!timestamp) {
        return '—'
      }
      return new Date(timestamp * 1000).toLocaleString()
    },
    pairingLabel(state) {
      const labels = {
        self: '—',
        available: this.season && this.season.status === 'active' ? t('learning', 'Available') : '—',
        open_incoming: t('learning', 'Incoming'),
        open_outgoing: t('learning', 'Pending'),
        accepted: t('learning', 'Ready'),
        played: t('learning', 'Played'),
        locked: '—',
      }
      return labels[state] || '—'
    },
    async fetchOverview() {
      this.loading = true
      this.error = ''
      try {
        const url = generateUrl('/apps/learning/api/courses/{courseId}/leagues/active', { courseId: this.courseId })
        const response = await axios.get(url)
        this.overview = response.data
      } catch (err) {
        this.error = err.response?.data?.error || t('learning', 'Failed to load league data.')
      } finally {
        this.loading = false
      }
    },
    async createSeason() {
      this.saving = true
      this.error = ''
      try {
        const url = generateUrl('/apps/learning/api/courses/{courseId}/leagues', { courseId: this.courseId })
        const response = await axios.post(url, {
          name: this.seasonName,
          poolId: this.selectedPoolId,
          clPoolId: this.selectedClPoolId,
        })
        this.overview = response.data
      } catch (err) {
        this.error = err.response?.data?.error || t('learning', 'Failed to create league season.')
      } finally {
        this.saving = false
      }
    },
    async startSeason() {
      if (!this.season) {
        return
      }
      this.saving = true
      this.error = ''
      try {
        const url = generateUrl('/apps/learning/api/courses/{courseId}/leagues/{id}/start', {
          courseId: this.courseId,
          id: this.season.id,
        })
        const response = await axios.post(url, {})
        this.overview = response.data
      } catch (err) {
        this.error = err.response?.data?.error || t('learning', 'Failed to start season.')
      } finally {
        this.saving = false
      }
    },
    async finishSeason() {
      if (!this.season) {
        return
      }
      this.saving = true
      this.error = ''
      try {
        const url = generateUrl('/apps/learning/api/courses/{courseId}/leagues/{id}/finish', {
          courseId: this.courseId,
          id: this.season.id,
        })
        const response = await axios.post(url, {})
        this.overview = response.data
      } catch (err) {
        this.error = err.response?.data?.error || t('learning', 'Failed to finish season.')
      } finally {
        this.saving = false
      }
    },
    async challengeOpponent(opponentUid) {
      if (!this.season) {
        return
      }
      this.saving = true
      this.error = ''
      try {
        const url = generateUrl('/apps/learning/api/courses/{courseId}/leagues/{id}/challenge/{opponentUid}', {
          courseId: this.courseId,
          id: this.season.id,
          opponentUid,
        })
        const response = await axios.post(url, {})
        this.overview = response.data
      } catch (err) {
        this.error = err.response?.data?.error || t('learning', 'Challenge could not be created.')
      } finally {
        this.saving = false
      }
    },
    async acceptChallenge(challengeId) {
      if (!this.season) {
        return
      }
      this.saving = true
      this.error = ''
      try {
        const url = generateUrl('/apps/learning/api/courses/{courseId}/leagues/{id}/challenges/{challengeId}/accept', {
          courseId: this.courseId,
          id: this.season.id,
          challengeId,
        })
        const response = await axios.post(url, {})
        this.activeLeagueDuelCode = response.data.duel_code
        await this.fetchOverview()
      } catch (err) {
        this.error = err.response?.data?.error || t('learning', 'Challenge could not be accepted.')
      } finally {
        this.saving = false
      }
    },
    openLeagueDuel(code) {
      this.activeLeagueDuelCode = code
    },
    async closeLeagueDuel() {
      this.activeLeagueDuelCode = ''
      await this.fetchOverview()
    },
  },
}
</script>

<style scoped>
.league-tab {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.league-create,
.league-summary,
.challenge-card,
.league-table-wrap,
.league-cl {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 16px;
  padding: 20px;
}

.league-grid,
.league-challenges,
.cl-rounds {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
}

.league-input,
.league-select {
  width: 100%;
  margin-bottom: 12px;
  padding: 12px 14px;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-main-background);
  color: var(--color-main-text);
}

.pool-context-card {
  margin-top: 10px;
  padding: 12px 14px;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: color-mix(in srgb, var(--color-main-background) 90%, var(--color-background-dark));
}

.context-line {
  font-size: 13px;
  line-height: 1.5;
}

.context-line.muted {
  color: var(--color-text-maxcontrast);
}

.league-label {
  display: block;
  margin-bottom: 6px;
  font-size: 13px;
  font-weight: 600;
}

.league-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 12px;
}

.league-pill {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  border-radius: 999px;
  margin-bottom: 8px;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
}

.status-open {
  background: color-mix(in srgb, var(--color-warning) 16%, var(--color-main-background));
}

.status-active {
  background: color-mix(in srgb, var(--color-primary-element) 16%, var(--color-main-background));
}

.status-finished {
  background: color-mix(in srgb, var(--color-success) 16%, var(--color-main-background));
}

.league-table {
  width: 100%;
  border-collapse: collapse;
}

.league-table th,
.league-table td {
  padding: 10px 8px;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
}

.my-row {
  background: color-mix(in srgb, var(--color-primary-element) 6%, var(--color-main-background));
}

.challenge-status {
  color: var(--color-text-maxcontrast);
  font-size: 13px;
  font-weight: 600;
}

.league-error {
  margin: 0;
}

.league-champion {
  font-weight: 700;
  color: var(--color-success);
}

@media (max-width: 768px) {
  .league-table {
    font-size: 14px;
  }
}
</style>
