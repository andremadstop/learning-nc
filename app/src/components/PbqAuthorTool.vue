<template>
  <div class="pbq-author-tool">
    <!-- Subtype selector -->
    <div class="author-section">
      <label class="author-label">PBQ-Typ</label>
      <select v-model="selectedSubtype" class="author-select">
        <option value="cli">CLI Terminal</option>
        <option value="placement">Placement (Topologie)</option>
        <option value="dropdown">Dropdown-Auswahl</option>
        <option value="cable">Kabel-Verbindung</option>
        <option value="multi_panel">Multi-Panel (CLI + Placement)</option>
      </select>
    </div>

    <!-- CLI Section -->
    <div v-if="selectedSubtype === 'cli'" class="author-section">
      <h3 class="author-heading">CLI-Konfiguration</h3>

      <div class="author-field">
        <label class="author-label">Domain</label>
        <select v-model="cliForm.domain" class="author-select">
          <option v-for="d in validDomains" :key="d" :value="d">{{ d }}</option>
        </select>
      </div>

      <div class="author-field">
        <label class="author-label">Hinweis (optional)</label>
        <input v-model="cliForm.hint" type="text" class="author-input" placeholder="z.B. Konfiguriere das Interface" />
      </div>

      <div class="author-field">
        <label class="author-label">Terminals</label>
        <div v-for="(term, i) in cliForm.terminals" :key="i" class="author-array-row">
          <input v-model="cliForm.terminals[i].name" type="text" class="author-input" placeholder="Terminal-Name" />
          <button class="author-btn-remove" :disabled="cliForm.terminals.length <= 1" @click="removeTerminal(i)">-</button>
        </div>
        <button class="author-btn-add" @click="addTerminal">+ Terminal</button>
      </div>

      <div class="author-field">
        <label class="author-label">Befehle und Ausgaben</label>
        <div v-for="(pair, i) in cliForm.commandOutputs" :key="i" class="author-cmd-row">
          <input v-model="cliForm.commandOutputs[i].cmd" type="text" class="author-input author-input-cmd" placeholder="Befehl (z.B. show version)" />
          <textarea v-model="cliForm.commandOutputs[i].output" class="author-textarea-cmd" placeholder="Ausgabe des Befehls" rows="3" />
          <button class="author-btn-remove" @click="removeCommandOutput(i)">-</button>
        </div>
        <button class="author-btn-add" @click="addCommandOutput">+ Befehl</button>
      </div>
    </div>

    <!-- Placement Section -->
    <div v-if="selectedSubtype === 'placement'" class="author-section">
      <h3 class="author-heading">Placement-Konfiguration</h3>

      <div class="author-field">
        <label class="author-label">Bewertungsmodus</label>
        <select v-model="placementForm.scoringMode" class="author-select">
          <option value="strict">Strict (jede Position muss stimmen)</option>
          <option value="partial">Partial (Teilpunkte)</option>
        </select>
      </div>

      <div class="author-field">
        <label class="author-label">Positionen</label>
        <div v-for="(pos, i) in placementForm.positions" :key="i" class="author-array-row">
          <input v-model="placementForm.positions[i].id" type="text" class="author-input author-input-sm" placeholder="ID (z.B. n1)" />
          <input v-model="placementForm.positions[i].label" type="text" class="author-input" placeholder="Label (z.B. Core Router)" />
          <input v-model="placementForm.positions[i].correct" type="text" class="author-input author-input-sm" placeholder="Richtig (z.B. router)" />
          <button class="author-btn-remove" :disabled="placementForm.positions.length <= 1" @click="removePosition(i)">-</button>
        </div>
        <button class="author-btn-add" @click="addPosition">+ Position</button>
      </div>

      <div class="author-field">
        <label class="author-label">Geraete-Optionen</label>
        <div class="author-checkboxes">
          <label v-for="dt in validDeviceTypes" :key="dt" class="author-checkbox-label">
            <input
              type="checkbox"
              :value="dt"
              :checked="placementForm.deviceOptions.includes(dt)"
              @change="toggleDeviceOption(dt)"
            />
            {{ dt }}
          </label>
        </div>
      </div>

      <div class="author-field">
        <label class="author-checkbox-label">
          <input v-model="placementForm.useTopology" type="checkbox" />
          SVG-Topologie verwenden
        </label>
      </div>

      <div v-if="placementForm.useTopology" class="author-topology-editor">
        <h4 class="author-subheading">Topologie-Knoten</h4>
        <div v-for="(node, i) in topologyForm.nodes" :key="i" class="author-array-row">
          <input v-model="topologyForm.nodes[i].id" type="text" class="author-input author-input-sm" placeholder="ID" />
          <select v-model="topologyForm.nodes[i].type" class="author-select author-select-sm">
            <option v-for="dt in validDeviceTypes" :key="dt" :value="dt">{{ dt }}</option>
          </select>
          <input v-model="topologyForm.nodes[i].label" type="text" class="author-input" placeholder="Label" />
          <input v-model.number="topologyForm.nodes[i].x" type="number" class="author-input author-input-xs" placeholder="X" />
          <input v-model.number="topologyForm.nodes[i].y" type="number" class="author-input author-input-xs" placeholder="Y" />
          <button class="author-btn-remove" @click="removeNode(i)">-</button>
        </div>
        <button class="author-btn-add" @click="addNode">+ Knoten</button>

        <h4 class="author-subheading">Topologie-Links</h4>
        <div v-for="(link, i) in topologyForm.links" :key="'l' + i" class="author-array-row">
          <input v-model="topologyForm.links[i].from" type="text" class="author-input" placeholder="Von (Node-ID)" />
          <input v-model="topologyForm.links[i].to" type="text" class="author-input" placeholder="Nach (Node-ID)" />
          <button class="author-btn-remove" @click="removeLink(i)">-</button>
        </div>
        <button class="author-btn-add" @click="addLink">+ Link</button>
      </div>
    </div>

    <!-- Dropdown Section -->
    <div v-if="selectedSubtype === 'dropdown'" class="author-section">
      <h3 class="author-heading">Dropdown-Konfiguration</h3>

      <div v-for="(q, qi) in dropdownForm.questions" :key="qi" class="author-dropdown-question">
        <div class="author-field">
          <label class="author-label">Frage {{ qi + 1 }}</label>
          <input v-model="dropdownForm.questions[qi].id" type="text" class="author-input author-input-sm" placeholder="ID (z.B. q1)" />
          <input v-model="dropdownForm.questions[qi].label" type="text" class="author-input" placeholder="Fragetext" />
          <input v-model="dropdownForm.questions[qi].correct" type="text" class="author-input author-input-sm" placeholder="Richtige Antwort" />
        </div>
        <div class="author-field">
          <label class="author-label">Optionen</label>
          <div v-for="(opt, oi) in q.options" :key="oi" class="author-array-row">
            <input
              :value="opt"
              type="text"
              class="author-input"
              placeholder="Option"
              @input="updateDropdownOption(qi, oi, $event.target.value)"
            />
          </div>
          <button class="author-btn-add" @click="addDropdownOption(qi)">+ Option</button>
        </div>
        <button class="author-btn-remove" :disabled="dropdownForm.questions.length <= 1" @click="removeDropdownQuestion(qi)">
          Frage entfernen
        </button>
      </div>
      <button class="author-btn-add" @click="addDropdownQuestion">+ Frage</button>
    </div>

    <!-- Cable Section -->
    <div v-if="selectedSubtype === 'cable'" class="author-section">
      <h3 class="author-heading">Kabel-Konfiguration (JSON)</h3>
      <p class="author-hint">Gib die Konfiguration als JSON-Objekt ein. Beispiel:
        <code>{"questions": [{"id": "c1", "from": "PC1", "to": "Switch1"}]}</code>
      </p>
      <textarea v-model="cableForm.rawJson" class="author-textarea-json" rows="12" placeholder='{"questions": [...]}' />
    </div>

    <!-- Multi-Panel Section -->
    <div v-if="selectedSubtype === 'multi_panel'" class="author-section">
      <h3 class="author-heading">Multi-Panel-Konfiguration</h3>

      <h4 class="author-subheading">CLI-Teil</h4>
      <div class="author-field">
        <label class="author-label">Domain</label>
        <select v-model="cliForm.domain" class="author-select">
          <option v-for="d in validDomains" :key="d" :value="d">{{ d }}</option>
        </select>
      </div>

      <div class="author-field">
        <label class="author-label">Terminals</label>
        <div v-for="(term, i) in cliForm.terminals" :key="i" class="author-array-row">
          <input v-model="cliForm.terminals[i].name" type="text" class="author-input" placeholder="Terminal-Name" />
          <button class="author-btn-remove" :disabled="cliForm.terminals.length <= 1" @click="removeTerminal(i)">-</button>
        </div>
        <button class="author-btn-add" @click="addTerminal">+ Terminal</button>
      </div>

      <div class="author-field">
        <label class="author-label">Befehle und Ausgaben</label>
        <div v-for="(pair, i) in cliForm.commandOutputs" :key="i" class="author-cmd-row">
          <input v-model="cliForm.commandOutputs[i].cmd" type="text" class="author-input author-input-cmd" placeholder="Befehl" />
          <textarea v-model="cliForm.commandOutputs[i].output" class="author-textarea-cmd" placeholder="Ausgabe" rows="3" />
          <button class="author-btn-remove" @click="removeCommandOutput(i)">-</button>
        </div>
        <button class="author-btn-add" @click="addCommandOutput">+ Befehl</button>
      </div>

      <h4 class="author-subheading">Placement-Teil</h4>
      <div class="author-field">
        <label class="author-label">Bewertungsmodus</label>
        <select v-model="placementForm.scoringMode" class="author-select">
          <option value="strict">Strict</option>
          <option value="partial">Partial</option>
        </select>
      </div>

      <div class="author-field">
        <label class="author-label">Positionen</label>
        <div v-for="(pos, i) in placementForm.positions" :key="i" class="author-array-row">
          <input v-model="placementForm.positions[i].id" type="text" class="author-input author-input-sm" placeholder="ID" />
          <input v-model="placementForm.positions[i].label" type="text" class="author-input" placeholder="Label" />
          <input v-model="placementForm.positions[i].correct" type="text" class="author-input author-input-sm" placeholder="Richtig" />
          <button class="author-btn-remove" :disabled="placementForm.positions.length <= 1" @click="removePosition(i)">-</button>
        </div>
        <button class="author-btn-add" @click="addPosition">+ Position</button>
      </div>

      <h4 class="author-subheading">Topologie-Knoten</h4>
      <div v-for="(node, i) in topologyForm.nodes" :key="i" class="author-array-row">
        <input v-model="topologyForm.nodes[i].id" type="text" class="author-input author-input-sm" placeholder="ID" />
        <select v-model="topologyForm.nodes[i].type" class="author-select author-select-sm">
          <option v-for="dt in validDeviceTypes" :key="dt" :value="dt">{{ dt }}</option>
        </select>
        <input v-model="topologyForm.nodes[i].label" type="text" class="author-input" placeholder="Label" />
        <input v-model.number="topologyForm.nodes[i].x" type="number" class="author-input author-input-xs" placeholder="X" />
        <input v-model.number="topologyForm.nodes[i].y" type="number" class="author-input author-input-xs" placeholder="Y" />
        <button class="author-btn-remove" @click="removeNode(i)">-</button>
      </div>
      <button class="author-btn-add" @click="addNode">+ Knoten</button>

      <h4 class="author-subheading">Topologie-Links</h4>
      <div v-for="(link, i) in topologyForm.links" :key="'ml' + i" class="author-array-row">
        <input v-model="topologyForm.links[i].from" type="text" class="author-input" placeholder="Von" />
        <input v-model="topologyForm.links[i].to" type="text" class="author-input" placeholder="Nach" />
        <button class="author-btn-remove" @click="removeLink(i)">-</button>
      </div>
      <button class="author-btn-add" @click="addLink">+ Link</button>
    </div>

    <!-- JSON Output -->
    <div class="author-section author-output-section">
      <h3 class="author-heading">Generiertes JSON</h3>
      <pre class="author-json-output">{{ generatedJson }}</pre>
      <NcButton @click="copyJson">{{ copySuccess ? 'Kopiert!' : 'JSON kopieren' }}</NcButton>
      <textarea v-if="showJsonFallback" :value="generatedJson" readonly class="author-json-fallback" rows="10" />
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { DOMAIN_SCHEMAS } from '../utils/cliStateMachine.js'
import { DEVICE_ICONS } from '../utils/networkTopologyIcons.js'

