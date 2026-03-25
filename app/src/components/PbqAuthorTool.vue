<template>
  <div class="pbq-author-tool">
    <div class="author-section">
      <label class="author-label">PBQ-Typ</label>
      <select v-model="selectedSubtype" class="author-select">
        <option value="cli">CLI Terminal</option>
        <option value="placement">Placement (Topologie)</option>
        <option value="dropdown">Dropdown-Auswahl</option>
        <option value="cable">Kabel-Verbindung</option>
        <option value="multi_panel">Multi-Panel</option>
        <option value="switch_config">Switch-Konfiguration</option>
        <option value="routing_config">Routing-Konfiguration</option>
        <option value="diagnostic">Diagnostic Review</option>
      </select>
      <p class="author-hint">
        Der Builder erzeugt das reine <code>pbq_config</code>-JSON. Der Subtype wird separat im Formular gesetzt.
      </p>
    </div>

    <div v-if="selectedSubtype === 'multi_panel'" class="author-section">
      <h3 class="author-heading">Multi-Panel-Aufbau</h3>
      <div class="author-field">
        <label class="author-label">Rechte Seite</label>
        <select v-model="multiPanelMode" class="author-select">
          <option value="placement">Placement / Topologie</option>
          <option value="dropdown">Dropdown / Worksheet</option>
        </select>
      </div>
    </div>

    <div v-if="usesCliSection" class="author-section">
      <h3 class="author-heading">CLI-Konfiguration</h3>

      <div class="author-field-grid">
        <div class="author-field">
          <label class="author-label">Domain</label>
          <select v-model="cliForm.domain" class="author-select">
            <option v-for="d in validDomains" :key="d" :value="d">{{ d }}</option>
          </select>
        </div>

        <div class="author-field">
          <label class="author-label">Output-Zuordnung</label>
          <select v-model="cliForm.outputScope" class="author-select">
            <option value="shared">Shared für alle Terminals</option>
            <option value="per_terminal">Terminal-spezifisch</option>
          </select>
        </div>
      </div>

      <div class="author-field">
        <label class="author-label">Hinweis (optional)</label>
        <input
          v-model="cliForm.hint"
          type="text"
          class="author-input"
          placeholder="z.B. Type help to view a list of available commands."
        />
      </div>

      <div class="author-field">
        <label class="author-label">Terminals</label>
        <div v-for="(term, i) in cliForm.terminals" :key="i" class="author-array-row">
          <input
            v-model="cliForm.terminals[i].name"
            type="text"
            class="author-input"
            placeholder="Terminal-Name"
          />
          <button
            type="button"
            class="author-btn-remove"
            :disabled="cliForm.terminals.length <= 1"
            @click="removeTerminal(i)"
          >
            -
          </button>
        </div>
        <button type="button" class="author-btn-add" @click="addTerminal">+ Terminal</button>
      </div>

      <div class="author-field">
        <label class="author-label">Befehle und Ausgaben</label>
        <div v-for="(pair, i) in cliForm.commandOutputs" :key="i" class="author-cmd-row">
          <select
            v-if="cliForm.outputScope === 'per_terminal'"
            v-model="cliForm.commandOutputs[i].terminal"
            class="author-select author-select-terminal"
          >
            <option v-for="term in cliTerminalNames" :key="term" :value="term">{{ term }}</option>
          </select>
          <input
            v-model="cliForm.commandOutputs[i].cmd"
            type="text"
            class="author-input author-input-cmd"
            placeholder="Befehl (z.B. show mac address-table)"
          />
          <textarea
            v-model="cliForm.commandOutputs[i].output"
            class="author-textarea-cmd"
            placeholder="Ausgabe des Befehls"
            rows="3"
          />
          <button type="button" class="author-btn-remove" @click="removeCommandOutput(i)">-</button>
        </div>
        <button type="button" class="author-btn-add" @click="addCommandOutput">+ Befehl</button>
      </div>
    </div>

    <div v-if="usesPlacementSection" class="author-section">
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
          <input
            v-model="placementForm.positions[i].id"
            type="text"
            class="author-input author-input-sm"
            placeholder="ID"
          />
          <input
            v-model="placementForm.positions[i].label"
            type="text"
            class="author-input"
            placeholder="Label"
          />
          <input
            v-model="placementForm.positions[i].correctInput"
            type="text"
            class="author-input"
            placeholder="Richtig (ein Wert oder A | B)"
          />
          <button
            type="button"
            class="author-btn-remove"
            :disabled="placementForm.positions.length <= 1"
            @click="removePosition(i)"
          >
            -
          </button>
        </div>
        <p class="author-inline-help">Mehrere akzeptierte Antworten mit <code>|</code> oder <code>,</code> trennen.</p>
        <button type="button" class="author-btn-add" @click="addPosition">+ Position</button>
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
          <button type="button" class="author-btn-remove" @click="removeNode(i)">-</button>
        </div>
        <button type="button" class="author-btn-add" @click="addNode">+ Knoten</button>

        <h4 class="author-subheading">Topologie-Links</h4>
        <div v-for="(link, i) in topologyForm.links" :key="'l' + i" class="author-array-row">
          <input v-model="topologyForm.links[i].from" type="text" class="author-input" placeholder="Von (Node-ID)" />
          <input v-model="topologyForm.links[i].to" type="text" class="author-input" placeholder="Nach (Node-ID)" />
          <button type="button" class="author-btn-remove" @click="removeLink(i)">-</button>
        </div>
        <button type="button" class="author-btn-add" @click="addLink">+ Link</button>
      </div>
    </div>

    <div v-if="usesDropdownSection" class="author-section">
      <h3 class="author-heading">Dropdown-Konfiguration</h3>

      <div class="author-field-grid">
        <div class="author-field">
          <label class="author-label">Layout</label>
          <select v-model="dropdownForm.layout" class="author-select">
            <option value="default">Default</option>
            <option value="cards">Cards</option>
          </select>
        </div>

        <div class="author-field">
          <label class="author-label">Sections</label>
          <button type="button" class="author-btn-add" @click="addDropdownSection">+ Section</button>
        </div>
      </div>

      <div v-if="dropdownForm.sections.length" class="author-subsection">
        <div v-for="(section, si) in dropdownForm.sections" :key="section.id || si" class="author-array-row">
          <input v-model="dropdownForm.sections[si].id" type="text" class="author-input author-input-sm" placeholder="ID" />
          <input v-model="dropdownForm.sections[si].title" type="text" class="author-input" placeholder="Titel" />
          <input v-model="dropdownForm.sections[si].note" type="text" class="author-input" placeholder="Hinweis (optional)" />
          <button type="button" class="author-btn-remove" @click="removeDropdownSection(si)">-</button>
        </div>
      </div>

      <div v-for="(q, qi) in dropdownForm.questions" :key="q.id || qi" class="author-dropdown-question">
        <div class="author-field-grid">
          <div class="author-field">
            <label class="author-label">Frage-ID</label>
            <input v-model="dropdownForm.questions[qi].id" type="text" class="author-input" placeholder="z.B. q1" />
          </div>
          <div class="author-field" v-if="dropdownForm.sections.length">
            <label class="author-label">Section</label>
            <select v-model="dropdownForm.questions[qi].section" class="author-select">
              <option value="">Default</option>
              <option v-for="section in dropdownForm.sections" :key="section.id" :value="section.id">{{ section.id }}</option>
            </select>
          </div>
        </div>

        <div class="author-field">
          <label class="author-label">Label</label>
          <input v-model="dropdownForm.questions[qi].label" type="text" class="author-input" placeholder="Fragetext" />
        </div>

        <div class="author-field-grid">
          <div class="author-field">
            <label class="author-label">Richtige Antwort</label>
            <input v-model="dropdownForm.questions[qi].correct" type="text" class="author-input" placeholder="korrekter Wert" />
          </div>
          <div class="author-field">
            <label class="author-label">Placeholder</label>
            <input v-model="dropdownForm.questions[qi].placeholder" type="text" class="author-input" placeholder="z.B. Select VLAN" />
          </div>
        </div>

        <div class="author-field-grid">
          <div class="author-field">
            <label class="author-label">Help-Text</label>
            <input v-model="dropdownForm.questions[qi].help_text" type="text" class="author-input" placeholder="optional" />
          </div>
          <div class="author-field">
            <label class="author-label">Current Value</label>
            <input v-model="dropdownForm.questions[qi].current_value" type="text" class="author-input" placeholder="optional" />
          </div>
        </div>

        <div class="author-field-grid">
          <div class="author-field">
            <label class="author-label">Readonly Value</label>
            <input v-model="dropdownForm.questions[qi].readonly_value" type="text" class="author-input" placeholder="optional" />
          </div>
          <div class="author-field">
            <label class="author-label">Initial Value</label>
            <input v-model="dropdownForm.questions[qi].initial_value" type="text" class="author-input" placeholder="optional" />
          </div>
        </div>

        <div class="author-field">
          <label class="author-checkbox-label">
            <input v-model="dropdownForm.questions[qi].scorable" type="checkbox" />
            Bewertbar
          </label>
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
            <button type="button" class="author-btn-remove" :disabled="q.options.length <= 1" @click="removeDropdownOption(qi, oi)">-</button>
          </div>
          <button type="button" class="author-btn-add" @click="addDropdownOption(qi)">+ Option</button>
        </div>

        <button
          type="button"
          class="author-btn-remove author-btn-remove--wide"
          :disabled="dropdownForm.questions.length <= 1"
          @click="removeDropdownQuestion(qi)"
        >
          Frage entfernen
        </button>
      </div>

      <button type="button" class="author-btn-add" @click="addDropdownQuestion">+ Frage</button>
    </div>

    <div v-if="isRawJsonSubtype" class="author-section">
      <h3 class="author-heading">{{ rawSubtypeTitle }}</h3>
      <p class="author-hint">
        Dieser Typ wird als JSON-Struktur bearbeitet, weil die kanonischen PBQs verschachtelte Felder verwenden.
      </p>
      <textarea
        v-model="rawConfigForms[selectedSubtype]"
        class="author-textarea-json"
        rows="14"
        :placeholder="rawSubtypePlaceholder"
      />
      <p v-if="rawConfigError" class="author-error">{{ rawConfigError }}</p>
      <button type="button" class="author-btn-add" @click="resetRawConfigTemplate">Template wiederherstellen</button>
    </div>

    <div v-if="selectedSubtype && !rawConfigError" class="author-preview-section">
      <h4 class="author-preview-title">Vorschau</h4>
      <div class="author-preview-wrapper">
        <PbqRenderer
          :question="previewQuestion"
          :disabled="false"
          @submit="noop"
          @skip="noop"
        />
      </div>
    </div>

    <div class="author-section author-output-section">
      <h3 class="author-heading">Generiertes Config-JSON</h3>
      <pre class="author-json-output">{{ generatedJson }}</pre>
      <div class="author-button-row">
        <NcButton @click="copyJson">{{ copySuccess ? 'Kopiert!' : 'Config kopieren' }}</NcButton>
        <NcButton type="primary" @click="applyConfig">Ins Formular übernehmen</NcButton>
      </div>
      <textarea v-if="showJsonFallback" :value="generatedJson" readonly class="author-json-fallback" rows="10" />
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { DOMAIN_SCHEMAS } from '../utils/cliStateMachine.js'
import { DEVICE_ICONS } from '../utils/networkTopologyIcons.js'
import PbqRenderer from './PbqRenderer.vue'

