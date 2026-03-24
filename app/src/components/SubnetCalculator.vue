<template>
	<section class="subnet-tool">
		<header class="subnet-tool__header">
			<div>
				<p class="subnet-tool__eyebrow">{{ t('learning', 'CompTIA Network+ N10-009') }}</p>
				<h2 class="subnet-tool__title">{{ t('learning', 'Subnetzrechner') }}</h2>
				<p class="subnet-tool__subtitle">{{ t('learning', 'Netzadresse, Broadcast, Binardarstellung und VLSM direkt im Browser berechnen.') }}</p>
			</div>
		</header>

		<nav class="subnet-tool__tabs" role="tablist" :aria-label="t('learning', 'Subnetzrechner Tabs')">
			<button
				v-for="tab in tabs"
				:key="tab.id"
				class="subnet-tool__tab"
				:class="{ 'subnet-tool__tab--active': activeTab === tab.id }"
				role="tab"
				:aria-selected="activeTab === tab.id ? 'true' : 'false'"
				@click="switchTab(tab.id)">
				{{ tab.label }}
			</button>
		</nav>

		<section v-if="isCalculatorTab" class="subnet-panel" role="tabpanel">
			<label class="subnet-label" for="subnet-calculator-input">{{ t('learning', 'IP/CIDR oder IP + Maske') }}</label>
			<input
				id="subnet-calculator-input"
				v-model.trim="calculatorInput"
				class="subnet-input"
				:class="calculatorInputClass"
				type="text"
				:placeholder="t('learning', 'Beispiel: 192.168.1.0/24 oder 192.168.1.0 255.255.255.0')">
			<p class="subnet-help">{{ t('learning', 'Die Berechnung aktualisiert sich sofort waehrend der Eingabe.') }}</p>
			<p v-if="calculatorError" class="subnet-state subnet-state--error">{{ calculatorError }}</p>
			<p v-else-if="calculatorResult" class="subnet-state subnet-state--valid">{{ t('learning', 'Gueltige Eingabe erkannt.') }}</p>

			<div v-if="calculatorResult" class="toggle-controls">
				<div class="toggle-controls__preset">
					<label class="subnet-label" for="toggle-preset">{{ t('learning', 'Anzeige-Preset') }}</label>
					<select
						id="toggle-preset"
						class="subnet-input toggle-controls__select"
						:value="activePreset"
						@change="applyPreset($event.target.value)">
						<option value="all">{{ t('learning', 'Alle Felder') }}</option>
						<option value="beginner">{{ t('learning', 'Anfaenger') }}</option>
						<option value="advanced">{{ t('learning', 'Fortgeschritten') }}</option>
						<option value="basics">{{ t('learning', 'Nur Basics') }}</option>
						<option v-if="activePreset === 'custom'" value="custom" disabled>{{ t('learning', 'Benutzerdefiniert') }}</option>
					</select>
				</div>
				<div class="toggle-controls__rows">
					<label v-for="(key, index) in rowKeys" :key="key" class="toggle-controls__checkbox">
						<input type="checkbox" :checked="visibleRows[key]" @change="toggleRow(key)">
						<span>{{ allCalculatorRows[index] ? allCalculatorRows[index].label : key }}</span>
					</label>
				</div>
			</div>

			<table v-if="calculatorResult" class="subnet-table">
				<tbody>
					<tr v-for="row in calculatorRows" :key="row.label">
						<th scope="row">{{ row.label }}</th>
						<td>{{ row.value }}</td>
					</tr>
				</tbody>
			</table>
		</section>

		<section v-if="isBinaryTab" class="subnet-panel" role="tabpanel">
			<p class="subnet-help">{{ t('learning', 'Netz-Bits sind cyan, Host-Bits amber markiert.') }}</p>
			<p v-if="!calculatorResult" class="subnet-empty">{{ t('learning', 'Gib zuerst im Rechner-Tab eine gueltige IPv4-Adresse mit Prefix oder Maske ein.') }}</p>

			<div v-else class="binary-panel">
				<div class="binary-legend">
					<span class="binary-legend__item"><span class="binary-legend__swatch binary-legend__swatch--network"></span>{{ t('learning', 'Netzanteil') }}</span>
					<span class="binary-legend__item"><span class="binary-legend__swatch binary-legend__swatch--host"></span>{{ t('learning', 'Hostanteil') }}</span>
				</div>

				<div class="binary-scroll">
					<div
						class="binary-grid"
						:aria-label="binaryAriaLabel"
						role="img">
						<div
							v-for="bit in bitCells"
							:key="'bit-' + bit.index"
							class="binary-grid__bit"
							:class="[
								bit.kind === 'network' ? 'binary-grid__bit--network' : 'binary-grid__bit--host',
								{ 'binary-grid__bit--octet-end': bit.isOctetEnd },
							]"
							:title="bit.title"
							:aria-label="bit.title">
							<span class="binary-grid__value">{{ bit.value }}</span>
							<span class="binary-grid__index">{{ bit.index }}</span>
						</div>
					</div>
				</div>

				<div class="binary-octets">
					<div v-for="(octet, index) in calculatorResult.network" :key="'network-octet-' + index" class="binary-octets__row">
						<span class="binary-octets__label">{{ t('learning', 'Oktett {n}', { n: index + 1 }) }}</span>
						<span class="binary-octets__value">{{ calculatorResult.ip[index] }}</span>
						<span class="binary-octets__meta">{{ t('learning', 'Netzmaske: {mask}', { mask: calculatorResult.mask[index] }) }}</span>
					</div>
				</div>
			</div>
		</section>

		<section v-if="isVlsmTab" class="subnet-panel" role="tabpanel">
			<div class="vlsm-form">
				<div class="vlsm-form__field">
					<label class="subnet-label" for="vlsm-network-input">{{ t('learning', 'Ausgangsnetz') }}</label>
					<input
						id="vlsm-network-input"
						v-model.trim="vlsmInput"
						class="subnet-input"
						:class="vlsmInputClass"
						type="text"
						:placeholder="t('learning', 'Beispiel: 10.0.0.0/24')">
				</div>

				<div class="vlsm-form__rows">
					<div v-for="(row, index) in vlsmRows" :key="row.id" class="vlsm-form__row">
						<input
							v-model.trim="row.name"
							class="subnet-input subnet-input--name"
							type="text"
							:placeholder="t('learning', 'Subnetzname')">
						<input
							v-model.number="row.hosts"
							class="subnet-input subnet-input--hosts"
							type="number"
							min="1"
							step="1"
							:placeholder="t('learning', 'Hosts')">
						<button
							class="subnet-button subnet-button--ghost"
							type="button"
							:disabled="vlsmRows.length === 1"
							@click="removeRequirement(index)">
							{{ t('learning', 'Entfernen') }}
						</button>
					</div>
				</div>

				<div class="vlsm-form__actions">
					<button class="subnet-button subnet-button--secondary" type="button" @click="addRequirement">
						{{ t('learning', 'Subnetz hinzufuegen') }}
					</button>
					<button class="subnet-button subnet-button--primary" type="button" @click="calculateVlsm">
						{{ t('learning', 'Berechnen') }}
					</button>
				</div>
			</div>

			<p class="subnet-help">{{ t('learning', 'VLSM allociert immer groesste Netze zuerst und nutzt die Netzadresse des eingegebenen Blocks.') }}</p>
			<p v-if="vlsmError" class="subnet-state subnet-state--error">{{ vlsmError }}</p>

			<table v-if="vlsmResults.length" class="subnet-table subnet-table--vlsm">
				<thead>
					<tr>
						<th scope="col">{{ t('learning', 'Subnetz') }}</th>
						<th scope="col">{{ t('learning', 'Netzadresse') }}</th>
						<th scope="col">{{ t('learning', 'CIDR') }}</th>
						<th scope="col">{{ t('learning', 'Hosts') }}</th>
						<th scope="col">{{ t('learning', 'Verschwendet') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in vlsmResults" :key="row.name + '-' + row.network.join('.')">
						<td>{{ row.name }}</td>
						<td>{{ formatAddress(row.network) }}</td>
						<td>/{{ row.prefix }}</td>
						<td>{{ row.hostCount }}</td>
						<td>{{ row.wasted }}</td>
					</tr>
				</tbody>
			</table>
		</section>

		<section v-if="isIpv6Tab" class="subnet-panel" role="tabpanel">
			<label class="subnet-label" for="ipv6-input">{{ t('learning', 'IPv6-Adresse / Prefix') }}</label>
			<input
				id="ipv6-input"
				v-model.trim="ipv6Input"
				class="subnet-input"
				:class="ipv6InputClass"
				type="text"
				:placeholder="t('learning', 'Beispiel: 2001:db8::1/48 oder fe80::1/10')">
			<p class="subnet-help">{{ t('learning', 'Die Berechnung aktualisiert sich sofort waehrend der Eingabe.') }}</p>
			<p v-if="ipv6Error" class="subnet-state subnet-state--error">{{ ipv6Error }}</p>
			<p v-else-if="ipv6Result" class="subnet-state subnet-state--valid">{{ t('learning', 'Gueltige Eingabe erkannt.') }}</p>

			<table v-if="ipv6Result" class="subnet-table">
				<tbody>
					<tr v-for="row in ipv6Rows" :key="row.label">
						<th scope="row">{{ row.label }}</th>
						<td>{{ row.value }}</td>
					</tr>
				</tbody>
			</table>

			<div v-if="ipv6Result" class="binary-panel">
				<div class="binary-legend">
					<span class="binary-legend__item"><span class="binary-legend__swatch binary-legend__swatch--network"></span>{{ t('learning', 'Prefix') }}</span>
					<span class="binary-legend__item"><span class="binary-legend__swatch binary-legend__swatch--host"></span>{{ t('learning', 'Interface-ID') }}</span>
				</div>

				<div class="binary-scroll">
					<div
						class="ipv6-binary-grid"
						:aria-label="t('learning', 'Prefix: {prefix} Bits, Interface-ID: {iid} Bits', { prefix: ipv6Parsed.prefix, iid: 128 - ipv6Parsed.prefix })"
						role="img">
						<div
							v-for="bit in ipv6BitCells"
							:key="'ipv6bit-' + bit.index"
							class="binary-grid__bit binary-grid__bit--ipv6"
							:class="[
								bit.kind === 'prefix' ? 'binary-grid__bit--network' : 'binary-grid__bit--host',
								{ 'binary-grid__bit--group-end': bit.isGroupEnd },
							]"
							:title="bit.title"
							:aria-label="bit.title">
							<span class="binary-grid__value">{{ bit.value }}</span>
							<span class="binary-grid__index">{{ bit.index }}</span>
						</div>
					</div>
				</div>

				<div class="ipv6-groups">
					<div v-for="group in ipv6Groups" :key="group.label" class="binary-octets__row">
						<span class="binary-octets__label">{{ group.label }}</span>
						<span class="binary-octets__value">{{ group.value }}</span>
					</div>
				</div>
			</div>
		</section>
	</section>
</template>

<script>
import {
	calculateSubnet,
	ipToString,
	parseInput,
	vlsmAllocate,
} from '../utils/subnetMath.js'
import { ROW_KEYS, getVisibleRows } from '../utils/togglePresets.js'
import {
	parseIPv6,
	calculateIPv6Subnet,
	ipv6AddressType,
	ipv6ToBitArray,
	formatIPv6,
} from '../utils/ipv6Math.js'

export default {
	name: 'SubnetCalculator',

	data() {
		return {
			activeTab: 'calculator',
			calculatorInput: '192.168.1.0/24',
			visibleRows: getVisibleRows('all'),
			activePreset: 'all',
			vlsmInput: '10.0.0.0/24',
			vlsmRows: [
				{ id: 1, name: 'LAN A', hosts: 100 },
				{ id: 2, name: 'LAN B', hosts: 50 },
			],
			nextRequirementId: 3,
			vlsmResults: [],
			vlsmError: '',
			ipv6Input: '2001:db8::1/48',
		}
	},

	computed: {
		isCalculatorTab() { return this.activeTab === 'calculator' },
		isBinaryTab() { return this.activeTab === 'binary' },
		isVlsmTab() { return this.activeTab === 'vlsm' },
		isIpv6Tab() { return this.activeTab === 'ipv6' },
		tabs() {
			return [
				{ id: 'calculator', label: t('learning', 'Rechner') },
				{ id: 'binary', label: t('learning', 'Binaer-Display') },
				{ id: 'vlsm', label: t('learning', 'VLSM') },
				{ id: 'ipv6', label: t('learning', 'IPv6') },
			]
		},

		calculatorParsed() {
			return parseInput(this.calculatorInput)
		},

		calculatorResult() {
			if (!this.calculatorParsed) return null
			return calculateSubnet(this.calculatorParsed.ip, this.calculatorParsed.prefix)
		},

		calculatorError() {
			if (!this.calculatorInput) return ''
			return this.calculatorResult ? '' : t('learning', 'Bitte eine gueltige IPv4-Adresse mit Prefix oder Subnetzmaske eingeben.')
		},

		calculatorInputClass() {
			return {
				'subnet-input--valid': !!this.calculatorResult,
				'subnet-input--error': !!this.calculatorError,
			}
		},

		rowKeys() {
			return ROW_KEYS
		},

		allCalculatorRows() {
			if (!this.calculatorResult) return []

			return [
				{ label: t('learning', 'Netzadresse'), value: this.formatAddress(this.calculatorResult.network) },
				{ label: t('learning', 'Broadcast'), value: this.formatAddress(this.calculatorResult.broadcast) },
				{ label: t('learning', 'Erster Host'), value: this.formatAddress(this.calculatorResult.firstHost) },
				{ label: t('learning', 'Letzter Host'), value: this.formatAddress(this.calculatorResult.lastHost) },
				{ label: t('learning', 'Nutzbare Hosts'), value: String(this.calculatorResult.hostCount) },
				{ label: t('learning', 'Subnetzmaske'), value: this.formatAddress(this.calculatorResult.mask) },
				{ label: t('learning', 'Wildcard'), value: this.formatAddress(this.calculatorResult.wildcard) },
				{ label: t('learning', 'CIDR'), value: '/' + this.calculatorResult.prefix },
				{ label: t('learning', 'Klasse'), value: `${this.calculatorResult.ipClass} ${t('learning', '(historisch)')}` },
				{ label: t('learning', 'Privat'), value: this.calculatorResult.isPrivate ? t('learning', 'Ja (RFC1918)') : t('learning', 'Nein') },
			]
		},

		calculatorRows() {
			return this.allCalculatorRows.filter((row, index) => this.visibleRows[ROW_KEYS[index]])
		},

		bitCells() {
			if (!this.calculatorResult) return []

			return this.calculatorResult.ipBits.map((value, index) => {
				const kind = this.calculatorResult.networkBits[index] === 1 ? 'network' : 'host'
				const octet = Math.floor(index / 8) + 1
				const bitInOctet = index % 8

				return {
					index,
					value,
					kind,
					isOctetEnd: (index + 1) % 8 === 0 && index !== 31,
					title: `${t('learning', 'Bit')} ${index} - ${kind === 'network' ? t('learning', 'Netz') : t('learning', 'Host')} - ${t('learning', 'Oktett')} ${octet} / ${t('learning', 'Position')} ${bitInOctet}`,
				}
			})
		},

		binaryAriaLabel() {
			if (!this.calculatorResult) return ''
			const networkBits = this.calculatorResult.prefix
			const hostBits = 32 - this.calculatorResult.prefix
			return t('learning', 'Netzanteil: {network} Bits, Hostanteil: {host} Bits', {
				network: networkBits,
				host: hostBits,
			})
		},

		vlsmParsed() {
			return parseInput(this.vlsmInput)
		},

		vlsmInputClass() {
			return {
				'subnet-input--valid': !!this.vlsmParsed,
				'subnet-input--error': !!this.vlsmInput && !this.vlsmParsed,
			}
		},

		ipv6Parsed() {
			return parseIPv6(this.ipv6Input)
		},

		ipv6Result() {
			if (!this.ipv6Parsed) return null
			const result = calculateIPv6Subnet(
				this.ipv6Parsed.groups.map(g => g.toString(16)).join(':'),
				this.ipv6Parsed.prefix,
			)
			if (!result) return null
			result.type = ipv6AddressType(this.ipv6Parsed.groups)
			return result
		},

		ipv6Error() {
			if (!this.ipv6Input) return ''
			return this.ipv6Result ? '' : t('learning', 'Bitte eine gueltige IPv6-Adresse mit Prefix eingeben (z.B. 2001:db8::1/48).')
		},

		ipv6InputClass() {
			return {
				'subnet-input--valid': !!this.ipv6Result,
				'subnet-input--error': !!this.ipv6Error,
			}
		},

		ipv6Rows() {
			if (!this.ipv6Result) return []
			return [
				{ label: t('learning', 'Netzadresse'), value: formatIPv6(this.ipv6Result.networkGroups) },
				{ label: t('learning', 'Prefix'), value: '/' + this.ipv6Result.prefix },
				{ label: t('learning', 'Adresstyp'), value: this.ipv6Result.type },
				{ label: t('learning', 'Erster Host'), value: formatIPv6(this.ipv6Result.firstHostGroups) },
				{ label: t('learning', 'Letzter Host'), value: formatIPv6(this.ipv6Result.lastHostGroups) },
				{ label: t('learning', 'Adressen'), value: this.ipv6Result.hostCount.toString() },
			]
		},

		ipv6BitCells() {
			if (!this.ipv6Parsed) return []
			const bits = ipv6ToBitArray(this.ipv6Parsed.groups)
			const prefix = this.ipv6Parsed.prefix
			return bits.map((value, index) => {
				const kind = index < prefix ? 'prefix' : 'interface'
				const group = Math.floor(index / 16) + 1
				const posInGroup = index % 16
				return {
					index,
					value,
					kind,
					isGroupEnd: (index + 1) % 16 === 0 && index !== 127,
					title: `${t('learning', 'Bit')} ${index} - ${kind === 'prefix' ? t('learning', 'Prefix') : t('learning', 'Interface-ID')} - ${t('learning', 'Gruppe')} ${group} / ${t('learning', 'Position')} ${posInGroup}`,
				}
			})
		},

		ipv6Groups() {
			if (!this.ipv6Parsed) return []
			return this.ipv6Parsed.groups.map((g, i) => ({
				label: t('learning', 'Gruppe {n}', { n: i + 1 }),
				value: g.toString(16).padStart(4, '0'),
			}))
		},
	},

	methods: {
		switchTab(id) {
			this.$set(this.$data, 'activeTab', id)
		},
		applyPreset(presetName) {
			this.activePreset = presetName
			this.visibleRows = getVisibleRows(presetName)
		},
		toggleRow(key) {
			this.$set(this.visibleRows, key, !this.visibleRows[key])
			this.activePreset = 'custom'
		},
		formatAddress(octets) {
			return octets ? ipToString(octets) : '-'
		},

		addRequirement() {
			this.vlsmRows.push({
				id: this.nextRequirementId++,
				name: '',
				hosts: null,
			})
		},

		removeRequirement(index) {
			this.vlsmRows.splice(index, 1)
		},

		calculateVlsm() {
			this.vlsmError = ''
			this.vlsmResults = []

			if (!this.vlsmParsed) {
				this.vlsmError = t('learning', 'Bitte ein gueltiges Ausgangsnetz fuer den VLSM-Rechner eingeben.')
				return
			}

			const requirements = this.vlsmRows.map(row => ({
				name: row.name,
				hosts: Number(row.hosts),
			})).filter(row => row.hosts > 0)

			if (!requirements.length) {
				this.vlsmError = t('learning', 'Bitte mindestens ein Subnetz mit positivem Hostbedarf angeben.')
				return
			}

			const base = calculateSubnet(this.vlsmParsed.ip, this.vlsmParsed.prefix)
			if (!base) {
				this.vlsmError = t('learning', 'Das Ausgangsnetz konnte nicht berechnet werden.')
				return
			}

			const allocations = vlsmAllocate(base.network, base.prefix, requirements)
			if (!allocations) {
				this.vlsmError = t('learning', 'Der Gesamtbedarf passt nicht in den verfuegbaren Adressraum.')
				return
			}

			this.vlsmResults = allocations
		},
	},
}
</script>

<style scoped>
.subnet-tool {
	background: var(--lnc-panel);
	border: 1px solid var(--lnc-border);
	border-radius: var(--lnc-radius-lg);
	box-shadow: var(--lnc-shadow-card);
	color: var(--lnc-text);
	padding: var(--lnc-space-xl);
}

.subnet-tool__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: var(--lnc-space-lg);
	margin-bottom: var(--lnc-space-xl);
}

