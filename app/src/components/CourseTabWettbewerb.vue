<template>
	<div class="course-tab-wettbewerb">
		<div class="wettbewerb-subnav" role="tablist" :aria-label="t('learning', 'Wettbewerb Bereiche')">
			<button
				v-for="tab in visibleSubTabs"
				:key="tab.id"
				class="wettbewerb-pill"
				:class="{ active: currentSubTab === tab.id }"
				@click="selectSubTab(tab.id)">
				{{ tab.label }}
			</button>
		</div>

		<!-- Leaderboard -->
		<div v-if="currentSubTab === 'leaderboard'" class="leaderboard-section">
			<div class="section-header">
				<h4>{{ t('learning', 'Leaderboard') }}</h4>
				<div class="leaderboard-header-actions">
					<label class="leaderboard-active-toggle">
						<input
							type="checkbox"
							:checked="leaderboardActiveOnly"
							@change="toggleLeaderboardActiveOnly($event.target.checked)">
						<span>{{ t('learning', 'Only active ({n}d)', { n: leaderboardActiveDays }) }}</span>
					</label>
					<NcButton type="tertiary" @click="fetchLeaderboard">
						{{ t('learning', 'Refresh') }}
					</NcButton>
				</div>
			</div>

			<div v-if="leaderboardLoading" class="loading-container">
				<NcLoadingIcon :size="44" />
				<p>{{ t('learning', 'Loading leaderboard...') }}</p>
			</div>

			<div v-else-if="leaderboardData.length > 0" class="progress-table-container" role="region" :aria-label="t('learning', 'Course leaderboard')">
				<table class="progress-table leaderboard-table">
					<thead>
						<tr>
							<th class="rank-col" scope="col">#</th>
							<th class="student-col sortable-col" scope="col" role="button" tabindex="0"
								:aria-sort="leaderboardSortKey === 'user_id' ? (leaderboardSortAsc ? 'ascending' : 'descending') : 'none'"
								@click="setLeaderboardSort('user_id')" @keydown.enter="setLeaderboardSort('user_id')" @keydown.space.prevent="setLeaderboardSort('user_id')">
								{{ t('learning', 'Student') }}
								<span v-if="leaderboardSortKey === 'user_id'" class="sort-arrow">{{ leaderboardSortAsc ? '\u25B2' : '\u25BC' }}</span>
							</th>
							<th class="stat-col sortable-col" scope="col" role="button" tabindex="0"
								:aria-sort="leaderboardSortKey === 'current_level' ? (leaderboardSortAsc ? 'ascending' : 'descending') : 'none'"
								@click="setLeaderboardSort('current_level')" @keydown.enter="setLeaderboardSort('current_level')" @keydown.space.prevent="setLeaderboardSort('current_level')">
								{{ t('learning', 'Level') }}
								<span v-if="leaderboardSortKey === 'current_level'" class="sort-arrow">{{ leaderboardSortAsc ? '\u25B2' : '\u25BC' }}</span>
							</th>
							<th class="stat-col sortable-col" scope="col" role="button" tabindex="0"
								:aria-sort="leaderboardSortKey === 'total_xp' ? (leaderboardSortAsc ? 'ascending' : 'descending') : 'none'"
								@click="setLeaderboardSort('total_xp')" @keydown.enter="setLeaderboardSort('total_xp')" @keydown.space.prevent="setLeaderboardSort('total_xp')">
								{{ t('learning', 'XP') }}
								<span v-if="leaderboardSortKey === 'total_xp'" class="sort-arrow">{{ leaderboardSortAsc ? '\u25B2' : '\u25BC' }}</span>
							</th>
							<th class="stat-col sortable-col" scope="col" role="button" tabindex="0"
								:aria-sort="leaderboardSortKey === 'total_mastered' ? (leaderboardSortAsc ? 'ascending' : 'descending') : 'none'"
								@click="setLeaderboardSort('total_mastered')" @keydown.enter="setLeaderboardSort('total_mastered')" @keydown.space.prevent="setLeaderboardSort('total_mastered')">
								{{ t('learning', 'Mastered') }}
								<span v-if="leaderboardSortKey === 'total_mastered'" class="sort-arrow">{{ leaderboardSortAsc ? '\u25B2' : '\u25BC' }}</span>
							</th>
							<template v-if="isInstructor">
								<th class="stat-col sortable-col" scope="col" role="button" tabindex="0"
									:aria-sort="leaderboardSortKey === 'current_streak' ? (leaderboardSortAsc ? 'ascending' : 'descending') : 'none'"
									@click="setLeaderboardSort('current_streak')" @keydown.enter="setLeaderboardSort('current_streak')" @keydown.space.prevent="setLeaderboardSort('current_streak')">
									{{ t('learning', 'Streak') }}
									<span v-if="leaderboardSortKey === 'current_streak'" class="sort-arrow">{{ leaderboardSortAsc ? '\u25B2' : '\u25BC' }}</span>
								</th>
								<th class="stat-col sortable-col" scope="col" role="button" tabindex="0"
									:aria-sort="leaderboardSortKey === 'total_sessions' ? (leaderboardSortAsc ? 'ascending' : 'descending') : 'none'"
									@click="setLeaderboardSort('total_sessions')" @keydown.enter="setLeaderboardSort('total_sessions')" @keydown.space.prevent="setLeaderboardSort('total_sessions')">
									{{ t('learning', 'Sessions') }}
									<span v-if="leaderboardSortKey === 'total_sessions'" class="sort-arrow">{{ leaderboardSortAsc ? '\u25B2' : '\u25BC' }}</span>
								</th>
							</template>
						</tr>
					</thead>
					<tbody>
						<tr v-for="entry in sortedLeaderboardData" :key="entry.user_id || entry.rank"
							:class="{ 'my-row': entry.is_me || entry.user_id === myUserId, 'clickable-row': isInstructor }"
							@click="isInstructor && entry.user_id ? $emit('selectStudent', { userId: entry.user_id, courseId: courseId }) : null">
							<td class="rank-col">
								<span v-if="entry.rank === 1" class="rank-medal">&#129351;</span>
								<span v-else-if="entry.rank === 2" class="rank-medal">&#129352;</span>
								<span v-else-if="entry.rank === 3" class="rank-medal">&#129353;</span>
								<span v-else>{{ entry.rank }}</span>
							</td>
							<td class="student-col">{{ entry.display_name }}</td>
							<td class="stat-col">
								<span class="level-pill" :class="levelClass(entry.current_level)">
									Lv.{{ entry.current_level || 1 }}
								</span>
							</td>
							<td class="stat-col">{{ Number(entry.total_xp || 0).toLocaleString() }}</td>
							<td class="stat-col">{{ entry.total_mastered || 0 }}</td>
							<template v-if="isInstructor">
								<td class="stat-col">
									<span v-if="entry.current_streak > 0" class="streak-pill"><span v-for="i in Math.min(entry.current_streak, 3)" :key="i" class="streak-flame" :style="{ animationDelay: (i * 0.1) + 's' }">&#x1F525;</span> {{ entry.current_streak }}d</span>
									<span v-else>&mdash;</span>
								</td>
								<td class="stat-col">{{ entry.total_sessions || 0 }}</td>
							</template>
						</tr>
					</tbody>
				</table>
				<div class="progress-pagination">
					<div class="progress-pagination-meta">
						{{ t('learning', 'Showing {start}-{end} of {total}', {
							start: leaderboardPageStart,
							end: leaderboardPageEnd,
							total: leaderboardMeta.total,
						}) }}
					</div>
					<div class="progress-pagination-actions">
						<NcButton type="tertiary" :disabled="!canLeaderboardPagePrev || leaderboardLoading" @click="pageLeaderboardPrev">
							{{ t('learning', 'Previous') }}
						</NcButton>
						<NcButton type="tertiary" :disabled="!canLeaderboardPageNext || leaderboardLoading" @click="pageLeaderboardNext">
							{{ t('learning', 'Next') }}
						</NcButton>
					</div>
				</div>
				<p v-if="myRank !== null && !isInstructor" class="my-rank-info">
					{{ t('learning', 'Your rank: #{rank}', { rank: myRank }) }}
				</p>
			</div>

			<NcEmptyContent v-else
				:name="t('learning', 'No leaderboard data')">
				<template #description>
					{{ t('learning', 'The leaderboard will appear once students start learning.') }}
				</template>
			</NcEmptyContent>
		</div>

		<!-- League -->
		<div v-if="currentSubTab === 'league'" class="league-section">
			<LeagueTab
				:courseId="courseId"
				:coursePools="coursePools"
				:isInstructor="isInstructor" />
		</div>

		<!-- Arena -->
		<div v-if="currentSubTab === 'arena'" class="arena-section">
			<ArenaSelector
				v-if="arenaSubMode === null"
				:modeConfig="course?.mode_config || {}"
				@select-mode="onArenaSelectMode" />
			<DuelMode
				v-else-if="arenaSubMode === 'duel'"
				:courseId="courseId"
				:coursePools="coursePools"
				:presetDuelCode="presetDuelCode"
				:hideJoinScreen="Boolean(presetDuelCode)"
				:contentLanguage="contentLanguage"
				@preset-consumed="$emit('preset-consumed')"
				@back="arenaSubMode = null" />
			<GameshowMode
				v-else-if="arenaSubMode === 'sprint'"
				:courseId="courseId"
				:coursePools="coursePools"
				:contentLanguage="contentLanguage"
				:mode="'sprint'"
				@back="arenaSubMode = null" />
			<GameshowMode
				v-else-if="arenaSubMode === 'elimination'"
				:courseId="courseId"
				:coursePools="coursePools"
				:contentLanguage="contentLanguage"
				:mode="'elimination'"
				@back="arenaSubMode = null" />
			<OldschoolSelector
				v-else-if="arenaSubMode === 'oldschool' && oldschoolSubMode === null"
				@select-mode="onOldschoolSelectMode" />
			<LernwuerfelMode
				v-else-if="arenaSubMode === 'oldschool' && oldschoolSubMode === 'lernwuerfel'"
				:courseId="courseId"
				:coursePools="coursePools"
				:contentLanguage="contentLanguage"
				@back="oldschoolSubMode = null" />
			<WissensturmMode
				v-else-if="arenaSubMode === 'oldschool' && oldschoolSubMode === 'wissensturm'"
				:courseId="courseId"
				:coursePools="coursePools"
				:contentLanguage="contentLanguage"
				@back="oldschoolSubMode = null" />
		</div>

		<!-- Abenteuer -->
		<div v-if="currentSubTab === 'abenteuer'" class="abenteuer-section">
			<AbenteuerMode
				:courseId="courseId"
				:coursePools="coursePools"
				:contentLanguage="contentLanguage"
				:allowedCampaigns="course && course.allowed_campaigns ? course.allowed_campaigns : null"
				@back="$emit('tab-change', 'training')" />
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import { formatXp } from '../format.js'
import LeagueTab from './LeagueTab.vue'
import ArenaSelector from './ArenaSelector.vue'
import DuelMode from './DuelMode.vue'
import GameshowMode from './GameshowMode.vue'
import OldschoolSelector from './OldschoolSelector.vue'
import WissensturmMode from './WissensturmMode.vue'
import LernwuerfelMode from './LernwuerfelMode.vue'
import AbenteuerMode from './AbenteuerMode.vue'

