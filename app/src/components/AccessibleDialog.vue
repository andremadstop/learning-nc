<template>
  <NcDialog
    ref="dialog"
    v-bind="$attrs"
    v-on="$listeners"
    @keydown.native.capture="handleKeydown">
    <div ref="content" class="accessible-dialog__content" tabindex="-1">
      <slot />
    </div>
    <template v-if="$slots.actions" #actions>
      <slot name="actions" />
    </template>
  </NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import { focusFirstElement, trapTabKey } from '../utils/dialogFocus.js'

export default {
  name: 'AccessibleDialog',
  components: { NcDialog },
  inheritAttrs: false,
  mounted() {
    this.$nextTick(() => {
      focusFirstElement(this.$refs.content, this.$refs.content)
    })
  },
  methods: {
    handleKeydown(event) {
      trapTabKey(event, this.$refs.dialog?.$el || this.$el, this.$refs.content)
    },
  },
}
</script>

<style scoped>
.accessible-dialog__content:focus {
  outline: none;
}
</style>
