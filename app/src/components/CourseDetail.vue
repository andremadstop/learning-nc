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
				<button v-for="tab in visibleTabs"
					:key="tab.id"
					class="tab-button"
					:class="{ active: currentTab === tab.id }"
					@click="selectTab(tab.id)">
					{{ tab.label }}
				</button>
			</div>

			<!-- Pools Tab -->
			<div v-if="currentTab === 'pools'" class="pools-section">
				<div class="section-header">
					<h4>{{ t('learning', 'Course Pools') }}</h4>
					<NcButton v-if="isInstructor" @click="openAddPoolModal">
						{{ t('learning', '+ Add Pool') }}
					</NcButton>
				</div>

				<NcNoteCard v-if="isInstructor" type="info" class="course-pool-help">
					{{ t('learning', 'Students practice exactly the pools assigned here. Optional filters can limit a pool to one exam key, one chapter or explicit question IDs without changing the original pool. "Required + enforced" blocks optional pools until every filtered question in the required pool was answered at least once.') }}
				</NcNoteCard>

				<div v-if="coursePools.length > 0" class="pool-list">
					<div v-for="pool in sortedPools"
						:key="pool.id"
						class="pool-item"
						tabindex="0" role="button"
						@click="$emit('openPool', pool.pool_id)"
						@keydown.enter="$emit('openPool', pool.pool_id)"
						@keydown.space.prevent="$emit('openPool', pool.pool_id)">
						<span v-if="isInstructor" class="pool-sort-order">{{ (pool.sort_order || 0) + 1 }}.</span>
						<div class="pool-info">
							<span class="pool-name">{{ pool.pool_name }}</span>
							<div class="pool-meta-row">
								<span class="pool-questions">
									{{ t('learning', '{n} questions', { n: pool.question_count || 0 }) }}
								</span>
								<span v-if="poolLanguageSummary(pool)" class="pool-language-summary">
									{{ poolLanguageSummary(pool) }}
								</span>
							</div>
						</div>
						<div class="pool-badges">
							<span v-if="pool.required" class="required-badge">
								{{ t('learning', 'Required') }}
							</span>
							<span v-if="pool.required_enforced" class="required-badge required-enforced-badge">
								{{ t('learning', 'Enforced') }}
							</span>
							<span v-for="summary in poolRuleSummary(pool)" :key="pool.id + '-' + summary" class="filter-badge">
								{{ summary }}
							</span>
						</div>
						<NcButton v-if="isInstructor"
							type="tertiary"
							size="small"
							class="pool-rules-btn"
							@click.stop="openPoolRulesModal(pool)">
							{{ t('learning', 'Rules') }}
						</NcButton>
						<NcButton v-if="isInstructor"
							type="tertiary-no-background"
							class="remove-pool-btn"
							:aria-label="t('learning', 'Remove pool')"
							@click.stop="confirmRemovePool(pool)">
							&times;
						</NcButton>
					</div>
				</div>

				<NcEmptyContent v-if="coursePools.length === 0"
					:name="t('learning', 'No pools assigned')">
					<template #description>
						<span v-if="isInstructor">{{ t('learning', 'Add question pools to this course') }}</span>
						<span v-else>{{ t('learning', 'No question pools are available in this course yet.') }}</span>
					</template>
				</NcEmptyContent>

				<!-- Student progress per pool (student view) -->
				<div v-if="studentProgress.length > 0" class="student-own-progress">
					<div class="student-progress-header">
						<h4>{{ t('learning', 'My Progress') }}</h4>
						<div class="progress-mode-switch" role="group" :aria-label="t('learning', 'Progress mode')">
							<button
								class="progress-mode-btn"
								:class="{ active: myProgressMode === 'mastery' }"
								:aria-pressed="myProgressMode === 'mastery' ? 'true' : 'false'"
								@click="myProgressMode = 'mastery'">
								{{ t('learning', 'Mastery') }}
							</button>
							<button
								class="progress-mode-btn"
								:class="{ active: myProgressMode === 'answered' }"
								:aria-pressed="myProgressMode === 'answered' ? 'true' : 'false'"
								@click="myProgressMode = 'answered'">
								{{ t('learning', 'Answered') }}
							</button>
						</div>
					</div>
					<p class="progress-mode-help">
						{{ t('learning', 'Mastery = Box 5 cards. Answered = questions answered in completed sessions.') }}
					</p>
					<div class="progress-bars">
						<div v-for="prog in studentProgress"
							:key="prog.pool_id"
							class="progress-row">
							<span class="progress-pool-name">{{ prog.pool_name }}</span>
							<div class="progress-bar-container">
								<div class="progress-bar-fill"
									:class="masteryClass(progressPercent(prog))"
									:style="{ width: progressPercent(prog) + '%' }" />
							</div>
							<span class="progress-percent">{{ progressPercent(prog) }}%</span>
							<span class="progress-meta">{{ progressMetaText(prog) }}</span>
						</div>
					</div>
				</div>
			</div>

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

			<div v-if="isStudentLearningTab" class="student-learning-section">
				<div v-if="!selectedLearningPool" class="pools-section">
					<div class="section-header">
						<h4>{{ activeLearningModeLabel }}</h4>
					</div>

					<div v-if="coursePools.length > 0" class="pool-list">
						<div v-for="pool in sortedPools"
							:key="'learning-' + pool.pool_id"
							class="pool-item"
							:class="{ 'pool-item-loading': loadingLearningPoolId === pool.pool_id, 'pool-item-locked': pool.locked_for_student }"
							tabindex="0"
							role="button"
							@click="selectLearningPool(pool)"
							@keydown.enter="selectLearningPool(pool)"
							@keydown.space.prevent="selectLearningPool(pool)">
							<div class="pool-info">
								<span class="pool-name">{{ pool.pool_name }}</span>
								<div class="pool-meta-row">
									<span class="pool-questions">
										{{ t('learning', '{n} questions', { n: getLearningPoolQuestionCount(pool) }) }}
									</span>
									<span v-if="poolLanguageSummary(pool)" class="pool-language-summary">
										{{ poolLanguageSummary(pool) }}
									</span>
								</div>
							</div>
							<div class="pool-badges">
								<span v-if="pool.required" class="required-badge">
									{{ t('learning', 'Required') }}
								</span>
								<span v-if="pool.required_enforced" class="required-badge required-enforced-badge">
									{{ t('learning', 'Enforced') }}
								</span>
								<span v-for="summary in poolRuleSummary(pool)" :key="'student-' + pool.pool_id + '-' + summary" class="filter-badge">
									{{ summary }}
								</span>
							</div>
						</div>
					</div>

					<NcNoteCard v-if="currentRequiredBlockers.length > 0" type="warning" class="required-lock-note">
						{{ t('learning', 'Optional pools are locked until these required pools are completed once: {names}', { names: currentRequiredBlockers.join(', ') }) }}
					</NcNoteCard>

					<NcEmptyContent v-else
						:name="t('learning', 'No pools assigned')">
						<template #description>
							{{ t('learning', 'No question pools are available in this course yet.') }}
						</template>
					</NcEmptyContent>
				</div>

					<TrainingMode
						v-else-if="activeLearningMode === 'training'"
						:poolId="selectedLearningPool.pool_id"
						:courseId="courseId"
						:totalQuestions="selectedLearningPoolQuestionCount"
						:contentLanguage="contentLanguage"
						@back="resetLearningPoolSelection" />

					<LeitnerMode
						v-else-if="activeLearningMode === 'leitner'"
						:poolId="selectedLearningPool.pool_id"
						:courseId="courseId"
						:contentLanguage="contentLanguage"
						@back="resetLearningPoolSelection" />

					<TrainingMode
						v-else-if="activeLearningMode === 'swipe'"
						:poolId="selectedLearningPool.pool_id"
						:courseId="courseId"
						:totalQuestions="selectedLearningPoolQuestionCount"
						:wfMode="true"
						:contentLanguage="contentLanguage"
						@back="resetLearningPoolSelection" />

					<ExamMode
						v-else-if="activeLearningMode === 'exam'"
						:poolId="selectedLearningPool.pool_id"
						:courseId="courseId"
						:totalQuestions="selectedLearningPoolQuestionCount"
						:contentLanguage="contentLanguage"
						@back="resetLearningPoolSelection" />
			</div>

			<!-- My Progress Tab (student self-view) -->
			<div v-if="currentTab === 'my-progress' && !isInstructor" class="my-progress-section">
				<StudentDetail
					:courseId="courseId"
					:studentId="myUserId"
					@back="currentTab = 'training'" />
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
					:gameMode="'sprint'"
					@back="arenaSubMode = null" />
				<GameshowMode
					v-else-if="arenaSubMode === 'elimination'"
					:courseId="courseId"
					:coursePools="coursePools"
					:contentLanguage="contentLanguage"
					:gameMode="'elimination'"
					@back="arenaSubMode = null" />
			</div>
		<!-- Curriculum Scope Tab (instructor only) -->
		<div v-if="currentTab === 'curriculum' && isInstructor" class="curriculum-section">
			<div class="curriculum-header">
				<h3>{{ t('learning', 'Themensteuerung') }}</h3>
				<p class="curriculum-desc">{{ t('learning', 'Lege fest, welche Kapitel aktuell im Kurs aktiv sind. Bei aktivem Filter werden Kurs-Duelle automatisch auf diese Kapitel eingeschränkt.') }}</p>
			</div>

			<div v-if="loadingCurriculum" class="curriculum-loading">
				<NcLoadingIcon :size="32" />
			</div>
			<template v-else>
				<div class="curriculum-toggle">
					<NcCheckboxRadioSwitch
						:checked="curriculumEnabled"
						@update:checked="curriculumEnabled = $event">
						{{ t('learning', 'Kapitel-Filter aktiv') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div v-if="curriculumEnabled" class="curriculum-chapters">
					<div v-if="curriculumAvailableChapters.length === 0" class="curriculum-empty">
						{{ t('learning', 'Keine Kapitelmetadaten gefunden. Importiere Fragen mit chapter_key-Werten, um diesen Filter zu nutzen.') }}
					</div>
					<template v-else>
						<div class="curriculum-select-actions">
							<NcButton size="small" @click="selectAllChapters">{{ t('learning', 'Alle') }}</NcButton>
							<NcButton size="small" @click="selectedChapterKeys = []">{{ t('learning', 'Keine') }}</NcButton>
						</div>
						<div
							v-for="chapter in curriculumAvailableChapters"
							:key="chapter.chapter_key"
							class="curriculum-chapter-row">
							<NcCheckboxRadioSwitch
								:checked="selectedChapterKeys.includes(chapter.chapter_key)"
								@update:checked="toggleChapter(chapter.chapter_key, $event)">
								<span class="chapter-title">{{ chapter.chapter_title || chapter.chapter_key }}</span>
								<span v-if="chapter.chapter_order" class="chapter-order">{{ t('learning', 'Kap. {n}', { n: chapter.chapter_order }) }}</span>
							</NcCheckboxRadioSwitch>
						</div>
					</template>
				</div>

				<div class="curriculum-actions">
					<NcButton type="primary" :disabled="savingCurriculum" @click="saveCurriculumScope">
						{{ savingCurriculum ? t('learning', 'Speichere…') : t('learning', 'Speichern') }}
					</NcButton>
					<span v-if="curriculumSaved" class="curriculum-saved-hint">✓ {{ t('learning', 'Gespeichert') }}</span>
				</div>
			</template>
		</div>

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

	<!-- Announcements Tab (instructor only) -->
	<div v-if="currentTab === 'announcements' && isInstructor" class="tab-content announcements-section">
		<div class="announcement-form">
			<h3>{{ t('learning', 'New announcement') }}</h3>
			<input v-model="newAnnouncementTitle" type="text" :placeholder="t('learning', 'Title')" class="nc-input announcement-input" />
			<textarea v-model="newAnnouncementBody" :placeholder="t('learning', 'Message...')" class="nc-textarea" rows="3" />
			<NcButton type="primary" :disabled="savingAnnouncement || !newAnnouncementTitle.trim()" @click="createAnnouncement">
				{{ t('learning', 'Publish') }}
			</NcButton>
		</div>
		<div class="announcements-list">
			<div v-if="announcements.length === 0" class="empty-hint">{{ t('learning', 'No announcements yet.') }}</div>
			<div v-for="a in announcements" :key="a.id" class="announcement-item">
				<div class="announcement-header">
					<strong>{{ a.title }}</strong>
					<NcButton type="tertiary" @click="deleteAnnouncement(a.id)">{{ t('learning', 'Delete') }}</NcButton>
				</div>
				<p class="announcement-body">{{ a.body }}</p>
				<small class="announcement-date">{{ formatDate(a.created_at) }}</small>
			</div>
		</div>
	</div>

	<!-- Exam Slot Tab (instructor only) -->
	<div v-if="currentTab === 'exam-slot' && isInstructor" class="tab-content exam-slot-section">
		<div v-if="activeExamSlot" class="active-slot-banner">
			<NcNoteCard type="warning">
				{{ t('learning', 'Exam is running!') }} {{ t('learning', 'Ends at:') }} {{ formatTimestamp(activeExamSlot.ends_at) }}
			</NcNoteCard>
			<NcButton type="error" @click="closeExamSlot">{{ t('learning', 'Close exam') }}</NcButton>
		</div>
		<div v-else class="start-slot-form">
			<h3>{{ t('learning', 'Start exam slot') }}</h3>
			<div class="form-row-cd">
				<label>{{ t('learning', 'Duration (minutes)') }}</label>
				<input v-model.number="examSlotDuration" type="number" min="10" max="300" class="nc-input" />
			</div>
			<div class="form-row-cd">
				<label>{{ t('learning', 'Question scope') }}</label>
				<select v-model="examSlotScope" class="nc-select-cd">
					<option value="all">{{ t('learning', 'All course questions') }}</option>
					<option value="curriculum">{{ t('learning', 'Active curriculum only') }}</option>
				</select>
			</div>
			<NcButton type="primary" :disabled="startingExamSlot" @click="startExamSlot">
				{{ t('learning', 'Start exam') }}
			</NcButton>
		</div>
	</div>

	<!-- Requests Tab (instructor only) -->
	<div v-if="currentTab === 'requests' && isInstructor" class="tab-content requests-section">
		<div v-if="loadingRequests" class="loading-hint">{{ t('learning', 'Loading...') }}</div>
		<div v-else-if="courseTickets.length === 0" class="empty-hint">
			{{ t('learning', 'No open requests for this course.') }}
		</div>
		<div v-else class="tickets-list-cd">
			<div v-for="ticket in courseTickets" :key="ticket.id" class="ticket-item-cd">
				<div class="ticket-header-cd">
					<span class="ticket-subject-cd">{{ ticket.subject }}</span>
					<span class="ticket-status-cd" :class="'status-' + ticket.status">{{ ticket.status }}</span>
				</div>
				<p class="ticket-message-cd">{{ ticket.message }}</p>
				<div v-if="ticket.status === 'open'" class="ticket-reply-cd">
					<textarea v-model="ticketReplies[ticket.id]" :placeholder="t('learning', 'Your answer...')" class="nc-textarea" rows="3" />
					<NcButton type="primary" @click="answerTicket(ticket.id)">{{ t('learning', 'Send answer') }}</NcButton>
				</div>
				<div v-if="ticket.answer_text" class="ticket-answer-cd">
					<strong>{{ t('learning', 'Answer:') }}</strong> {{ ticket.answer_text }}
				</div>
			</div>
		</div>
	</div>

	<!-- Mode Config Tab (instructor only) -->
	<div v-if="currentTab === 'mode-config' && isInstructor" class="tab-content mode-config-section">
		<h3>{{ t('learning', 'Kursregeln — Lernmodi') }}</h3>
		<p class="mode-config-hint">{{ t('learning', 'Deaktivierte Modi werden Studierenden nicht angezeigt. Training ist immer aktiv.') }}</p>
		<div class="mode-toggles">
			<div v-for="mode in modeConfigKeys" :key="mode.key" class="mode-toggle-row">
				<label class="mode-toggle-label">
					<input type="checkbox" :checked="modeConfigLocal[mode.key] !== false" @change="toggleMode(mode.key, $event.target.checked)" :disabled="mode.key === 'training'" />
					{{ mode.label }}
				</label>
			</div>
		</div>
		<NcButton type="primary" :disabled="savingModeConfig" @click="saveModeConfig">
			{{ savingModeConfig ? t('learning', 'Saving...') : t('learning', 'Save') }}
		</NcButton>
		<NcNoteCard v-if="modeConfigSaved" type="success" class="mode-config-saved">{{ t('learning', 'Saved.') }}</NcNoteCard>
	</div>

		</template>

		<!-- Add Pool Modal -->
		<NcModal
			v-if="showAddPoolModal"
			:show="showAddPoolModal"
			@update:show="onAddPoolModalShowChanged"
			@close="closeAddPoolModal"
			@closing="closeAddPoolModal">
			<div class="modal-content">
				<h3>{{ t('learning', 'Add Pool to Course') }}</h3>

				<div v-if="poolsLoading" class="loading-container">
					<NcLoadingIcon :size="44" />
					<p>{{ t('learning', 'Loading available pools...') }}</p>
				</div>

				<template v-else>
					<NcNoteCard v-if="poolModalError" type="error" class="modal-error">
						{{ poolModalError }}
					</NcNoteCard>

					<div v-if="availablePools.length > 0" class="pool-select-list">
						<div v-for="pool in availablePools"
							:key="pool.id"
							class="pool-select-item"
							:class="{ disabled: isPoolAlreadyAdded(pool.id), selected: selectedPoolIds.includes(pool.id) }"
							tabindex="0" role="checkbox"
							:aria-checked="selectedPoolIds.includes(pool.id)"
							@click="togglePoolSelection(pool)"
							@keydown.enter="togglePoolSelection(pool)"
							@keydown.space.prevent="togglePoolSelection(pool)">
							<div class="pool-select-info">
								<span class="pool-select-name">{{ pool.name }}</span>
								<span v-if="pool.description" class="pool-select-desc">{{ pool.description }}</span>
							</div>
							<span v-if="isPoolAlreadyAdded(pool.id)" class="pool-already-added">
								{{ t('learning', 'Already added') }}
							</span>
							<span v-else-if="selectedPoolIds.includes(pool.id)" class="pool-check">✓</span>
						</div>
						<div v-if="selectedPoolIds.length > 0" class="pool-add-confirm">
							<NcButton type="primary"
								:disabled="savingPool"
								@click="addSelectedPools">
								{{ savingPool
									? t('learning', 'Adding...')
									: t('learning', 'Add {n} pool(s)', { n: selectedPoolIds.length }) }}
							</NcButton>
						</div>
					</div>

					<NcEmptyContent v-else
						:name="t('learning', 'No pools available')">
						<template #description>
							{{ t('learning', 'Create question pools first before adding them to a course.') }}
						</template>
					</NcEmptyContent>
				</template>
			</div>
		</NcModal>

		<!-- Remove pool confirmation modal -->
			<NcModal v-if="showRemovePoolModal" @close="showRemovePoolModal = false" @closing="showRemovePoolModal = false" size="small">
			<div class="modal-content">
				<h3>{{ t('learning', 'Remove Pool') }}</h3>
				<p>{{ t('learning', 'Remove "{name}" from this course? Students will lose access to these questions.', { name: removingPool ? removingPool.pool_name : '' }) }}</p>
				<div class="modal-actions">
					<NcButton type="tertiary"
						:disabled="savingPool"
						@click="showRemovePoolModal = false">
						{{ t('learning', 'Cancel') }}
					</NcButton>
					<NcButton type="error"
						:disabled="savingPool"
						@click="removePool">
						{{ savingPool ? t('learning', 'Removing...') : t('learning', 'Remove') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

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

			<NcModal v-if="showPoolRulesModal" @close="closePoolRulesModal" @closing="closePoolRulesModal" size="normal">
				<div class="modal-content">
					<h3>{{ t('learning', 'Pool Rules') }}</h3>
					<p v-if="editingPoolRules" class="rules-pool-name">{{ editingPoolRules.pool_name }}</p>

					<NcNoteCard v-if="poolRulesError" type="error" class="modal-error">
						{{ poolRulesError }}
					</NcNoteCard>

					<div class="rules-field">
						<label class="rules-checkbox">
							<input v-model="poolRulesForm.required" type="checkbox">
							<span>{{ t('learning', 'Show this pool as required') }}</span>
						</label>
					</div>

					<div class="rules-field">
						<label class="rules-checkbox">
							<input v-model="poolRulesForm.requiredEnforced" type="checkbox" :disabled="!poolRulesForm.required">
							<span>{{ t('learning', 'Block optional pools until every filtered question here was answered once') }}</span>
						</label>
					</div>

					<div class="rules-field">
						<label for="pool-rule-exam">{{ t('learning', 'Exam key filter') }}</label>
						<select id="pool-rule-exam" v-model="poolRulesForm.filterExamKey" class="nc-input">
							<option value="">{{ t('learning', 'No exam filter') }}</option>
							<option v-for="examKey in currentPoolExamOptions" :key="examKey" :value="examKey">
								{{ examKey }}
							</option>
						</select>
					</div>

					<div class="rules-field">
						<label for="pool-rule-chapter">{{ t('learning', 'Chapter filter') }}</label>
						<select id="pool-rule-chapter" v-model="poolRulesForm.filterChapterKey" class="nc-input">
							<option value="">{{ t('learning', 'No chapter filter') }}</option>
							<option v-for="chapter in currentPoolChapterOptions" :key="chapter.key" :value="chapter.key">
								{{ chapter.order ? chapter.order + ' - ' + chapter.title : chapter.title }}
							</option>
						</select>
					</div>

					<div class="rules-field">
						<label for="pool-rule-question-ids">{{ t('learning', 'Specific question IDs') }}</label>
						<textarea id="pool-rule-question-ids"
							v-model="poolRulesForm.filterQuestionIdsText"
							rows="3"
							class="nc-input"
							:placeholder="t('learning', 'Optional comma-separated question IDs, e.g. 101, 102, 205')"></textarea>
						<p class="rules-help">
							{{ t('learning', 'Leave empty to use the full pool or the selected exam/chapter subset.') }}
						</p>
					</div>

					<div class="modal-actions">
						<NcButton type="tertiary" :disabled="savingPoolRules" @click="closePoolRulesModal">
							{{ t('learning', 'Cancel') }}
						</NcButton>
						<NcButton type="primary" :disabled="savingPoolRules" @click="savePoolRules">
							{{ savingPoolRules ? t('learning', 'Saving...') : t('learning', 'Save Rules') }}
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
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import { formatXp, formatRelativeDateString } from '../format.js'
import ExamMode from './ExamMode.vue'
import LeagueTab from './LeagueTab.vue'
import LeitnerMode from './LeitnerMode.vue'
import StudentDetail from './StudentDetail.vue'
import DuelMode from './DuelMode.vue'
import GameshowMode from './GameshowMode.vue'
import TrainingMode from './TrainingMode.vue'
import ArenaSelector from './ArenaSelector.vue'

export default {
	name: 'CourseDetail',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcModal,
		NcTextField,
		NcNoteCard,
		ExamMode,
		LeagueTab,
		LeitnerMode,
		StudentDetail,
		DuelMode,
		GameshowMode,
		TrainingMode,
		ArenaSelector,
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
			selectedLearningPool: null,
			activeLearningMode: null,
			poolQuestionCounts: {},
			loadingLearningPoolId: null,

			// Pool modal
			showAddPoolModal: false,
			poolsLoading: false,
			poolModalError: '',
			allPools: [],
			savingPool: false,
			showRemovePoolModal: false,
			removingPool: null,
			showPoolRulesModal: false,
			editingPoolRules: null,
			poolRulesError: '',
			savingPoolRules: false,
			poolRulesForm: {
				required: true,
				requiredEnforced: false,
				filterExamKey: '',
				filterChapterKey: '',
				filterQuestionIdsText: '',
			},

			// Curriculum scope
			loadingCurriculum: false,
			savingCurriculum: false,
			curriculumSaved: false,
			curriculumEnabled: false,
			selectedChapterKeys: [],
			curriculumAvailableChapters: [],

			// Members
			newMemberUsername: '',
			addingMember: false,
			memberError: '',
			savingMember: null,
			showRemoveMemberModal: false,
			removingMember: null,

			selectedPoolToAdd: null,
			selectedPoolIds: [],

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

			// Heatmap
			loadingHeatmap: false,
			heatmapChapters: [],
			expandedChapter: null,

			// Weak questions
			loadingWeakQuestions: false,
			weakQuestions: [],

			// Announcements
			announcements: [],
			newAnnouncementTitle: '',
			newAnnouncementBody: '',
			savingAnnouncement: false,

			// Exam slot
			activeExamSlot: null,
			examSlotDuration: 90,
			examSlotScope: 'all',
			startingExamSlot: false,

			// Course tickets
			loadingRequests: false,
			courseTickets: [],
			ticketReplies: {},
			// Mode Config
			modeConfigLocal: {},
			savingModeConfig: false,
			modeConfigSaved: false,

			// Arena sub-mode
			arenaSubMode: null, // 'duel' | 'sprint' | 'elimination' | null
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
		isStudentLearningTab() {
			return !this.isInstructor && ['training', 'leitner', 'swipe', 'exam'].includes(this.currentTab)
		},
		modeConfigKeys() {
			return [
				{ key: 'training', label: t('learning', 'Training (immer aktiv)') },
				{ key: 'leitner', label: t('learning', 'Leitner') },
				{ key: 'swipe', label: t('learning', 'Wahr/Falsch (Swipe)') },
				{ key: 'exam', label: t('learning', 'Exam') },
				{ key: 'duel', label: t('learning', 'Duell') },
				{ key: 'gameshow', label: t('learning', 'Gameshow') },
				{ key: 'league', label: t('learning', 'Liga') },
			]
		},
		visibleTabs() {
			if (this.isInstructor) {
				return [
					{ id: 'pools', label: t('learning', 'Pools') },
					{ id: 'members', label: t('learning', 'Members') },
					{ id: 'progress', label: t('learning', 'Progress') },
					{ id: 'leaderboard', label: t('learning', 'Leaderboard') },
					{ id: 'league', label: t('learning', 'Liga') },
					{ id: 'arena', label: t('learning', 'Arena') },
					{ id: 'curriculum', label: t('learning', 'Themen') },
					{ id: 'heatmap', label: t('learning', 'Heatmap') },
					{ id: 'weak-questions', label: t('learning', 'Schwache Fragen') },
					{ id: 'announcements', label: t('learning', 'Ankündigungen') },
					{ id: 'exam-slot', label: t('learning', 'Prüfungs-Slot') },
					{ id: 'requests', label: t('learning', 'Anfragen') },
					{ id: 'mode-config', label: t('learning', 'Kursregeln') },
				]
			}
			const mc = this.course?.mode_config || {}
			const enabled = (key) => mc[key] !== false
			const tabs = [
				{ id: 'training', label: t('learning', 'Training') },
			]
			if (enabled('leitner')) tabs.push({ id: 'leitner', label: t('learning', 'Leitner') })
			if (enabled('swipe')) tabs.push({ id: 'swipe', label: t('learning', 'Wahr/Falsch') })
			if (enabled('exam')) tabs.push({ id: 'exam', label: t('learning', 'Exam') })
			tabs.push({ id: 'my-progress', label: t('learning', 'Mein Fortschritt') })
			tabs.push({ id: 'leaderboard', label: t('learning', 'Leaderboard') })
			if (enabled('league')) tabs.push({ id: 'league', label: t('learning', 'Liga') })
			tabs.push({ id: 'arena', label: t('learning', 'Arena') })
			return tabs
		},
		activeLearningModeLabel() {
			const labels = {
				training: t('learning', 'Training'),
				leitner: t('learning', 'Leitner'),
				swipe: t('learning', 'Wahr/Falsch'),
				exam: t('learning', 'Exam'),
			}
			return labels[this.activeLearningMode] || t('learning', 'Choose a learning mode')
		},
		selectedLearningPoolQuestionCount() {
			if (!this.selectedLearningPool) {
				return 0
			}
			return this.poolQuestionCounts[this.selectedLearningPool.pool_id]
				?? this.selectedLearningPool.question_count
				?? 0
		},
		currentRequiredBlockers() {
			return this.sortedPools
				.filter(pool => pool.required_enforced && !pool.required_completed)
				.map(pool => pool.pool_name)
		},
		currentPoolExamOptions() {
			return this.editingPoolRules?.available_filters?.exam_keys || []
		},
		currentPoolChapterOptions() {
			return this.editingPoolRules?.available_filters?.chapters || []
		},
		sortedPools() {
			return [...this.coursePools].sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0))
		},
		availablePools() {
			return this.allPools
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
		courseId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.fetchCourseDetail()
				}
			},
		},
		currentTab(tab) {
			if (this.isStudentLearningTab) {
				this.activeLearningMode = tab
				this.selectedLearningPool = null
			}
			if (tab === 'progress' && this.isInstructor && this.progressData.length === 0) {
				this.fetchProgress()
				this.fetchAtRisk()
			}
			if (tab === 'leaderboard') {
				this.fetchLeaderboard()
			}
			if (tab === 'curriculum' && this.isInstructor) {
				this.fetchCurriculumScope()
			}
			if (tab === 'heatmap' && this.isInstructor) {
				this.fetchHeatmap()
			}
			if (tab === 'weak-questions' && this.isInstructor) {
				this.fetchWeakQuestions()
			}
			if (tab === 'announcements' && this.isInstructor) {
				this.fetchAnnouncements()
			}
			if (tab === 'exam-slot' && this.isInstructor) {
				this.fetchActiveExamSlot()
			}
			if (tab === 'requests' && this.isInstructor) {
				this.fetchCourseTickets()
			}
			if (tab === 'mode-config' && this.isInstructor) {
				this.modeConfigLocal = Object.assign({}, this.course?.mode_config || {})
				this.modeConfigSaved = false
			}
			this.emitVirtuProfContext()
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
			emitVirtuProfContext() {
				if (this.isInstructor || !this.course) {
					return
				}
				let area = 'course-detail'
				if (this.currentTab === 'my-progress') {
					area = 'course-my-progress'
				} else if (this.currentTab === 'leaderboard') {
					area = 'course-leaderboard'
				} else if (this.currentTab === 'league') {
					area = 'course-league'
				} else if (this.currentTab === 'arena') {
					area = this.arenaSubMode ? `course-${this.arenaSubMode}` : 'course-arena'
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
			selectTab(tabId) {
				this.currentTab = tabId
				if (tabId !== 'arena') {
					this.arenaSubMode = null
				}
				if (tabId === 'league' && !this.isInstructor) {
					this.$root.$emit('virtuprof:trigger', 'liga-first-visit')
				}
			},
			onArenaSelectMode(mode) {
				this.arenaSubMode = mode
			},

			getLearningPoolQuestionCount(pool) {
				return this.poolQuestionCounts[pool.pool_id] ?? pool.question_count ?? 0
			},
			poolLanguageCodes(pool) {
				const langs = Array.isArray(pool.available_content_languages) ? pool.available_content_languages : ['de']
				return langs
					.filter(code => ['de', 'en', 'ru', 'ar'].includes(code))
					.map(code => code.toUpperCase())
			},
			poolLanguageSummary(pool) {
				const codes = this.poolLanguageCodes(pool)
				return codes.length > 1 ? codes.join(' | ') : ''
			},
			poolRuleSummary(pool) {
				const summary = []
				if (pool.filter_exam_key) {
					summary.push(t('learning', 'Exam: {value}', { value: pool.filter_exam_key }))
				}
				if (pool.filter_chapter_key) {
					const chapter = (pool.available_filters?.chapters || []).find(item => item.key === pool.filter_chapter_key)
					summary.push(t('learning', 'Chapter: {value}', { value: chapter?.title || pool.filter_chapter_key }))
				}
				const questionIdCount = Array.isArray(pool.filter_question_ids) ? pool.filter_question_ids.length : 0
				if (questionIdCount > 0) {
					summary.push(t('learning', '{n} fixed questions', { n: questionIdCount }))
				}
				return summary
			},
			parsePoolRuleQuestionIds() {
				return this.poolRulesForm.filterQuestionIdsText
					.split(/[\s,;]+/)
					.map(value => Number.parseInt(value, 10))
					.filter(value => Number.isInteger(value) && value > 0)
			},
			openPoolRulesModal(pool) {
				this.editingPoolRules = { ...pool }
				this.poolRulesError = ''
				this.poolRulesForm = {
					required: pool.required !== false,
					requiredEnforced: pool.required_enforced === true,
					filterExamKey: pool.filter_exam_key || '',
					filterChapterKey: pool.filter_chapter_key || '',
					filterQuestionIdsText: Array.isArray(pool.filter_question_ids) ? pool.filter_question_ids.join(', ') : '',
				}
				this.showPoolRulesModal = true
			},
			closePoolRulesModal() {
				this.showPoolRulesModal = false
				this.editingPoolRules = null
				this.poolRulesError = ''
			},
			async savePoolRules() {
				if (!this.editingPoolRules) return
				this.savingPoolRules = true
				this.poolRulesError = ''
				const url = generateUrl('/apps/learning/api/courses/{courseId}/pools/{poolId}', {
					courseId: this.courseId,
					poolId: this.editingPoolRules.pool_id,
				})
				try {
					await axios.put(url, {
						required: this.poolRulesForm.required,
						requiredEnforced: this.poolRulesForm.required && this.poolRulesForm.requiredEnforced,
						filterExamKey: this.poolRulesForm.filterExamKey || null,
						filterChapterKey: this.poolRulesForm.filterChapterKey || null,
						filterQuestionIds: this.parsePoolRuleQuestionIds(),
					})
					this.closePoolRulesModal()
					await this.fetchCourseDetail()
				} catch (err) {
					this.poolRulesError = err.response?.data?.error || t('learning', 'Failed to save pool rules.')
				} finally {
					this.savingPoolRules = false
				}
			},

			async fetchPoolQuestionCount(poolId) {
				const pool = this.coursePools.find(item => item.pool_id === poolId)
				if (pool && typeof pool.question_count === 'number') {
					this.$set(this.poolQuestionCounts, poolId, pool.question_count)
					return pool.question_count
				}
				if (this.poolQuestionCounts[poolId] !== undefined) {
					return this.poolQuestionCounts[poolId]
				}

				const url = generateUrl('/apps/learning/api/pools/{poolId}/questions', { poolId })
				const response = await axios.get(url)
				const questionCount = Array.isArray(response.data) ? response.data.length : 0
				this.$set(this.poolQuestionCounts, poolId, questionCount)
				return questionCount
			},

			async selectLearningPool(pool) {
				if (pool.locked_for_student) {
					this.error = t('learning', 'Complete all enforced required pools first.')
					return
				}
				this.loadingLearningPoolId = pool.pool_id
				this.error = ''
				try {
					await this.fetchPoolQuestionCount(pool.pool_id)
					this.selectedLearningPool = pool
				} catch (err) {
					this.error = t('learning', 'Failed to load pool questions.')
				} finally {
					this.loadingLearningPoolId = null
				}
			},

			resetLearningPoolSelection() {
				this.selectedLearningPool = null
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

			async fetchCourseDetail() {
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}', { courseId: this.courseId })
				const response = await axios.get(url)
				this.course = response.data
				this.coursePools = response.data.pools || []
				this.courseMembers = response.data.members || []

				// Default tab for students
				if (!this.course.is_instructor) {
					this.currentTab = this.presetDuelCode ? 'duel' : 'training'
					this.activeLearningMode = 'training'
					this.selectedLearningPool = null
					this.fetchStudentProgress()
				} else if (this.presetDuelCode) {
					this.currentTab = 'duel'
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

		async openAddPoolModal() {
			this.showAddPoolModal = true
			this.poolModalError = ''
			this.selectedPoolToAdd = null
			this.selectedPoolIds = []
			if (this.allPools.length === 0) {
				await this.fetchAllPools()
			}
		},

		onAddPoolModalShowChanged(show) {
			if (!show) {
				this.closeAddPoolModal()
			}
		},

		closeAddPoolModal() {
			this.showAddPoolModal = false
		},

		async fetchAllPools() {
			this.poolsLoading = true
			this.poolModalError = ''
			try {
				const url = generateUrl('/apps/learning/api/pools')
				const response = await axios.get(url)
				// FIX-HI-4: API returns {own:[], shared:[]} not a plain array
				if (Array.isArray(response.data)) {
					this.allPools = response.data
				} else if (response.data && typeof response.data === 'object') {
					this.allPools = [...(response.data.own || []), ...(response.data.shared || [])]
				} else {
					this.allPools = []
				}
			} catch (err) {
				console.error('Failed to fetch pools:', err)
				this.poolModalError = t('learning', 'Failed to load available pools.')
			} finally {
				this.poolsLoading = false
			}
		},

		isPoolAlreadyAdded(poolId) {
			return this.coursePools.some(p => p.pool_id === poolId)
		},

		togglePoolSelection(pool) {
			if (this.isPoolAlreadyAdded(pool.id)) return
			const idx = this.selectedPoolIds.indexOf(pool.id)
			if (idx >= 0) {
				this.selectedPoolIds.splice(idx, 1)
			} else {
				this.selectedPoolIds.push(pool.id)
			}
		},

		async addSelectedPools() {
			if (this.selectedPoolIds.length === 0) return
			this.savingPool = true
			this.poolModalError = ''
			const url = generateUrl('/apps/learning/api/courses/{courseId}/pools', { courseId: this.courseId })
			const baseSortOrder = this.coursePools.length > 0
				? Math.max(...this.coursePools.map(p => p.sort_order || 0)) + 1
				: 0
			try {
				for (let i = 0; i < this.selectedPoolIds.length; i++) {
					await axios.post(url, {
						poolId: this.selectedPoolIds[i],
						sortOrder: baseSortOrder + i,
						required: true,
					})
				}
				this.selectedPoolIds = []
				this.selectedPoolToAdd = null
				this.showAddPoolModal = false
				await this.fetchCourseDetail()
			} catch (err) {
				console.error('Failed to add pools:', err)
				const message = err.response?.data?.error || err.response?.data?.message
				this.poolModalError = message || t('learning', 'Failed to add pool to course.')
			} finally {
				this.savingPool = false
			}
		},

		async addPool(pool) {
			if (this.isPoolAlreadyAdded(pool.id)) return
			this.selectedPoolIds = [pool.id]
			await this.addSelectedPools()
		},

		exportAtRiskCsv() {
			const url = generateUrl('/apps/learning/api/courses/{courseId}/at-risk/export/csv', { courseId: this.courseId })
			window.location.href = url
		},

		confirmRemovePool(pool) {
			this.removingPool = pool
			this.showRemovePoolModal = true
		},

		async removePool() {
			if (!this.removingPool) {
				return
			}

			this.savingPool = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/pools/{poolId}', {
					courseId: this.courseId,
					poolId: this.removingPool.pool_id,
				})
				await axios.delete(url)
				this.showRemovePoolModal = false
				this.removingPool = null
				await this.fetchCourseDetail()
			} catch (err) {
				console.error('Failed to remove pool:', err)
				this.error = t('learning', 'Failed to remove pool from course.')
			} finally {
				this.savingPool = false
			}
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

		async fetchCurriculumScope() {
			this.loadingCurriculum = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/curriculum-scope', { courseId: this.courseId })
				const response = await axios.get(url)
				this.curriculumEnabled = response.data.enabled || false
				this.selectedChapterKeys = response.data.selected_chapter_keys || []
				this.curriculumAvailableChapters = response.data.available_chapters || []
			} catch (err) {
				console.error('Failed to fetch curriculum scope:', err)
			} finally {
				this.loadingCurriculum = false
			}
		},

		async saveCurriculumScope() {
			this.savingCurriculum = true
			this.curriculumSaved = false
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/curriculum-scope', { courseId: this.courseId })
				await axios.put(url, {
					enabled: this.curriculumEnabled,
					chapterKeys: this.selectedChapterKeys,
				})
				this.curriculumSaved = true
				setTimeout(() => { this.curriculumSaved = false }, 2500)
			} catch (err) {
				console.error('Failed to save curriculum scope:', err)
			} finally {
				this.savingCurriculum = false
			}
		},

		toggleChapter(chapterKey, checked) {
			if (checked) {
				if (!this.selectedChapterKeys.includes(chapterKey)) {
					this.selectedChapterKeys = [...this.selectedChapterKeys, chapterKey]
				}
			} else {
				this.selectedChapterKeys = this.selectedChapterKeys.filter(k => k !== chapterKey)
			}
		},

		selectAllChapters() {
			this.selectedChapterKeys = this.curriculumAvailableChapters.map(c => c.chapter_key)
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

		async fetchAnnouncements() {
			if (!this.course) return
			try {
				const res = await axios.get(generateUrl(`/apps/learning/api/courses/${this.courseId}/announcements`))
				this.announcements = res.data.announcements || res.data || []
			} catch (e) {
				console.error('Failed to load announcements', e)
			}
		},

		async createAnnouncement() {
			if (!this.newAnnouncementTitle.trim()) return
			this.savingAnnouncement = true
			try {
				const res = await axios.post(generateUrl(`/apps/learning/api/courses/${this.courseId}/announcements`), {
					title: this.newAnnouncementTitle,
					body: this.newAnnouncementBody,
				})
				this.announcements.unshift(res.data.announcement || res.data)
				this.newAnnouncementTitle = ''
				this.newAnnouncementBody = ''
			} catch (e) {
				console.error('Failed to create announcement', e)
			} finally {
				this.savingAnnouncement = false
			}
		},

		async deleteAnnouncement(id) {
			try {
				await axios.delete(generateUrl(`/apps/learning/api/courses/${this.courseId}/announcements/${id}`))
				this.announcements = this.announcements.filter(a => a.id !== id)
			} catch (e) {
				console.error('Failed to delete announcement', e)
			}
		},

		async fetchActiveExamSlot() {
			if (!this.course) return
			try {
				const res = await axios.get(generateUrl(`/apps/learning/api/courses/${this.courseId}/exam-slot/active`))
				this.activeExamSlot = res.data.slot || null
			} catch (e) {
				this.activeExamSlot = null
			}
		},

		async startExamSlot() {
			this.startingExamSlot = true
			try {
				const res = await axios.post(generateUrl(`/apps/learning/api/courses/${this.courseId}/exam-slot`), {
					durationMinutes: this.examSlotDuration,
					scopeMode: this.examSlotScope,
				})
				this.activeExamSlot = res.data.slot || res.data
			} catch (e) {
				console.error('Failed to start exam slot', e)
			} finally {
				this.startingExamSlot = false
			}
		},

		async closeExamSlot() {
			try {
				await axios.post(generateUrl(`/apps/learning/api/courses/${this.courseId}/exam-slot/close`))
				this.activeExamSlot = null
			} catch (e) {
				console.error('Failed to close exam slot', e)
			}
		},

		async fetchCourseTickets() {
			if (!this.course) return
			this.loadingRequests = true
			try {
				const res = await axios.get(generateUrl(`/apps/learning/api/courses/${this.courseId}/support-tickets`))
				this.courseTickets = res.data.tickets || res.data || []
			} catch (e) {
				console.error('Failed to load course tickets', e)
			} finally {
				this.loadingRequests = false
			}
		},

		async answerTicket(ticketId) {
			const answer = this.ticketReplies[ticketId]
			if (!answer || !answer.trim()) return
			try {
				await axios.post(generateUrl(`/apps/learning/api/settings/admin/support-tickets/${ticketId}/answer`), {
					answerText: answer,
				})
				const ticket = this.courseTickets.find(t => t.id === ticketId)
				if (ticket) {
					ticket.status = 'answered'
					ticket.answer_text = answer
				}
				this.$delete(this.ticketReplies, ticketId)
			} catch (e) {
				console.error('Failed to answer ticket', e)
			}
		},

		toggleMode(key, value) {
			this.$set(this.modeConfigLocal, key, value)
		},

		async saveModeConfig() {
			this.savingModeConfig = true
			try {
				const res = await axios.put(generateUrl(`/apps/learning/api/courses/${this.courseId}/mode-config`), {
					modeConfig: this.modeConfigLocal,
				})
				if (this.course) {
					this.course.mode_config = res.data?.mode_config || this.modeConfigLocal
				}
				this.modeConfigSaved = true
				setTimeout(() => { this.modeConfigSaved = false }, 3000)
			} catch (e) {
				console.error('Failed to save mode config', e)
			} finally {
				this.savingModeConfig = false
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

/* Announcements */
.announcements-section {}
.announcement-form { margin-bottom: 24px; padding: 16px; background: var(--color-background-dark); border-radius: 8px; }
.announcement-form h3 { margin: 0 0 12px 0; font-size: 1.1em; font-weight: 600; }
.announcement-input { width: 100%; box-sizing: border-box; margin-bottom: 8px; }
.announcements-list { display: flex; flex-direction: column; gap: 8px; }
.announcement-item { padding: 12px; border: 1px solid var(--color-border); border-radius: 8px; }
.announcement-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.announcement-body { color: var(--color-text-maxcontrast); margin: 4px 0; }
.announcement-date { color: var(--color-text-maxcontrast); font-size: 0.85em; }

/* Exam Slot */
.exam-slot-section {}
.active-slot-banner { margin-bottom: 16px; display: flex; flex-direction: column; gap: 12px; }
.start-slot-form h3 { margin: 0 0 16px 0; font-size: 1.1em; font-weight: 600; }
.form-row-cd { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.form-row-cd label { font-weight: 600; font-size: 0.9em; }

/* Requests (course tickets) */
.requests-section {}
.tickets-list-cd { display: flex; flex-direction: column; gap: 12px; }
.ticket-item-cd { border: 1px solid var(--color-border); border-radius: 8px; padding: 12px; }
.ticket-header-cd { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.ticket-subject-cd { font-weight: 600; }
.ticket-status-cd { font-size: 0.85em; padding: 2px 8px; border-radius: 4px; }
.ticket-status-cd.status-open { background: color-mix(in srgb, var(--color-warning) 15%, transparent); color: var(--color-warning); }
.ticket-status-cd.status-answered { background: color-mix(in srgb, var(--color-success) 15%, transparent); color: var(--color-success); }
.ticket-message-cd { color: var(--color-text-maxcontrast); margin-bottom: 8px; }
.ticket-reply-cd { margin-top: 8px; display: flex; flex-direction: column; gap: 8px; }
.ticket-answer-cd { margin-top: 8px; padding: 8px; background: var(--color-background-dark); border-radius: 4px; font-size: 0.9em; }

/* AdminSettings ticket filter note */
.ticket-filter-note { margin-bottom: 8px; }

/* Mode Config */
.mode-config-section { padding: 16px 0; }
.mode-config-hint { color: var(--color-text-maxcontrast); margin-bottom: 16px; font-size: 0.9em; }
.mode-toggles { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; }
.mode-toggle-row { display: flex; align-items: center; }
.mode-toggle-label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 1em; }
.mode-toggle-label input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.mode-toggle-label input[type="checkbox"]:disabled { opacity: 0.5; cursor: not-allowed; }
.mode-config-saved { margin-top: 12px; }
</style>