export default {
	name: 'CourseTabWettbewerb',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		LeagueTab,
		ArenaSelector,
		DuelMode,
		GameshowMode,
		OldschoolSelector,
		WissensturmMode,
		LernwuerfelMode,
		AbenteuerMode,
	},

	props: {
		courseId: { type: Number, required: true },
		course: { type: Object, default: null },
		userRole: { type: String, required: true },
		coursePools: { type: Array, default: () => [] },
		activeTab: { type: String, default: '' },
		contentLanguage: { type: String, default: '' },
		presetDuelCode: { type: String, default: '' },
	},

	data() {
		return {
			currentSubTab: 'leaderboard',

			// Leaderboard
			leaderboardLoading: false,
			leaderboardData: [],
			myRank: null,
			leaderboardSortKey: 'total_xp',
			leaderboardSortAsc: false,
			leaderboardPageSize: 25,
			leaderboardActiveOnly: false,
			leaderboardActiveDays: 30,
			leaderboardMeta: {
				total: 0,
				limit: 25,
				offset: 0,
			},

			// Arena sub-mode
			arenaSubMode: null,
			oldschoolSubMode: null,
		}
	},

	computed: {
		isInstructor() {
			return this.course && this.course.is_instructor
		},
		myUserId() {
			const user = getCurrentUser()
			return user ? user.uid : null
		},
		hasEnabledArenaModes() {
			const mc = this.course?.mode_config || {}
			const enabled = (key) => mc[key] !== false
			return enabled('duel') || enabled('gameshow') || enabled('oldschool')
		},
		visibleSubTabs() {
			const mc = this.course?.mode_config || {}
			const enabled = (key) => mc[key] !== false
			const tabs = [
				{ id: 'leaderboard', label: t('learning', 'Leaderboard') },
			]
			if (this.isInstructor || enabled('league')) {
				tabs.push({ id: 'league', label: t('learning', 'Liga') })
			}
			if (this.isInstructor || this.hasEnabledArenaModes) {
				tabs.push({ id: 'arena', label: t('learning', 'Arena') })
			}
			if (this.isInstructor || enabled('abenteuer')) {
				tabs.push({ id: 'abenteuer', label: t('learning', 'Abenteuer') })
			}
			return tabs
		},
		sortedLeaderboardData() {
			return this.leaderboardData
		},
		canLeaderboardPagePrev() {
			return (this.leaderboardMeta.offset || 0) > 0
		},
		canLeaderboardPageNext() {
			return (this.leaderboardMeta.offset + this.leaderboardMeta.limit) < this.leaderboardMeta.total
		},
		leaderboardPageStart() {
			if (this.leaderboardMeta.total === 0 || this.leaderboardData.length === 0) return 0
			return this.leaderboardMeta.offset + 1
		},
		leaderboardPageEnd() {
			if (this.leaderboardMeta.total === 0 || this.leaderboardData.length === 0) return 0
			return this.leaderboardMeta.offset + this.leaderboardData.length
		},
	},

	watch: {
		activeTab: {
			immediate: true,
			handler(tab) {
				this.syncFromActiveTab(tab)
			},
		},
		presetDuelCode: {
			immediate: true,
			handler(newCode) {
				if (newCode) {
					this.currentSubTab = 'arena'
					this.arenaSubMode = 'duel'
				}
			},
		},
		arenaSubMode(mode) {
			this.$emit('arena-sub-mode', mode)
		},
	},

	methods: {
		syncFromActiveTab(tab) {
			const leafTabs = this.visibleSubTabs.map((t) => t.id)
			if (leafTabs.includes(tab)) {
				this.currentSubTab = tab
				this.lazyLoad(tab)
			}
		},
		lazyLoad(tab) {
			if (tab === 'leaderboard') {
				this.fetchLeaderboard()
			}
		},
		selectSubTab(tabId) {
			this.currentSubTab = tabId
			this.$emit('tab-change', tabId)
			if (tabId !== 'arena') {
				this.arenaSubMode = null
				this.oldschoolSubMode = null
			}
			this.lazyLoad(tabId)
		},

		levelClass(level) {
			const l = level || 1
			if (l >= 10) return 'level-gold'
			if (l >= 5) return 'level-blue'
			return 'level-grey'
		},

		formatXp(value) {
			return formatXp(value)
		},

		onArenaSelectMode(mode) {
			this.arenaSubMode = mode
		},
		onOldschoolSelectMode(mode) {
			this.oldschoolSubMode = mode
		},

		setLeaderboardSort(key) {
			if (this.leaderboardSortKey === key) {
				this.leaderboardSortAsc = !this.leaderboardSortAsc
			} else {
				this.leaderboardSortKey = key
				this.leaderboardSortAsc = key === 'user_id' || key === 'last_activity_date'
			}
			this.leaderboardMeta.offset = 0
			this.fetchLeaderboard()
		},

		toggleLeaderboardActiveOnly(checked) {
			this.leaderboardActiveOnly = !!checked
			this.leaderboardMeta.offset = 0
			this.fetchLeaderboard()
		},

		pageLeaderboardPrev() {
			if (!this.canLeaderboardPagePrev || this.leaderboardLoading) return
			this.leaderboardMeta.offset = Math.max(0, this.leaderboardMeta.offset - this.leaderboardMeta.limit)
			this.fetchLeaderboard()
		},

		pageLeaderboardNext() {
			if (!this.canLeaderboardPageNext || this.leaderboardLoading) return
			this.leaderboardMeta.offset += this.leaderboardMeta.limit
			this.fetchLeaderboard()
		},

		async fetchLeaderboard() {
			this.leaderboardLoading = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/leaderboard', { courseId: this.courseId })
				const response = await axios.get(url, {
					params: {
						limit: this.leaderboardMeta.limit || this.leaderboardPageSize,
						offset: this.leaderboardMeta.offset || 0,
						sortKey: this.leaderboardSortKey || 'total_xp',
						sortDir: this.leaderboardSortAsc ? 'asc' : 'desc',
						activeOnly: this.leaderboardActiveOnly ? 1 : 0,
						activeWithinDays: this.leaderboardActiveDays,
					},
				})
				this.leaderboardData = response.data.leaderboard || []
				this.myRank = response.data.my_rank
				this.leaderboardMeta = {
					total: Number(response.data?.meta?.total || this.leaderboardData.length || 0),
					limit: Number(response.data?.meta?.limit || this.leaderboardPageSize),
					offset: Number(response.data?.meta?.offset || 0),
				}
			} catch (err) {
				console.error('Failed to fetch leaderboard:', err)
				this.$emit('error', t('learning', 'Failed to load leaderboard.'))
			} finally {
				this.leaderboardLoading = false
			}
		},
	},
}
</script>

