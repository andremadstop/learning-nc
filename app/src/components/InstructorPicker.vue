<template>
	<div class="instructor-picker">
		<NcSelect :model-value="modelValue"
			class="instructor-picker__select"
			:input-label="label || t('learning', 'Kursleitende (Gruppen und Personen)')"
			:placeholder="placeholder || t('learning', 'Gruppe oder Person suchen …')"
			:options="options"
			:multiple="true"
			:filterable="false"
			:loading="searching"
			:close-on-select="false"
			:disabled="disabled"
			label="label"
			@search="onSearch"
			@update:model-value="$emit('update:modelValue', $event || [])">
			<template #option="option">
				<span class="instructor-picker__option">
					<span class="instructor-picker__option-label">{{ option.label }}</span>
					<span class="instructor-picker__option-hint">{{ optionHint(option) }}</span>
				</span>
			</template>
		</NcSelect>
		<p v-if="error" class="instructor-picker__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { toOptions } from '../utils/member-picker.js'

const SEARCH_DEBOUNCE_MS = 250

/**
 * GitHub #60: Nextcloud groups and individual people who count as instructors app-wide, picked
 * from the instance's own groups and accounts instead of one typed group name. The value is a
 * list of picker options ({id: 'group:<gid>' | 'user:<uid>', type, value, label}); the admin page
 * saves it with toInstructorPayload().
 */
export default {
	name: 'InstructorPicker',

	components: { NcSelect },

	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},
		disabled: {
			type: Boolean,
			default: false,
		},
		// GitHub #59: the same picker chooses who is told of technical tickets (accounts only)
		label: {
			type: String,
			default: '',
		},
		placeholder: {
			type: String,
			default: '',
		},
		// 'instructors': groups and people (#60); 'ticket-recipients': administrators only (#59)
		kind: {
			type: String,
			default: 'instructors',
			validator: (value) => ['instructors', 'ticket-recipients'].includes(value),
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			options: [],
			searching: false,
			searchTimer: null,
			searchSeq: 0,
			error: '',
		}
	},

	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},

	methods: {
		searchEndpoint() {
			return this.kind === 'ticket-recipients'
				? generateUrl('/apps/learning/api/settings/admin/ticket-recipient-search')
				: generateUrl('/apps/learning/api/settings/admin/instructor-search')
		},

		optionHint(option) {
			return option.type === 'group' ? t('learning', 'Gruppe') : option.value
		},

		onSearch(term) {
			const search = (term || '').trim()
			clearTimeout(this.searchTimer)
			if (!search) {
				this.searchSeq += 1
				this.options = []
				this.searching = false
				return
			}
			this.searching = true
			this.searchTimer = setTimeout(() => this.runSearch(search), SEARCH_DEBOUNCE_MS)
		},

		async runSearch(term) {
			const seq = ++this.searchSeq
			try {
				const { data } = await axios.get(this.searchEndpoint(), { params: { search: term } })
				if (seq !== this.searchSeq) {
					return // a newer search is on its way
				}
				this.options = toOptions(data)
				this.error = ''
			} catch (err) {
				if (seq !== this.searchSeq) {
					return
				}
				console.error('Instructor search failed:', err)
				this.options = []
				this.error = t('learning', 'Die Suche ist fehlgeschlagen.')
			} finally {
				if (seq === this.searchSeq) {
					this.searching = false
				}
			}
		},
	},
}
</script>

<style scoped>
.instructor-picker__select {
	width: 100%;
	max-width: 560px;
}

.instructor-picker__option {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.instructor-picker__option-hint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.instructor-picker__error {
	margin: 4px 0 0;
	color: var(--color-error-text, var(--color-error));
}
</style>