function createCliTerminals() {
  return [{ name: 'Router' }]
}

function createCliOutputs(terminalName = 'Router') {
  return [{ terminal: terminalName, cmd: '', output: '' }]
}

function createPlacementPositions() {
  return [{ id: 'n1', label: '', correctInput: '' }]
}

function createDropdownQuestion(id = 'q1') {
  return {
    id,
    section: '',
    label: '',
    correct: '',
    placeholder: '',
    help_text: '',
    current_value: '',
    readonly_value: '',
    initial_value: '',
    scorable: true,
    options: ['', ''],
  }
}

function createSwitchTemplate() {
  return {
    instructions: [],
    vlan_catalog: [{ id: '10', name: 'Users' }],
    status_options: ['Enabled', 'Disabled'],
    lacp_options: ['Enabled', 'Disabled'],
    switches: [
      {
        id: 'switch1',
        label: 'Switch 1',
        subtitle: '',
        ports: [
          {
            id: 's1_p1',
            label: 'Port 1',
            role: 'Uplink',
            initial: {
              status: 'Enabled',
              lacp: 'Disabled',
              vlans: { 10: 'none' },
            },
            correct: {
              status: 'Enabled',
              lacp: 'Enabled',
              vlans: { 10: 'tagged' },
            },
          },
        ],
      },
    ],
  }
}