.subnet-tool__eyebrow {
	color: var(--lnc-cyan);
	font-size: 0.85rem;
	font-weight: 700;
	letter-spacing: 0.08em;
	margin: 0 0 6px;
	text-transform: uppercase;
}

.subnet-tool__title {
	font-family: var(--lnc-font-system);
	font-size: 1.75rem;
	line-height: 1.15;
	margin: 0 0 8px;
}

.subnet-tool__subtitle {
	color: var(--lnc-text-secondary);
	margin: 0;
	max-width: 60ch;
}

.subnet-tool__tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	margin-bottom: var(--lnc-space-xl);
}

.subnet-tool__tab {
	background: var(--lnc-panel);
	background: color-mix(in srgb, var(--lnc-panel) 88%, var(--lnc-primary) 12%);
	border: 1px solid var(--lnc-border);
	border-radius: 999px;
	color: var(--lnc-text);
	cursor: pointer;
	font-family: var(--lnc-font-system);
	font-size: 0.95rem;
	font-weight: 600;
	padding: 10px 16px;
}

.subnet-tool__tab--active {
	background: var(--lnc-primary);
	border-color: var(--lnc-primary);
	color: #fff;
	box-shadow: var(--lnc-shadow-glow);
}

.subnet-panel {
	display: flex;
	flex-direction: column;
	gap: var(--lnc-space-md);
}

