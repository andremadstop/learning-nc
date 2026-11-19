<template>
	<!-- GitHub #99: what archiving a course changes, for instructors. The same text in the
		confirmation before archiving (CourseList.vue) and on the archived course (CourseDetail.vue).
		It describes the code as it is: tests/Unit/Service/CourseArchiveReadersInventoryTest.php
		fails when something starts reading the course status, and this text must follow. -->
	<div class="course-archive-explanation" data-test="course-archive-explanation">
		<p>{{ t('learning', 'Archivieren kennzeichnet den Kurs als abgeschlossen. Es löscht nichts und ändert keine Ergebnisse.') }}</p>
		<p :id="keepId" class="course-archive-explanation__label">
			{{ t('learning', 'Lernende können weiterhin alles nutzen, was der Kurs anbietet:') }}
		</p>
		<ul :aria-labelledby="keepId">
			<li>{{ t('learning', 'den Kurs in ihrer Kursliste sehen und öffnen') }}</li>
			<li>{{ t('learning', 'trainieren, wiederholen und das Lernmaterial öffnen') }}</li>
			<li>{{ t('learning', 'Prüfungen ablegen, die wie bisher zählen') }}</li>
			<li>{{ t('learning', 'Zertifikate erhalten; bestehende Zertifikate laufen wie bisher ab, und an die Erneuerung wird weiter erinnert') }}</li>
			<li>{{ t('learning', 'fällige Wiederholungen im Kalender-Abo sehen') }}</li>
		</ul>
		<!-- Both losses hang on the course's exam date: without one, learners lose nothing. -->
		<template v-if="hasExamDate">
			<p :id="stopId" class="course-archive-explanation__label">
				{{ t('learning', 'Lernende bekommen nicht mehr:') }}
			</p>
			<ul :aria-labelledby="stopId">
				<li>{{ t('learning', 'die Erinnerung an den Prüfungstermin des Kurses') }}</li>
				<li>{{ t('learning', 'den Countdown zum Prüfungstermin im Nextcloud-Dashboard') }}</li>
			</ul>
		</template>
		<p v-else data-test="course-archive-no-loss">
			{{ t('learning', 'Für Lernende ändert sich nichts, denn der Kurs hat keinen Prüfungstermin.') }}
		</p>
		<p>{{ t('learning', 'Du kannst den Kurs weiter bearbeiten und Teilnehmende einschreiben.') }}</p>
		<p data-test="course-archive-reactivate">
			{{ canReactivate
				? t('learning', 'Du kannst den Kurs jederzeit über das Menü in der Kursliste wieder aktivieren.')
				: t('learning', 'Wieder aktivieren kann ihn die Person, die ihn angelegt hat.') }}
		</p>
	</div>
</template>

<script>
let nextId = 0

export default {
	name: 'CourseArchiveExplanation',
	props: {
		/** The reactivation menu of the course list is only on the courses a person created. */
		canReactivate: {
			type: Boolean,
			default: true,
		},
		/**
		 * The exam-date reminder and the dashboard countdown are all learners lose: a course
		 * without an exam date loses nothing. Defaults to the full list for a caller that does not say.
		 */
		hasExamDate: {
			type: Boolean,
			default: true,
		},
	},
	data() {
		nextId += 1
		return {
			keepId: `course-archive-keep-${nextId}`,
			stopId: `course-archive-stop-${nextId}`,
		}
	},
}
</script>

<style scoped>
.course-archive-explanation p {
	margin: 0 0 8px;
}

.course-archive-explanation ul {
	margin: 0 0 12px;
	padding-inline-start: 20px;
	list-style: disc;
}

.course-archive-explanation li {
	margin-block: 2px;
	overflow-wrap: anywhere;
}

.course-archive-explanation__label {
	font-weight: bold;
}

.course-archive-explanation p:last-child {
	margin-bottom: 0;
}
</style>
