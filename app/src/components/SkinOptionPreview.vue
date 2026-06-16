<template>
	<!-- Decorative preview: the sibling text label names the option. aria-hidden -->
	<!-- keeps the avatar's role=img/aria-label out of the NcSelect option's -->
	<!-- accessible name (else it became "<avatar> Prof. Lern" and broke -->
	<!-- exact-name option lookups + announced redundantly to screen readers). -->
	<span class="skin-option-preview" :style="boxStyle" aria-hidden="true">
		<NovaAvatar v-if="skinId === 'nova'" :size="size" />
		<ProfLernAvatar v-else-if="skinId === 'prof_lern_classic'" :size="size" />
		<TheoretikerAvatar v-else-if="skinId === 'theoretiker'" :size="size" />
		<KosmologeAvatar v-else-if="skinId === 'kosmologe'" :size="size" />
		<PopularisiererAvatar v-else-if="skinId === 'popularisierer'" :size="size" />
		<CharacterAvatar v-else :character-id="skinId" state="idle" :size="size" />
	</span>
</template>

<script>
import NovaAvatar from './nova/NovaAvatar.vue'
import ProfLernAvatar from './ProfLernAvatar.vue'
import TheoretikerAvatar from './TheoretikerAvatar.vue'
import KosmologeAvatar from './KosmologeAvatar.vue'
import PopularisiererAvatar from './PopularisiererAvatar.vue'
import CharacterAvatar from './CharacterAvatar.vue'

export default {
	name: 'SkinOptionPreview',
	components: { NovaAvatar, ProfLernAvatar, TheoretikerAvatar, KosmologeAvatar, PopularisiererAvatar, CharacterAvatar },
	props: {
		skinId: { type: String, required: true },
		size: { type: Number, default: 32 },
	},
	computed: {
		boxStyle() {
			const px = this.size + 'px'
			return { width: px, height: px, display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }
		},
	},
}
</script>

<style scoped>
.skin-option-preview {
	flex-shrink: 0;
	pointer-events: none;
}
</style>