.subnet-label {
	display: block;
	font-size: 0.95rem;
	font-weight: 700;
	margin-bottom: 6px;
}

.subnet-input {
	background: var(--lnc-bg);
	border: 1px solid var(--lnc-border);
	border-radius: var(--lnc-radius-md);
	color: var(--lnc-text);
	font-family: 'JetBrains Mono', 'SFMono-Regular', Consolas, monospace;
	font-size: 0.98rem;
	min-height: 46px;
	padding: 0 14px;
	width: 100%;
}

.subnet-input--valid {
	border-color: var(--lnc-green);
	box-shadow: 0 0 0 1px rgba(0, 230, 118, 0.28);
	box-shadow: 0 0 0 1px color-mix(in srgb, var(--lnc-green) 55%, transparent);
}

.subnet-input--error {
	border-color: var(--lnc-danger);
	box-shadow: 0 0 0 1px rgba(248, 81, 73, 0.24);
	box-shadow: 0 0 0 1px color-mix(in srgb, var(--lnc-danger) 40%, transparent);
}

.subnet-help,
.subnet-empty {
	color: var(--lnc-text-secondary);
	margin: 0;
}

.subnet-state {
	border-radius: var(--lnc-radius-sm);
	font-size: 0.95rem;
	font-weight: 600;
	margin: 0;
	padding: 10px 12px;
}

