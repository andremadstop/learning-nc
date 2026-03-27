<template>
  <div v-if="outfit !== 'standard'" class="nova-accessory">
    <!-- Future outfits: admin, security, guru, feierabend -->
  </div>
</template>

<script>
export default {
	name: 'NovaAccessorySlot',
	props: {
		outfit: { type: String, default: 'standard' },
		characterId: { type: String, default: 'nova' },
	},
}
</script>

<style scoped>
.nova-accessory {
  position: absolute;
  inset: 0;
  pointer-events: none;
}
</style>
