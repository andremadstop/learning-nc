<template>
	<div class="character-avatar"
		:class="stateClasses"
		:style="avatarStyle">
		<svg :width="size"
			:height="size"
			:viewBox="'0 0 ' + size + ' ' + size"
			xmlns="http://www.w3.org/2000/svg"
			role="img"
			:aria-label="character.name + ' (' + state + ')'">
			<!-- Head -->
			<circle :cx="cx" :cy="headCy" :r="headR" :fill="character.palette.primary" />

			<!-- Body -->
			<path :d="bodyPath" :fill="character.palette.accent" />

			<!-- Distinguishing feature elements -->
			<template v-for="(el, i) in featureElements" :key="'f-' + i">
				<circle v-if="el.type === 'circle'"
					:cx="el.cx"
					:cy="el.cy"
					:r="el.r"
					:fill="el.fill || character.palette.accent"
					:stroke="el.stroke || 'none'"
					:stroke-width="el.strokeWidth || 0" />
				<rect v-else-if="el.type === 'rect'"
					:x="el.x"
					:y="el.y"
					:width="el.w"
					:height="el.h"
					:rx="el.rx || 0"
					:fill="el.fill || character.palette.accent"
					:stroke="el.stroke || 'none'"
					:stroke-width="el.strokeWidth || 0" />
				<path v-else-if="el.type === 'path'"
					:d="el.d"
					:fill="el.fill || 'none'"
					:stroke="el.stroke || character.palette.accent"
					:stroke-width="el.strokeWidth || 2"
					:stroke-linecap="el.linecap || 'round'" />
				<line v-else-if="el.type === 'line'"
					:key="'f-' + i"
					:x1="el.x1"
					:y1="el.y1"
					:x2="el.x2"
					:y2="el.y2"
					:stroke="el.stroke || character.palette.accent"
					:stroke-width="el.strokeWidth || 2"
					:stroke-linecap="el.linecap || 'round'" />
				<text v-else-if="el.type === 'text'"
					:key="'f-' + i"
					:x="el.x"
					:y="el.y"
					:fill="el.fill || character.palette.primary"
					:font-size="el.fontSize || 14"
					text-anchor="middle"
					dominant-baseline="central">{{ el.content }}</text>
			</template>
		</svg>
		<!-- State glow overlay -->
		<div v-if="showGlow" class="character-glow" :style="glowStyle" />
	</div>
</template>

