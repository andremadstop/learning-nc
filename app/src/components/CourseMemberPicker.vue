<template>
	<div class="member-picker">
		<div class="member-picker__row">
			<NcSelect v-model="selected"
				class="member-picker__select"
				:input-label="t('learning', 'Personen oder Gruppen')"
				:placeholder="t('learning', 'Name oder Gruppe suchen …')"
				:options="options"
				:multiple="true"
				:filterable="false"
				:loading="searching"
				:close-on-select="false"
				:disabled="adding"
				label="label"
				@search="onSearch">
				<template #option="option">
					<span class="member-picker__option">
						<span class="member-picker__option-label">{{ option.label }}</span>
						<span class="member-picker__option-hint">{{ optionHint(option) }}</span>
					</span>
				</template>
			</NcSelect>
			<div class="member-picker__role">
				<label :for="roleSelectId">{{ t('learning', 'Rolle') }}</label>
				<select :id="roleSelectId" v-model="role" :disabled="adding">
					<option value="student">
						{{ t('learning', 'Lernende:r') }}
					</option>
					<option value="instructor">
						{{ t('learning', 'Kursleitung') }}
					</option>
				</select>
			</div>
			<NcButton class="member-picker__add"
				variant="primary"
				type="button"
				:disabled="selected.length === 0 || adding"
				@click="addSelected">
				{{ adding ? t('learning', 'Wird hinzugefügt …') : t('learning', 'Hinzufügen') }}
			</NcButton>
		</div>
		<p class="member-picker__help">
			{{ t('learning', 'Eine Gruppe schreibt alle ein, die jetzt in der Gruppe sind. Wer später dazukommt, wird nicht automatisch hinzugefügt.') }}
		</p>

		<NcNoteCard v-if="error" type="error" class="member-picker__note">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard v-if="result" :type="resultType" class="member-picker__note">
			<p v-if="result.added.length">
				{{ t('learning', 'Hinzugefügt ({n}): {names}', { n: result.added.length, names: names(result.added) }) }}
			</p>
			<p v-if="result.already.length">
				{{ t('learning', 'Schon im Kurs ({n}): {names}', { n: result.already.length, names: names(result.already) }) }}
			</p>
			<p v-if="result.roleUnchanged.length">
				{{ t('learning', 'Schon im Kurs mit einer anderen Rolle, Rolle nicht geändert ({n}): {names}. Die Rolle änderst du in der Liste unten.', { n: result.roleUnchanged.length, names: rolesText(result.roleUnchanged) }) }}
			</p>
			<p v-if="result.notFound.length">
				{{ t('learning', 'Nicht gefunden: {names}', { names: result.notFound.join(', ') }) }}
			</p>
			<p v-if="result.groupsUnavailable.length">
				{{ t('learning', 'Gruppe nicht verfügbar: {names}', { names: result.groupsUnavailable.join(', ') }) }}
			</p>
			<p v-if="result.failed.length">
				{{ t('learning', 'Konnte nicht hinzugefügt werden: {names}', { names: names(result.failed) }) }}
			</p>
			<p v-if="isEmptyResult">
				{{ t('learning', 'Niemand wurde hinzugefügt.') }}
			</p>
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { buildBulkPayload, toOptions } from '../utils/member-picker.js'

const SEARCH_DEBOUNCE_MS = 250

/**
 * Picker to enrol people and groups in a course (GitHub #35).
 *
 * Search runs through GET /member-search (Nextcloud's collaborator search, so the instance's
 * sharing and enumeration rules apply). "Add" posts {userIds, groupIds, role} to /members/bulk
 * and shows, per account, who was added, who already was a member (and whose role was NOT
 * changed to the chosen one) and what failed.
 */
let pickerCounter = 0