function createRoutingTemplate() {
  return {
    instructions: [],
    routers: [
      {
        id: 'router_a',
        label: 'Router A',
        summary: '',
        routing_table: '',
        interface_options: ['Gi1', 'Gi2', 'Gi3'],
        initial: {
          problem_found: 'No',
          destination_prefix: '',
          destination_prefix_mask: '',
          interface: '',
        },
        correct: {
          problem_found: 'Yes',
          destination_prefix: '10.0.1.0',
          destination_prefix_mask: '255.255.255.0',
          interface: 'Gi1',
        },
      },
    ],
  }
}

function createDiagnosticTemplate() {
  return {
    instructions: [],
    findings: [
      {
        id: 'finding_1',
        label: 'Finding 1',
        hint: '',
        component_options: ['Cable1', 'Switch1'],
        problem_options: ['Wrong VLAN assignment'],
        solution_options: ['Change the switchport VLAN'],
        correct: {
          component: 'Cable1',
          problem: 'Wrong VLAN assignment',
          solution: 'Change the switchport VLAN',
        },
      },
    ],
  }
}

function createCableTemplate() {
  return {
    questions: [
      {
        id: 'c1',
        cable_id: 'cable1',
        from: 'PC1',
        to: 'Switch1',
        correct_problem: 'Open pair',
        correct_solution: 'Replace the cable',
      },
    ],
  }
}