.subnet-state--valid {
	background: rgba(0, 230, 118, 0.12);
	background: color-mix(in srgb, var(--lnc-green) 12%, transparent);
	color: var(--lnc-green);
}

.subnet-state--error {
	background: rgba(248, 81, 73, 0.12);
	background: color-mix(in srgb, var(--lnc-danger) 12%, transparent);
	color: var(--lnc-danger);
}

.subnet-table {
	border-collapse: collapse;
	font-family: 'JetBrains Mono', 'SFMono-Regular', Consolas, monospace;
	overflow: hidden;
	width: 100%;
}

.subnet-table th,
.subnet-table td {
	border-bottom: 1px solid var(--lnc-border);
	padding: 12px 14px;
	text-align: left;
	vertical-align: top;
}

.subnet-table th {
	color: var(--lnc-text-secondary);
	font-family: var(--lnc-font-system);
	font-size: 0.92rem;
	font-weight: 700;
	width: 32%;
}

.binary-panel {
	display: flex;
	flex-direction: column;
	gap: var(--lnc-space-lg);
}

.binary-legend {
	display: flex;
	flex-wrap: wrap;
	gap: 14px;
}

.binary-legend__item {
	align-items: center;
	display: inline-flex;
	gap: 8px;
}

.binary-legend__swatch {
	border-radius: 999px;
	display: inline-block;
	height: 12px;
	width: 12px;
}

