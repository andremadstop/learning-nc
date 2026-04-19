<template>
	<div
		ref="avatarRef"
		:class="stateClasses"
		:style="avatarStyle">
		<svg
			ref="svgRef"
			:width="size"
			:height="size"
			:viewBox="'0 0 ' + size + ' ' + size"
			xmlns="http://www.w3.org/2000/svg"
			role="img"
			:aria-label="ariaLabel">
			<g id="body" :style="bodyGroupStyle">
				<path :d="bodyPath" :fill="character.palette.accent" />
			</g>

			<g id="head" :style="headGroupStyle">
				<circle :cx="cx" :cy="headCy" :r="headR" :fill="character.palette.primary" />
				<template v-for="(el, i) in headFeatureElements" :key="'head-' + i">
					<circle v-if="el.type === 'circle'"
						:class="el.className || null"
						:cx="el.cx"
						:cy="el.cy"
						:r="el.r"
						:fill="el.fill || character.palette.accent"
						:opacity="el.opacity ?? null"
						:stroke="el.stroke || 'none'"
						:stroke-width="el.strokeWidth || 0" />
					<ellipse v-else-if="el.type === 'ellipse'"
						:class="el.className || null"
						:cx="el.cx"
						:cy="el.cy"
						:rx="el.rx"
						:ry="el.ry"
						:fill="el.fill || character.palette.accent"
						:opacity="el.opacity ?? null"
						:stroke="el.stroke || 'none'"
						:stroke-width="el.strokeWidth || 0" />
					<rect v-else-if="el.type === 'rect'"
						:class="el.className || null"
						:x="el.x"
						:y="el.y"
						:width="el.w"
						:height="el.h"
						:rx="el.rx || 0"
						:fill="el.fill || character.palette.accent"
						:opacity="el.opacity ?? null"
						:stroke="el.stroke || 'none'"
						:stroke-width="el.strokeWidth || 0" />
					<path v-else-if="el.type === 'path'"
						:class="el.className || null"
						:d="el.d"
						:fill="el.fill || 'none'"
						:opacity="el.opacity ?? null"
						:stroke="el.stroke || character.palette.accent"
						:stroke-width="el.strokeWidth || 2"
						:stroke-dasharray="el.strokeDasharray || null"
						:stroke-linecap="el.linecap || 'round'" />
					<line v-else-if="el.type === 'line'"
						:class="el.className || null"
						:x1="el.x1"
						:y1="el.y1"
						:x2="el.x2"
						:y2="el.y2"
						:stroke="el.stroke || character.palette.accent"
						:stroke-width="el.strokeWidth || 2"
						:stroke-linecap="el.linecap || 'round'" />
					<text v-else-if="el.type === 'text'"
						:class="el.className || null"
						:x="el.x"
						:y="el.y"
						:fill="el.fill || character.palette.primary"
						:font-size="el.fontSize || 14"
						text-anchor="middle"
						dominant-baseline="central">{{ el.content }}</text>
				</template>
			</g>

			<g id="arms" :style="armsGroupStyle">
				<template v-for="(el, i) in armFeatureElements" :key="'arms-' + i">
					<circle v-if="el.type === 'circle'"
						:class="el.className || null"
						:cx="el.cx"
						:cy="el.cy"
						:r="el.r"
						:fill="el.fill || character.palette.accent"
						:opacity="el.opacity ?? null"
						:stroke="el.stroke || 'none'"
						:stroke-width="el.strokeWidth || 0" />
					<ellipse v-else-if="el.type === 'ellipse'"
						:class="el.className || null"
						:cx="el.cx"
						:cy="el.cy"
						:rx="el.rx"
						:ry="el.ry"
						:fill="el.fill || character.palette.accent"
						:opacity="el.opacity ?? null"
						:stroke="el.stroke || 'none'"
						:stroke-width="el.strokeWidth || 0" />
					<rect v-else-if="el.type === 'rect'"
						:class="el.className || null"
						:x="el.x"
						:y="el.y"
						:width="el.w"
						:height="el.h"
						:rx="el.rx || 0"
						:fill="el.fill || character.palette.accent"
						:opacity="el.opacity ?? null"
						:stroke="el.stroke || 'none'"
						:stroke-width="el.strokeWidth || 0" />
					<path v-else-if="el.type === 'path'"
						:class="el.className || null"
						:d="el.d"
						:fill="el.fill || 'none'"
						:opacity="el.opacity ?? null"
						:stroke="el.stroke || character.palette.accent"
						:stroke-width="el.strokeWidth || 2"
						:stroke-dasharray="el.strokeDasharray || null"
						:stroke-linecap="el.linecap || 'round'" />
					<line v-else-if="el.type === 'line'"
						:class="el.className || null"
						:x1="el.x1"
						:y1="el.y1"
						:x2="el.x2"
						:y2="el.y2"
						:stroke="el.stroke || character.palette.accent"
						:stroke-width="el.strokeWidth || 2"
						:stroke-linecap="el.linecap || 'round'" />
					<text v-else-if="el.type === 'text'"
						:class="el.className || null"
						:x="el.x"
						:y="el.y"
						:fill="el.fill || character.palette.primary"
						:font-size="el.fontSize || 14"
						text-anchor="middle"
						dominant-baseline="central">{{ el.content }}</text>
				</template>
			</g>
		</svg>
		<div v-if="showGlow" class="character-glow" :style="glowStyle" />
	</div>