function cloneJson(value, fallback) {
  try {
    return JSON.parse(JSON.stringify(value))
  } catch (error) {
    return fallback
  }
}

function parseJson(text, fallback = null) {
  if (!text || typeof text !== 'string') {
    return fallback
  }
  try {
    return JSON.parse(text)
  } catch (error) {
    return fallback
  }
}

function stringifyPretty(value) {
  return JSON.stringify(value, null, 2)
}

function normalizeChoiceValue(value) {
  if (Array.isArray(value)) {
    return value.join(' | ')
  }
  return value || ''
}

function parseChoiceValue(value) {
  const tokens = String(value || '')
    .split(/[|,]/)
    .map(token => token.trim())
    .filter(Boolean)

  if (!tokens.length) {
    return ''
  }
  if (tokens.length === 1) {
    return tokens[0]
  }
  return tokens
}

export default {
  name: 'PbqAuthorTool',
  components: { NcButton, PbqRenderer },
  props: {
    initialSubtype: { type: String, default: '' },
    initialConfig: { type: [Object, String], default: null },
  },
  data() {
    return {
      selectedSubtype: 'cli',
      copySuccess: false,
      showJsonFallback: false,
      multiPanelMode: 'placement',
      cliForm: {
        domain: 'cisco_ios',
        hint: '',
        outputScope: 'shared',
        terminals: createCliTerminals(),
        commandOutputs: createCliOutputs(),
      },
      placementForm: {
        useTopology: true,
        scoringMode: 'strict',
        positions: createPlacementPositions(),
        deviceOptions: ['router', 'switch', 'firewall', 'server'],
      },
      topologyForm: {
        nodes: [{ id: 'n1', type: 'router', label: '', x: 200, y: 150 }],
        links: [],
      },
      dropdownForm: {
        layout: 'default',
        sections: [],
        questions: [createDropdownQuestion()],
      },
      rawConfigForms: {
        cable: stringifyPretty(createCableTemplate()),
        switch_config: stringifyPretty(createSwitchTemplate()),
        routing_config: stringifyPretty(createRoutingTemplate()),
        diagnostic: stringifyPretty(createDiagnosticTemplate()),
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
    cliTerminalNames() {
      const names = this.cliForm.terminals
        .map(term => term.name && term.name.trim())
        .filter(Boolean)
      return names.length ? names : ['Router']
    },
    usesCliSection() {
      return this.selectedSubtype === 'cli' || this.selectedSubtype === 'multi_panel'
    },
    usesPlacementSection() {
      return this.selectedSubtype === 'placement' || (this.selectedSubtype === 'multi_panel' && this.multiPanelMode === 'placement')
    },
    usesDropdownSection() {
      return this.selectedSubtype === 'dropdown' || (this.selectedSubtype === 'multi_panel' && this.multiPanelMode === 'dropdown')
    },
    isRawJsonSubtype() {
      return ['cable', 'switch_config', 'routing_config', 'diagnostic'].includes(this.selectedSubtype)
    },
    rawSubtypeTitle() {
      switch (this.selectedSubtype) {
        case 'cable':
          return 'Kabel-Konfiguration (JSON)'
        case 'switch_config':
          return 'Switch-Konfiguration (JSON)'
        case 'routing_config':
          return 'Routing-Konfiguration (JSON)'
        case 'diagnostic':
          return 'Diagnostic-Konfiguration (JSON)'
        default:
          return 'JSON-Konfiguration'
      }
    },
    rawSubtypePlaceholder() {
      switch (this.selectedSubtype) {
        case 'switch_config':
          return '{"switches":[...]}'
        case 'routing_config':
          return '{"routers":[...]}'
        case 'diagnostic':
          return '{"findings":[...]}'
        default:
          return '{"questions":[...]}'
      }
    },
    rawConfigError() {
      if (!this.isRawJsonSubtype) {
        return ''
      }
      return parseJson(this.rawConfigForms[this.selectedSubtype]) === null
        ? 'Ungültiges JSON für diesen PBQ-Typ.'
        : ''
    },
    generatedConfig() {
      switch (this.selectedSubtype) {
        case 'cli':
          return this.buildCliConfig()
        case 'placement':
          return this.buildPlacementConfig()
        case 'dropdown':
          return this.buildDropdownConfig()
        case 'cable':
        case 'switch_config':
        case 'routing_config':
        case 'diagnostic':
          return parseJson(this.rawConfigForms[this.selectedSubtype], {}) || {}
        case 'multi_panel':
          return this.buildMultiPanelConfig()
        default:
          return {}
      }
    },
    generatedJson() {
      return stringifyPretty(this.generatedConfig)
    },
    previewQuestion() {
      return {
        pbq_subtype: this.selectedSubtype,
        pbq_config: this.generatedConfig,
      }
    },
  },
  created() {
    this.hydrateFromInitial()
  },
  methods: {
    noop() {},
    hydrateFromInitial() {
      const subtype = this.initialSubtype || 'cli'
      this.selectedSubtype = subtype

      let config = this.initialConfig
      if (typeof config === 'string') {
        config = parseJson(config)
      }
      if (!config || typeof config !== 'object' || Array.isArray(config)) {
        return
      }

      switch (subtype) {
        case 'cli':
          this.hydrateCliForm(config)
          break
        case 'placement':
          this.hydratePlacementForm(config)
          break
        case 'dropdown':
          this.hydrateDropdownForm(config)
          break
        case 'multi_panel':
          this.hydrateMultiPanelForm(config)
          break
        case 'cable':
        case 'switch_config':
        case 'routing_config':
        case 'diagnostic':
          this.$set(this.rawConfigForms, subtype, stringifyPretty(config))
          break
        default:
          break
      }
    },
    hydrateCliForm(config) {
      const terminals = Array.isArray(config.terminals) && config.terminals.length
        ? cloneJson(config.terminals, createCliTerminals())
        : createCliTerminals()
      const terminalNames = terminals.map(term => term.name).filter(Boolean)
      const commandOutputs = config.command_outputs || {}
      const scopedRows = []
      const nestedEntries = Object.entries(commandOutputs)
      const isPerTerminal = nestedEntries.length > 0 && nestedEntries.every(([key, value]) => {
        return terminalNames.includes(key) && value && typeof value === 'object' && !Array.isArray(value)
      })

      if (isPerTerminal) {
        nestedEntries.forEach(([terminal, outputs]) => {
          Object.entries(outputs).forEach(([cmd, output]) => {
            scopedRows.push({ terminal, cmd, output })
          })
        })
      } else {
        Object.entries(commandOutputs).forEach(([cmd, output]) => {
          scopedRows.push({ terminal: terminalNames[0] || 'Router', cmd, output })
        })
      }

      this.cliForm = {
        domain: config.domain || 'cisco_ios',
        hint: config.hint || '',
        outputScope: isPerTerminal ? 'per_terminal' : 'shared',
        terminals,
        commandOutputs: scopedRows.length ? scopedRows : createCliOutputs(terminalNames[0] || 'Router'),
      }
    },
    hydratePlacementForm(config) {
      const positions = Array.isArray(config.positions) && config.positions.length
        ? config.positions.map(position => ({
          id: position.id || '',
          label: position.label || '',
          correctInput: normalizeChoiceValue(position.correct),
        }))
        : createPlacementPositions()

      this.placementForm = {
        useTopology: !!config.topology,
        scoringMode: config.scoring_mode || 'strict',
        positions,
        deviceOptions: Array.isArray(config.device_options) && config.device_options.length
          ? [...config.device_options]
          : ['router', 'switch', 'firewall', 'server'],
      }

      this.topologyForm = config.topology
        ? cloneJson(config.topology, this.topologyForm)
        : {
          nodes: [{ id: 'n1', type: 'router', label: '', x: 200, y: 150 }],
          links: [],
        }
    },
    hydrateDropdownForm(config) {
      const questions = Array.isArray(config.questions) && config.questions.length
        ? config.questions.map((question, index) => ({
          ...createDropdownQuestion(question.id || 'q' + (index + 1)),
          id: question.id || 'q' + (index + 1),
          section: question.section || '',
          label: question.label || '',
          correct: question.correct || '',
          placeholder: question.placeholder || '',
          help_text: question.help_text || '',
          current_value: question.current_value || '',
          readonly_value: question.readonly_value || '',
          initial_value: question.initial_value || '',
          scorable: question.scorable !== false,
          options: Array.isArray(question.options) && question.options.length ? [...question.options] : ['', ''],
        }))
        : [createDropdownQuestion()]

      this.dropdownForm = {
        layout: config.layout || 'default',
        sections: Array.isArray(config.sections) ? cloneJson(config.sections, []) : [],
        questions,
      }
    },
    hydrateMultiPanelForm(config) {
      this.hydrateCliForm(config.cli || {})
      if (config.dropdown) {
        this.multiPanelMode = 'dropdown'
        this.hydrateDropdownForm(config.dropdown)
      } else {
        this.multiPanelMode = 'placement'
        this.hydratePlacementForm({
          ...(config.placement || {}),
          topology: config.topology || (config.placement || {}).topology || null,
        })
      }
    },
    buildCliConfig() {
      const terminals = this.cliForm.terminals
        .map(term => ({ name: (term.name || '').trim() }))
        .filter(term => term.name)

      const commandRows = this.cliForm.commandOutputs
        .map(pair => ({
          terminal: pair.terminal || this.cliTerminalNames[0],
          cmd: (pair.cmd || '').trim(),
          output: pair.output || '',
        }))
        .filter(pair => pair.cmd !== '')

      let commandOutputs = {}
      if (this.cliForm.outputScope === 'per_terminal') {
        commandRows.forEach(pair => {
          if (!commandOutputs[pair.terminal]) {
            commandOutputs[pair.terminal] = {}
          }
          commandOutputs[pair.terminal][pair.cmd] = pair.output
        })
      } else {
        commandOutputs = commandRows.reduce((acc, pair) => {
          acc[pair.cmd] = pair.output
          return acc
        }, {})
      }

      const config = {
        domain: this.cliForm.domain,
        terminals: terminals.length ? terminals : createCliTerminals(),
        command_outputs: commandOutputs,
      }

      if (this.cliForm.hint && this.cliForm.hint.trim() !== '') {
        config.hint = this.cliForm.hint.trim()
      }

      return config
    },
    buildPlacementConfig() {
      const positions = this.placementForm.positions.map(position => {
        const parsedCorrect = parseChoiceValue(position.correctInput)
        const next = {
          id: position.id,
          label: position.label,
          correct: parsedCorrect,
        }
        return next
      })

      const config = {
        positions,
        device_options: [...this.placementForm.deviceOptions],
        scoring_mode: this.placementForm.scoringMode,
      }

      if (this.placementForm.useTopology && this.topologyForm.nodes.length) {
        config.topology = {
          nodes: cloneJson(this.topologyForm.nodes, []),
          links: cloneJson(this.topologyForm.links, []),
        }
      }

      return config
    },
    buildDropdownConfig() {
      const sections = this.dropdownForm.sections
        .map(section => ({
          id: (section.id || '').trim(),
          title: section.title || '',
          note: section.note || '',
        }))
        .filter(section => section.id)

      const questions = this.dropdownForm.questions.map(question => {
        const next = {
          id: question.id,
          label: question.label,
        }

        if (question.section) next.section = question.section
        if (question.correct) next.correct = question.correct
        if (question.placeholder) next.placeholder = question.placeholder
        if (question.help_text) next.help_text = question.help_text
        if (question.current_value) next.current_value = question.current_value
        if (question.readonly_value) next.readonly_value = question.readonly_value
        if (question.initial_value) next.initial_value = question.initial_value
        if (question.scorable === false) next.scorable = false

        const options = (question.options || []).map(option => option.trim()).filter(Boolean)
        if (options.length) next.options = options

        return next
      })

      const config = {
        questions,
      }

      if (this.dropdownForm.layout !== 'default') {
        config.layout = this.dropdownForm.layout
      }
      if (sections.length) {
        config.sections = sections
      }

      return config
    },
    buildMultiPanelConfig() {
      const config = {
        cli: this.buildCliConfig(),
      }

      if (this.multiPanelMode === 'dropdown') {
        config.dropdown = this.buildDropdownConfig()
      } else {
        const placement = this.buildPlacementConfig()
        config.placement = {
          positions: placement.positions,
          device_options: placement.device_options,
          scoring_mode: placement.scoring_mode,
        }
        if (placement.topology) {
          config.topology = placement.topology
        }
      }

      return config
    },
    async copyJson() {
      try {
        await navigator.clipboard.writeText(this.generatedJson)
        this.copySuccess = true
        setTimeout(() => { this.copySuccess = false }, 2000)
      } catch (error) {
        this.showJsonFallback = true
      }
    },
    applyConfig() {
      this.$emit('apply', {
        subtype: this.selectedSubtype,
        config: cloneJson(this.generatedConfig, {}),
        json: this.generatedJson,
      })
    },
    addTerminal() {
      this.cliForm.terminals.push({ name: '' })
      if (this.cliForm.outputScope === 'per_terminal') {
        this.cliForm.commandOutputs.forEach(pair => {
          if (!pair.terminal) {
            this.$set(pair, 'terminal', this.cliTerminalNames[0])
          }
        })
      }
    },
    removeTerminal(i) {
      const removed = this.cliForm.terminals[i] ? this.cliForm.terminals[i].name : null
      this.cliForm.terminals.splice(i, 1)
      const fallbackTerminal = this.cliTerminalNames[0]
      this.cliForm.commandOutputs = this.cliForm.commandOutputs.map(pair => ({
        ...pair,
        terminal: pair.terminal === removed ? fallbackTerminal : (pair.terminal || fallbackTerminal),
      }))
    },
    addCommandOutput() {
      this.cliForm.commandOutputs.push({
        terminal: this.cliTerminalNames[0],
        cmd: '',
        output: '',
      })
    },
    removeCommandOutput(i) {
      this.cliForm.commandOutputs.splice(i, 1)
      if (!this.cliForm.commandOutputs.length) {
        this.cliForm.commandOutputs.push({
          terminal: this.cliTerminalNames[0],
          cmd: '',
          output: '',
        })
      }
    },
    addPosition() {
      const id = 'n' + (this.placementForm.positions.length + 1)
      this.placementForm.positions.push({ id, label: '', correctInput: '' })
    },
    removePosition(i) {
      this.placementForm.positions.splice(i, 1)
    },
    toggleDeviceOption(dt) {
      const idx = this.placementForm.deviceOptions.indexOf(dt)
      if (idx === -1) {
        this.placementForm.deviceOptions.push(dt)
      } else {
        this.placementForm.deviceOptions.splice(idx, 1)
      }
    },
    addNode() {
      const id = 'n' + (this.topologyForm.nodes.length + 1)
      this.topologyForm.nodes.push({ id, type: 'router', label: '', x: 200, y: 150 })
    },
    removeNode(i) {
      this.topologyForm.nodes.splice(i, 1)
    },
    addLink() {
      this.topologyForm.links.push({ from: '', to: '' })
    },
    removeLink(i) {
      this.topologyForm.links.splice(i, 1)
    },
    addDropdownSection() {
      const id = 'section_' + (this.dropdownForm.sections.length + 1)
      this.dropdownForm.sections.push({ id, title: '', note: '' })
    },
    removeDropdownSection(i) {
      const removed = this.dropdownForm.sections[i]
      this.dropdownForm.sections.splice(i, 1)
      if (removed && removed.id) {
        this.dropdownForm.questions = this.dropdownForm.questions.map(question => ({
          ...question,
          section: question.section === removed.id ? '' : question.section,
        }))
      }
    },
    addDropdownQuestion() {
      const id = 'q' + (this.dropdownForm.questions.length + 1)
      this.dropdownForm.questions.push(createDropdownQuestion(id))
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
    removeDropdownOption(qi, oi) {
      const options = [...this.dropdownForm.questions[qi].options]
      options.splice(oi, 1)
      this.$set(this.dropdownForm.questions[qi], 'options', options.length ? options : [''])
    },
    updateDropdownOption(qi, oi, value) {
      const opts = [...this.dropdownForm.questions[qi].options]
      opts[oi] = value
      this.$set(this.dropdownForm.questions[qi], 'options', opts)
    },
    resetRawConfigTemplate() {
      switch (this.selectedSubtype) {
        case 'cable':
          this.$set(this.rawConfigForms, 'cable', stringifyPretty(createCableTemplate()))
          break
        case 'switch_config':
          this.$set(this.rawConfigForms, 'switch_config', stringifyPretty(createSwitchTemplate()))
          break
        case 'routing_config':
          this.$set(this.rawConfigForms, 'routing_config', stringifyPretty(createRoutingTemplate()))
          break
        case 'diagnostic':
          this.$set(this.rawConfigForms, 'diagnostic', stringifyPretty(createDiagnosticTemplate()))
          break
        default:
          break
      }
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

.author-field,
.author-subsection {
  margin-bottom: 12px;
}

.author-field-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
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

.author-input-sm { flex: 0 0 90px; }
.author-input-xs { flex: 0 0 64px; }
.author-input-cmd { flex: 0 0 220px; }

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
.author-select-terminal { flex: 0 0 180px; }

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
  box-sizing: border-box;
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

.author-btn-remove--wide {
  margin-top: 8px;
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

.author-hint,
.author-inline-help {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 8px;
}

.author-inline-help {
  margin-top: 6px;
}

.author-hint code,
.author-inline-help code {
  background: var(--color-background-dark);
  padding: 1px 4px;
  border-radius: 3px;
  font-size: 11px;
}

.author-error {
  margin: 8px 0 0;
  color: var(--color-error);
  font-size: 12px;
  font-weight: 600;
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
  box-sizing: border-box;
}

.author-button-row {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.author-preview-section {
  margin-top: 24px;
  border-top: 1px solid var(--color-border);
  padding-top: 16px;
}

.author-preview-title {
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 12px;
  color: var(--color-main-text);
}

.author-preview-wrapper {
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large);
  padding: 16px;
  overflow-y: auto;
  max-height: 450px;
}

@media (max-width: 768px) {
  .author-array-row,
  .author-cmd-row {
    flex-direction: column;
    align-items: stretch;
  }

  .author-input-sm,
  .author-input-xs,
  .author-input-cmd,
  .author-select-sm,
  .author-select-terminal {
    flex: 1 1 auto;
  }
}
</style>