export default {
	name: 'CourseMemberPicker',

	components: {
		NcButton,
		NcNoteCard,
		NcSelect,
	},

	props: {
		courseId: {
			type: [Number, String],
			required: true,
		},
	},

	emits: ['members-changed'],

	data() {
		pickerCounter += 1
		return {
			selected: [],
			options: [],
			searchTerm: '',
			searching: false,
			searchTimer: null,
			searchSeq: 0,
			role: 'student',
			adding: false,
			result: null,
			error: '',
			roleSelectId: 'member-picker-role-' + pickerCounter,
		}
	},

	computed: {
		resultType() {
			if (!this.result) {
				return 'info'
			}
			if (this.result.failed.length) {
				return 'error'
			}
			if (this.result.roleUnchanged.length || this.result.notFound.length || this.result.groupsUnavailable.length || this.isEmptyResult) {
				return 'warning'
			}
			return 'success'
		},
		isEmptyResult() {
			return !!this.result && this.result.added.length === 0 && this.result.already.length === 0 && this.result.roleUnchanged.length === 0
		},
	},

	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},

	methods: {
		optionHint(option) {
			if (option.type === 'group') {
				return t('learning', 'Gruppe')
			}
			return option.isMember ? t('learning', 'schon im Kurs') : option.value
		},

		names(entries) {
			return entries.map((entry) => entry.display_name || entry.user_id).join(', ')
		},

		rolesText(entries) {
			return entries.map((entry) => {
				const role = entry.role === 'instructor' ? t('learning', 'Kursleitung') : t('learning', 'Lernende:r')
				return (entry.display_name || entry.user_id) + ' (' + role + ')'
			}).join(', ')
		},

		onSearch(term) {
			this.searchTerm = (term || '').trim()
			clearTimeout(this.searchTimer)
			if (!this.searchTerm) {
				this.options = []
				this.searching = false
				return
			}
			this.searching = true
			this.searchTimer = setTimeout(() => this.runSearch(this.searchTerm), SEARCH_DEBOUNCE_MS)
		},

		async runSearch(term) {
			const seq = ++this.searchSeq
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/member-search', { courseId: this.courseId })
				const { data } = await axios.get(url, { params: { search: term } })
				if (seq !== this.searchSeq) {
					return // a newer search is on its way
				}
				this.options = toOptions(data)
				this.error = ''
			} catch (err) {
				if (seq !== this.searchSeq) {
					return
				}
				console.error('Member search failed:', err)
				this.options = []
				this.error = t('learning', 'Die Suche ist fehlgeschlagen.')
			} finally {
				if (seq === this.searchSeq) {
					this.searching = false
				}
			}
		},

		async addSelected() {
			if (this.selected.length === 0 || this.adding) {
				return
			}
			this.adding = true
			this.error = ''
			this.result = null
			try {
				const url = generateUrl('/apps/learning/api/courses/{courseId}/members/bulk', { courseId: this.courseId })
				const { data } = await axios.post(url, buildBulkPayload(this.selected, this.role))
				this.applyResult(data)
			} catch (err) {
				console.error('Failed to add members:', err)
				const data = err.response?.data
				if (data && Array.isArray(data.failed)) {
					// partial enrolment: the server names who failed and who was added
					this.applyResult(data)
				} else {
					this.error = data?.error || t('learning', 'Mitglieder konnten nicht hinzugefügt werden.')
				}
			} finally {
				this.adding = false
			}
		},

		applyResult(data) {
			this.result = {
				added: data?.added || [],
				already: data?.already || [],
				roleUnchanged: data?.roleUnchanged || [],
				notFound: data?.notFound || [],
				groupsUnavailable: data?.groupsUnavailable || [],
				failed: data?.failed || [],
			}
			if (this.result.added.length > 0) {
				this.$emit('members-changed')
			}
			if (this.result.failed.length === 0) {
				this.selected = []
			}
		},
	},
}
</script>

<style scoped>
.member-picker__row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
}

.member-picker__select {
	flex: 1 1 280px;
	min-width: 0;
}

.member-picker__role {
	display: flex;
	flex-direction: column;
	flex: 0 0 auto;
}

.member-picker__role label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.member-picker__role select {
	min-height: 36px;
}

/* The old "Add" button shrank to "A…" next to the field: never let it shrink. */
.member-picker__add {
	flex-shrink: 0;
}

.member-picker__option {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.member-picker__option-hint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.member-picker__help {
	margin: 6px 0 0;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.member-picker__note p {
	margin: 0 0 4px;
}

@media (max-width: 600px) {
	.member-picker__row {
		flex-direction: column;
		align-items: stretch;
	}
}
</style>