.binary-legend__swatch--network {
	background: var(--lnc-cyan);
}

.binary-legend__swatch--host {
	background: var(--lnc-amber);
}

.binary-scroll {
	overflow-x: auto;
	padding-bottom: 4px;
}

.binary-grid {
	display: inline-grid;
	gap: 0;
	grid-template-columns: repeat(32, minmax(38px, 1fr));
	min-width: 1216px;
}

.binary-grid__bit {
	align-items: center;
	background: var(--lnc-panel);
	background: color-mix(in srgb, var(--lnc-panel) 78%, var(--lnc-bg) 22%);
	border: 1px solid var(--lnc-border);
	display: flex;
	flex-direction: column;
	font-family: 'JetBrains Mono', 'SFMono-Regular', Consolas, monospace;
	gap: 4px;
	justify-content: center;
	min-height: 74px;
	padding: 10px 4px;
}

.binary-grid__bit--network {
	background: rgba(88, 166, 255, 0.16);
	background: color-mix(in srgb, var(--lnc-cyan) 16%, var(--lnc-panel));
}

.binary-grid__bit--host {
	background: rgba(210, 153, 34, 0.16);
	background: color-mix(in srgb, var(--lnc-amber) 16%, var(--lnc-panel));
}

.binary-grid__bit--octet-end {
	border-right: 3px solid var(--lnc-primary);
}