export default {
  name: 'PbqAuthorTool',

  components: { NcButton },

  data() {
    return {
      selectedSubtype: 'cli',
      copySuccess: false,
      showJsonFallback: false,

      cliForm: {
        domain: 'cisco_ios',
        hint: '',
        terminals: [{ name: 'Router' }],
        commandOutputs: [{ cmd: '', output: '' }],
      },

      placementForm: {
        useTopology: true,
        scoringMode: 'strict',
        positions: [{ id: 'n1', label: '', correct: '' }],
        deviceOptions: ['router', 'switch', 'firewall', 'server'],
      },

      topologyForm: {
        nodes: [{ id: 'n1', type: 'router', label: '', x: 200, y: 150 }],
        links: [],
      },

      dropdownForm: {
        questions: [{ id: 'q1', label: '', options: ['', ''], correct: '' }],
      },

      cableForm: {
        rawJson: '',
      },
    }
  },

  computed: {
    validDomains() {
      return Object.keys(DOMAIN_SCHEMAS)
    },

    validDeviceTypes() {
      return Object.keys(DEVICE_ICONS)
    },

    generatedConfig() {
      switch (this.selectedSubtype) {
        case 'cli': {
          const config = {
            domain: this.cliForm.domain,
            terminals: this.cliForm.terminals,
            command_outputs: this.cliForm.commandOutputs
              .filter(pair => pair.cmd.trim() !== '')
              .reduce((acc, pair) => {
                acc[pair.cmd.toLowerCase()] = pair.output
                return acc
              }, {}),
          }
          if (this.cliForm.hint && this.cliForm.hint.trim() !== '') {
            config.hint = this.cliForm.hint
          }
          return config
        }

        case 'placement': {
          const hasNodes = this.topologyForm.nodes.length > 0
          return {
            positions: this.placementForm.positions,
            device_options: this.placementForm.deviceOptions,
            scoring_mode: this.placementForm.scoringMode,
            topology: (this.placementForm.useTopology && hasNodes)
              ? { nodes: this.topologyForm.nodes, links: this.topologyForm.links }
              : null,
          }
        }

        case 'dropdown':
          return {
            questions: this.dropdownForm.questions,
          }

        case 'cable':
          try {
            return JSON.parse(this.cableForm.rawJson)
          } catch {
            return {}
          }

        case 'multi_panel': {
          const hasNodes = this.topologyForm.nodes.length > 0
          return {
            cli: {
              domain: this.cliForm.domain,
              terminals: this.cliForm.terminals,
              command_outputs: this.cliForm.commandOutputs
                .filter(pair => pair.cmd.trim() !== '')
                .reduce((acc, pair) => {
                  acc[pair.cmd.toLowerCase()] = pair.output
                  return acc
                }, {}),
            },
            placement: {
              positions: this.placementForm.positions,
              device_options: this.placementForm.deviceOptions,
              scoring_mode: this.placementForm.scoringMode,
            },
            topology: hasNodes
              ? { nodes: this.topologyForm.nodes, links: this.topologyForm.links }
              : null,
          }
        }

        default:
          return {}
      }
    },

    generatedJson() {
      return JSON.stringify({
        pbq_subtype: this.selectedSubtype,
        pbq_config: this.generatedConfig,
      }, null, 2)
    },
  },

  methods: {
    async copyJson() {
      try {
        await navigator.clipboard.writeText(this.generatedJson)
        this.copySuccess = true
        setTimeout(() => { this.copySuccess = false }, 2000)
      } catch {
        this.showJsonFallback = true
      }
    },

    // Terminal management
    addTerminal() {
      this.cliForm.terminals.push({ name: '' })
    },
    removeTerminal(i) {
      this.cliForm.terminals.splice(i, 1)
    },

    // Command output management
    addCommandOutput() {
      this.cliForm.commandOutputs.push({ cmd: '', output: '' })
    },
    removeCommandOutput(i) {
      this.cliForm.commandOutputs.splice(i, 1)
    },

    // Position management
    addPosition() {
      const id = 'n' + (this.placementForm.positions.length + 1)
      this.placementForm.positions.push({ id, label: '', correct: '' })
    },
    removePosition(i) {
      this.placementForm.positions.splice(i, 1)
    },

    // Device option toggle
    toggleDeviceOption(dt) {
      const idx = this.placementForm.deviceOptions.indexOf(dt)
      if (idx === -1) {
        this.placementForm.deviceOptions.push(dt)
      } else {
        this.placementForm.deviceOptions.splice(idx, 1)
      }
    },

    // Node management
    addNode() {
      const id = 'n' + (this.topologyForm.nodes.length + 1)
      this.topologyForm.nodes.push({ id, type: 'router', label: '', x: 200, y: 150 })
    },
    removeNode(i) {
      this.topologyForm.nodes.splice(i, 1)
    },

    // Link management
    addLink() {
      this.topologyForm.links.push({ from: '', to: '' })
    },
    removeLink(i) {
      this.topologyForm.links.splice(i, 1)
    },

    // Dropdown question management
    addDropdownQuestion() {
      const id = 'q' + (this.dropdownForm.questions.length + 1)
      this.dropdownForm.questions.push({ id, label: '', options: ['', ''], correct: '' })
    },
    removeDropdownQuestion(i) {
      this.dropdownForm.questions.splice(i, 1)
    },
    addDropdownOption(qi) {
      this.$set(
        this.dropdownForm.questions[qi],
        'options',
        [...this.dropdownForm.questions[qi].options, ''],
      )
    },
    updateDropdownOption(qi, oi, value) {
      const opts = [...this.dropdownForm.questions[qi].options]
      opts[oi] = value
      this.$set(this.dropdownForm.questions[qi], 'options', opts)
    },
  },
}
</script>

