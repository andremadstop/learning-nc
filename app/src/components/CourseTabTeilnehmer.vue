<template>
	<div class="course-tab-teilnehmer">
		<div class="teilnehmer-subnav" role="tablist" :aria-label="t('learning', 'Teilnehmer Bereiche')">
			<button
				v-for="tab in visibleSubTabs"
				:key="tab.id"
				class="teilnehmer-pill"
				:class="{ active: currentSubTab === tab.id }"
				@click="selectSubTab(tab.id)">
				{{ tab.label }}
			</button>
		</div>

		<div v-if="currentSubTab === 'classbook'" class="classbook-section">
			<!-- GitHub #35: the role inside this course (kudos are a student action), not the global one. -->
			<Klassenbuch :course-id="courseId" :user-role="isInstructor ? 'instructor' : 'student'" :gamification-enabled="gamificationEnabled" />
		</div>

		<!-- Members (instructor only) -->
		<div v-if="currentSubTab === 'members' && isInstructor" class="members-section">
			<div class="section-header">
				<h4>{{ t('learning', 'Members ({n})', { n: courseMembers.length }) }}</h4>
			</div>

			<!-- GitHub #35: search picker for people and groups instead of a typed username -->
			<CourseMemberPicker :course-id="courseId" @members-changed="$emit('members-changed')" />

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
						<NcButton variant="tertiary"
							:disabled="savingMember === member.id"
							@click="toggleMemberRole(member)">
							{{ member.role === 'student'
								? t('learning', 'Make Instructor')
								: t('learning', 'Make Student') }}
						</NcButton>
						<NcButton variant="tertiary-no-background"
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
					{{ t('learning', 'Suche oben nach Personen oder Gruppen, um sie hinzuzufügen.') }}
				</template>
			</NcEmptyContent>
		</div>

		<!-- Progress (instructor only) -->
		<div v-if="currentSubTab === 'progress' && isInstructor" class="progress-section">
			<div class="section-header">
				<h4>{{ t('learning', 'Student Progress') }}</h4>
				<NcButton variant="tertiary" @click="fetchProgress(); fetchAtRisk()">
					{{ t('learning', 'Refresh') }}
				</NcButton>
			</div>

			<!-- GitHub #68: without Leitner the server fills the table from exams and hit rates
				(progress_basis 'exams'); the note says what the figures are. -->
			<NcNoteCard v-if="progressExamBasis" type="info" class="progress-leitner-note">
				{{ progressMeta.cert_applicable
					? t('learning', 'Dieser Kurs nutzt Leitner nicht. Bei Pflicht-Pools steht deshalb das beste Prüfungsergebnis (✓ = bestanden), bei den übrigen Pools die Trefferquote beim Üben und in Prüfungen.')
					: t('learning', 'Dieser Kurs nutzt Leitner nicht. Die Prozentwerte zeigen deshalb die Trefferquote je Pool beim Üben und in Prüfungen.') }}
			</NcNoteCard>

			<!-- At-Risk Section -->
			<div v-if="atRiskStudents.length > 0" class="at-risk-section" :class="{ collapsed: atRiskCollapsed }">
				<div class="at-risk-header" @click="atRiskCollapsed = !atRiskCollapsed">
					<h4 class="at-risk-title">
						{{ t('learning', 'At-Risk Students ({n})', { n: atRiskStudents.length }) }}
					</h4>
					<NcButton variant="tertiary" size="small" @click.stop="exportAtRiskCsv">
						{{ t('learning', 'Export CSV') }}
					</NcButton>
					<button class="at-risk-toggle">{{ atRiskCollapsed ? '\u25BC' : '\u25B2' }}</button>
				</div>
				<p v-if="!atRiskCollapsed" class="at-risk-hint">
					{{ atRiskHint }}
				</p>
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
							<span v-for="(reason, idx) in riskReasonLabels(student)" :key="idx" class="risk-reason-tag">
								{{ reason }}
							</span>
						</div>
						<div class="at-risk-meta">
							<span v-if="student.critical_cards_count > 0">{{ t('learning', 'Critical cards: {n}', { n: student.critical_cards_count }) }}</span>
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
							<th v-if="gamificationEnabled" class="stat-col sortable-col" scope="col" role="button" tabindex="0"
								:aria-sort="progressSortKey === 'current_level' ? (progressSortAsc ? 'ascending' : 'descending') : 'none'"
								@click="setProgressSort('current_level')" @keydown.enter="setProgressSort('current_level')" @keydown.space.prevent="setProgressSort('current_level')">
								{{ t('learning', 'Level') }}
								<span v-if="progressSortKey === 'current_level'" class="sort-arrow">{{ progressSortAsc ? '\u25B2' : '\u25BC' }}</span>
							</th>
							<th v-if="gamificationEnabled" class="stat-col sortable-col" scope="col" role="button" tabindex="0"
								:aria-sort="progressSortKey === 'total_xp' ? (progressSortAsc ? 'ascending' : 'descending') : 'none'"
								@click="setProgressSort('total_xp')" @keydown.enter="setProgressSort('total_xp')" @keydown.space.prevent="setProgressSort('total_xp')">
								{{ t('learning', 'XP') }}
								<span v-if="progressSortKey === 'total_xp'" class="sort-arrow">{{ progressSortAsc ? '\u25B2' : '\u25BC' }}</span>
							</th>
							<th v-if="!progressExamBasis" class="stat-col sortable-col" scope="col" role="button" tabindex="0"
								:aria-sort="progressSortKey === 'critical_cards_count' ? (progressSortAsc ? 'ascending' : 'descending') : 'none'"
								@click="setProgressSort('critical_cards_count')" @keydown.enter="setProgressSort('critical_cards_count')" @keydown.space.prevent="setProgressSort('critical_cards_count')">
								{{ t('learning', 'Critical Cards') }}
								<span v-if="progressSortKey === 'critical_cards_count'" class="sort-arrow">{{ progressSortAsc ? '\u25B2' : '\u25BC' }}</span>
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
								{{ overallColumnLabel }}
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
							<template v-if="gamificationEnabled">
								<td class="stat-col">
									<span class="level-pill" :class="levelClass(row.current_level)">
										Lv.{{ row.current_level || 1 }}
									</span>
								</td>
								<td class="stat-col">{{ formatXp(row.total_xp) }}</td>
							</template>
							<td v-if="!progressExamBasis" class="stat-col critical-cards-col">
								<span class="critical-cards-pill" :class="criticalCardsClass(row.critical_cards_count)">
									{{ row.critical_cards_count || 0 }}
								</span>
							</td>
							<template v-if="progressExamBasis">
								<td v-for="pool in coursePools"
									:key="'td-' + pool.id"
									class="pool-col"
									:class="examCell(row, pool.pool_id).cls"
									:title="examCell(row, pool.pool_id).title">
									{{ examCell(row, pool.pool_id).text }}
									<template v-if="examCell(row, pool.pool_id).passed">
										<span class="exam-passed-mark" aria-hidden="true">✓</span>
										<span class="hidden-visually">{{ t('learning', 'Bestanden') }}</span>
									</template>
								</td>
								<td class="overall-col" :class="overallExamCell(row).cls">
									{{ overallExamCell(row).text }}
								</td>
							</template>
							<template v-else>
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
							</template>
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
						<NcButton variant="tertiary" :disabled="!canPagePrev || progressLoading" @click="pageProgressPrev">
							{{ t('learning', 'Previous') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="!canPageNext || progressLoading" @click="pageProgressNext">
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

		<!-- Heatmap (instructor only) -->
		<div v-if="currentSubTab === 'heatmap' && isInstructor" class="tab-content heatmap-section">
			<div v-if="loadingHeatmap" class="loading-hint">{{ t('learning', 'Loading...') }}</div>
			<div v-else-if="heatmapChapters.length === 0" class="empty-hint">
				{{ t('learning', 'No chapter data available yet. Students need to answer questions first.') }}
			</div>
			<div v-else>
				<p class="section-hint">{{ t('learning', 'Erfolgsquote je Kapitel über alle Lernenden, Fragen ohne Kapitel je Pool. Die schwächsten zuerst.') }}</p>
				<div class="heatmap-list">
					<div v-for="ch in heatmapChapters" :key="ch.chapter_key" class="heatmap-row">
						<div class="heatmap-meta">
							<span class="chapter-title-hm">{{ ch.chapter_title || ch.chapter_key }}</span>
							<span class="heatmap-stats">{{ ch.success_rate }}% · {{ t('learning', 'Lernende: {n}', { n: ch.unique_learners }) }}</span>
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
								<span class="wrong-q-rate">{{ t('learning', '{wrong} von {total} Antworten falsch ({rate} %)', { wrong: q.wrong_answers, total: q.total_answers, rate: q.wrong_rate }) }}</span>
							</div>
						</div>
						<!-- GitHub #44: the button toggled an empty list; it only shows when there is one. -->
						<NcButton v-if="ch.top_wrong_questions && ch.top_wrong_questions.length > 0" variant="tertiary" @click="toggleExpandChapter(ch.chapter_key)">
							{{ expandedChapter === ch.chapter_key ? t('learning', 'Hide') : t('learning', 'Show worst questions') }}
						</NcButton>
						<p v-else class="heatmap-no-worst">
							{{ t('learning', 'Noch keine falsch beantwortete Frage mit mindestens {n} Antworten.', { n: minAnswersPerQuestion }) }}
						</p>
					</div>
				</div>
			</div>
		</div>

		<!-- Weak Questions (instructor only) -->
		<div v-if="currentSubTab === 'weak-questions' && isInstructor" class="tab-content weak-questions-section">
			<div v-if="loadingWeakQuestions" class="loading-hint">{{ t('learning', 'Loading...') }}</div>
			<div v-else-if="weakQuestions.length === 0" class="empty-hint">
				{{ t('learning', 'Noch keine schwachen Fragen. Eine Frage erscheint hier ab {n} Antworten.', { n: minAnswersPerQuestion }) }}
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
						<td>{{ q.chapter_title || q.chapter_key || '\u2014' }}</td>
						<td class="wrong-rate" :class="q.wrong_rate >= 70 ? 'rate-red' : q.wrong_rate >= 50 ? 'rate-yellow' : 'rate-ok'">
							{{ q.wrong_rate }}%
						</td>
						<td class="q-actions">
							<NcButton variant="tertiary" @click="toggleQuestionPause(q)">
								{{ q.is_paused ? t('learning', 'Resume') : t('learning', 'Pause') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Class Profile (instructor only) -->
		<div v-if="currentSubTab === 'class-profile' && isInstructor" class="class-profile-section">
			<div class="section-header">
				<h4>{{ t('learning', 'Class Profile') }}</h4>
				<NcButton variant="tertiary" @click="fetchTelosAggregate">
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
						<p v-else class="class-profile-empty">{{ t('learning', 'Noch keine Onboarding-Daten.') }}</p>
					</div>

					<div class="class-profile-panel">
						<h5>{{ t('learning', 'Ziel-Zertifizierungen') }}</h5>
						<ul v-if="sortedDistributionEntries(telosAggregate.target_certs).length" class="class-profile-list">
							<li v-for="entry in sortedDistributionEntries(telosAggregate.target_certs)" :key="'cert-' + entry.key">
								<span>{{ entry.key }}</span>
								<strong>{{ entry.value }}</strong>
							</li>
						</ul>
						<p v-else class="class-profile-empty">{{ t('learning', 'Noch keine Ziel-Zertifizierungen erfasst.') }}</p>
					</div>
				</div>

				<div class="class-profile-panel">
					<h5>{{ t('learning', 'Anstehende Prüfungen') }}</h5>
					<ul v-if="(telosAggregate.upcoming_exams || []).length" class="class-profile-list">
						<li v-for="exam in telosAggregate.upcoming_exams" :key="exam.user_id + '-' + exam.target_date">
							<span>{{ exam.user_id }} · {{ exam.target_cert || t('learning', 'Exam') }}</span>
							<strong>{{ exam.target_date }} · {{ t('learning', '{n} days', { n: exam.days_until }) }}</strong>
						</li>
					</ul>
					<p v-else class="class-profile-empty">{{ t('learning', 'Keine anstehenden Prüfungstermine in den nächsten 180 Tagen.') }}</p>
				</div>
			</div>

			<!-- GitHub #40: the course switch for learning partners applies here too -->
			<BuddyMatching v-if="buddiesOn" :course-id="courseId" />
		</div>

		<!-- My Progress (student self-view) -->
		<div v-if="currentSubTab === 'my-progress' && !isInstructor" class="my-progress-section">
			<!-- GitHub #63: an instructor in the student view has no learner record (404). -->
			<NcNoteCard v-if="previewsAsStudent" type="info" class="student-preview-note">
				{{ t('learning', 'Schüler-Ansicht: Lernende sehen hier ihren eigenen Lernfortschritt. Du bist in diesem Kurs Lehrkraft und hast deshalb keinen eigenen.') }}
			</NcNoteCard>
			<StudentDetail
				v-else
				:courseId="courseId"
				:studentId="myUserId"
				:gamification-enabled="gamificationEnabled"
				:show-recent-sessions="!hasCompletionArea"
				@back="$emit('tab-change', 'training')" />
		</div>

		<!-- Summary: instructors only. GitHub #36: learners have it as an area of its own (CourseDetail). -->
		<div v-if="currentSubTab === 'summary' && isInstructor" class="summary-section">
			<!-- Compliance report (instructor + cert_enabled only; clean DTO endpoint, no recipient-id) -->
			<div v-if="showCertReport" class="cert-report-section">
				<div class="cert-report-header">
					<h4 class="cert-report-title">{{ t('learning', 'Compliance-Bericht') }}</h4>
					<div class="cert-report-header-actions">
						<NcButton variant="tertiary" size="small" @click="exportCertReportCsv">
							{{ t('learning', 'Ausgestellte Zertifikate als CSV') }}
						</NcButton>
						<!-- GitHub #42: copies of every listed certificate (same filters as the table and the CSV). -->
						<NcButton v-if="certRows.length > 0"
							variant="tertiary"
							size="small"
							class="cert-report-zip"
							@click="downloadAllCertificates">
							{{ t('learning', 'Alle als JSON herunterladen (ZIP)') }}
						</NcButton>
					</div>
				</div>
				<!-- GitHub #42: the copies are the signed certificate files, not PDFs; the PDF is View → Print. -->
				<p v-if="certRows.length > 0" class="cert-report-copies-hint">
					{{ t('learning', 'PDF: „Ansehen“ und dort „Drucken“ (als PDF speichern). „Herunterladen“ liefert die signierte, maschinell prüfbare Zertifikatsdatei (Open Badges, JSON).') }}
				</p>
				<NcNoteCard v-if="certSigningAvailable === false" type="error" class="cert-signing-warning">
					{{ t('learning', 'Zertifikate können derzeit nicht ausgestellt werden: Es gibt keinen nutzbaren Signaturschlüssel. Teilnehmende, die bestehen, erhalten erst ein Zertifikat, wenn die Administration den Schlüssel eingerichtet hat (occ learning:cert:init-issuer).') }}
				</NcNoteCard>
				<NcNoteCard v-if="certPassedWithoutCertificate.length > 0" type="warning" class="cert-passed-without-certificate">
					<p>{{ t('learning', 'Bestanden, aber noch kein Zertifikat ausgestellt — bitte wende dich an die Administration:') }}</p>
					<ul>
						<li v-for="(entry, index) in certPassedWithoutCertificate" :key="index">
							{{ entry.display_name }}
						</li>
					</ul>
				</NcNoteCard>
				<NcNoteCard type="info">
					{{ t('learning', 'Der Bericht zeigt ausgestellte Zertifikate, keine historischen Bestehensereignisse.') }}
				</NcNoteCard>
				<div class="cert-report-filters">
					<label class="cert-filter">
						<span>{{ t('learning', 'Bestanden ab') }}</span>
						<input type="date" :value="certFromDate" @input="certFromDate = $event.target.value">
					</label>
					<label class="cert-filter">
						<span>{{ t('learning', 'Bestanden bis') }}</span>
						<input type="date" :value="certToDate" @input="certToDate = $event.target.value">
					</label>
					<label class="cert-filter">
						<span>{{ t('learning', 'Ablauf innerhalb (Tage)') }}</span>
						<input type="number" min="0" step="1" :value="certExpiringDays"
							@input="certExpiringDays = $event.target.value">
					</label>
					<NcButton variant="secondary" @click="fetchCertReport">
						{{ t('learning', 'Filter anwenden') }}
					</NcButton>
				</div>
				<p class="cert-report-hint">{{ t('learning', 'Inkl. bereits abgelaufener Zertifikate') }}</p>
				<div v-if="certRows.length > 0" class="cert-report-table-container" role="region"
					:aria-label="t('learning', 'Compliance-Bericht')">
					<table class="cert-report-table">
						<thead>
							<tr>
								<th scope="col">{{ t('learning', 'Name (Person)') }}</th>
								<th scope="col">{{ t('learning', 'Bestanden am') }}</th>
								<th scope="col">{{ t('learning', 'Score (%)') }}</th>
								<th scope="col">{{ t('learning', 'Gültig bis') }}</th>
								<th scope="col">{{ t('learning', 'Verifizierungs-ID') }}</th>
								<th scope="col">{{ t('learning', 'Aktion') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in certRows" :key="row.verification_id">
								<td>{{ row.display_name }}</td>
								<td>{{ formatCertDate(row.passed_at) }}</td>
								<td>{{ formatCertScore(row.score) }}</td>
								<td>
									{{ row.expires_at ? formatCertDate(row.expires_at) : t('learning', 'unbegrenzt') }}
									<span v-if="row.expired" class="cert-expired-tag">({{ t('learning', 'Expired') }})</span>
								</td>
								<td class="cert-vid">{{ row.verification_id }}</td>
								<td>
									<div class="cert-row-actions">
										<!-- GitHub #42: the instructor keeps copies for audits (view = learner's certificate incl. print, download = learner's file). -->
										<NcButton variant="tertiary"
											size="small"
											class="cert-view"
											:aria-label="t('learning', 'Zertifikat von {name} ansehen', { name: row.display_name })"
											@click="viewCertificate(row)">
											{{ t('learning', 'Ansehen') }}
										</NcButton>
										<NcButton variant="tertiary"
											size="small"
											class="cert-download"
											:aria-label="t('learning', 'Herunterladen (JSON): signierte Zertifikatsdatei von {name}', { name: row.display_name })"
											@click="downloadCertificate(row)">
											{{ t('learning', 'Herunterladen (JSON)') }}
										</NcButton>
										<NcButton variant="tertiary"
											size="small"
											class="cert-revoke"
											:disabled="revokingVid === row.verification_id"
											@click="revokeCertificate(row)">
											{{ t('learning', 'Widerrufen') }}
										</NcButton>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-else class="cert-report-empty">{{ t('learning', 'Noch keine Zertifikate ausgestellt') }}</p>
				<NcModal v-if="viewingCertVid"
					size="large"
					:name="t('learning', 'Zertifikat')"
					@close="viewingCertVid = null">
					<div class="cert-view-modal">
						<Certificate :verification-id="viewingCertVid" :course-id="courseId" />
					</div>
				</NcModal>

				<!-- Lückencheck B2: every enrolled learner, also those who never passed (members, not certificates). -->
				<div class="cert-coverage">
					<div class="cert-report-header">
						<h5 class="cert-coverage-title">{{ t('learning', 'Abdeckung: alle eingeschriebenen Lernenden') }}</h5>
						<NcButton variant="tertiary" size="small" class="cert-coverage-csv" @click="exportCoverageCsv">
							{{ t('learning', 'Abdeckung aller Lernenden als CSV') }}
						</NcButton>
					</div>
					<p class="cert-report-hint">
						{{ t('learning', 'Eine Zeile pro Person, mit oder ohne Zertifikat. Dozierende sind nicht enthalten.') }}
					</p>
					<div class="cert-report-filters">
						<label class="cert-filter">
							<span>{{ t('learning', 'Status') }}</span>
							<select class="cert-coverage-filter" :value="coverageStatusFilter" @change="onCoverageStatusChange($event.target.value)">
								<option value="">{{ t('learning', 'Alle') }}</option>
								<option v-for="status in coverageStatuses" :key="status" :value="status">
									{{ coverageStatusLabel(status) }}
								</option>
							</select>
						</label>
					</div>
					<div v-if="coverageRows.length > 0" class="cert-report-table-container" role="region"
						:aria-label="t('learning', 'Abdeckung: alle eingeschriebenen Lernenden')">
						<table class="cert-report-table cert-coverage-table">
							<thead>
								<tr>
									<th scope="col">{{ t('learning', 'Name (Person)') }}</th>
									<th scope="col">{{ t('learning', 'Status') }}</th>
									<th scope="col">{{ t('learning', 'Letztes Zertifikat') }}</th>
									<th scope="col">{{ t('learning', 'Gültig bis') }}</th>
									<th scope="col">{{ t('learning', 'Pflicht-Pools bestanden') }}</th>
									<!-- GitHub #35 (B4): only for a course with required content -->
									<th v-if="coverageHasRequiredContent" scope="col" data-test="coverage-content-col">
										{{ t('learning', 'Pflichtinhalte bestätigt') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in coverageRows" :key="row.member_ref">
									<td>{{ row.display_name }}</td>
									<td>
										<span class="cert-coverage-status" :class="'cert-coverage-status--' + row.status">
											{{ coverageStatusLabel(row.status) }}
										</span>
									</td>
									<td>{{ row.last_certificate_at ? formatCertDate(row.last_certificate_at) : '—' }}</td>
									<td>
										<template v-if="row.last_certificate_at">
											{{ row.expires_at ? formatCertDate(row.expires_at) : t('learning', 'unbegrenzt') }}
										</template>
										<template v-else>
											—
										</template>
									</td>
									<td>{{ formatPoolProgress(row.required_pools_passed, row.required_pools_total) }}</td>
									<td v-if="coverageHasRequiredContent">
										{{ formatContentProgress(row.required_content_confirmed, row.required_content_total) }}
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<p v-else-if="coverageLoaded" class="cert-report-empty">
						{{ coverageAppliedStatus ? t('learning', 'Niemand mit diesem Status.') : t('learning', 'Keine Lernenden eingeschrieben.') }}
					</p>
				</div>
			</div>
			<NcNoteCard v-else type="info">
				{{ t('learning', 'Der Klassen-Abschlussbericht folgt in Phase 107. Hier erscheint später die Dozentenansicht für den Kursabschluss.') }}
			</NcNoteCard>
		</div>

		<!-- Remove member confirmation modal -->
		<NcModal v-if="showRemoveMemberModal" @close="showRemoveMemberModal = false" @closing="showRemoveMemberModal = false" size="small">
			<div class="modal-content">
				<h3>{{ t('learning', 'Mitglied entfernen') }}</h3>
				<p>{{ t('learning', '"{name}" aus diesem Kurs entfernen?', { name: removingMember ? removingMember.user_id : '' }) }}</p>
				<div class="modal-actions">
					<NcButton variant="tertiary"
						:disabled="savingMember !== null"
						@click="showRemoveMemberModal = false">
						{{ t('learning', 'Abbrechen') }}
					</NcButton>
					<NcButton variant="error"
						:disabled="savingMember !== null"
						@click="removeMember">
						{{ savingMember !== null ? t('learning', 'Entferne...') : t('learning', 'Entfernen') }}
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
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { formatXp, formatRelativeDateString } from '../format.js'
import { buildCertReportQuery, buildCoverageQuery, COVERAGE_STATUSES, formatPoolProgress, shouldShowCertReport, formatDate, formatScore } from '../utils/cert-report.js'
import { formatContentProgress } from '../utils/requiredContent.js'
import { abschlussTabIds, buddiesEnabled, previewsCourseAsStudent, teilnehmerTabIds } from '../utils/courseView.js'
import Klassenbuch from './Klassenbuch.vue'
import CourseMemberPicker from './CourseMemberPicker.vue'
import StudentDetail from './StudentDetail.vue'
import Certificate from './Certificate.vue'
import { courseDownloadUrl, certReportZipUrl } from '../services/CertificateService.js'
import BuddyMatching from './BuddyMatching.vue'
import { MIN_ANSWERS_PER_QUESTION } from '../utils/courseFigures.js'

export default {
	name: 'CourseTabTeilnehmer',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcModal,
		CourseMemberPicker,
		NcNoteCard,
		Klassenbuch,
		StudentDetail,
		BuddyMatching,
		Certificate,
	},

	props: {
		// GitHub #35: CourseDetail's single instructor gate (membership, student view off).
		actsAsInstructor: { type: Boolean, default: false },
		courseId: { type: Number, required: true },
		course: { type: Object, default: null },
		userRole: { type: String, required: true },
		courseMembers: { type: Array, default: () => [] },
		coursePools: { type: Array, default: () => [] },
		activeTab: { type: String, default: '' },
		// GitHub #36: the admin's global gamification switch (App.vue → CourseDetail). Default on.
		gamificationEnabled: { type: Boolean, default: true },
	},

	data() {
		return {
			currentSubTab: 'members',

			// Members
			memberError: '',
			savingMember: null,
			showRemoveMemberModal: false,
			removingMember: null,

			// Progress
			progressLoading: false,
			progressData: [],
			// GitHub #36: without gamification XP and level are hidden; the table sorts by name.
			progressSortKey: this.gamificationEnabled !== false ? 'total_xp' : 'user_id',
			progressSortAsc: this.gamificationEnabled === false,
			progressPageSize: 25,
			progressMeta: {
				total: 0,
				limit: 25,
				offset: 0,
			},

			// At-Risk
			atRiskStudents: [],
			atRiskBasis: 'leitner',
			atRiskCollapsed: false,

			// Compliance report (cert)
			certRows: [],
			// null = unknown (not loaded / older server); false = certificates cannot be signed.
			certSigningAvailable: null,
			// GitHub #35: members who passed but hold no certificate ([{display_name}]).
			certPassedWithoutCertificate: [],
			revokingVid: null,
			viewingCertVid: null,
			// GitHub #42: the filter query the table was last loaded with — the ZIP packs that list.
			certAppliedQuery: '',
			certFromDate: '',
			certToDate: '',
			certExpiringDays: '',
			// Lückencheck B2: coverage list (every enrolled learner). The CSV uses the applied status.
			coverageStatuses: COVERAGE_STATUSES,
			coverageRows: [],
			coverageLoaded: false,
			coverageStatusFilter: '',
			coverageAppliedStatus: '',
			coverageRequestSeq: 0,

			// Telos aggregate
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
			minAnswersPerQuestion: MIN_ANSWERS_PER_QUESTION,

			// Weak questions
			loadingWeakQuestions: false,
			weakQuestions: [],
		}
	},

	computed: {
		// GitHub #35 (B4): the column only for a course with required content, IT courses keep the table.
		coverageHasRequiredContent() {
			return this.coverageRows.some((row) => Number(row.required_content_total) > 0)
		},
		// GitHub #35: course membership minus the student view, computed once in CourseDetail.
		isInstructor() {
			return this.actsAsInstructor
		},
		// GitHub #36: the course switched Leitner off, so the mastery columns cannot move.
		courseWithoutLeitner() {
			return this.course?.mode_config?.leitner === false
		},
		// GitHub #68: the server says which figures the progress rows carry. An older server
		// without the field keeps the Leitner table.
		progressExamBasis() {
			return this.progressMeta?.progress_basis === 'exams'
		},
		overallColumnLabel() {
			if (!this.progressExamBasis) return t('learning', 'Overall')
			return this.progressMeta.cert_applicable ? t('learning', 'Ergebnis') : t('learning', 'Trefferquote gesamt')
		},
		atRiskHint() {
			if (this.atRiskBasis === 'exams') {
				return t('learning', 'Lernende, die noch nicht bestanden haben und bei denen es eilt: Die Frist ist nah oder vorbei, die Erneuerung ist offen oder sie waren länger nicht aktiv. Die Gründe stehen auf jeder Karte.')
			}
			if (this.atRiskBasis === 'practice') {
				return t('learning', 'Lernende, die länger nicht aktiv waren oder eine niedrige Trefferquote haben. Die Gründe stehen auf jeder Karte.')
			}
			return t('learning', 'Lernende, die beim Üben auffallen, zum Beispiel lange nicht aktiv, niedrige Trefferquote oder viele kritische Karten (Fragen, die oft falsch beantwortet werden). Die Gründe stehen auf jeder Karte.')
		},
		// GitHub #63: see previewsCourseAsStudent()
		previewsAsStudent() {
			return previewsCourseAsStudent(this.course, this.actsAsInstructor)
		},
		myUserId() {
			const user = getCurrentUser()
			return user ? user.uid : null
		},
		isCourseSummaryReleased() {
			return this.course?.mode_config?.course_summary === true
		},
		buddiesOn() {
			return buddiesEnabled(this.course)
		},
		showCertReport() {
			return shouldShowCertReport(this.isInstructor, this.course)
		},
		// GitHub #36: the ids come from the helper CourseDetail routes with, so both agree.
		visibleSubTabs() {
			const labels = {
				members: t('learning', 'Mitglieder'),
				classbook: t('learning', 'Klassenbuch'),
				progress: t('learning', 'Fortschritt'),
				heatmap: t('learning', 'Heatmap'),
				'weak-questions': t('learning', 'Schwache Fragen'),
				'class-profile': t('learning', 'Klassen-Profil'),
				'my-progress': t('learning', 'Mein Fortschritt'),
				summary: this.course?.cert_enabled ? t('learning', 'Abschluss & Zertifikat') : t('learning', 'Abschluss'),
			}
			return teilnehmerTabIds(this.course, {
				actsAsInstructor: this.isInstructor,
				gamificationEnabled: this.gamificationEnabled,
			}).map((id) => ({ id, label: labels[id] }))
		},
		// GitHub #36: the learner's completion area shows the recent sessions; "Mein Fortschritt"
		// leaves them out then, so they are not listed twice.
		hasCompletionArea() {
			return abschlussTabIds(this.course, { actsAsInstructor: this.isInstructor }).length > 0
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
	},

	watch: {
		activeTab: {
			immediate: true,
			handler(tab) {
				this.syncFromActiveTab(tab)
			},
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
			if (tab === 'progress' && this.isInstructor && this.progressData.length === 0) {
				this.fetchProgress()
				this.fetchAtRisk()
			}
			if (tab === 'class-profile' && this.isInstructor) {
				this.fetchTelosAggregate()
			}
			if (tab === 'heatmap' && this.isInstructor) {
				this.fetchHeatmap()
			}
			if (tab === 'weak-questions' && this.isInstructor) {
				this.fetchWeakQuestions()
			}
			if (tab === 'summary' && this.showCertReport && this.certRows.length === 0) {
				this.fetchCertReport()
			}
			if (tab === 'summary' && this.showCertReport && !this.coverageLoaded) {
				this.fetchCoverage()
			}
		},
		selectSubTab(tabId) {
			this.currentSubTab = tabId
			this.$emit('tab-change', tabId)
			this.lazyLoad(tabId)
		},

		// Helpers
		levelClass(level) {
			const l = level || 1
			if (l >= 10) return 'level-gold'
			if (l >= 5) return 'level-blue'
			return 'level-grey'
		},
		formatXp(value) {
			return formatXp(value)
		},
		/**
		 * The at-risk reasons in the reader's language (audit #154). The server sends them as codes
		 * with their number; a server without risk_reason_codes still gets its German sentences shown.
		 *
		 * @param {object} student at-risk entry from the API
		 * @return {string[]}
		 */
		riskReasonLabels(student) {
			const codes = student?.risk_reason_codes
			if (!Array.isArray(codes)) {
				return student?.risk_reasons || []
			}
			return codes.map(({ code, n }) => {
				switch (code) {
				case 'inactive_days': return t('learning', 'Inaktiv seit {n} Tagen', { n })
				case 'never_active': return t('learning', 'Noch nie aktiv gewesen')
				case 'low_accuracy': return t('learning', 'Trefferquote {n} %', { n })
				case 'critical_cards': return t('learning', 'Kritische Karten: {n}', { n })
				case 'box_one_share': return t('learning', '{n} % der Karten in Box 1', { n })
				case 'streak_lost': return t('learning', 'Streak verloren (war {n})', { n })
				case 'few_sessions': return t('learning', 'Sessions in 14 Tagen: {n}', { n })
				// GitHub #68: a course without Leitner judges by the exams.
				case 'not_passed': return t('learning', 'Noch nicht bestanden')
				case 'deadline_overdue': return t('learning', 'Frist überschritten')
				case 'deadline_days': return t('learning', 'Frist endet in {n} Tagen', { n })
				case 'certificate_expired': return t('learning', 'Zertifikat abgelaufen')
				case 'renewal_open': return t('learning', 'Erneuerung offen')
				default: return code
				}
			})
		},
		formatLastActive(dateStr) {
			return formatRelativeDateString(dateStr)
		},
		formatDate(timestamp) {
			if (!timestamp) return ''
			try {
				const ts = typeof timestamp === 'number' ? timestamp * 1000 : Date.parse(timestamp)
				const date = new Date(ts)
				if (isNaN(date.getTime())) return String(timestamp)
				return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
			} catch {
				return String(timestamp)
			}
		},
		getPoolMastery(row, poolId) {
			if (!row.pools || !Array.isArray(row.pools)) return null
			const pool = row.pools.find(p => p.pool_id === poolId)
			if (!pool) return null
			if (pool.total_questions > 0) {
				return Math.round((pool.mastered || 0) / pool.total_questions * 100)
			}
			return 0
		},
		/**
		 * GitHub #68: one pool cell of a course without Leitner: the best exam of a required pool
		 * (with its pass), otherwise the hit rate of the learner's sessions on the pool.
		 *
		 * @param {object} row progress row
		 * @param {number} poolId pool id
		 * @return {{text: string, cls: string, title: string, passed: boolean}}
		 */
		examCell(row, poolId) {
			const pool = (Array.isArray(row?.pools) ? row.pools : []).find(p => p.pool_id === poolId)
			const value = pool?.value ?? null
			if (pool?.metric === 'exam') {
				if (value === null) {
					// In the renewal window only new exams count; the old pass is still on the certificate.
					const title = row?.certificate_valid && row?.renewal_open
						? t('learning', 'Erneuerung offen: noch keine neue Prüfung abgelegt')
						: t('learning', 'Noch keine Prüfung abgelegt')
					return { text: '-', cls: '', title, passed: false }
				}
				return {
					text: value + '%',
					cls: pool.exam_passed ? 'mastery-high' : 'mastery-low',
					title: t('learning', 'Bestes Prüfungsergebnis'),
					passed: pool.exam_passed === true,
				}
			}
			if (value === null) {
				return { text: '-', cls: '', title: t('learning', 'Noch keine Antwort'), passed: false }
			}
			return { text: value + '%', cls: this.masteryClass(value), title: t('learning', 'Trefferquote beim Üben und in Prüfungen'), passed: false }
		},
		overallExamCell(row) {
			const score = row?.overall_mastery ?? null
			if (row?.overall_metric === 'exam') {
				// A valid certificate in its renewal window is not "not passed" (coverage: renewal_open).
				if (!row.overall_passed && row.certificate_valid) {
					return row.renewal_open
						? { text: t('learning', 'Zertifikat gültig · Erneuerung offen'), cls: 'mastery-medium' }
						: { text: t('learning', 'Zertifikat gültig'), cls: 'mastery-high' }
				}
				const label = row.overall_passed ? t('learning', 'Bestanden') : t('learning', 'Nicht bestanden')
				return {
					text: score !== null ? label + ' (' + score + '%)' : label,
					cls: row.overall_passed ? 'mastery-high' : (score !== null ? 'mastery-low' : ''),
				}
			}
			return { text: score !== null ? score + '%' : '-', cls: this.masteryClass(score) }
		},
		masteryClass(mastery) {
			if (mastery === null || mastery === undefined) return ''
			if (mastery >= 80) return 'mastery-high'
			if (mastery >= 40) return 'mastery-medium'
			return 'mastery-low'
		},
		criticalCardsClass(count) {
			const value = Number(count || 0)
			if (value >= 10) return 'critical-cards-high'
			if (value >= 3) return 'critical-cards-medium'
			return 'critical-cards-low'
		},
		sortedDistributionEntries(source) {
			if (!source || typeof source !== 'object') return []
			return Object.entries(source)
				.map(([key, value]) => ({ key, value: Number(value || 0) }))
				.sort((left, right) => right.value - left.value || left.key.localeCompare(right.key))
		},

		// Member CRUD
		async toggleMemberRole(member) {
			const newRole = member.role === 'student' ? 'instructor' : 'student'
			this.savingMember = member.id
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/members', { courseId: this.courseId })
				await axios.post(url, { userId: member.user_id, role: newRole })
				this.$emit('members-changed')
			} catch (err) {
				console.error('Failed to update member role:', err)
				this.memberError = t('learning', 'Mitgliedsrolle konnte nicht aktualisiert werden.')
			} finally {
				this.savingMember = null
			}
		},
		confirmRemoveMember(member) {
			this.removingMember = member
			this.showRemoveMemberModal = true
		},
		async removeMember() {
			if (!this.removingMember) return
			this.savingMember = this.removingMember.id
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/members/{memberId}', {
					courseId: this.courseId,
					memberId: this.removingMember.id,
				})
				await axios.delete(url)
				this.showRemoveMemberModal = false
				this.removingMember = null
				this.$emit('members-changed')
			} catch (err) {
				console.error('Failed to remove member:', err)
				this.memberError = t('learning', 'Mitglied konnte nicht entfernt werden.')
			} finally {
				this.savingMember = null
			}
		},

		// Progress
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
		async fetchProgress() {
			this.progressLoading = true
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/progress', { courseId: this.courseId })
				const response = await axios.get(url, {
					params: {
						limit: this.progressMeta.limit || this.progressPageSize,
						offset: this.progressMeta.offset || 0,
						sortKey: this.progressSortKey || (this.gamificationEnabled !== false ? 'total_xp' : 'user_id'),
						sortDir: this.progressSortAsc ? 'asc' : 'desc',
					},
				})
				const data = response.data
				if (Array.isArray(data)) {
					this.progressData = data
					this.progressMeta = { total: data.length, limit: this.progressPageSize, offset: 0 }
				} else if (data && Array.isArray(data.students)) {
					this.progressData = data.students
					this.progressMeta = {
						total: Number(data.meta?.total || data.students.length || 0),
						limit: Number(data.meta?.limit || this.progressPageSize),
						offset: Number(data.meta?.offset || 0),
						// GitHub #68: which figures the rows carry, and whether the course certifies.
						progress_basis: data.meta?.progress_basis || 'leitner',
						cert_applicable: data.meta?.cert_applicable === true,
					}
				} else {
					this.progressData = []
					this.progressMeta = { total: 0, limit: this.progressPageSize, offset: 0 }
				}
			} catch (err) {
				console.error('Failed to fetch progress:', err)
				this.$emit('error', t('learning', 'Fortschrittsdaten konnten nicht geladen werden.'))
			} finally {
				this.progressLoading = false
			}
		},
		exportAtRiskCsv() {
			const url = generateUrl('/apps/learning/api/courses/{courseId}/at-risk/export/csv', { courseId: this.courseId })
			window.location.href = url
		},
		async fetchAtRisk() {
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/at-risk', { courseId: this.courseId })
				const response = await axios.get(url)
				this.atRiskStudents = response.data.at_risk || []
				this.atRiskBasis = response.data.basis || 'leitner'
			} catch (err) {
				console.error('Failed to fetch at-risk students:', err)
			}
		},
		// Convert a 'YYYY-MM-DD' date-input value to unix seconds (UTC).
		// endOfDay pushes the upper bound to 23:59:59 so the `to` filter is inclusive.
		certDateToUnix(dateStr, endOfDay) {
			if (!dateStr) return null
			const ms = Date.parse(dateStr + 'T00:00:00Z')
			if (Number.isNaN(ms)) return null
			return Math.floor(ms / 1000) + (endOfDay ? 86399 : 0)
		},
		certFilters() {
			const days = this.certExpiringDays
			return {
				from: this.certDateToUnix(this.certFromDate, false),
				to: this.certDateToUnix(this.certToDate, true),
				expiringDays: (days === '' || days === null || days === undefined) ? null : Number(days),
			}
		},
		formatCertDate(unix) {
			return formatDate(unix)
		},
		formatCertScore(score) {
			return formatScore(score)
		},
		// The table fetch builds its query with buildCertReportQuery() and keeps it as
		// certAppliedQuery; the CSV and the ZIP reuse that query (must-have: table == CSV filtered set).
		async fetchCertReport() {
			try {
				const qs = buildCertReportQuery(this.certFilters())
				const url = generateUrl('/apps/learning/api/courses/{courseId}/cert-report', { courseId: this.courseId })
				const response = await axios.get(url + (qs ? '?' + qs : ''))
				this.certRows = response.data.rows || []
				this.certAppliedQuery = qs
				this.certSigningAvailable = typeof response.data.signing_available === 'boolean'
					? response.data.signing_available
					: null
				this.certPassedWithoutCertificate = Array.isArray(response.data.passed_without_certificate)
					? response.data.passed_without_certificate
					: []
			} catch (err) {
				console.error('Failed to fetch cert report:', err)
				this.$emit('error', t('learning', 'Compliance-Bericht konnte nicht geladen werden.'))
			}
		},
		coverageStatusLabel(status) {
			switch (status) {
			case 'current': return t('learning', 'Aktuell (Abdeckungsstatus)')
			case 'renewal_open': return t('learning', 'Erneuerung offen (Abdeckungsstatus)')
			case 'expired': return t('learning', 'Abgelaufen, in Kulanzzeit (Abdeckungsstatus)')
			case 'overdue': return t('learning', 'Überfällig (Abdeckungsstatus)')
			case 'never_passed': return t('learning', 'Nie bestanden (Abdeckungsstatus)')
			default: return status
			}
		},
		formatPoolProgress(passed, total) {
			return formatPoolProgress(passed, total)
		},
		formatContentProgress(confirmed, total) {
			return formatContentProgress(confirmed, total)
		},
		onCoverageStatusChange(status) {
			this.coverageStatusFilter = status
			this.fetchCoverage()
		},
		// Lückencheck B2: the table keeps the status it was loaded with; the CSV exports that one.
		// Only the newest request may fill the table: a slower answer to an earlier filter choice
		// would otherwise show its rows under the select's newer status.
		async fetchCoverage() {
			const status = this.coverageStatusFilter
			const seq = ++this.coverageRequestSeq
			try {
				const qs = buildCoverageQuery(status)
				const url = generateUrl('/apps/learning/api/courses/{courseId}/cert-coverage', { courseId: this.courseId })
				const response = await axios.get(url + (qs ? '?' + qs : ''))
				if (seq !== this.coverageRequestSeq) {
					return
				}
				this.coverageRows = Array.isArray(response.data.rows) ? response.data.rows : []
				this.coverageAppliedStatus = qs ? status : ''
				this.coverageLoaded = true
			} catch (err) {
				if (seq !== this.coverageRequestSeq) {
					return
				}
				console.error('Failed to fetch coverage report:', err)
				this.$emit('error', t('learning', 'Abdeckungsbericht konnte nicht geladen werden.'))
			}
		},
		exportCoverageCsv() {
			const qs = buildCoverageQuery(this.coverageAppliedStatus)
			const url = generateUrl('/apps/learning/api/courses/{courseId}/cert-coverage/export/csv', { courseId: this.courseId })
			window.location.href = url + (qs ? '?' + qs : '')
		},
		// GitHub #45: the CSV is the list the table shows — the query the rows were loaded with, not
		// filter inputs that were edited but not applied yet (same rule as the ZIP, #42).
		exportCertReportCsv() {
			const qs = this.certAppliedQuery
			const url = generateUrl('/apps/learning/api/courses/{courseId}/cert-report/export/csv', { courseId: this.courseId })
			window.location.href = url + (qs ? '?' + qs : '')
		},
		// GitHub #42: View opens the learner's certificate (course-scoped endpoint, instructor gate
		// server-side); its Print gives the same PDF the learner prints.
		viewCertificate(row) {
			this.viewingCertVid = row.verification_id
		},
		// GitHub #42: the learner's own certificate file, by navigation like the CSV (no CSRF token needed).
		downloadCertificate(row) {
			window.location.href = courseDownloadUrl(this.courseId, row.verification_id)
		},
		// GitHub #42: every certificate of the table as shown, in one ZIP — the query the rows were
		// loaded with, not filter inputs that were edited but not applied yet.
		downloadAllCertificates() {
			window.location.href = certReportZipUrl(this.courseId, this.certAppliedQuery)
		},
		// Instructor revoke: owner-gated + idempotent server-side (VERIFY-05). The /cert-report DTO
		// has no revoked flag, so we always allow the click and refetch — a revoked cert reflects
		// its new status on the next report load. The backend is the single source of truth.
		async revokeCertificate(row) {
			if (!window.confirm(t('learning', 'Zertifikat widerrufen? Diese Aktion kann nicht rückgängig gemacht werden.'))) {
				return
			}
			this.revokingVid = row.verification_id
			try {
				const url = generateUrl('/apps/learning/api/certificates/{verificationId}/revoke', { verificationId: row.verification_id })
				await axios.post(url)
				showSuccess(t('learning', 'Zertifikat wurde widerrufen'))
				await this.fetchCertReport()
				// A revoked certificate changes the learner's coverage status too.
				await this.fetchCoverage()
			} catch (err) {
				console.error('Failed to revoke certificate:', err)
				showError(t('learning', 'Widerruf fehlgeschlagen'))
			} finally {
				this.revokingVid = null
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
				this.telosAggregateError = t('learning', 'Klassen-Profil konnte nicht geladen werden.')
			} finally {
				this.telosAggregateLoading = false
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
.course-tab-teilnehmer {
	padding: 0;
}

.teilnehmer-subnav {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-bottom: 16px;
}

.teilnehmer-pill {
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

.teilnehmer-pill:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.teilnehmer-pill.active {
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

/* Members */
.members-section .member-picker { margin-bottom: 16px; }

.member-error { margin-bottom: 12px; }

.member-list { display: flex; flex-direction: column; gap: 4px; }

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

.member-name { font-weight: 600; color: var(--color-main-text); }

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

.progress-table tbody tr:nth-child(even) { background: var(--color-background-hover); }
.progress-table tbody tr:last-child td { border-bottom: none; }

.progress-pagination {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	padding: 10px 4px 0;
}

.progress-pagination-meta { font-size: 0.9em; color: var(--color-text-maxcontrast); }
.progress-pagination-actions { display: inline-flex; gap: 8px; }

.student-col { text-align: start !important; font-weight: 500; white-space: nowrap; }
.pool-col, .overall-col { min-width: 80px; }
.overall-col { font-weight: 600; }
.stat-col { min-width: 70px; text-align: center; white-space: nowrap; }

td.mastery-high { background: var(--color-success); color: var(--color-success-text, var(--color-success)); font-weight: 700; border-inline-start: 3px solid var(--color-element-success); }
.exam-passed-mark { margin-inline-start: 4px; font-weight: 700; }
td.mastery-medium { background: var(--color-warning); color: var(--color-warning-text, var(--color-warning)); font-weight: 700; border-inline-start: 3px solid var(--color-element-warning); }
td.mastery-low { background: var(--color-error); color: var(--color-error-text, var(--color-error)); font-weight: 700; border-inline-start: 3px solid var(--color-element-error); }
.critical-cards-col { text-align: center; }
.critical-cards-pill {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 34px;
	padding: 4px 10px;
	border-radius: 999px;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}
.critical-cards-high {
	background: var(--color-error);
	color: var(--color-error-text, var(--color-error));
}
.critical-cards-medium {
	background: var(--color-warning);
	color: var(--color-warning-text, var(--color-warning));
}
.critical-cards-low {
	background: var(--color-success);
	color: var(--color-success-text, var(--color-success));
}

.level-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.8em; font-weight: 700; white-space: nowrap; }
.level-grey { background: var(--color-background-dark); color: var(--color-text-maxcontrast); }
.level-blue { background: color-mix(in srgb, var(--color-primary-element) 15%, transparent); color: var(--color-primary-element); }
.level-gold { background: color-mix(in srgb, var(--color-warning) 20%, transparent); color: var(--color-warning); }

.sortable-col { cursor: pointer; user-select: none; }
.sortable-col:hover { background: color-mix(in srgb, var(--color-primary-element) 8%, var(--color-background-dark)); }
.sort-arrow { font-size: 0.7em; margin-left: 4px; }

.clickable-row { cursor: pointer; }
.clickable-row:hover td { background: color-mix(in srgb, var(--color-primary-element) 8%, transparent); transition: background 0.15s; }

.last-active-col { color: var(--color-text-maxcontrast); font-size: 0.85em; }

/* At-Risk */
.at-risk-section { margin-bottom: 24px; border: 2px solid var(--color-element-error); border-radius: 12px; padding: 16px 20px; background: color-mix(in srgb, var(--color-error) 5%, var(--color-main-background)); }
.at-risk-section.collapsed { padding-bottom: 16px; }
.at-risk-header { display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
.at-risk-title { margin: 0; font-size: 16px; font-weight: 700; color: var(--color-error-text, var(--color-error)); }
.at-risk-toggle { background: none; border: none; font-size: 14px; color: var(--color-text-maxcontrast); cursor: pointer; padding: 4px 8px; }
.at-risk-hint { margin: 8px 0 0; color: var(--color-text-maxcontrast); }
.progress-leitner-note { margin-bottom: 12px; }
.at-risk-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin-top: 12px; }
.at-risk-card { border: 1px solid var(--color-border); border-radius: 10px; padding: 14px 16px; background: var(--color-main-background); cursor: pointer; transition: box-shadow 0.2s, transform 0.15s; }
.at-risk-card:hover { box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); transform: translateY(-1px); }
.at-risk-card.risk-high { border-inline-start: 4px solid var(--color-element-error); }
.at-risk-card.risk-medium { border-inline-start: 4px solid var(--color-element-warning); }
.at-risk-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.at-risk-name { font-weight: 600; font-size: 14px; color: var(--color-main-text); }
.risk-badge { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.risk-badge.high { background: var(--color-error); color: var(--color-error-text, var(--color-error)); border: 1px solid var(--color-border-error); }
.risk-badge.medium { background: var(--color-warning); color: var(--color-warning-text, var(--color-warning)); border: 1px solid var(--color-element-warning); }
.at-risk-reasons { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.risk-reason-tag { padding: 2px 8px; border-radius: 6px; font-size: 11px; background: var(--color-background-hover); color: var(--color-text-maxcontrast); }
.at-risk-meta { display: flex; gap: 12px; font-size: 11px; color: var(--color-text-maxcontrast); }

/* Compliance report */
.cert-report-section { margin-bottom: 24px; border: 1px solid var(--color-border); border-radius: 12px; padding: 16px 20px; background: var(--color-main-background); }
.cert-report-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px; }
.cert-report-header-actions { display: flex; flex-wrap: wrap; gap: 4px; }
.cert-row-actions { display: flex; flex-wrap: wrap; gap: 4px; }
.cert-view-modal { padding: 16px 20px 20px; }
.cert-report-title { margin: 0; font-size: 16px; font-weight: 700; color: var(--color-main-text); }
.cert-report-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; margin-top: 12px; }
.cert-filter { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--color-text-maxcontrast); }
.cert-filter input { padding: 6px 8px; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-main-background); color: var(--color-main-text); }
.cert-report-hint { margin: 8px 0 0; font-size: 11px; color: var(--color-text-maxcontrast); }
.cert-report-table-container { margin-top: 12px; overflow-x: auto; }
.cert-report-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.cert-report-table th, .cert-report-table td { text-align: start; padding: 8px 10px; border-bottom: 1px solid var(--color-border); }
.cert-report-table th { color: var(--color-text-maxcontrast); font-weight: 600; }
.cert-report-table .cert-vid { font-family: monospace; font-size: 11px; color: var(--color-text-maxcontrast); }
.cert-report-table .cert-expired-tag { color: var(--color-error-text, var(--color-error)); font-weight: 600; }
.cert-report-empty { margin-top: 12px; color: var(--color-text-maxcontrast); font-size: 13px; }
.cert-coverage { margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--color-border); }
.cert-coverage-title { margin: 0; font-size: 14px; font-weight: 700; color: var(--color-main-text); }
.cert-filter select { padding: 6px 8px; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-main-background); color: var(--color-main-text); }
.cert-coverage-status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; border: 1px solid var(--color-border-dark, var(--color-border)); color: var(--color-main-text); background: transparent; }
.cert-coverage-status--current { color: var(--color-success-text, var(--color-main-text)); border-color: var(--color-success); }
.cert-coverage-status--renewal_open, .cert-coverage-status--expired { color: var(--color-warning-text, var(--color-main-text)); border-color: var(--color-warning); }
.cert-coverage-status--overdue { color: var(--color-error-text, var(--color-error)); border-color: var(--color-error); }
.cert-report-copies-hint { margin-block: 8px 0; color: var(--color-text-maxcontrast); font-size: 13px; }

/* Modal */
.modal-content { padding: 24px; min-width: 320px; }
.modal-content h3 { margin: 0 0 20px 0; font-size: 1.2em; font-weight: 700; color: var(--color-main-text); }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--color-border); }

/* Shared tab content */
.tab-content { padding: 16px 0; }
.loading-hint, .empty-hint { color: var(--color-text-maxcontrast); padding: 24px; text-align: center; }
.section-hint { color: var(--color-text-maxcontrast); margin-bottom: 16px; font-size: 0.9em; }

/* Heatmap */
.heatmap-list { display: flex; flex-direction: column; gap: 16px; }
.heatmap-row { padding-bottom: 16px; border-bottom: 1px solid var(--color-border); }
.heatmap-meta { display: flex; justify-content: space-between; margin-bottom: 6px; }
.chapter-title-hm { font-weight: 600; }
.heatmap-stats { color: var(--color-text-maxcontrast); font-size: 0.9em; }
.heatmap-no-worst { color: var(--color-text-maxcontrast); font-size: 0.9em; margin: 4px 0 0; }
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

@media (max-width: 768px) {
	.member-item { flex-direction: column; align-items: flex-start; }
	.member-actions { width: 100%; justify-content: flex-end; }
	.modal-content { padding: 16px; min-width: unset; }
}

@media (prefers-reduced-motion: reduce) {
	.member-item:hover { transform: none; }
	.at-risk-card:hover { transform: none; }
}
</style>
