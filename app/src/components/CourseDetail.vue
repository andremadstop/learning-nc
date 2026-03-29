<template>
	<div class="course-detail">
		<!-- Header -->
		<div class="course-detail-header">
			<NcButton type="tertiary" @click="$emit('back')">
				{{ t('learning', '\u2190 Back to Courses') }}
			</NcButton>
			<div class="header-title">
				<h3>{{ course ? course.title : t('learning', 'Loading...') }}</h3>
				<span v-if="course && course.status" class="status-badge" :class="course.status">
					{{ course.status === 'active' ? t('learning', 'Active') : t('learning', 'Archived') }}
				</span>
			</div>
			<p v-if="course && course.description" class="course-description">
				{{ course.description }}
			</p>
			<a v-if="course && course.talk_room_token"
				:href="talkRoomUrl"
				target="_blank"
				rel="noopener"
				class="talk-room-link">
				{{ t('learning', 'Talk-Raum') }}
			</a>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="loading-container">
			<NcLoadingIcon :size="44" />
			<p>{{ t('learning', 'Loading course details...') }}</p>
		</div>

		<!-- Error state -->
		<NcNoteCard v-if="error" type="error" class="detail-error">
			{{ error }}
		</NcNoteCard>

		<template v-if="!loading && course">
			<!-- Tab selector -->
			<div class="tab-selector">
				<template v-for="(tab, idx) in visibleTabs">
					<span v-if="idx > 0 && tab.group && visibleTabs[idx - 1].group && tab.group !== visibleTabs[idx - 1].group"
						:key="'sep-' + idx"
						class="tab-group-separator" />
					<button
						:key="tab.id"
						class="tab-button"
						:class="{ active: isTabActive(tab.id) }"
						@click="selectTab(tab.id)">
						{{ tab.label }}
					</button>
				</template>
			</div>

			<CourseTabLernraum
				v-if="isLernraumTab(currentTab)"
				:course-id="courseId"
				:course="course"
				:user-role="userRole"
				:course-pools="coursePools"
				:all-pools="allPools"
				:active-tab="currentTab"
				:content-language="contentLanguage"
				@all-pools-loaded="allPools = $event"
				@error="error = $event"
				@knowledge-pending-count="knowledgePendingCount = $event"
				@mode-activated="activeLearningMode = $event"
				@openPool="$emit('openPool', $event)"
				@pool-selected="selectedLearningPool = $event"
				@refresh-course-detail="fetchCourseDetail"
				@tab-change="selectTab" />

			<!-- Members Tab (instructor only) -->
			<div v-if="currentTab === 'members' && isInstructor" class="members-section">
				<div class="section-header">
					<h4>{{ t('learning', 'Members ({n})', { n: courseMembers.length }) }}</h4>
				</div>

				<!-- Add member form -->
				<div class="add-member-form">
					<NcTextField :value.sync="newMemberUsername"
						:label="t('learning', 'Username')"
						:placeholder="t('learning', 'Enter username to add...')"
						:disabled="addingMember"
						@keydown.enter="addMember" />
					<NcButton type="secondary"
						:disabled="!newMemberUsername.trim() || addingMember"
						@click="addMember">
						{{ addingMember ? t('learning', 'Adding...') : t('learning', 'Add') }}
					</NcButton>
				</div>

				<NcNoteCard v-if="memberError" type="error" class="member-error">
					{{ memberError }}
				</NcNoteCard>

				<div v-if="courseMembers.length > 0" class="member-list">
					<div v-for="member in courseMembers"
						:key="member.id"
						class="member-item">
						<div class="member-info">
							<span class="member-avatar">{{ member.user_id.charAt(0).toUpperCase() }}</span>
							<span class="member-name">{{ member.user_id }}</span>
							<span class="member-role-badge" :class="member.role">
								{{ member.role }}
							</span>
							<span class="member-date">
								{{ t('learning', 'Enrolled {date}', { date: formatDate(member.enrolled_at) }) }}
							</span>
						</div>
						<div class="member-actions">
							<NcButton type="tertiary"
								:disabled="savingMember === member.id"
								@click="toggleMemberRole(member)">
								{{ member.role === 'student'
									? t('learning', 'Make Instructor')
									: t('learning', 'Make Student') }}
							</NcButton>
							<NcButton type="tertiary-no-background"
								:aria-label="t('learning', 'Remove member')"
								:disabled="savingMember === member.id"
								@click="confirmRemoveMember(member)">
								&times;
							</NcButton>
						</div>
					</div>
				</div>

				<NcEmptyContent v-if="courseMembers.length === 0"
					:name="t('learning', 'No members yet')">
					<template #description>
						{{ t('learning', 'Add members by entering their username above.') }}
					</template>
				</NcEmptyContent>
			</div>

			<!-- Progress Tab (instructor only) -->
			<div v-if="currentTab === 'progress' && isInstructor" class="progress-section">
				<div class="section-header">
					<h4>{{ t('learning', 'Student Progress') }}</h4>
					<NcButton type="tertiary" @click="fetchProgress(); fetchAtRisk()">
						{{ t('learning', 'Refresh') }}
					</NcButton>
				</div>

				<!-- At-Risk Section -->
				<div v-if="atRiskStudents.length > 0" class="at-risk-section" :class="{ collapsed: atRiskCollapsed }">
					<div class="at-risk-header" @click="atRiskCollapsed = !atRiskCollapsed">
						<h4 class="at-risk-title">
							{{ t('learning', 'At-Risk Students ({n})', { n: atRiskStudents.length }) }}
						</h4>
						<NcButton type="tertiary" size="small" @click.stop="exportAtRiskCsv">
							{{ t('learning', 'Export CSV') }}
						</NcButton>
						<button class="at-risk-toggle">{{ atRiskCollapsed ? '\u25BC' : '\u25B2' }}</button>
					</div>
					<div v-if="!atRiskCollapsed" class="at-risk-cards">
						<div v-for="student in atRiskStudents" :key="'risk-' + student.user_id"
							class="at-risk-card" :class="'risk-' + student.risk_level"
							@click="$emit('selectStudent', { userId: student.user_id, courseId: courseId })">
							<div class="at-risk-card-header">
								<span class="at-risk-name">{{ student.display_name }}</span>
								<span class="risk-badge" :class="student.risk_level">
									{{ student.risk_level === 'high' ? t('learning', 'High Risk') : t('learning', 'Medium Risk') }}
								</span>
							</div>
							<div class="at-risk-reasons">
								<span v-for="(reason, idx) in student.risk_reasons" :key="idx" class="risk-reason-tag">
									{{ reason }}
								</span>
							</div>
							<div class="at-risk-meta">
								<span v-if="student.accuracy !== null">{{ t('learning', 'Accuracy: {n}%', { n: student.accuracy }) }}</span>
								<span v-if="student.last_active">{{ t('learning', 'Last active: {date}', { date: formatLastActive(student.last_active) }) }}</span>
							</div>
						</div>
					</div>
				</div>

				<div v-if="progressLoading" class="loading-container">
					<NcLoadingIcon :size="44" />
					<p>{{ t('learning', 'Loading progress data...') }}</p>
				</div>

				<div v-else-if="progressData.length > 0" class="progress-table-container" role="region" :aria-label="t('learning', 'Student progress')">
					<table class="progress-table">
						<thead>
							<tr>
								<th class="student-col sortable-col" scope="col" role="button" tabindex="0"
									:aria-sort="progressSortKey === 'user_id' ? (progressSortAsc ? 'ascending' : 'descending') : 'none'"
									@click="setProgressSort('user_id')" @keydown.enter="setProgressSort('user_id')" @keydown.space.prevent="setProgressSort('user_id')">
									{{ t('learning', 'Student') }}
									<span v-if="progressSortKey === 'user_id'" class="sort-arrow">{{ progressSortAsc ? '\u25B2' : '\u25BC' }}</span>
								</th>
								<th class="stat-col sortable-col" scope="col" role="button" tabindex="0"
									:aria-sort="progressSortKey === 'current_level' ? (progressSortAsc ? 'ascending' : 'descending') : 'none'"
									@click="setProgressSort('current_level')" @keydown.enter="setProgressSort('current_level')" @keydown.space.prevent="setProgressSort('current_level')">
									{{ t('learning', 'Level') }}
									<span v-if="progressSortKey === 'current_level'" class="sort-arrow">{{ progressSortAsc ? '\u25B2' : '\u25BC' }}</span>
								</th>
								<th class="stat-col sortable-col" scope="col" role="button" tabindex="0"
									:aria-sort="progressSortKey === 'total_xp' ? (progressSortAsc ? 'ascending' : 'descending') : 'none'"
									@click="setProgressSort('total_xp')" @keydown.enter="setProgressSort('total_xp')" @keydown.space.prevent="setProgressSort('total_xp')">
									{{ t('learning', 'XP') }}
									<span v-if="progressSortKey === 'total_xp'" class="sort-arrow">{{ progressSortAsc ? '\u25B2' : '\u25BC' }}</span>
								</th>
								<th v-for="pool in coursePools"
									:key="'th-' + pool.id"
									class="pool-col"
									scope="col">
									{{ pool.pool_name }}
								</th>
								<th class="overall-col sortable-col" scope="col" role="button" tabindex="0"
									:aria-sort="progressSortKey === 'overall_mastery' ? (progressSortAsc ? 'ascending' : 'descending') : 'none'"
									@click="setProgressSort('overall_mastery')" @keydown.enter="setProgressSort('overall_mastery')" @keydown.space.prevent="setProgressSort('overall_mastery')">
									{{ t('learning', 'Overall') }}
									<span v-if="progressSortKey === 'overall_mastery'" class="sort-arrow">{{ progressSortAsc ? '\u25B2' : '\u25BC' }}</span>
								</th>
								<th class="stat-col sortable-col" scope="col" role="button" tabindex="0"
									:aria-sort="progressSortKey === 'last_activity_date' ? (progressSortAsc ? 'ascending' : 'descending') : 'none'"
									@click="setProgressSort('last_activity_date')" @keydown.enter="setProgressSort('last_activity_date')" @keydown.space.prevent="setProgressSort('last_activity_date')">
									{{ t('learning', 'Last Active') }}
									<span v-if="progressSortKey === 'last_activity_date'" class="sort-arrow">{{ progressSortAsc ? '\u25B2' : '\u25BC' }}</span>
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in sortedProgressData" :key="row.user_id"
								class="clickable-row"
								@click="$emit('selectStudent', { userId: row.user_id, courseId: courseId })">
								<td class="student-col">{{ row.display_name || row.user_id }}</td>
								<td class="stat-col">
									<span class="level-pill" :class="levelClass(row.current_level)">
										Lv.{{ row.current_level || 1 }}
									</span>
								</td>
								<td class="stat-col">{{ formatXp(row.total_xp) }}</td>
								<td v-for="pool in coursePools"
									:key="'td-' + pool.id"
									class="pool-col"
									:class="masteryClass(getPoolMastery(row, pool.pool_id))">
									{{ getPoolMastery(row, pool.pool_id) !== null
										? getPoolMastery(row, pool.pool_id) + '%'
										: '-' }}
								</td>
								<td class="overall-col"
									:class="masteryClass(row.overall_mastery)">
									{{ row.overall_mastery !== null && row.overall_mastery !== undefined
										? row.overall_mastery + '%'
										: '-' }}
								</td>
								<td class="stat-col last-active-col">{{ formatLastActive(row.last_activity_date) }}</td>
							</tr>
						</tbody>
					</table>
					<div class="progress-pagination">
						<div class="progress-pagination-meta">
							{{ t('learning', 'Showing {start}-{end} of {total}', {
								start: progressPageStart,
								end: progressPageEnd,
								total: progressMeta.total,
							}) }}
						</div>
						<div class="progress-pagination-actions">
							<NcButton type="tertiary" :disabled="!canPagePrev || progressLoading" @click="pageProgressPrev">
								{{ t('learning', 'Previous') }}
							</NcButton>
							<NcButton type="tertiary" :disabled="!canPageNext || progressLoading" @click="pageProgressNext">
								{{ t('learning', 'Next') }}
							</NcButton>
						</div>
					</div>
				</div>

				<NcEmptyContent v-else
					:name="t('learning', 'No progress data')">
					<template #description>
						{{ t('learning', 'Progress will appear once students start working on course pools.') }}
					</template>
				</NcEmptyContent>
			</div>

			<div v-if="currentTab === 'class-profile' && isInstructor" class="class-profile-section">
				<div class="section-header">
					<h4>{{ t('learning', 'Class Profile') }}</h4>
					<NcButton type="tertiary" @click="fetchTelosAggregate">
						{{ t('learning', 'Refresh') }}
					</NcButton>
				</div>

				<NcNoteCard type="info" class="class-profile-note">
					{{ t('learning', 'This area aggregates students\' own learning goals and self-assessment. Only completed Telos profiles appear in the detailed distributions.') }}
				</NcNoteCard>

				<div v-if="telosAggregateLoading" class="loading-container">
					<NcLoadingIcon :size="44" />
					<p>{{ t('learning', 'Loading class profile...') }}</p>
				</div>

				<NcNoteCard v-else-if="telosAggregateError" type="error">
					{{ telosAggregateError }}
				</NcNoteCard>

				<div v-else class="class-profile-body">
					<div class="class-profile-cards">
						<div class="class-profile-card">
							<span class="class-profile-label">{{ t('learning', 'Students') }}</span>
							<strong class="class-profile-value">{{ telosAggregate.total || 0 }}</strong>
						</div>
						<div class="class-profile-card">
							<span class="class-profile-label">{{ t('learning', 'Onboarded') }}</span>
							<strong class="class-profile-value">{{ telosAggregate.onboarded || 0 }}</strong>
						</div>
						<div class="class-profile-card">
							<span class="class-profile-label">{{ t('learning', 'Avg. hours / week') }}</span>
							<strong class="class-profile-value">{{ telosAggregate.avg_hours_per_week || 0 }}</strong>
						</div>
					</div>

					<div class="class-profile-grid">
						<div class="class-profile-panel">
							<h5>{{ t('learning', 'Experience levels') }}</h5>
							<ul v-if="sortedDistributionEntries(telosAggregate.experience_levels).length" class="class-profile-list">
								<li v-for="entry in sortedDistributionEntries(telosAggregate.experience_levels)" :key="'exp-' + entry.key">
									<span>{{ entry.key }}</span>
									<strong>{{ entry.value }}</strong>
								</li>
							</ul>
							<p v-else class="class-profile-empty">{{ t('learning', 'No onboarding data yet.') }}</p>
						</div>

						<div class="class-profile-panel">
							<h5>{{ t('learning', 'Target certifications') }}</h5>
							<ul v-if="sortedDistributionEntries(telosAggregate.target_certs).length" class="class-profile-list">
								<li v-for="entry in sortedDistributionEntries(telosAggregate.target_certs)" :key="'cert-' + entry.key">
									<span>{{ entry.key }}</span>
									<strong>{{ entry.value }}</strong>
								</li>
							</ul>
							<p v-else class="class-profile-empty">{{ t('learning', 'No target certifications recorded yet.') }}</p>
						</div>
					</div>

					<div class="class-profile-panel">
						<h5>{{ t('learning', 'Upcoming exams') }}</h5>
						<ul v-if="(telosAggregate.upcoming_exams || []).length" class="class-profile-list">
							<li v-for="exam in telosAggregate.upcoming_exams" :key="exam.user_id + '-' + exam.target_date">
								<span>{{ exam.user_id }} · {{ exam.target_cert || t('learning', 'Exam') }}</span>
								<strong>{{ exam.target_date }} · {{ t('learning', '{n} days', { n: exam.days_until }) }}</strong>
							</li>
						</ul>
						<p v-else class="class-profile-empty">{{ t('learning', 'No upcoming exam dates in the next 180 days.') }}</p>
					</div>
				</div>

				<BuddyMatching :course-id="courseId" />
			</div>

			<!-- My Progress Tab (student self-view) -->
			<div v-if="currentTab === 'my-progress' && !isInstructor" class="my-progress-section">
				<StudentDetail
					:courseId="courseId"
					:studentId="myUserId"
					@back="currentTab = 'training'" />
			</div>

			<div v-if="currentTab === 'summary' && !isInstructor" class="summary-section">
				<CourseSummary
					:courseId="courseId"
					:courseName="course?.title || ''" />
			</div>

			<div v-if="currentTab === 'summary' && isInstructor" class="summary-section">
				<NcNoteCard type="info">
					{{ t('learning', 'Der Klassen-Abschlussbericht folgt in Phase 107. Hier erscheint später die Dozentenansicht für den Kursabschluss.') }}
				</NcNoteCard>
			</div>

			<!-- Leaderboard Tab -->
			<div v-if="currentTab === 'leaderboard'" class="leaderboard-section">
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

			<div v-if="currentTab === 'league'" class="league-section">
				<LeagueTab
					:courseId="courseId"
					:coursePools="coursePools"
					:isInstructor="isInstructor" />
			</div>
			<!-- Arena Tab -->
			<div v-if="currentTab === 'arena'" class="arena-section">
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
					@preset-consumed="$emit('clearPresetDuel')"
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
			<!-- Abenteuer Tab (standalone, not inside Arena) -->
			<div v-if="currentTab === 'abenteuer'" class="abenteuer-section">
				<AbenteuerMode
					:courseId="courseId"
					:coursePools="coursePools"
					:contentLanguage="contentLanguage"
					@back="currentTab = 'training'" />
			</div>
		<!-- Curriculum Scope Tab (instructor only) -->
	<!-- Heatmap Tab (instructor only) -->
	<div v-if="currentTab === 'heatmap' && isInstructor" class="tab-content heatmap-section">
		<div v-if="loadingHeatmap" class="loading-hint">{{ t('learning', 'Loading...') }}</div>
		<div v-else-if="heatmapChapters.length === 0" class="empty-hint">
			{{ t('learning', 'No chapter data available yet. Students need to answer questions first.') }}
		</div>
		<div v-else>
			<p class="section-hint">{{ t('learning', 'Success rate per chapter across all students. Sorted by worst first.') }}</p>
			<div class="heatmap-list">
				<div v-for="ch in heatmapChapters" :key="ch.chapter_key" class="heatmap-row">
					<div class="heatmap-meta">
						<span class="chapter-title-hm">{{ ch.chapter_title || ch.chapter_key }}</span>
						<span class="heatmap-stats">{{ ch.success_rate }}% · {{ ch.unique_learners }} {{ t('learning', 'learners') }}</span>
					</div>
					<div class="heatmap-bar-bg">
						<div
							class="heatmap-bar-fill"
							:class="ch.severity"
							:style="{ width: ch.success_rate + '%' }" />
					</div>
					<div v-if="expandedChapter === ch.chapter_key && ch.top_wrong_questions && ch.top_wrong_questions.length > 0" class="wrong-questions">
						<div v-for="q in ch.top_wrong_questions" :key="q.id" class="wrong-q-row">
							<span class="wrong-q-text">{{ q.text }}</span>
							<span class="wrong-q-rate">{{ q.wrong_rate }}% falsch</span>
						</div>
					</div>
					<NcButton type="tertiary" @click="toggleExpandChapter(ch.chapter_key)">
						{{ expandedChapter === ch.chapter_key ? t('learning', 'Hide') : t('learning', 'Show worst questions') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>

	<!-- Weak Questions Tab (instructor only) -->
	<div v-if="currentTab === 'weak-questions' && isInstructor" class="tab-content weak-questions-section">
		<div v-if="loadingWeakQuestions" class="loading-hint">{{ t('learning', 'Loading...') }}</div>
		<div v-else-if="weakQuestions.length === 0" class="empty-hint">
			{{ t('learning', 'No weak questions found. Questions need at least 5 answers to appear here.') }}
		</div>
		<table v-else class="weak-questions-table">
			<thead>
				<tr>
					<th>#</th>
					<th>{{ t('learning', 'Question') }}</th>
					<th>{{ t('learning', 'Chapter') }}</th>
					<th>{{ t('learning', 'Wrong rate') }}</th>
					<th>{{ t('learning', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(q, idx) in weakQuestions" :key="q.question_id" :class="{ 'paused-row': q.is_paused }">
					<td>{{ idx + 1 }}</td>
					<td class="q-text">{{ q.question_text }}</td>
					<td>{{ q.chapter_title || q.chapter_key || '—' }}</td>
					<td class="wrong-rate" :class="q.wrong_rate >= 70 ? 'rate-red' : q.wrong_rate >= 50 ? 'rate-yellow' : 'rate-ok'">
						{{ q.wrong_rate }}%
					</td>
					<td class="q-actions">
						<NcButton type="tertiary" @click="toggleQuestionPause(q)">
							{{ q.is_paused ? t('learning', 'Resume') : t('learning', 'Pause') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<CourseTabKommunikation
		v-if="isKommunikationTab(currentTab)"
		:course-id="courseId"
		:course="course"
		:user-role="userRole"
		:active-tab="currentTab"
		@tab-change="selectTab"
		@error="error = $event"
		@refresh-course-detail="fetchCourseDetail" />

	<CourseTabVerwaltung
		v-if="isVerwaltungTab(currentTab) && isInstructor"
		:course-id="courseId"
		:course="course"
		:user-role="userRole"
		:active-tab="currentTab"
		@tab-change="selectTab"
		@error="error = $event"
		@refresh-course-detail="fetchCourseDetail" />

		</template>

		<!-- Remove member confirmation modal -->
		<NcModal v-if="showRemoveMemberModal" @close="showRemoveMemberModal = false" @closing="showRemoveMemberModal = false" size="small">
			<div class="modal-content">
				<h3>{{ t('learning', 'Remove Member') }}</h3>
				<p>{{ t('learning', 'Remove "{name}" from this course?', { name: removingMember ? removingMember.user_id : '' }) }}</p>
				<div class="modal-actions">
					<NcButton type="tertiary"
						:disabled="savingMember !== null"
						@click="showRemoveMemberModal = false">
						{{ t('learning', 'Cancel') }}
					</NcButton>
					<NcButton type="error"
						:disabled="savingMember !== null"
						@click="removeMember">
						{{ savingMember !== null ? t('learning', 'Removing...') : t('learning', 'Remove') }}
					</NcButton>
				</div>
			</div>
			</NcModal>
		</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import { formatXp, formatRelativeDateString } from '../format.js'
import LeagueTab from './LeagueTab.vue'
import StudentDetail from './StudentDetail.vue'
import DuelMode from './DuelMode.vue'
import GameshowMode from './GameshowMode.vue'
import ArenaSelector from './ArenaSelector.vue'
import OldschoolSelector from './OldschoolSelector.vue'
import WissensturmMode from './WissensturmMode.vue'
import LernwuerfelMode from './LernwuerfelMode.vue'
import AbenteuerMode from './AbenteuerMode.vue'
import CourseTabLernraum from './CourseTabLernraum.vue'
import CourseTabKommunikation from './CourseTabKommunikation.vue'
import CourseTabVerwaltung from './CourseTabVerwaltung.vue'
import CourseSummary from './CourseSummary.vue'

export default {
	name: 'CourseDetail',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcModal,
		NcTextField,
		NcNoteCard,
		LeagueTab,
		StudentDetail,
		DuelMode,
		GameshowMode,
		ArenaSelector,
		OldschoolSelector,
		WissensturmMode,
		LernwuerfelMode,
		AbenteuerMode,
		CourseTabLernraum,
		CourseTabKommunikation,
		CourseTabVerwaltung,
		CourseSummary,
	},

	props: {
		courseId: {
			type: Number,
			required: true,
		},
		userRole: {
			type: String,
			required: true,
			validator(value) {
				return ['instructor', 'student'].includes(value)
			},
		},
		contentLanguage: {
			type: String,
			default: '',
		},
		presetDuelCode: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			course: null,
			coursePools: [],
			courseMembers: [],
			currentTab: 'pools',
			knowledgePendingCount: 0,
			selectedLearningPool: null,
			activeLearningMode: null,
			allPools: [],

			// Members
			newMemberUsername: '',
			addingMember: false,
			memberError: '',
			savingMember: null,
			showRemoveMemberModal: false,
			removingMember: null,

			// Progress
			progressLoading: false,
			progressData: [],
			progressSortKey: 'total_xp',
			progressSortAsc: false,
			progressPageSize: 25,
			progressMeta: {
				total: 0,
				limit: 25,
				offset: 0,
			},

				// Student's own progress
				studentProgress: [],
				myProgressMode: 'mastery',

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

			// At-Risk
			atRiskStudents: [],
			atRiskCollapsed: false,
			telosAggregateLoading: false,
			telosAggregateError: '',
			telosAggregate: {
				total: 0,
				onboarded: 0,
				avg_hours_per_week: 0,
				experience_levels: {},
				target_certs: {},
				upcoming_exams: [],
			},

			// Heatmap
			loadingHeatmap: false,
			heatmapChapters: [],
			expandedChapter: null,

			// Weak questions
			loadingWeakQuestions: false,
			weakQuestions: [],

			// Arena sub-mode
			arenaSubMode: null, // 'duel' | 'sprint' | 'elimination' | null

			// Oldschool sub-mode
			oldschoolSubMode: null, // 'lernwuerfel' | 'wissensturm' | null
		}
	},

	computed: {
		isInstructor() {
			return this.course && this.course.is_instructor
		},
		talkRoomUrl() {
			if (!this.course?.talk_room_token) return ''
			return '/apps/spreed/#/call/' + this.course.talk_room_token
		},
		myUserId() {
			const user = getCurrentUser()
			return user ? user.uid : null
		},
		isCourseSummaryReleased() {
			return this.course?.mode_config?.course_summary === true
		},
		isStudentLearningTab() {
			return !this.isInstructor && ['training', 'leitner', 'exam'].includes(this.currentTab)
		},
		kommunikationLeafTabs() {
			if (this.isInstructor) {
				return ['announcements', 'feed', 'requests']
			}
			return ['feed', 'buddies', 'schwarm']
		},
		verwaltungLeafTabs() {
			return ['mode-config', 'exam-slot']
		},
		lernraumLeafTabs() {
			if (this.isInstructor) {
				return ['pools', 'curriculum', 'materials', 'knowledge']
			}
			const mc = this.course?.mode_config || {}
			const enabled = (key) => mc[key] !== false
			const tabs = ['training']
			if (enabled('leitner')) tabs.push('leitner')
			if (enabled('exam')) tabs.push('exam')
			if (this.course?.material_folder) tabs.push('materials')
			return tabs
		},
		visibleTabs() {
			if (this.isInstructor) {
				return [
					{ id: 'lernraum', label: t('learning', 'Lernraum'), group: 'Lernraum' },
					// Teilnehmer
					{ id: 'members', label: t('learning', 'Members'), group: 'Teilnehmer' },
					{ id: 'progress', label: t('learning', 'Progress'), group: 'Teilnehmer' },
					{ id: 'heatmap', label: t('learning', 'Heatmap'), group: 'Teilnehmer' },
					{ id: 'weak-questions', label: t('learning', 'Schwache Fragen'), group: 'Teilnehmer' },
					{ id: 'class-profile', label: t('learning', 'Klassen-Profil'), group: 'Teilnehmer' },
					{ id: 'summary', label: t('learning', 'Abschluss'), group: 'Teilnehmer' },
					// Wettbewerb
					{ id: 'leaderboard', label: t('learning', 'Leaderboard'), group: 'Wettbewerb' },
					{ id: 'league', label: t('learning', 'Liga'), group: 'Wettbewerb' },
					{ id: 'arena', label: t('learning', 'Arena'), group: 'Wettbewerb' },
					{ id: 'abenteuer', label: t('learning', 'Abenteuer'), group: 'Wettbewerb' },
					// Kommunikation
					{ id: 'kommunikation', label: t('learning', 'Kommunikation'), group: 'Kommunikation' },
					// Verwaltung
					{ id: 'verwaltung', label: t('learning', 'Verwaltung'), group: 'Verwaltung' },
				]
			}
			const mc = this.course?.mode_config || {}
			const enabled = (key) => mc[key] !== false
			const tabs = [
				{ id: 'lernraum', label: t('learning', 'Lernraum') },
			]
			tabs.push({ id: 'my-progress', label: t('learning', 'Mein Fortschritt') })
			if (this.isCourseSummaryReleased) tabs.push({ id: 'summary', label: t('learning', 'Abschluss') })
			tabs.push({ id: 'kommunikation', label: t('learning', 'Kommunikation') })
			tabs.push({ id: 'leaderboard', label: t('learning', 'Leaderboard') })
			if (enabled('league')) tabs.push({ id: 'league', label: t('learning', 'Liga') })
			if (this.hasEnabledArenaModes) tabs.push({ id: 'arena', label: t('learning', 'Arena') })
			if (enabled('abenteuer')) tabs.push({ id: 'abenteuer', label: t('learning', 'Abenteuer') })
			return tabs
		},
		hasEnabledArenaModes() {
			const mc = this.course?.mode_config || {}
			const enabled = (key) => mc[key] !== false
			return enabled('duel') || enabled('gameshow') || enabled('oldschool')
		},
		sortedProgressData() {
			return this.progressData
		},
		canPagePrev() {
			return (this.progressMeta.offset || 0) > 0
		},
		canPageNext() {
			return (this.progressMeta.offset + this.progressMeta.limit) < this.progressMeta.total
		},
		progressPageStart() {
			if (this.progressMeta.total === 0 || this.progressData.length === 0) return 0
			return this.progressMeta.offset + 1
		},
		progressPageEnd() {
			if (this.progressMeta.total === 0 || this.progressData.length === 0) return 0
			return this.progressMeta.offset + this.progressData.length
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
		visibleTabs(tabs) {
			const isVisibleLeaf = tabs.find((tab) => tab.id === this.currentTab)
			const isVisibleLernraumLeaf = tabs.find((tab) => tab.id === 'lernraum') && this.isLernraumTab(this.currentTab)
			const isVisibleKommunikationLeaf = tabs.find((tab) => tab.id === 'kommunikation') && this.isKommunikationTab(this.currentTab)
			const isVisibleVerwaltungLeaf = tabs.find((tab) => tab.id === 'verwaltung') && this.isVerwaltungTab(this.currentTab)
			if (!isVisibleLeaf && !isVisibleLernraumLeaf && !isVisibleKommunikationLeaf && !isVisibleVerwaltungLeaf) {
				this.currentTab = this.defaultLernraumTab()
			}
		},
		courseId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.fetchCourseDetail()
				}
			},
		},
		currentTab(tab) {
			if (tab === 'swipe') {
				this.currentTab = 'training'
				return
			}
			if (this.isStudentLearningTab) {
				this.activeLearningMode = tab
				this.selectedLearningPool = null
			}
			if (tab === 'progress' && this.isInstructor && this.progressData.length === 0) {
				this.fetchProgress()
				this.fetchAtRisk()
			}
			if (tab === 'class-profile' && this.isInstructor) {
				this.fetchTelosAggregate()
			}
			if (tab === 'leaderboard') {
				this.fetchLeaderboard()
			}
			if (tab === 'heatmap' && this.isInstructor) {
				this.fetchHeatmap()
			}
			if (tab === 'weak-questions' && this.isInstructor) {
				this.fetchWeakQuestions()
			}
			this.emitVirtuProfContext()
			this.emitLearningGuide()
		},
		activeLearningMode() {
			this.emitVirtuProfContext()
		},
		selectedLearningPool() {
			this.emitVirtuProfContext()
		},
		course() {
			this.emitVirtuProfContext()
		},
		presetDuelCode(newCode) {
			if (newCode) {
				this.currentTab = 'arena'
				this.arenaSubMode = 'duel'
			}
		},
	},

		methods: {
			defaultLernraumTab() {
				return this.lernraumLeafTabs[0] || (this.isInstructor ? 'pools' : 'training')
			},
			isLernraumTab(tabId) {
				return this.lernraumLeafTabs.includes(tabId)
			},
			isKommunikationTab(tabId) {
				return this.kommunikationLeafTabs.includes(tabId)
			},
			isVerwaltungTab(tabId) {
				return this.verwaltungLeafTabs.includes(tabId)
			},
			isTabActive(tabId) {
				if (tabId === 'lernraum') {
					return this.isLernraumTab(this.currentTab)
				}
				if (tabId === 'kommunikation') {
					return this.isKommunikationTab(this.currentTab)
				}
				if (tabId === 'verwaltung') {
					return this.isVerwaltungTab(this.currentTab)
				}
				return this.currentTab === tabId
			},
			resolveSelectableTab(tabId) {
				if (tabId === 'lernraum') {
					return this.isLernraumTab(this.currentTab) ? this.currentTab : this.defaultLernraumTab()
				}
				if (tabId === 'kommunikation') {
					return this.isKommunikationTab(this.currentTab) ? this.currentTab : this.kommunikationLeafTabs[0]
				}
				if (tabId === 'verwaltung') {
					return this.isVerwaltungTab(this.currentTab) ? this.currentTab : this.verwaltungLeafTabs[0]
				}
				return tabId
			},
			emitVirtuProfContext() {
				if (this.isInstructor || !this.course) {
					return
				}
				let area = 'course-detail'
				if (this.currentTab === 'my-progress') {
					area = 'course-my-progress'
				} else if (this.currentTab === 'summary') {
					area = 'course-summary'
				} else if (this.currentTab === 'leaderboard') {
					area = 'course-leaderboard'
				} else if (this.currentTab === 'league') {
					area = 'course-league'
				} else if (this.currentTab === 'arena') {
					if (this.arenaSubMode === 'sprint') {
						area = 'course-arena-sprint'
					} else if (this.arenaSubMode === 'elimination') {
						area = 'course-arena-elimination'
					} else if (this.arenaSubMode === 'duel') {
						area = 'course-duel'
					} else {
						area = 'course-arena'
					}
				} else if (this.isStudentLearningTab) {
					area = this.selectedLearningPool
						? `course-${this.activeLearningMode}-active`
						: `course-${this.activeLearningMode}-pool-select`
				}
				this.$root.$emit('virtuprof:context', {
					area,
					courseTitle: this.course?.title || '',
					poolName: this.selectedLearningPool?.pool_name || '',
				})
			},
			emitLearningGuide() {
				if (this.isInstructor) {
					return
				}
				const payload = this.learningGuidePayload(this.currentTab)
				if (payload) {
					this.$root.$emit('virtuprof:guide', payload)
				}
			},
			learningGuidePayload(tabId) {
				const guides = {
					training: {
						key: 'mode:course-training',
						title: t('learning', 'Training'),
						text: t('learning', 'Training gives immediate feedback after every answer. It is the best mode for active practice when you still want explanations and quick correction.'),
						shortText: t('learning', 'Training is the direct-feedback mode for quick practice.'),
					},
					leitner: {
						key: 'mode:course-leitner',
						title: t('learning', 'Leitner'),
						text: t('learning', 'Leitner focuses on spaced repetition. Hard cards return faster, mastered cards disappear for longer, and that makes it your main long-term retention mode.'),
						shortText: t('learning', 'Leitner repeats weak cards more often and mastered cards less often.'),
					},
					exam: {
						key: 'mode:course-exam',
						title: t('learning', 'Exam'),
						text: t('learning', 'Exam mode hides feedback until the end so you can simulate a real test run. Use it when you want pressure, pacing and a cleaner score signal.'),
						shortText: t('learning', 'Exam mode saves feedback until the end of the run.'),
					},
					leaderboard: {
						key: 'mode:course-leaderboard',
						title: t('learning', 'Leaderboard'),
						text: t('learning', 'The leaderboard compares course activity and mastery. It is useful for spotting momentum, not just raw ranking, especially when active-only filtering is enabled.'),
						shortText: t('learning', 'The leaderboard compares XP, mastery and recent activity inside the course.'),
					},
					league: {
						key: 'mode:course-league',
						title: t('learning', 'Liga'),
						text: t('learning', 'Liga tracks longer-running competitive progress across the course. It is less about one session and more about sustained learning performance over time.'),
						shortText: t('learning', 'Liga highlights sustained course progress over time.'),
					},
					arena: {
						key: 'mode:course-arena',
						title: t('learning', 'Arena'),
						text: t('learning', 'Arena is for competitive formats like duel, sprint and elimination. Use it when you want speed, pressure and direct comparison with other learners.'),
						shortText: t('learning', 'Arena bundles the competitive learning modes.'),
					},
					abenteuer: {
						key: 'mode:course-abenteuer',
						title: t('learning', 'Abenteuer'),
						text: t('learning', 'Adventure mode wraps learning in narrative progression. It is useful when you want more atmosphere and a slower, guided rhythm than raw quiz mode.'),
						shortText: t('learning', 'Adventure mode adds narrative progression around the questions.'),
					},
					'my-progress': {
						key: 'mode:course-my-progress',
						title: t('learning', 'My Progress'),
						text: t('learning', 'This area summarizes your own learning state in the course. Use it to see where mastery is building and where gaps still remain.'),
						shortText: t('learning', 'My Progress shows your current state across the course.'),
					},
					summary: {
						key: 'mode:course-summary',
						title: t('learning', 'Kursabschluss'),
						text: t('learning', 'This area condenses your course progress into a final overview with mastery, streaks, badges and the questions that still need work.'),
						shortText: t('learning', 'Kursabschluss zeigt deinen zusammengefassten Stand für den Kurs.'),
					},
				}
				return guides[tabId] || null
			},
			selectTab(tabId) {
				const resolvedTabId = this.resolveSelectableTab(tabId)
				this.currentTab = resolvedTabId
				this.$root.$emit('course:tab-change', resolvedTabId)
				if (resolvedTabId !== 'arena') {
					this.arenaSubMode = null
					this.oldschoolSubMode = null
				}
				if (resolvedTabId === 'arena' && !this.isInstructor) {
					this.$root.$emit('virtuprof:trigger', 'arena-first-visit')
				}
				if (resolvedTabId === 'league' && !this.isInstructor) {
					this.$root.$emit('virtuprof:trigger', 'liga-first-visit')
				}
			},
			onArenaSelectMode(mode) {
				this.arenaSubMode = mode
				if (this.isInstructor) {
					return
				}
				if (mode === 'sprint') {
					this.$root.$emit('virtuprof:trigger', 'gameshow-sprint-first-start')
				} else if (mode === 'elimination') {
					this.$root.$emit('virtuprof:trigger', 'gameshow-elimination-first-start')
				}
			},
			onOldschoolSelectMode(mode) {
				this.oldschoolSubMode = mode
			},

			progressPercent(prog) {
				const total = prog.total_questions || 0
				const answered = prog.answered || 0
				const mastered = prog.mastered || 0
				if (this.myProgressMode === 'answered') {
					return total > 0 ? Math.round(Math.min(answered, total) / total * 100) : 0
				}
				return total > 0 ? Math.round(Math.min(mastered, total) / total * 100) : 0
			},

			progressMetaText(prog) {
				if (this.myProgressMode === 'answered') {
					return t('learning', '{n} mastered', { n: prog.mastered || 0 })
				}
				return t('learning', '{n} answered', { n: prog.answered || 0 })
			},

			normalizeModeConfig(modeConfig = {}) {
				return Object.assign({
					training: true,
					leitner: true,
					swipe: true,
					exam: true,
					duel: true,
					gameshow: true,
					league: true,
					oldschool: true,
					abenteuer: false,
					course_summary: false,
				}, modeConfig || {})
			},

			async fetchCourseDetail() {
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}', { courseId: this.courseId })
				const response = await axios.get(url)
				this.course = {
					...response.data,
					mode_config: this.normalizeModeConfig(response.data?.mode_config || {}),
				}
				this.coursePools = response.data.pools || []
				this.courseMembers = response.data.members || []

				// Default tab for students
				if (!this.course.is_instructor) {
					this.currentTab = this.presetDuelCode ? 'arena' : 'training'
					this.arenaSubMode = this.presetDuelCode ? 'duel' : null
					this.activeLearningMode = 'training'
					this.selectedLearningPool = null
					this.fetchStudentProgress()
				} else if (this.presetDuelCode) {
					this.currentTab = 'arena'
					this.arenaSubMode = 'duel'
				}
				this.$nextTick(() => {
					this.emitVirtuProfContext()
				})
			} catch (err) {
				console.error('Failed to fetch course detail:', err)
				if (err.response?.status === 404) {
					this.error = t('learning', 'Course not found.')
				} else if (err.response?.status === 403) {
					this.error = t('learning', 'You do not have access to this course.')
				} else {
					this.error = t('learning', 'Failed to load course details. Please try again.')
				}
			} finally {
				this.loading = false
			}
		},

		async fetchStudentProgress() {
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/my-progress', { courseId: this.courseId })
				const response = await axios.get(url)
				const data = response.data
				let pools = []
				if (Array.isArray(data)) {
					pools = data
				} else if (data && data.pools) {
					pools = data.pools
				}
				// Compute mastery percentage from mastered/total_questions
				this.studentProgress = pools.map(p => ({
					...p,
					mastery: p.total_questions > 0 ? Math.round((p.mastered || 0) / p.total_questions * 100) : 0,
				}))
			} catch (err) {
				// Student progress is optional, do not block the view
				console.error('Failed to fetch student progress:', err)
			}
		},

		async fetchProgress() {
			this.progressLoading = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/progress', { courseId: this.courseId })
				const response = await axios.get(url, {
					params: {
						limit: this.progressMeta.limit || this.progressPageSize,
						offset: this.progressMeta.offset || 0,
						sortKey: this.progressSortKey || 'total_xp',
						sortDir: this.progressSortAsc ? 'asc' : 'desc',
					},
				})
				// FIX-HI-3: Backend returns {students: [...]} not a plain array
				const data = response.data
				if (Array.isArray(data)) {
					this.progressData = data
					this.progressMeta = {
						total: data.length,
						limit: this.progressPageSize,
						offset: 0,
					}
				} else if (data && Array.isArray(data.students)) {
					this.progressData = data.students
					this.progressMeta = {
						total: Number(data.meta?.total || data.students.length || 0),
						limit: Number(data.meta?.limit || this.progressPageSize),
						offset: Number(data.meta?.offset || 0),
					}
				} else {
					this.progressData = []
					this.progressMeta = {
						total: 0,
						limit: this.progressPageSize,
						offset: 0,
					}
				}
			} catch (err) {
				console.error('Failed to fetch progress:', err)
				this.error = t('learning', 'Failed to load progress data.')
			} finally {
				this.progressLoading = false
			}
		},

		exportAtRiskCsv() {
			const url = generateUrl('/apps/learning/api/courses/{courseId}/at-risk/export/csv', { courseId: this.courseId })
			window.location.href = url
		},

		async addMember() {
			const username = this.newMemberUsername.trim()
			if (!username) {
				return
			}

			this.addingMember = true
			this.memberError = ''
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/members', { courseId: this.courseId })
				await axios.post(url, { userId: username })
				this.newMemberUsername = ''
				await this.fetchCourseDetail()
			} catch (err) {
				console.error('Failed to add member:', err)
				const message = err.response?.data?.error || err.response?.data?.message
				if (err.response?.status === 404) {
					this.memberError = t('learning', 'User "{name}" not found.', { name: username })
				} else if (err.response?.status === 409) {
					this.memberError = t('learning', 'User "{name}" is already a member.', { name: username })
				} else {
					this.memberError = message || t('learning', 'Failed to add member.')
				}
			} finally {
				this.addingMember = false
			}
		},

		async toggleMemberRole(member) {
			const newRole = member.role === 'student' ? 'instructor' : 'student'
			this.savingMember = member.id
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/members', { courseId: this.courseId })
				await axios.post(url, { userId: member.user_id, role: newRole })
				await this.fetchCourseDetail()
			} catch (err) {
				console.error('Failed to update member role:', err)
				this.memberError = t('learning', 'Failed to update member role.')
			} finally {
				this.savingMember = null
			}
		},

		confirmRemoveMember(member) {
			this.removingMember = member
			this.showRemoveMemberModal = true
		},

		async removeMember() {
			if (!this.removingMember) {
				return
			}

			this.savingMember = this.removingMember.id
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/members/{memberId}', {
					courseId: this.courseId,
					memberId: this.removingMember.id,
				})
				await axios.delete(url)
				this.showRemoveMemberModal = false
				this.removingMember = null
				await this.fetchCourseDetail()
				if (this.currentTab === 'leaderboard') {
					await this.fetchLeaderboard()
				}
			} catch (err) {
				console.error('Failed to remove member:', err)
				this.memberError = t('learning', 'Failed to remove member.')
			} finally {
				this.savingMember = null
			}
		},

		getPoolMastery(row, poolId) {
			if (!row.pools || !Array.isArray(row.pools)) {
				return null
			}
			// FIX-HI-3: pools is an array, find by pool_id; compute mastery from mastered/total_questions
			const pool = row.pools.find(p => p.pool_id === poolId)
			if (!pool) {
				return null
			}
			if (pool.total_questions > 0) {
				return Math.round((pool.mastered || 0) / pool.total_questions * 100)
			}
			return 0
		},

		masteryClass(mastery) {
			if (mastery === null || mastery === undefined) {
				return ''
			}
			if (mastery >= 80) {
				return 'mastery-high'
			}
			if (mastery >= 40) {
				return 'mastery-medium'
			}
			return 'mastery-low'
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

		formatLastActive(dateStr) {
			return formatRelativeDateString(dateStr)
		},

		setProgressSort(key) {
			if (this.progressSortKey === key) {
				this.progressSortAsc = !this.progressSortAsc
			} else {
				this.progressSortKey = key
				this.progressSortAsc = key === 'user_id' || key === 'last_activity_date'
			}
			this.progressMeta.offset = 0
			this.fetchProgress()
		},

		pageProgressPrev() {
			if (!this.canPagePrev || this.progressLoading) return
			this.progressMeta.offset = Math.max(0, this.progressMeta.offset - this.progressMeta.limit)
			this.fetchProgress()
		},

		pageProgressNext() {
			if (!this.canPageNext || this.progressLoading) return
			this.progressMeta.offset += this.progressMeta.limit
			this.fetchProgress()
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

		async fetchAtRisk() {
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/at-risk', { courseId: this.courseId })
				const response = await axios.get(url)
				this.atRiskStudents = response.data.at_risk || []
			} catch (err) {
				// At-risk is supplementary, don't block the view
				console.error('Failed to fetch at-risk students:', err)
			}
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
				this.error = t('learning', 'Failed to load leaderboard.')
			} finally {
				this.leaderboardLoading = false
			}
		},

		async fetchTelosAggregate() {
			this.telosAggregateLoading = true
			this.telosAggregateError = ''
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/telos', { courseId: this.courseId })
				const response = await axios.get(url)
				this.telosAggregate = {
					total: Number(response.data?.total || 0),
					onboarded: Number(response.data?.onboarded || 0),
					avg_hours_per_week: Number(response.data?.avg_hours_per_week || 0),
					experience_levels: response.data?.experience_levels || {},
					target_certs: response.data?.target_certs || {},
					upcoming_exams: Array.isArray(response.data?.upcoming_exams) ? response.data.upcoming_exams : [],
				}
			} catch (err) {
				console.error('Failed to fetch class profile:', err)
				this.telosAggregateError = t('learning', 'Failed to load class profile.')
			} finally {
				this.telosAggregateLoading = false
			}
		},
		sortedDistributionEntries(source) {
			if (!source || typeof source !== 'object') {
				return []
			}
			return Object.entries(source)
				.map(([key, value]) => ({ key, value: Number(value || 0) }))
				.sort((left, right) => right.value - left.value || left.key.localeCompare(right.key))
		},

		formatDate(timestamp) {
			if (!timestamp) {
				return ''
			}
			try {
				// Backend returns Unix timestamps in seconds
				const ts = typeof timestamp === 'number' ? timestamp * 1000 : Date.parse(timestamp)
				const date = new Date(ts)
				if (isNaN(date.getTime())) {
					return String(timestamp)
				}
				return date.toLocaleDateString(undefined, {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
				})
			} catch {
				return String(timestamp)
			}
		},

		formatTimestamp(timestamp) {
			if (!timestamp) return ''
			try {
				return new Date(timestamp * 1000).toLocaleTimeString()
			} catch {
				return String(timestamp)
			}
		},

		async fetchHeatmap() {
			if (!this.course) return
			this.loadingHeatmap = true
			try {
				const res = await axios.get(generateUrl(`/apps/learning/api/courses/${this.courseId}/chapter-heatmap`))
				this.heatmapChapters = res.data.heatmap || res.data || []
			} catch (e) {
				console.error('Failed to load heatmap', e)
			} finally {
				this.loadingHeatmap = false
			}
		},

		toggleExpandChapter(key) {
			this.expandedChapter = this.expandedChapter === key ? null : key
		},

		async fetchWeakQuestions() {
			if (!this.course) return
			this.loadingWeakQuestions = true
			try {
				const res = await axios.get(generateUrl(`/apps/learning/api/courses/${this.courseId}/weak-questions`))
				this.weakQuestions = res.data.questions || res.data || []
			} catch (e) {
				console.error('Failed to load weak questions', e)
			} finally {
				this.loadingWeakQuestions = false
			}
		},

		async toggleQuestionPause(q) {
			try {
				await axios.post(generateUrl(`/apps/learning/api/courses/${this.courseId}/questions/${q.question_id}/override`), {
					paused: !q.is_paused,
					highlight: false,
				})
				q.is_paused = !q.is_paused
			} catch (e) {
				console.error('Failed to update question override', e)
			}
		},

	},
}
</script>