.binary-grid__value {
	font-size: 1.15rem;
	font-weight: 700;
}

.binary-grid__index {
	color: var(--lnc-text-secondary);
	font-size: 0.72rem;
}

.binary-octets {
	display: grid;
	gap: 10px;
	grid-template-columns: repeat(4, minmax(0, 1fr));
}

.binary-octets__row {
	background: var(--lnc-panel);
	background: color-mix(in srgb, var(--lnc-panel) 88%, var(--lnc-bg) 12%);
	border: 1px solid var(--lnc-border);
	border-radius: var(--lnc-radius-md);
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px;
}

.binary-octets__label {
	color: var(--lnc-text-secondary);
	font-size: 0.8rem;
	font-weight: 700;
	text-transform: uppercase;
}

.binary-octets__value {
	font-family: 'JetBrains Mono', 'SFMono-Regular', Consolas, monospace;
	font-size: 1.2rem;
	font-weight: 700;
}

.binary-octets__meta {
	color: var(--lnc-text-secondary);
	font-size: 0.85rem;
}

.vlsm-form {
	display: flex;
	flex-direction: column;
	gap: var(--lnc-space-lg);
}

.vlsm-form__rows {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.vlsm-form__row {
	display: grid;
	gap: 10px;
	grid-template-columns: minmax(0, 2fr) minmax(120px, 160px) auto;
}

.vlsm-form__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
}