</template>

<script>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useDocumentVisibility } from '@vueuse/core'
import { getCharacter } from '../data/characters.js'
import { useOptionalA11yStore } from '../stores/a11yStore.js'

function featureAnchorY(element, bodyTop) {
	if (!element || typeof element !== 'object') {
		return bodyTop
	}

	switch (element.type) {
	case 'circle':
	case 'ellipse':
		return Number(element.cy ?? bodyTop)
	case 'rect':
		return Number(element.y ?? bodyTop) + Number(element.h ?? 0) / 2
	case 'line':
		return (Number(element.y1 ?? bodyTop) + Number(element.y2 ?? bodyTop)) / 2
	case 'path':
		return Number(element.anchorY ?? bodyTop)
	case 'text':
		return Number(element.y ?? bodyTop)
	default:
		return bodyTop
	}
}

function groupStyle(transformOrigin) {
	return {
		transformOrigin,
		transformBox: 'fill-box',
	}
}

export default {
	name: 'CharacterAvatar',

	props: {
		characterId: {
			type: String,
			required: true,
		},
		state: {
			type: String,
			default: 'idle',
		},
		size: {
			type: Number,
			default: 96,
		},
	},

	setup() {
		const avatarRef = ref(null)
		const svgRef = ref(null)
		const documentVisibility = useDocumentVisibility()
		const isIntersecting = ref(true)
		const a11yStore = useOptionalA11yStore()
		const animationsQuiet = computed(() => (a11yStore ? !a11yStore.animationsEnabled : false))
		let observer = null

		function syncAnimationPlayback() {
			const element = svgRef.value
			if (!element || typeof element.getAnimations !== 'function') {
				return
			}

			const shouldPause = animationsQuiet.value || !isIntersecting.value || documentVisibility.value === 'hidden'
			element.getAnimations({ subtree: true }).forEach((animation) => {
				if (shouldPause) {
					animation.pause()
				} else {
					animation.play()
				}
			})
		}

		onMounted(() => {
			if (typeof IntersectionObserver === 'function' && avatarRef.value) {
				observer = new IntersectionObserver((entries) => {
					isIntersecting.value = entries[0]?.isIntersecting !== false
					syncAnimationPlayback()
				}, { threshold: 0.05 })
				observer.observe(avatarRef.value)
			}

			syncAnimationPlayback()
		})

		watch(documentVisibility, syncAnimationPlayback)
		watch(animationsQuiet, syncAnimationPlayback)

		onBeforeUnmount(() => {
			if (observer) {
				observer.disconnect()
				observer = null
			}
		})

		return {
			animationsQuiet,
			avatarRef,
			svgRef,
		}
	},

	computed: {
		character() {
			return getCharacter(this.characterId)
		},

		ariaLabel() {
			return this.character.name
		},

		cx() { return this.size / 2 },
		headCy() { return this.size * 0.28 },
		headR() { return this.size * 0.16 },
		bodyTop() { return this.size * 0.46 },
		bodyBottom() { return this.size * 0.88 },

		bodyPath() {
			const s = this.size
			const cx = this.cx
			const top = this.bodyTop
			const bot = this.bodyBottom
			const sil = this.character.silhouette

			const widths = {
				nova: 0.28,
				architect: 0.34,
				security: 0.32,
				sysadmin: 0.36,
				helpdesk: 0.30,
				chronos: 0.24,
				ghostline: 0.26,
				klaus_dau: 0.36,
				dr_hartmann: 0.32,
				frau_weber: 0.30,
				uschi: 0.34,
				tim_azubi: 0.28,
				sven_berater: 0.30,
				theoretiker: 0.32,
				kosmologe: 0.34,
				popularisierer: 0.33,
				fallback: 0.30,
			}
			const hw = s * (widths[sil] || 0.30)

			if (sil === 'ghostline') {
				return `M${cx} ${top - 4} L${cx + hw} ${bot} L${cx - hw} ${bot} Z`
			}
			if (sil === 'chronos') {
				const coatBot = bot + 4
				return `M${cx - hw * 0.7} ${top} L${cx + hw * 0.7} ${top} L${cx + hw} ${coatBot} L${cx - hw} ${coatBot} Z`
			}
			if (sil === 'kosmologe') {
				return `M${cx - hw * 0.75} ${top} Q${cx} ${top - 8} ${cx + hw * 0.75} ${top} L${cx + hw} ${bot - 8} L${cx - hw} ${bot - 8} Z`
			}
			const topW = hw * 0.8
			return `M${cx - topW} ${top} Q${cx - topW - 2} ${top} ${cx - hw} ${bot} L${cx + hw} ${bot} Q${cx + topW + 2} ${top} ${cx + topW} ${top} Z`
		},

		featureElements() {
			const s = this.size
			const cx = this.cx
			const hcy = this.headCy
			const hr = this.headR
			const pal = this.character.palette
			const sil = this.character.silhouette

			switch (sil) {
			case 'nova':
				return [
					{ type: 'circle', cx, cy: hcy - hr - 6, r: hr + 4, fill: 'none', stroke: pal.glow, strokeWidth: 1.5, anchorY: hcy - hr - 6 },
					{ type: 'circle', cx: cx - 8, cy: hcy + hr + 14, r: 2, fill: pal.glow, anchorY: hcy + hr + 14 },
					{ type: 'circle', cx: cx + 8, cy: hcy + hr + 18, r: 2, fill: pal.glow, anchorY: hcy + hr + 18 },
					{ type: 'circle', cx, cy: hcy + hr + 22, r: 2, fill: pal.glow, anchorY: hcy + hr + 22 },
				]

			case 'architect':
				return [
					{ type: 'line', x1: cx - hr, y1: hcy - 2, x2: cx + hr, y2: hcy - 2, stroke: pal.glow, strokeWidth: 3, anchorY: hcy - 2 },
					{ type: 'rect', x: cx - hr - 2, y: hcy - 4, w: 4, h: 4, fill: pal.glow, anchorY: hcy - 2 },
					{ type: 'rect', x: cx + hr - 2, y: hcy - 4, w: 4, h: 4, fill: pal.glow, anchorY: hcy - 2 },
				]

			case 'security':
				return [
					{ type: 'path', d: `M${cx - hr + 2} ${hcy - 4} L${cx} ${hcy + 2} L${cx + hr - 2} ${hcy - 4}`, stroke: pal.glow, strokeWidth: 2, anchorY: hcy - 1 },
					{ type: 'line', x1: cx - s * 0.28, y1: s * 0.62, x2: cx + s * 0.28, y2: s * 0.62, stroke: pal.primary, strokeWidth: 2, anchorY: s * 0.62 },
				]

			case 'sysadmin':
				return [
					{ type: 'rect', x: cx - hr * 0.6, y: hcy + hr - 1, w: hr * 1.2, h: hr * 0.5, rx: 2, fill: pal.primary, anchorY: hcy + hr + 2 },
					{ type: 'rect', x: cx + s * 0.18, y: s * 0.58, w: s * 0.1, h: s * 0.12, rx: 2, fill: pal.glow, anchorY: s * 0.64 },
					{ type: 'path', d: `M${cx + s * 0.28} ${s * 0.60} Q${cx + s * 0.34} ${s * 0.64} ${cx + s * 0.28} ${s * 0.68}`, stroke: pal.glow, strokeWidth: 1.5, fill: 'none', anchorY: s * 0.64 },
				]

			case 'helpdesk':
				return [
					{ type: 'path', d: `M${cx - hr - 4} ${hcy} Q${cx} ${hcy - hr - 12} ${cx + hr + 4} ${hcy}`, stroke: pal.glow, strokeWidth: 2, fill: 'none', anchorY: hcy - hr - 6 },
					{ type: 'circle', cx: cx - hr - 4, cy: hcy + 2, r: 3, fill: pal.glow, anchorY: hcy + 2 },
					{ type: 'circle', cx: cx + hr + 4, cy: hcy + 2, r: 3, fill: pal.glow, anchorY: hcy + 2 },
				]

			case 'chronos':
				return [
					{ type: 'circle', cx: cx + s * 0.22, cy: s * 0.56, r: s * 0.07, fill: 'none', stroke: pal.glow, strokeWidth: 1.5, anchorY: s * 0.56 },
					{ type: 'line', x1: cx + s * 0.22, y1: s * 0.56, x2: cx + s * 0.22, y2: s * 0.50, stroke: pal.glow, strokeWidth: 1.5, anchorY: s * 0.53 },
					{ type: 'line', x1: cx + s * 0.22, y1: s * 0.56, x2: cx + s * 0.26, y2: s * 0.56, stroke: pal.glow, strokeWidth: 1.5, anchorY: s * 0.56 },
				]

			case 'ghostline':
				return [
					{ type: 'line', x1: cx - 6, y1: hcy - 3, x2: cx - 2, y2: hcy - 3, stroke: pal.accent, strokeWidth: 2, anchorY: hcy - 3 },
					{ type: 'line', x1: cx + 2, y1: hcy - 3, x2: cx + 6, y2: hcy - 3, stroke: pal.accent, strokeWidth: 2, anchorY: hcy - 3 },
					{ type: 'line', x1: cx - 10, y1: s * 0.55, x2: cx + 10, y2: s * 0.55, stroke: pal.accent, strokeWidth: 1, anchorY: s * 0.55 },
					{ type: 'line', x1: cx - 6, y1: s * 0.60, x2: cx + 14, y2: s * 0.60, stroke: pal.accent, strokeWidth: 1, anchorY: s * 0.60 },
					{ type: 'line', x1: cx - 14, y1: s * 0.65, x2: cx + 6, y2: s * 0.65, stroke: pal.accent, strokeWidth: 1, anchorY: s * 0.65 },
				]

			case 'klaus_dau':
				return [
					{ type: 'path', d: `M${cx - 6} ${hcy - hr - 10} Q${cx - 2} ${hcy - hr - 18} ${cx + 2} ${hcy - hr - 10} Q${cx + 6} ${hcy - hr - 2} ${cx + 10} ${hcy - hr - 10}`, stroke: pal.glow, strokeWidth: 1.5, fill: 'none', anchorY: hcy - hr - 10 },
					{ type: 'rect', x: cx + s * 0.16, y: s * 0.64, w: s * 0.06, h: s * 0.10, rx: 3, fill: pal.accent, anchorY: s * 0.69 },
				]

			case 'dr_hartmann':
				return [
					{ type: 'path', d: `M${cx} ${this.bodyTop} L${cx - 4} ${this.bodyTop + 16} L${cx + 4} ${this.bodyTop + 16} Z`, fill: pal.glow, anchorY: this.bodyTop + 8 },
					{ type: 'rect', x: cx + s * 0.16, y: s * 0.72, w: s * 0.14, h: s * 0.10, rx: 2, fill: pal.glow, anchorY: s * 0.77 },
					{ type: 'line', x1: cx + s * 0.20, y1: s * 0.72, x2: cx + s * 0.26, y2: s * 0.72, stroke: pal.primary, strokeWidth: 2, anchorY: s * 0.72 },
				]

			case 'frau_weber':
				return [
					{ type: 'circle', cx: cx - hr * 0.4, cy: hcy - 1, r: hr * 0.3, fill: 'none', stroke: pal.glow, strokeWidth: 1.5, anchorY: hcy - 1 },
					{ type: 'circle', cx: cx + hr * 0.4, cy: hcy - 1, r: hr * 0.3, fill: 'none', stroke: pal.glow, strokeWidth: 1.5, anchorY: hcy - 1 },
					{ type: 'line', x1: cx - hr * 0.1, y1: hcy - 1, x2: cx + hr * 0.1, y2: hcy - 1, stroke: pal.glow, strokeWidth: 1, anchorY: hcy - 1 },
					{ type: 'rect', x: cx - s * 0.28, y: s * 0.58, w: s * 0.12, h: s * 0.16, rx: 2, fill: pal.glow, anchorY: s * 0.66 },
				]

			case 'uschi':
				return [
					{ type: 'path', d: `M${cx - hr - 2} ${hcy - 4} Q${cx - hr - 6} ${hcy - hr} ${cx} ${hcy - hr - 4}`, stroke: pal.primary, strokeWidth: 2, fill: 'none', anchorY: hcy - hr - 4 },
					{ type: 'path', d: `M${cx} ${hcy - hr - 4} Q${cx + hr + 6} ${hcy - hr} ${cx + hr + 2} ${hcy - 4}`, stroke: pal.primary, strokeWidth: 2, fill: 'none', anchorY: hcy - hr - 4 },
					{ type: 'rect', x: cx - s * 0.24, y: s * 0.60, w: s * 0.08, h: s * 0.10, rx: 2, fill: pal.glow, anchorY: s * 0.65 },
					{ type: 'path', d: `M${cx - s * 0.16} ${s * 0.62} Q${cx - s * 0.12} ${s * 0.65} ${cx - s * 0.16} ${s * 0.68}`, stroke: pal.glow, strokeWidth: 1.5, fill: 'none', anchorY: s * 0.65 },
				]

			case 'tim_azubi':
				return [
					{ type: 'path', d: `M${cx - hr - 4} ${hcy + 2} Q${cx} ${hcy - hr - 10} ${cx + hr + 4} ${hcy + 2}`, stroke: pal.accent, strokeWidth: 2.5, fill: 'none', anchorY: hcy - hr - 4 },
					{ type: 'rect', x: cx + s * 0.14, y: s * 0.58, w: s * 0.06, h: s * 0.10, rx: 2, fill: pal.glow, anchorY: s * 0.63 },
				]

			case 'sven_berater':
				return [
					{ type: 'rect', x: cx - s * 0.14, y: s * 0.62, w: s * 0.28, h: s * 0.04, rx: 1, fill: pal.accent, anchorY: s * 0.64 },
					{ type: 'rect', x: cx - s * 0.12, y: s * 0.56, w: s * 0.24, h: s * 0.06, rx: 2, fill: pal.glow, anchorY: s * 0.59 },
					{ type: 'rect', x: cx + s * 0.18, y: hcy - hr - 10, w: s * 0.18, h: s * 0.10, rx: 4, fill: pal.glow, stroke: pal.accent, strokeWidth: 1, anchorY: hcy - hr - 5 },
					{ type: 'path', d: `M${cx + s * 0.22} ${hcy - hr} L${cx + s * 0.20} ${hcy - hr + 4} L${cx + s * 0.26} ${hcy - hr}`, fill: pal.glow, anchorY: hcy - hr + 2 },
				]

			case 'theoretiker':
				return [
					{ type: 'path', d: `M${cx - hr - 6} ${hcy - 6} Q${cx - hr} ${hcy - hr - 12} ${cx - 3} ${hcy - hr - 4}`, stroke: pal.glow, strokeWidth: 2.5, fill: 'none', anchorY: hcy - hr - 8 },
					{ type: 'path', d: `M${cx + 3} ${hcy - hr - 4} Q${cx + hr} ${hcy - hr - 12} ${cx + hr + 6} ${hcy - 4}`, stroke: pal.glow, strokeWidth: 2.5, fill: 'none', anchorY: hcy - hr - 8 },
					{ type: 'ellipse', cx: cx - 4, cy: hcy - 1, rx: 2.1, ry: 1.3, className: 'eye', fill: 'var(--color-main-text)', anchorY: hcy - 1 },
					{ type: 'ellipse', cx: cx + 4, cy: hcy - 1, rx: 2.1, ry: 1.3, className: 'eye', fill: 'var(--color-main-text)', anchorY: hcy - 1 },
					{ type: 'path', d: `M${cx - 5} ${hcy + hr - 2} Q${cx} ${hcy + hr + 4} ${cx + 5} ${hcy + hr - 2}`, stroke: 'var(--color-main-text)', strokeWidth: 1.4, fill: 'none', anchorY: hcy + hr },
					{ type: 'path', d: `M${cx - 10} ${s * 0.54} L${cx - 2} ${s * 0.50} L${cx + 4} ${s * 0.56}`, stroke: pal.glow, strokeWidth: 1.8, fill: 'none', anchorY: s * 0.54 },
					{ type: 'path', d: `M${cx + 8} ${s * 0.50} L${cx + 14} ${s * 0.47} M${cx + 10} ${s * 0.46} L${cx + 12} ${s * 0.52}`, stroke: pal.glow, strokeWidth: 1.6, fill: 'none', anchorY: s * 0.49 },
				]

			case 'kosmologe':
				return [
					{ type: 'circle', cx: cx - 4, cy: hcy - 1, r: 3.2, fill: 'none', stroke: pal.glow, strokeWidth: 1.3, anchorY: hcy - 1 },
					{ type: 'circle', cx: cx + 4, cy: hcy - 1, r: 3.2, fill: 'none', stroke: pal.glow, strokeWidth: 1.3, anchorY: hcy - 1 },
					{ type: 'line', x1: cx - 1, y1: hcy - 1, x2: cx + 1, y2: hcy - 1, stroke: pal.glow, strokeWidth: 1, anchorY: hcy - 1 },
					{ type: 'ellipse', cx: cx - 4, cy: hcy - 1, rx: 1.6, ry: 1.2, className: 'eye', fill: 'var(--color-main-text)', anchorY: hcy - 1 },
					{ type: 'ellipse', cx: cx + 4, cy: hcy - 1, rx: 1.6, ry: 1.2, className: 'eye', fill: 'var(--color-main-text)', anchorY: hcy - 1 },
					{ type: 'rect', x: cx - s * 0.20, y: s * 0.68, w: s * 0.40, h: s * 0.07, rx: 5, fill: pal.primary, opacity: 0.25, anchorY: s * 0.71 },
					{ type: 'circle', cx: cx - s * 0.16, cy: s * 0.80, r: s * 0.07, fill: 'none', stroke: pal.accent, strokeWidth: 2, anchorY: s * 0.80 },
					{ type: 'circle', cx: cx + s * 0.16, cy: s * 0.80, r: s * 0.07, fill: 'none', stroke: pal.accent, strokeWidth: 2, anchorY: s * 0.80 },
					{ type: 'path', d: `M${cx - s * 0.22} ${s * 0.74} L${cx - s * 0.30} ${s * 0.80} M${cx + s * 0.22} ${s * 0.74} L${cx + s * 0.30} ${s * 0.80}`, stroke: pal.glow, strokeWidth: 2, fill: 'none', anchorY: s * 0.77 },
					{ type: 'path', d: `M${cx - s * 0.24} ${s * 0.82} Q${cx - s * 0.18} ${s * 0.88} ${cx - s * 0.12} ${s * 0.82}`, stroke: pal.glow, strokeWidth: 1.6, fill: 'none', anchorY: s * 0.85 },
					{ type: 'path', d: `M${cx + s * 0.12} ${s * 0.82} Q${cx + s * 0.18} ${s * 0.88} ${cx + s * 0.24} ${s * 0.82}`, stroke: pal.glow, strokeWidth: 1.6, fill: 'none', anchorY: s * 0.85 },
				]

			case 'popularisierer':
				return [
					{ type: 'ellipse', cx: cx - 4, cy: hcy - 1, rx: 2, ry: 1.2, className: 'eye', fill: 'var(--color-main-text)', anchorY: hcy - 1 },
					{ type: 'ellipse', cx: cx + 4, cy: hcy - 1, rx: 2, ry: 1.2, className: 'eye', fill: 'var(--color-main-text)', anchorY: hcy - 1 },
					{ type: 'path', d: `M${cx - 3} ${hcy + hr - 1} Q${cx} ${hcy + hr + 5} ${cx + 3} ${hcy + hr - 1}`, stroke: 'var(--color-main-text)', strokeWidth: 1.5, fill: 'none', anchorY: hcy + hr + 1 },
					{ type: 'path', d: `M${cx - 7} ${s * 0.56} Q${cx} ${s * 0.50} ${cx + 7} ${s * 0.56}`, stroke: pal.glow, strokeWidth: 1.6, fill: 'none', anchorY: s * 0.54 },
					{ type: 'circle', cx: cx + s * 0.18, cy: s * 0.46, r: 3, fill: pal.glow, opacity: 0.85, anchorY: s * 0.46 },
					{ type: 'circle', cx: cx + s * 0.24, cy: s * 0.42, r: 1.8, fill: pal.accent, opacity: 0.85, anchorY: s * 0.42 },
					{ type: 'circle', cx: cx + s * 0.30, cy: s * 0.38, r: 1.2, fill: pal.primary, opacity: 0.8, anchorY: s * 0.38 },
					{ type: 'line', x1: cx + s * 0.16, y1: s * 0.46, x2: cx + s * 0.30, y2: s * 0.38, stroke: pal.glow, strokeWidth: 1, strokeDasharray: '2 2', anchorY: s * 0.42 },
				]

			default:
				return [
					{ type: 'text', x: cx, y: hcy, content: '?', fill: pal.primary, fontSize: hr * 1.4, anchorY: hcy },
				]
			}
		},

		headFeatureElements() {
			return this.featureElements.filter((element) => featureAnchorY(element, this.bodyTop) < this.bodyTop)
		},

		armFeatureElements() {
			return this.featureElements.filter((element) => featureAnchorY(element, this.bodyTop) >= this.bodyTop)
		},

		headGroupStyle() {
			return groupStyle('50% 35%')
		},

		bodyGroupStyle() {
			return groupStyle('50% 75%')
		},

		armsGroupStyle() {
			return groupStyle('50% 55%')
		},

		stateClasses() {
			return {
				'character-avatar': true,
				['character-state--' + this.state]: true,
				'lnc-quiet': this.animationsQuiet,
			}
		},

		avatarStyle() {
			return {
				width: this.size + 'px',
				height: this.size + 'px',
				position: 'relative',
				display: 'inline-block',
			}
		},

		showGlow() {
			return ['thinking', 'explain', 'celebrate', 'relieved'].includes(this.state)
		},

		glowStyle() {
			const glowColors = {
				thinking: 'var(--lnc-cyan)',
				explain: this.character.palette.accent,
				celebrate: 'var(--lnc-green)',
				relieved: 'var(--lnc-green)',
			}
			const color = glowColors[this.state] || 'var(--lnc-cyan)'
			return {
				position: 'absolute',
				top: '0',
				left: '0',
				width: '100%',
				height: '100%',
				borderRadius: '50%',
				background: `radial-gradient(circle, ${color}33 0%, transparent 70%)`,
				pointerEvents: 'none',
			}
		},
	},
}
</script>