<style scoped>
.pbq-author-tool {
  padding: 16px;
  max-width: 900px;
}

.author-section {
  margin-bottom: 20px;
  padding: 16px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-background-soft);
}

.author-output-section {
  background: var(--color-background-dark);
}

.author-heading {
  font-size: 15px;
  font-weight: 600;
  margin: 0 0 12px 0;
  color: var(--color-text-dark);
}

.author-subheading {
  font-size: 13px;
  font-weight: 600;
  margin: 12px 0 8px 0;
  color: var(--color-text-maxcontrast);
}

.author-field {
  margin-bottom: 12px;
}

.author-label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 4px;
  color: var(--color-text-maxcontrast);
}

.author-input {
  padding: 6px 10px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-main-background);
  color: var(--color-text-dark);
  font-size: 13px;
  flex: 1;
}

.author-input-sm  { flex: 0 0 80px; }
.author-input-xs  { flex: 0 0 60px; }
.author-input-cmd { flex: 0 0 200px; }

.author-select {
  padding: 6px 10px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-main-background);
  color: var(--color-text-dark);
  font-size: 13px;
  cursor: pointer;
}

.author-select-sm { flex: 0 0 110px; }

.author-array-row {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 6px;
}

.author-cmd-row {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  margin-bottom: 8px;
}