.subnet-button {
	border: 1px solid transparent;
	border-radius: 999px;
	cursor: pointer;
	font-family: var(--lnc-font-system);
	font-size: 0.95rem;
	font-weight: 700;
	min-height: 42px;
	padding: 0 16px;
}

.subnet-button--primary {
	background: var(--lnc-primary);
	color: #fff;
}

.subnet-button--secondary {
	background: var(--lnc-panel);
	background: color-mix(in srgb, var(--lnc-cyan) 16%, var(--lnc-panel));
	border-color: var(--lnc-border);
	border-color: color-mix(in srgb, var(--lnc-cyan) 28%, var(--lnc-border));
	color: var(--lnc-text);
}

.subnet-button--ghost {
	background: transparent;
	border-color: var(--lnc-border);
	color: var(--lnc-text);
}

.subnet-button:disabled {
	cursor: not-allowed;
	opacity: 0.5;
}

.toggle-controls {
	display: flex;
	flex-wrap: wrap;
	gap: var(--lnc-space-md);
	align-items: flex-start;
	padding: var(--lnc-space-md);
	background: color-mix(in srgb, var(--lnc-panel) 92%, var(--lnc-primary) 8%);
	border: 1px solid var(--lnc-border);
	border-radius: var(--lnc-radius-md);
}

