<template>
  <div class="pbq-cli">
    <div v-if="config.hint" class="pbq-cli-hint">
      <span class="pbq-cli-hint-icon">💡</span>
      <span>{{ config.hint }}</span>
    </div>
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
          <span class="pbq-terminal-prompt">{{ currentPrompt(term) }} </span>
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
import { evaluateCommand, getPrompt, DOMAIN_SCHEMAS } from '../utils/cliStateMachine'

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
    const termModes = {}
    const termContexts = {}
    for (const term of (this.config.terminals || [])) {
      inputBuffers[term.name] = ''
      localHistory[term.name] = Array.isArray(this.value[term.name]) ? [...this.value[term.name]] : []
      const domain = this.config.domain || 'generic'
      const schema = DOMAIN_SCHEMAS[domain] || DOMAIN_SCHEMAS.generic
      termModes[term.name] = schema.defaultMode
      termContexts[term.name] = {}
    }
    return { inputBuffers, localHistory, termModes, termContexts }
  },
  methods: {
    getHistory(termName) { return this.localHistory[termName] || [] },
    resolveCommandOutputs(termName) {
      const outputs = this.config.command_outputs || {}
      const scopedOutputs = outputs[termName]
      if (scopedOutputs && typeof scopedOutputs === 'object' && !Array.isArray(scopedOutputs)) {
        return scopedOutputs
      }
      return outputs
    },
    currentPrompt(term) {
      const domain = this.config.domain || 'generic'
      const mode = this.termModes[term.name]
      const context = this.termContexts[term.name] || {}
      // Backward compat: if no domain field and term has initial_prompt, use it
      if (!this.config.domain && term.initial_prompt) {
        return term.initial_prompt
      }
      return getPrompt(domain, mode, term.name, context)
    },
    submitCommand(term) {
      const cmd = this.inputBuffers[term.name].trim()
      if (!cmd) return

      const domain = this.config.domain || 'generic'
      const currentMode = this.termModes[term.name]
      const context = this.termContexts[term.name] || {}
      const commandOutputs = this.resolveCommandOutputs(term.name)

      // Build the prompt line for this command (the line that appears as typed)
      const promptStr = this.currentPrompt(term)
      const cmdLine = promptStr + ' ' + cmd

      // Evaluate the command against the state machine
      const result = evaluateCommand(cmd, domain, currentMode, context, commandOutputs)

      // Build the history array update
      const history = this.localHistory[term.name] || []
      history.push(cmdLine)
      for (const line of result.lines) {
        history.push(line)
      }

      // Update reactive state using $set (Vue 2 reactivity safe)
      this.$set(this.localHistory, term.name, history)
      this.$set(this.termModes, term.name, result.nextMode)
      this.$set(this.termContexts, term.name, result.nextContext)

      // Clear input
      this.inputBuffers[term.name] = ''

      // Emit (keep existing emit contract: full history array per terminal)
      this.$emit('update', term.name, [...history])

      // Scroll (keep existing $nextTick scroll logic)
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
.pbq-cli-hint {
  display: flex; align-items: flex-start; gap: 8px;
  padding: 10px 14px; background: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
  border-radius: 8px; font-size: 13px; color: var(--color-main-text); line-height: 1.4;
}
.pbq-cli-hint-icon { flex-shrink: 0; }
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