.author-textarea-cmd {
  flex: 1;
  padding: 6px 10px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-main-background);
  color: var(--color-text-dark);
  font-family: monospace;
  font-size: 12px;
  resize: vertical;
}

.author-textarea-json {
  width: 100%;
  padding: 8px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-main-background);
  color: var(--color-text-dark);
  font-family: monospace;
  font-size: 12px;
  resize: vertical;
}

.author-btn-add {
  font-size: 12px;
  padding: 4px 10px;
  border: 1px solid var(--color-primary-element);
  border-radius: 4px;
  background: transparent;
  color: var(--color-primary-element);
  cursor: pointer;
  margin-top: 4px;
}

.author-btn-add:hover {
  background: var(--color-primary-element-light);
}

.author-btn-remove {
  font-size: 12px;
  padding: 4px 8px;
  border: 1px solid var(--color-border-dark);
  border-radius: 4px;
  background: transparent;
  color: var(--color-text-maxcontrast);
  cursor: pointer;
  flex-shrink: 0;
}

.author-btn-remove:disabled {
  opacity: 0.4;
  cursor: default;
}

.author-checkboxes {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;
}

.author-checkbox-label {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  cursor: pointer;
}

.author-hint {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 8px;
}

.author-hint code {
  background: var(--color-background-dark);
  padding: 1px 4px;
  border-radius: 3px;
  font-size: 11px;
}

.author-dropdown-question {
  padding: 12px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  margin-bottom: 12px;
  background: var(--color-main-background);
}

.author-topology-editor {
  margin-top: 12px;
  padding: 12px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
}

.author-json-output {
  font-family: monospace;
  font-size: 12px;
  white-space: pre-wrap;
  background: var(--color-background-dark);
  padding: 12px;
  border-radius: 6px;
  max-height: 300px;
  overflow-y: auto;
  margin-bottom: 12px;
  border: 1px solid var(--color-border);
}

.author-json-fallback {
  width: 100%;
  margin-top: 8px;
  font-family: monospace;
  font-size: 12px;
  padding: 8px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-main-background);
  resize: vertical;
}
</style>