<style scoped>
.course-tab-wettbewerb {
	padding: 0;
}

.wettbewerb-subnav {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-bottom: 16px;
}

.wettbewerb-pill {
	padding: 6px 14px;
	border: 1px solid var(--color-border);
	border-radius: 16px;
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	font-size: 0.88em;
	font-weight: 500;
	transition: all 0.15s;
}

.wettbewerb-pill:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.wettbewerb-pill.active {
	background: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	color: var(--color-primary-element);
	border-color: var(--color-primary-element);
	font-weight: 600;
}

/* Section header */
.section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.section-header h4 {
	margin: 0;
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 1px;
	color: var(--color-text-maxcontrast);
	font-weight: 700;
}

.leaderboard-header-actions {
	display: inline-flex;
	align-items: center;
	gap: 12px;
}

.leaderboard-active-toggle {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

/* Loading */
.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
}

.loading-container p { margin-top: 12px; }

/* Progress table / leaderboard */
.progress-table-container {
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.progress-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.progress-table th,
.progress-table td {
	padding: 10px 14px;
	text-align: center;
	border-bottom: 1px solid var(--color-border);
}

.progress-table th {
	background: var(--color-background-dark);
	font-weight: 600;
	color: var(--color-main-text);
	white-space: nowrap;
	position: sticky;
	top: 0;
}

.progress-table tbody tr:nth-child(even) {
	background: var(--color-background-hover);
}

.progress-table tbody tr:last-child td {
	border-bottom: none;
}

.progress-pagination {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	padding: 10px 4px 0;
}

.progress-pagination-meta {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.progress-pagination-actions {
	display: inline-flex;
	gap: 8px;
}

.student-col {
	text-align: start !important;
	font-weight: 500;
	white-space: nowrap;
}

.stat-col {
	min-width: 70px;
	text-align: center;
	white-space: nowrap;
}

.rank-col {
	width: 44px;
	text-align: center !important;
	font-weight: 600;
}

.rank-medal {
	font-size: 1.4em;
}

.leaderboard-table .rank-medal { display: inline-block; }

.my-row td {
	font-weight: 700;
	background: color-mix(in srgb, var(--color-primary-element) 8%, transparent) !important;
}

.my-rank-info {
	margin-top: 12px;
	text-align: center;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

/* Level pills */
.level-pill {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 700;
	white-space: nowrap;
}

.level-grey {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.level-blue {
	background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
	color: var(--color-primary-element);
}

.level-gold {
	background: color-mix(in srgb, var(--color-warning) 20%, transparent);
	color: var(--color-warning);
}

.sortable-col {
	cursor: pointer;
	user-select: none;
}

.sortable-col:hover {
	background: color-mix(in srgb, var(--color-primary-element) 8%, var(--color-background-dark));
}

.sort-arrow {
	font-size: 0.7em;
	margin-left: 4px;
}

.clickable-row {
	cursor: pointer;
}

.clickable-row:hover td {
	background: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
	transition: background 0.15s;
}

.streak-pill {
	display: inline-flex;
	align-items: center;
	gap: 1px;
	color: var(--color-warning);
	font-weight: 600;
}

.streak-flame {
	display: inline-block;
	animation: flame-dance 0.8s ease-in-out infinite alternate;
	font-size: 0.85em;
}

@keyframes flame-dance {
	0% { transform: translateY(0) scale(1); }
	100% { transform: translateY(-1px) scale(1.1); }
}

@media (prefers-reduced-motion: reduce) {
	.streak-flame { animation: none; }
}
</style>
