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
  props: {
    // GitHub #99 review: an aria-describedby passed through $attrs never reached the dialog,
    // because NcModal merges its own "modal-description-<id>" over $attrs. Declared as a prop it
    // leaves $attrs and is set on the role="dialog" element itself (applyDescribedBy()).
    ariaDescribedby: {
      type: String,
      default: '',
    },
  },
  watch: {
    ariaDescribedby() {
      this.$nextTick(() => this.applyDescribedBy())
    },
  },
  mounted() {
    this.$nextTick(() => {
      this.applyDescribedBy()
      focusFirstElement(this.$refs.content, this.$refs.content)
    })
  },
  methods: {
    // NcModal's vnode keeps the same "modal-description-<id>" on every patch, so Vue does not
    // write the attribute again and the value set here stays.
    applyDescribedBy() {
      if (!this.ariaDescribedby) {
        return
      }
      const dialog = this.$refs.content?.closest?.('[role="dialog"]')
      if (dialog) {
        dialog.setAttribute('aria-describedby', this.ariaDescribedby)
      }
    },
    handleKeydown(event) {
      if (this.closeOnEscapeFromToggle(event)) {
        return
      }
      trapTabKey(event, this.trapRoot(event), this.$refs.content)
    },
    /**
     * Persona sweep C9b (#99): NcModal ignores Escape while an input has the focus (useHotKey,
     * so that typing is not cut off). That also holds for a checkbox or radio button, which takes
     * no typing: in the archive confirmation, Escape on "Nicht mehr anzeigen" closed nothing. For
     * those, Escape takes the dialog's own close button, the same way as a click on its ×, so
     * every @closing / @close of the caller runs. Text fields keep NcModal's behaviour.
     */
    closeOnEscapeFromToggle(event) {
      if (event?.key !== 'Escape' || event.defaultPrevented) {
        return false
      }
      const target = event.target
      if (!target || target.tagName !== 'INPUT' || !['checkbox', 'radio'].includes(String(target.type).toLowerCase())) {
        return false
      }
      const close = this.trapRoot(event)?.querySelector?.('.modal-container__close')
      if (!close) {
        return false
      }
      event.preventDefault()
      close.click()
      return true
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