<style scoped>
.character-avatar {
	transition: transform 200ms ease, opacity 200ms ease;
}

.character-state--idle {
	opacity: 1;
}

.character-state--thinking .character-glow {
	animation: ca-pulse 2s ease-in-out infinite;
}

.character-state--explain {
	transform: scale(1.02);
}

.character-state--alert {
	animation: ca-shake 400ms ease-in-out;
	box-shadow: 0 0 0 2px var(--lnc-danger);
	border-radius: 50%;
}

.character-state--celebrate {
	animation: ca-bounce 500ms ease-out;
}

.character-state--celebrate g#head {
	animation: ca-bounce 500ms ease-out;
}

.character-state--wave g#arms {
	animation: ca-wave 700ms ease-in-out;
}

.character-state--confused {
	animation: ca-tilt 800ms ease-in-out infinite;
}

.character-state--frustrated {
	filter: sepia(0.3) hue-rotate(-20deg) saturate(1.4);
}

.character-state--relieved {
	filter: brightness(1.15);
	animation: ca-gentle-bounce 600ms ease-out;
}

.character-state--impressed {
	transform: scale(1.06);
}

@keyframes ca-pulse {
	0%, 100% { opacity: 0.3; }
	50% { opacity: 0.7; }
}

@keyframes ca-shake {
	0%, 100% { transform: translateX(0); }
	20% { transform: translateX(-3px); }
	40% { transform: translateX(3px); }
	60% { transform: translateX(-2px); }
	80% { transform: translateX(2px); }
}

@keyframes ca-bounce {
	0% { transform: scale(1); }
	40% { transform: scale(1.08); }
	70% { transform: scale(0.98); }
	100% { transform: scale(1); }
}

@keyframes ca-wave {
	0%, 100% { transform: rotate(0deg); }
	25% { transform: rotate(10deg); }
	50% { transform: rotate(-6deg); }
	75% { transform: rotate(8deg); }
}

@keyframes ca-tilt {
	0%, 100% { transform: rotate(0deg); }
	25% { transform: rotate(3deg); }
	75% { transform: rotate(-3deg); }
}

@keyframes ca-gentle-bounce {
	0% { transform: translateY(0); }
	40% { transform: translateY(-3px); }
	100% { transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
	.character-avatar,
	.character-avatar * {
		animation: none !important;
		transition: none !important;
	}
}
</style>
