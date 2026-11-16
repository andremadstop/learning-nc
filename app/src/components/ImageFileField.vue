<template>
	<!-- GitHub #72: one image field with its error right beside it. The parent chooses the file
		(file picker, upload, …) on "pick" and passes a refused file back as `error`; the field shows
		it below the buttons, announces it (live region) and ties it and the limit hint to the pick
		button (aria-describedby). Reused by the certificate logo library (#78). -->
	<div
		class="image-file-field"
		:class="{ 'image-file-field--error': !!error }"
		role="group"
		:aria-labelledby="labelId"
		:data-test="testId || null">
		<div class="image-file-field__row">
			<span :id="labelId" class="image-file-field__label">{{ label }}</span>
			<img
				v-if="imageUrl"
				:src="imageUrl"
				:alt="imageAlt || label"
				class="image-file-field__thumb">
			<NcButton
				variant="secondary"
				:disabled="disabled || busy"
				:data-test="pickTestId || null"
				:aria-label="pickAriaLabel || null"
				:aria-describedby="describedIds"
				:aria-invalid="error ? 'true' : null"
				@click="$emit('pick')">
				{{ pickLabel }}
			</NcButton>
			<NcButton
				v-if="imageUrl"
				variant="tertiary"
				:disabled="disabled || busy"
				:data-test="removeTestId || null"
				:aria-label="removeAriaLabel || null"
				@click="$emit('remove')">
				{{ removeLabel || t('learning', 'Entfernen') }}
			</NcButton>
		</div>
		<p v-if="hint" :id="hintId" class="image-file-field__hint">
			{{ hint }}
		</p>
		<!-- always in the DOM: a live region that appears together with its text is often not read -->
		<p
			:id="errorId"
			class="image-file-field__error"
			aria-live="polite"
			aria-atomic="true"
			:data-test="testId ? testId + '-error' : null">
			<template v-if="error">
				{{ error }}
			</template>
		</p>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'

export default {
	name: 'ImageFileField',

	components: {
		NcButton,
	},

	props: {
		/** unique per page; the ids of label, hint and error derive from it */
		idBase: { type: String, required: true },
		label: { type: String, required: true },
		pickLabel: { type: String, required: true },
		pickAriaLabel: { type: String, default: '' },
		removeLabel: { type: String, default: '' },
		removeAriaLabel: { type: String, default: '' },
		imageUrl: { type: String, default: '' },
		imageAlt: { type: String, default: '' },
		/** the limits, shown below the field before anything is chosen */
		hint: { type: String, default: '' },
		/** ids of hints outside the field (e.g. one hint for several fields) */
		describedBy: { type: String, default: '' },
		/** why the last file was refused; empty when there is nothing to say */
		error: { type: String, default: '' },
		busy: { type: Boolean, default: false },
		disabled: { type: Boolean, default: false },
		testId: { type: String, default: '' },
		pickTestId: { type: String, default: '' },
		removeTestId: { type: String, default: '' },
	},

	emits: ['pick', 'remove'],

	computed: {
		labelId() {
			return `${this.idBase}-label`
		},
		hintId() {
			return `${this.idBase}-hint`
		},
		errorId() {
			return `${this.idBase}-error`
		},
		describedIds() {
			return [this.describedBy, this.hint ? this.hintId : '', this.errorId].filter(Boolean).join(' ')
		},
	},
}
</script>

<style scoped>
.image-file-field {
	margin-bottom: 6px;
}

.image-file-field__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.image-file-field__label {
	min-width: 110px;
	font-weight: 600;
}

.image-file-field__thumb {
	max-height: 48px;
	max-width: 120px;
	object-fit: contain;
	/* the image is printed on white paper: show it on white in dark mode too */
	background: #fff;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
}

.image-file-field__hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.image-file-field__error {
	margin: 0;
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

.image-file-field--error .image-file-field__error {
	margin-top: 4px;
	padding-inline-start: 8px;
	border-inline-start: 3px solid var(--color-error);
}
</style>