<style scoped>
.course-detail {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

/* Header */
.course-detail-header {
	margin-bottom: 24px;
}

.header-title {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 8px;
}

.header-title h3 {
	margin: 0;
	font-size: 1.4em;
	font-weight: 700;
	color: var(--color-main-text);
}

.course-description {
	margin: 8px 0 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.95em;
	line-height: 1.5;
}

.status-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.03em;
	white-space: nowrap;
}

.status-badge.active {
	background: color-mix(in srgb, var(--color-success) 15%, transparent);
	color: var(--color-success);
}

.status-badge.archived {
	background: var(--color-background-dark);
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

.detail-error {
	margin-bottom: 16px;
}

/* Tab selector */
.tab-selector {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 24px;
	border-bottom: 2px solid var(--color-border);
	padding-bottom: 0;
}

.tab-button {
	padding: 10px 20px;
	border: none;
	background: none;
	cursor: pointer;
	font-size: 0.95em;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	border-bottom: 2px solid transparent;
	margin-bottom: -2px;
	transition: color 0.15s, border-color 0.15s;
	white-space: nowrap;
}

.tab-button:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.tab-button.active {
	color: var(--color-primary-element);
	border-bottom-color: var(--color-primary-element);
	font-weight: 600;
}

.tab-group-separator {
	width: 1px;
	height: 24px;
	background: var(--color-border);
	margin: 0 8px;
	align-self: center;
	flex-shrink: 0;
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

/* Pool list */
.pool-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.course-pool-help,
.required-lock-note {
	margin-bottom: 12px;
}

.pool-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	cursor: pointer;
	transition: transform 0.2s, background 0.15s, box-shadow 0.2s;
}

.pool-item:hover {
	background: var(--color-background-hover);
	transform: translateY(-1px);
	box-shadow: 0 3px 10px color-mix(in srgb, var(--color-main-text) 6%, transparent);
}

.pool-item-locked {
	opacity: 0.65;
	cursor: not-allowed;
}

.pool-item-locked:hover {
	transform: none;
	box-shadow: none;
}

.pool-sort-order {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	font-weight: 600;
	min-width: 24px;
	flex-shrink: 0;
}

.pool-info {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.pool-name {
	font-weight: 600;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.pool-questions {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.pool-meta-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.pool-language-summary {
	font-size: 0.76em;
	font-weight: 700;
	letter-spacing: 0.04em;
	color: var(--color-primary-element);
	white-space: nowrap;
}

.pool-badges {
	display: flex;
	gap: 6px;
	flex-shrink: 0;
}

.required-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.75em;
	font-weight: 600;
	background: color-mix(in srgb, var(--color-warning) 15%, transparent);
	color: var(--color-warning);
	white-space: nowrap;
}

.required-enforced-badge {
	background: color-mix(in srgb, var(--color-error) 12%, transparent);
	color: var(--color-error);
}

.filter-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.75em;
	font-weight: 600;
	background: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	color: var(--color-primary-element);
	white-space: nowrap;
}

.pool-rules-btn {
	flex-shrink: 0;
}

.remove-pool-btn {
	flex-shrink: 0;
	font-size: 1.3em;
	line-height: 1;
	color: var(--color-text-maxcontrast);
}

.remove-pool-btn:hover {
	color: var(--color-error);
}

/* Student own progress */
.student-own-progress {
	margin-top: 32px;
}

.student-progress-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	margin-bottom: 12px;
}

.student-own-progress h4 {
	margin: 0;
	font-size: 1.1em;
	font-weight: 600;
	color: var(--color-main-text);
}

.progress-mode-switch {
	display: inline-flex;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	overflow: hidden;
}

.progress-mode-btn {
	border: 0;
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	padding: 6px 10px;
	font-size: 0.78em;
	font-weight: 600;
	cursor: pointer;
}

.pool-item-loading {
	opacity: 0.7;
}

.progress-mode-btn + .progress-mode-btn {
	border-left: 1px solid var(--color-border);
}

.progress-mode-btn.active {
	background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
	color: var(--color-primary-element);
}

.progress-mode-help {
	margin: 0 0 10px 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.82em;
}

.progress-bars {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.progress-row {
	display: flex;
	align-items: center;
	gap: 12px;
}

.progress-pool-name {
	width: 140px;
	flex-shrink: 0;
	font-size: 0.9em;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.progress-bar-container {
	flex: 1;
	height: 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	overflow: hidden;
}

.progress-bar-fill {
	height: 100%;
	border-radius: 6px;
	transition: width 0.3s ease;
}

.progress-bar-fill.mastery-high {
	background: var(--color-success);
}

.progress-bar-fill.mastery-medium {
	background: var(--color-warning);
}

.progress-bar-fill.mastery-low {
	background: var(--color-error);
}

.progress-percent {
	width: 44px;
	flex-shrink: 0;
	text-align: right;
	font-size: 0.85em;
	font-weight: 600;
	color: var(--color-main-text);
}

.progress-meta {
	width: 96px;
	flex-shrink: 0;
	text-align: right;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

/* Members section */
.add-member-form {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	margin-bottom: 16px;
}

.add-member-form .nc-text-field {
	flex: 1;
}

.member-error {
	margin-bottom: 12px;
}

.member-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.member-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	transition: transform 0.2s, box-shadow 0.2s;
}

.member-item:hover {
	transform: translateY(-1px);
	box-shadow: 0 2px 8px color-mix(in srgb, var(--color-main-text) 6%, transparent);
}

.member-info {
	display: flex;
	align-items: center;
	gap: 10px;
	flex: 1;
	min-width: 0;
}

.member-avatar {
	width: 32px;
	height: 32px;
	border-radius: 50%;
	background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
	color: var(--color-primary-element);
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: 700;
	font-size: 14px;
	flex-shrink: 0;
}

.member-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.member-role-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: capitalize;
	white-space: nowrap;
}

.member-role-badge.instructor {
	background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
	color: var(--color-primary-element);
}

.member-role-badge.student {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.member-date {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.member-actions {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

/* Progress table */
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

.pool-col,
.overall-col {
	min-width: 80px;
}

.overall-col {
	font-weight: 600;
}

td.mastery-high {
	background: color-mix(in srgb, var(--color-success) 10%, transparent);
	color: var(--color-success);
	font-weight: 700;
	border-inline-start: 3px solid var(--color-success);
}

td.mastery-medium {
	background: color-mix(in srgb, var(--color-warning) 10%, transparent);
	color: var(--color-warning);
	font-weight: 700;
	border-inline-start: 3px solid var(--color-warning);
}

td.mastery-low {
	background: color-mix(in srgb, var(--color-error) 10%, transparent);
	color: var(--color-error);
	font-weight: 700;
	border-inline-start: 3px solid var(--color-error);
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

/* Sortable columns */
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

/* Stat columns */
.stat-col {
	min-width: 70px;
	text-align: center;
	white-space: nowrap;
}

/* Clickable rows */
.clickable-row {
	cursor: pointer;
}

.clickable-row:hover td {
	background: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
	transition: background 0.15s;
}

/* Leaderboard */
.rank-col {
	width: 44px;
	text-align: center !important;
	font-weight: 600;
}

.rank-medal {
	font-size: 1.4em;
}

/* Top 3 row glow */
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

.last-active-col {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

/* Modal styles */
.modal-content {
	padding: 24px;
	min-width: 320px;
}

.modal-content h3 {
	margin: 0 0 20px 0;
	font-size: 1.2em;
	font-weight: 700;
	color: var(--color-main-text);
}

.modal-error {
	margin-bottom: 16px;
}

.rules-pool-name {
	margin: -8px 0 16px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.rules-field {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.rules-checkbox {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	cursor: pointer;
}

.rules-help {
	margin: 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

/* Pool select in modal */
.pool-select-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-height: 400px;
	overflow-y: auto;
}

.pool-select-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	cursor: pointer;
	transition: background 0.15s;
}

.pool-select-item:hover:not(.disabled) {
	background: var(--color-background-hover);
}

.pool-select-item.selected {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.pool-select-item.disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.pool-add-confirm {
	display: flex;
	justify-content: flex-end;
	padding-top: 8px;
}

.pool-select-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.pool-select-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.pool-select-desc {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.pool-already-added {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	flex-shrink: 0;
}

.pool-check {
	font-size: 1.1em;
	color: var(--color-primary-element);
	font-weight: 700;
	flex-shrink: 0;
}

/* Responsive */
@media (max-width: 768px) {
	.course-detail {
		padding: 12px;
	}

	.tab-selector {
		gap: 2px;
	}

	.tab-button {
		padding: 8px 14px;
		font-size: 0.9em;
	}

	.section-header {
		flex-direction: column;
		align-items: flex-start;
		gap: 8px;
	}

	.member-item {
		flex-direction: column;
		align-items: flex-start;
	}

	.member-actions {
		width: 100%;
		justify-content: flex-end;
	}

	.add-member-form {
		flex-direction: column;
		align-items: stretch;
	}

	.progress-pool-name {
		width: 100px;
	}

	.modal-content {
		padding: 16px;
		min-width: unset;
	}

	.pool-select-list {
		max-height: 300px;
	}
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

/* At-Risk Section */
.at-risk-section {
	margin-bottom: 24px;
	border: 2px solid var(--color-error);
	border-radius: 12px;
	padding: 16px 20px;
	background: color-mix(in srgb, var(--color-error) 5%, var(--color-main-background));
}
.at-risk-section.collapsed {
	padding-bottom: 16px;
}
.at-risk-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	cursor: pointer;
}
.at-risk-title {
	margin: 0;
	font-size: 16px;
	font-weight: 700;
	color: var(--color-error);
}
.at-risk-toggle {
	background: none;
	border: none;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 4px 8px;
}
.at-risk-cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 12px;
	margin-top: 12px;
}
.at-risk-card {
	border: 1px solid var(--color-border);
	border-radius: 10px;
	padding: 14px 16px;
	background: var(--color-main-background);
	cursor: pointer;
	transition: box-shadow 0.2s, transform 0.15s;
}
.at-risk-card:hover {
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
	transform: translateY(-1px);
}
.at-risk-card.risk-high {
	border-inline-start: 4px solid var(--color-error);
}
.at-risk-card.risk-medium {
	border-inline-start: 4px solid var(--color-warning);
}
.at-risk-card-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}
.at-risk-name {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
}
.risk-badge {
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
}
.risk-badge.high {
	background: color-mix(in srgb, var(--color-error) 15%, transparent);
	color: var(--color-error);
	border: 1px solid var(--color-error);
}
.risk-badge.medium {
	background: color-mix(in srgb, var(--color-warning) 15%, transparent);
	color: var(--color-warning);
	border: 1px solid var(--color-warning);
}
.at-risk-reasons {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-bottom: 8px;
}
.risk-reason-tag {
	padding: 2px 8px;
	border-radius: 6px;
	font-size: 11px;
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
}
.at-risk-meta {
	display: flex;
	gap: 12px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

@media (prefers-reduced-motion: reduce) {
	.pool-item:hover,
	.member-item:hover { transform: none; }
	.streak-flame { animation: none; }
	.at-risk-card:hover { transform: none; }
}

/* Curriculum Scope */
.curriculum-section { padding: 24px 0; max-width: 720px; }
.curriculum-header h3 { font-size: 20px; font-weight: 600; margin-bottom: 8px; }
.curriculum-desc { color: var(--color-text-maxcontrast); margin-bottom: 20px; font-size: 14px; line-height: 1.5; }
.curriculum-loading { display: flex; justify-content: center; padding: 40px; }
.curriculum-toggle { margin-bottom: 20px; }
.curriculum-chapters { border: 1px solid var(--color-border); border-radius: 12px; padding: 16px; margin-bottom: 20px; }
.curriculum-empty { color: var(--color-text-maxcontrast); font-size: 14px; text-align: center; padding: 20px; }
.curriculum-select-actions { display: flex; gap: 8px; margin-bottom: 12px; }
.curriculum-chapter-row { padding: 6px 0; border-bottom: 1px solid var(--color-border-dark); }
.curriculum-chapter-row:last-child { border-bottom: none; }
.chapter-title { font-size: 14px; }
.chapter-order { font-size: 12px; color: var(--color-text-maxcontrast); margin-inline-start: 8px; }
.curriculum-actions { display: flex; align-items: center; gap: 12px; }
.curriculum-saved-hint { font-size: 13px; color: var(--color-success); font-weight: 600; }

/* Shared tab content */
.tab-content { padding: 16px 0; }
.loading-hint, .empty-hint { color: var(--color-text-maxcontrast); padding: 24px; text-align: center; }
.section-hint { color: var(--color-text-maxcontrast); margin-bottom: 16px; font-size: 0.9em; }
.nc-textarea { width: 100%; margin: 8px 0; padding: 8px; border: 1px solid var(--color-border); border-radius: 4px; background: var(--color-main-background); font-family: inherit; font-size: inherit; box-sizing: border-box; }
.nc-select-cd { width: 100%; padding: 6px 8px; border: 1px solid var(--color-border); border-radius: 4px; background: var(--color-main-background); font-size: inherit; }

/* Heatmap */
.heatmap-section {}
.heatmap-list { display: flex; flex-direction: column; gap: 16px; }
.heatmap-row { padding-bottom: 16px; border-bottom: 1px solid var(--color-border); }
.heatmap-meta { display: flex; justify-content: space-between; margin-bottom: 6px; }
.chapter-title-hm { font-weight: 600; }
.heatmap-stats { color: var(--color-text-maxcontrast); font-size: 0.9em; }
.heatmap-bar-bg { height: 12px; background: var(--color-background-dark); border-radius: 6px; overflow: hidden; margin-bottom: 6px; }
.heatmap-bar-fill { height: 100%; border-radius: 6px; transition: width 0.3s; }
.heatmap-bar-fill.red { background: var(--color-error); }
.heatmap-bar-fill.yellow { background: #e6a817; }
.heatmap-bar-fill.green { background: var(--color-success); }
.wrong-questions { background: var(--color-background-dark); padding: 8px 12px; border-radius: 4px; margin-bottom: 6px; }
.wrong-q-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 0.9em; }
.wrong-q-rate { color: var(--color-error); font-weight: 600; }

/* Weak questions */
.weak-questions-table { width: 100%; border-collapse: collapse; }
.weak-questions-table th, .weak-questions-table td { padding: 8px 12px; border-bottom: 1px solid var(--color-border); text-align: start; }
.weak-questions-table th { font-weight: 600; background: var(--color-background-dark); }
.q-text { max-width: 300px; }
.paused-row { opacity: 0.5; }
.rate-red { color: var(--color-error); font-weight: 700; }
.rate-yellow { color: #e6a817; font-weight: 600; }


/* Class profile */
.class-profile-note { margin-bottom: 16px; }
.class-profile-body { display: grid; gap: 16px; }
.class-profile-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
.class-profile-card,
.class-profile-panel { border: 1px solid var(--color-border); border-radius: 10px; background: var(--color-main-background); padding: 14px; }
.class-profile-label { display: block; color: var(--color-text-maxcontrast); font-size: 0.85em; margin-bottom: 6px; }
.class-profile-value { font-size: 1.6em; line-height: 1.1; }
.class-profile-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
.class-profile-panel h5 { margin: 0 0 10px; font-size: 1em; }
.class-profile-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
.class-profile-list li { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.class-profile-empty { margin: 0; color: var(--color-text-maxcontrast); font-size: 0.9em; }

/* AdminSettings ticket filter note */
.ticket-filter-note { margin-bottom: 8px; }

/* Talk link in header */
.talk-room-link {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--color-primary);
	text-decoration: none;
	font-size: 0.9em;
	margin-top: 4px;
}
.talk-room-link:hover { text-decoration: underline; }

</style>