.toggle-controls__preset {
	min-width: 200px;
}

.toggle-controls__select {
	width: 100%;
	cursor: pointer;
	appearance: auto;
}

.toggle-controls__rows {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 16px;
	flex: 1;
	min-width: 280px;
}

.toggle-controls__checkbox {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 0.9rem;
	cursor: pointer;
	white-space: nowrap;
}

.toggle-controls__checkbox input[type="checkbox"] {
	accent-color: var(--lnc-primary);
	cursor: pointer;
}

.ipv6-binary-grid {
	display: inline-grid;
	gap: 0;
	grid-template-columns: repeat(128, minmax(22px, 1fr));
	min-width: 2816px;
}

.binary-grid__bit--ipv6 {
	min-height: 56px;
	padding: 6px 2px;
}

.binary-grid__bit--ipv6 .binary-grid__value {
	font-size: 0.95rem;
}

.binary-grid__bit--ipv6 .binary-grid__index {
	font-size: 0.65rem;
}

.binary-grid__bit--group-end {
	border-right: 3px solid var(--lnc-primary);
}

.ipv6-groups {
	display: grid;
	gap: 10px;
	grid-template-columns: repeat(8, minmax(0, 1fr));
}

@media (max-width: 900px) {
	.binary-octets {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.ipv6-groups {
		grid-template-columns: repeat(4, minmax(0, 1fr));
	}

	.vlsm-form__row {
		grid-template-columns: 1fr;
	}
}

@media (max-width: 640px) {
	.subnet-tool {
		padding: var(--lnc-space-lg);
	}

	.subnet-table th,
	.subnet-table td {
		padding: 10px 12px;
	}

	.binary-octets {
		grid-template-columns: 1fr;
	}

	.ipv6-groups {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.toggle-controls {
		flex-direction: column;
	}

	.toggle-controls__preset {
		min-width: unset;
		width: 100%;
	}
}

@media (prefers-reduced-motion: reduce) {
	.subnet-tool__tab,
	.subnet-button,
	.subnet-input {
		transition: none;
	}
}
</style>