<script>
import { getCharacter } from '../data/characters.js'

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

	computed: {
		character() {
			return getCharacter(this.characterId)
		},

		/** Base metrics derived from size */
		cx() { return this.size / 2 },
		headCy() { return this.size * 0.28 },
		headR() { return this.size * 0.16 },
		bodyTop() { return this.size * 0.46 },
		bodyBottom() { return this.size * 0.88 },

		/** Body path varies by silhouette type */
		bodyPath() {
			const s = this.size
			const cx = this.cx
			const top = this.bodyTop
			const bot = this.bodyBottom
			const sil = this.character.silhouette

			// Width multipliers per silhouette
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
				fallback: 0.30,
			}
			const hw = s * (widths[sil] || 0.30)

			if (sil === 'ghostline') {
				// Angular hood shape
				return `M${cx} ${top - 4} L${cx + hw} ${bot} L${cx - hw} ${bot} Z`
			}
			if (sil === 'chronos') {
				// Tall coat triangle
				const coatBot = bot + 4
				return `M${cx - hw * 0.7} ${top} L${cx + hw * 0.7} ${top} L${cx + hw} ${coatBot} L${cx - hw} ${coatBot} Z`
			}
			// Default rounded body trapezoid
			const topW = hw * 0.8
			return `M${cx - topW} ${top} Q${cx - topW - 2} ${top} ${cx - hw} ${bot} L${cx + hw} ${bot} Q${cx + topW + 2} ${top} ${cx + topW} ${top} Z`
		},

		/** Feature elements unique to each silhouette */
		featureElements() {
			const s = this.size
			const cx = this.cx
			const hcy = this.headCy
			const hr = this.headR
			const pal = this.character.palette
			const sil = this.character.silhouette

			switch (sil) {
			case 'nova':
				// Halo ring above head + data dots
				return [
					{ type: 'circle', cx, cy: hcy - hr - 6, r: hr + 4, fill: 'none', stroke: pal.glow, strokeWidth: 1.5 },
					{ type: 'circle', cx: cx - 8, cy: hcy + hr + 14, r: 2, fill: pal.glow },
					{ type: 'circle', cx: cx + 8, cy: hcy + hr + 18, r: 2, fill: pal.glow },
					{ type: 'circle', cx, cy: hcy + hr + 22, r: 2, fill: pal.glow },
				]

			case 'architect':
				// Visor line across eyes
				return [
					{ type: 'line', x1: cx - hr, y1: hcy - 2, x2: cx + hr, y2: hcy - 2, stroke: pal.glow, strokeWidth: 3 },
					{ type: 'rect', x: cx - hr - 2, y: hcy - 4, w: 4, h: 4, fill: pal.glow },
					{ type: 'rect', x: cx + hr - 2, y: hcy - 4, w: 4, h: 4, fill: pal.glow },
				]

			case 'security':
				// V-shaped visor + tool belt line
				return [
					{ type: 'path', d: `M${cx - hr + 2} ${hcy - 4} L${cx} ${hcy + 2} L${cx + hr - 2} ${hcy - 4}`, stroke: pal.glow, strokeWidth: 2 },
					{ type: 'line', x1: cx - s * 0.28, y1: s * 0.62, x2: cx + s * 0.28, y2: s * 0.62, stroke: pal.primary, strokeWidth: 2 },
				]

			case 'sysadmin':
				// Beard below chin + coffee mug
				return [
					{ type: 'rect', x: cx - hr * 0.6, y: hcy + hr - 1, w: hr * 1.2, h: hr * 0.5, rx: 2, fill: pal.primary },
					{ type: 'rect', x: cx + s * 0.18, y: s * 0.58, w: s * 0.1, h: s * 0.12, rx: 2, fill: pal.glow },
					{ type: 'path', d: `M${cx + s * 0.28} ${s * 0.60} Q${cx + s * 0.34} ${s * 0.64} ${cx + s * 0.28} ${s * 0.68}`, stroke: pal.glow, strokeWidth: 1.5, fill: 'none' },
				]

			case 'helpdesk':
				// Headset arc over head + strap line
				return [
					{ type: 'path', d: `M${cx - hr - 4} ${hcy} Q${cx} ${hcy - hr - 12} ${cx + hr + 4} ${hcy}`, stroke: pal.glow, strokeWidth: 2, fill: 'none' },
					{ type: 'circle', cx: cx - hr - 4, cy: hcy + 2, r: 3, fill: pal.glow },
					{ type: 'circle', cx: cx + hr + 4, cy: hcy + 2, r: 3, fill: pal.glow },
				]

			case 'chronos':
				// Clock circle accessory
				return [
					{ type: 'circle', cx: cx + s * 0.22, cy: s * 0.56, r: s * 0.07, fill: 'none', stroke: pal.glow, strokeWidth: 1.5 },
					{ type: 'line', x1: cx + s * 0.22, y1: s * 0.56, x2: cx + s * 0.22, y2: s * 0.50, stroke: pal.glow, strokeWidth: 1.5 },
					{ type: 'line', x1: cx + s * 0.22, y1: s * 0.56, x2: cx + s * 0.26, y2: s * 0.56, stroke: pal.glow, strokeWidth: 1.5 },
				]

			case 'ghostline':
				// No face (empty), glitch lines
				return [
					{ type: 'line', x1: cx - 6, y1: hcy - 3, x2: cx - 2, y2: hcy - 3, stroke: pal.accent, strokeWidth: 2 },
					{ type: 'line', x1: cx + 2, y1: hcy - 3, x2: cx + 6, y2: hcy - 3, stroke: pal.accent, strokeWidth: 2 },
					{ type: 'line', x1: cx - 10, y1: s * 0.55, x2: cx + 10, y2: s * 0.55, stroke: pal.accent, strokeWidth: 1 },
					{ type: 'line', x1: cx - 6, y1: s * 0.60, x2: cx + 14, y2: s * 0.60, stroke: pal.accent, strokeWidth: 1 },
					{ type: 'line', x1: cx - 14, y1: s * 0.65, x2: cx + 6, y2: s * 0.65, stroke: pal.accent, strokeWidth: 1 },
				]

			case 'klaus_dau':
				// Confused squiggle above head + mouse in hand
				return [
					{ type: 'path', d: `M${cx - 6} ${hcy - hr - 10} Q${cx - 2} ${hcy - hr - 18} ${cx + 2} ${hcy - hr - 10} Q${cx + 6} ${hcy - hr - 2} ${cx + 10} ${hcy - hr - 10}`, stroke: pal.glow, strokeWidth: 1.5, fill: 'none' },
					{ type: 'rect', x: cx + s * 0.16, y: s * 0.64, w: s * 0.06, h: s * 0.10, rx: 3, fill: pal.accent },
				]

			case 'dr_hartmann':
				// Tie triangle + briefcase rect
				return [
					{ type: 'path', d: `M${cx} ${this.bodyTop} L${cx - 4} ${this.bodyTop + 16} L${cx + 4} ${this.bodyTop + 16} Z`, fill: pal.glow },
					{ type: 'rect', x: cx + s * 0.16, y: s * 0.72, w: s * 0.14, h: s * 0.10, rx: 2, fill: pal.glow },
					{ type: 'line', x1: cx + s * 0.20, y1: s * 0.72, x2: cx + s * 0.26, y2: s * 0.72, stroke: pal.primary, strokeWidth: 2 },
				]

			case 'frau_weber':
				// Glasses circles + folder rect
				return [
					{ type: 'circle', cx: cx - hr * 0.4, cy: hcy - 1, r: hr * 0.3, fill: 'none', stroke: pal.glow, strokeWidth: 1.5 },
					{ type: 'circle', cx: cx + hr * 0.4, cy: hcy - 1, r: hr * 0.3, fill: 'none', stroke: pal.glow, strokeWidth: 1.5 },
					{ type: 'line', x1: cx - hr * 0.1, y1: hcy - 1, x2: cx + hr * 0.1, y2: hcy - 1, stroke: pal.glow, strokeWidth: 1 },
					{ type: 'rect', x: cx - s * 0.28, y: s * 0.58, w: s * 0.12, h: s * 0.16, rx: 2, fill: pal.glow },
				]

			case 'uschi':
				// Wavy hair arcs + coffee cup
				return [
					{ type: 'path', d: `M${cx - hr - 2} ${hcy - 4} Q${cx - hr - 6} ${hcy - hr} ${cx} ${hcy - hr - 4}`, stroke: pal.primary, strokeWidth: 2, fill: 'none' },
					{ type: 'path', d: `M${cx} ${hcy - hr - 4} Q${cx + hr + 6} ${hcy - hr} ${cx + hr + 2} ${hcy - 4}`, stroke: pal.primary, strokeWidth: 2, fill: 'none' },
					{ type: 'rect', x: cx - s * 0.24, y: s * 0.60, w: s * 0.08, h: s * 0.10, rx: 2, fill: pal.glow },
					{ type: 'path', d: `M${cx - s * 0.16} ${s * 0.62} Q${cx - s * 0.12} ${s * 0.65} ${cx - s * 0.16} ${s * 0.68}`, stroke: pal.glow, strokeWidth: 1.5, fill: 'none' },
				]

			case 'tim_azubi':
				// Hoodie hood arc + phone rect
				return [
					{ type: 'path', d: `M${cx - hr - 4} ${hcy + 2} Q${cx} ${hcy - hr - 10} ${cx + hr + 4} ${hcy + 2}`, stroke: pal.accent, strokeWidth: 2.5, fill: 'none' },
					{ type: 'rect', x: cx + s * 0.14, y: s * 0.58, w: s * 0.06, h: s * 0.10, rx: 2, fill: pal.glow },
				]

			case 'sven_berater':
				// Laptop rect + speech bubble
				return [
					{ type: 'rect', x: cx - s * 0.14, y: s * 0.62, w: s * 0.28, h: s * 0.04, rx: 1, fill: pal.accent },
					{ type: 'rect', x: cx - s * 0.12, y: s * 0.56, w: s * 0.24, h: s * 0.06, rx: 2, fill: pal.glow },
					{ type: 'rect', x: cx + s * 0.18, y: hcy - hr - 10, w: s * 0.18, h: s * 0.10, rx: 4, fill: pal.glow, stroke: pal.accent, strokeWidth: 1 },
					{ type: 'path', d: `M${cx + s * 0.22} ${hcy - hr} L${cx + s * 0.20} ${hcy - hr + 4} L${cx + s * 0.26} ${hcy - hr}`, fill: pal.glow },
				]

			default:
				// Fallback: question mark
				return [
					{ type: 'text', x: cx, y: hcy, content: '?', fill: pal.primary, fontSize: hr * 1.4 },
				]
			}
		},

		stateClasses() {
			return {
				'character-avatar': true,
				['character-state--' + this.state]: true,
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

/* ── State: idle ── */
.character-state--idle {
	opacity: 1;
}

/* ── State: thinking ── */
.character-state--thinking .character-glow {
	animation: ca-pulse 2s ease-in-out infinite;
}

/* ── State: explain ── */
.character-state--explain {
	transform: scale(1.02);
}

/* ── State: alert ── */
.character-state--alert {
	animation: ca-shake 400ms ease-in-out;
	box-shadow: 0 0 0 2px var(--lnc-danger);
	border-radius: 50%;
}

/* ── State: celebrate ── */
.character-state--celebrate {
	animation: ca-bounce 500ms ease-out;
}

/* ── State: confused ── */
.character-state--confused {
	animation: ca-tilt 800ms ease-in-out infinite;
}

/* ── State: frustrated ── */
.character-state--frustrated {
	filter: sepia(0.3) hue-rotate(-20deg) saturate(1.4);
}

/* ── State: relieved ── */
.character-state--relieved {
	filter: brightness(1.15);
	animation: ca-gentle-bounce 600ms ease-out;
}

/* ── State: impressed ── */
.character-state--impressed {
	transform: scale(1.06);
}

/* ── Keyframes ── */
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

/* ── Accessibility: reduce motion ── */
@media (prefers-reduced-motion: reduce) {
	.character-avatar,
	.character-avatar * {
		animation: none !important;
		transition: none !important;
	}
}
</style>
