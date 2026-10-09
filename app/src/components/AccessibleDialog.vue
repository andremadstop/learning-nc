<template>
  <NcDialog
    ref="dialog"
    v-bind="$attrs"
    @keydown.capture="handleKeydown">
    <div ref="content" class="accessible-dialog__content" tabindex="-1">
      <slot />
    </div>
    <template v-if="$slots.actions" #actions>
      <slot name="actions" />
    </template>
  </NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
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
      trapTabKey(event, this.trapRoot(event), this.$refs.content)
    },
    // The dialog element in the DOM. NcDialog's root is NcModal, whose root is a Teleport, so
    // `$refs.dialog.$el` is only the teleport anchor. The capture listener sits on the modal mask
    // (NcModal passes $attrs to it), so the event's currentTarget is the whole dialog.
    trapRoot(event) {
      const target = event?.currentTarget
      if (target && typeof target.querySelectorAll === 'function' && target.contains?.(this.$refs.content)) {
        return target
      }
      return this.$refs.content?.closest?.('[role="dialog"]') || this.$refs.content
    },
  },
}
</script>

<style scoped>
.accessible-dialog__content:focus {
  outline: none;
}
</style>
