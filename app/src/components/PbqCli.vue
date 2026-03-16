<template>
  <div class="pbq-cli">
    <div
      v-for="term in config.terminals"
      :key="term.name"
      class="pbq-terminal"
    >
      <div class="pbq-terminal-titlebar">
        <span>{{ term.name }}</span>
        <span class="pbq-terminal-close">&#x2715;</span>
      </div>
      <div class="pbq-terminal-body" :ref="'body_' + term.name">
        <div v-for="(line, i) in getHistory(term.name)" :key="i" class="pbq-terminal-line">{{ line }}</div>
        <div class="pbq-terminal-input-row">
          <span class="pbq-terminal-prompt">{{ term.initial_prompt }} </span>
          <input
            v-if="!disabled"
            v-model="inputBuffers[term.name]"
            @keydown.enter.prevent="submitCommand(term)"
            class="pbq-terminal-input"
            autocomplete="off"
            spellcheck="false"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'PbqCli',
  props: {
    config: { type: Object, required: true },
    value: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
  },
  data() {
    const inputBuffers = {}
    const localHistory = {}
    for (const term of (this.config.terminals || [])) {
      inputBuffers[term.name] = ''
      localHistory[term.name] = Array.isArray(this.value[term.name]) ? [...this.value[term.name]] : []
    }
    return { inputBuffers, localHistory }
  },
  methods: {
    getHistory(termName) { return this.localHistory[termName] || [] },
    submitCommand(term) {
      const cmd = this.inputBuffers[term.name].trim()
      if (!cmd) return
      const prompt = term.initial_prompt || '>'
      const history = this.localHistory[term.name] || []
      history.push(prompt + ' ' + cmd)
      this.$set(this.localHistory, term.name, history)
      this.inputBuffers[term.name] = ''
      this.$emit('update', term.name, [...history])
      this.$nextTick(() => {
        const refs = this.$refs['body_' + term.name]
        const el = Array.isArray(refs) ? refs[0] : refs
        if (el) el.scrollTop = el.scrollHeight
      })
    },
  },
}
</script>

<style scoped>
.pbq-cli { display: flex; flex-direction: column; gap: 16px; margin-top: 16px; }
.pbq-terminal { border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.25); }
.pbq-terminal-titlebar {
  background: #1565c0; color: #fff; padding: 6px 12px;
  display: flex; justify-content: space-between; align-items: center;
  font-size: 13px; font-family: monospace; font-weight: 600;
}
.pbq-terminal-close { cursor: default; opacity: .6; }
.pbq-terminal-body {
  background: #1e1e1e; color: #d4d4d4; font-family: 'Cascadia Code', 'Consolas', 'Courier New', monospace;
  font-size: 13px; padding: 12px; min-height: 140px; max-height: 280px;
  overflow-y: auto; line-height: 1.6;
}
.pbq-terminal-line { white-space: pre-wrap; }
.pbq-terminal-input-row { display: flex; align-items: center; }
.pbq-terminal-prompt { color: #9cdcfe; white-space: pre; flex-shrink: 0; }
.pbq-terminal-input {
  flex: 1; background: transparent; border: none; outline: none; caret-color: #fff;
  color: #d4d4d4; font-family: inherit; font-size: inherit;
}
</style>
