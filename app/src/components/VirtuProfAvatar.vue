<template>
  <div
    class="virtuprof-avatar-wrapper"
    @click="handleClick">
    <div
      class="virtuprof-avatar"
      :class="[`animation-${animation}`, { 'has-message': hasMessage, 'is-waving': waving }]">

      <svg viewBox="0 0 60 80" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">

        <!-- Körper -->
        <rect x="15" y="40" width="30" height="30" rx="6" fill="#2c6c9f" />
        <!-- Hemd-Kragen (V-Form) -->
        <path d="M 23 40 L 30 47 L 37 40" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="1" stroke-linejoin="round" />
        <!-- Fliege -->
        <polygon points="27.5,44 30,42.5 27.5,41" fill="#f2c230" />
        <polygon points="32.5,44 30,42.5 32.5,41" fill="#f2c230" />
        <circle cx="30" cy="42.5" r="1.1" fill="#c49a00" />
        <!-- Fragezeichen -->
        <text x="30" y="63" text-anchor="middle" font-size="14" font-weight="bold" fill="rgba(255,255,255,0.85)" font-family="serif">?</text>

        <!-- Wink-Arm (nur sichtbar in is-waving) -->
        <g class="wave-arm">
          <path d="M 42 42 Q 52 34 50 24" stroke="#f3c7a6" stroke-width="4.5" fill="none" stroke-linecap="round" />
          <circle cx="50" cy="22" r="4" fill="#f3c7a6" />
        </g>

        <!-- Kopf -->
        <circle cx="30" cy="28" r="16" fill="#f3c7a6" />
        <!-- Ohrmuscheln -->
        <ellipse cx="14.5" cy="28" rx="2" ry="2.8" fill="#ecb48f" />
        <ellipse cx="45.5" cy="28" rx="2" ry="2.8" fill="#ecb48f" />

        <!-- Augenbrauen -->
        <g class="eyebrows">
          <path d="M 19.5 21.5 Q 23.5 19.5 27.5 21" stroke="#7a5230" stroke-width="1.3" fill="none" stroke-linecap="round" />
          <path d="M 32.5 21 Q 36.5 19.5 40.5 21.5" stroke="#7a5230" stroke-width="1.3" fill="none" stroke-linecap="round" />
        </g>

        <!-- Augen (Weiß + Iris + Pupille + Lichtpunkt) -->
        <g class="pupils">
          <!-- Linkes Auge -->
          <ellipse cx="24" cy="27" rx="3.8" ry="3.2" fill="white" />
          <circle cx="24" cy="27" r="2.1" fill="#5b8fd4" />
          <circle cx="24" cy="27" r="1.3" fill="#1a2535" />
          <circle cx="25" cy="26" r="0.65" fill="white" opacity="0.9" />
          <!-- Rechtes Auge -->
          <ellipse cx="36" cy="27" rx="3.8" ry="3.2" fill="white" />
          <circle cx="36" cy="27" r="2.1" fill="#5b8fd4" />
          <circle cx="36" cy="27" r="1.3" fill="#1a2535" />
          <circle cx="37" cy="26" r="0.65" fill="white" opacity="0.9" />
        </g>

        <!-- Brille (über Augen) -->
        <g class="glasses">
          <rect x="19.2" y="23.5" width="9.6" height="7" rx="2.5" fill="rgba(120,180,240,0.07)" stroke="#2c3e50" stroke-width="0.9" />
          <rect x="31.2" y="23.5" width="9.6" height="7" rx="2.5" fill="rgba(120,180,240,0.07)" stroke="#2c3e50" stroke-width="0.9" />
          <!-- Steg -->
          <line x1="28.8" y1="27" x2="31.2" y2="27" stroke="#2c3e50" stroke-width="0.9" />
          <!-- Bügel -->
          <line x1="19.2" y1="26.5" x2="15" y2="25" stroke="#2c3e50" stroke-width="0.9" stroke-linecap="round" />
          <line x1="40.8" y1="26.5" x2="45" y2="25" stroke="#2c3e50" stroke-width="0.9" stroke-linecap="round" />
        </g>

        <!-- Mund -->
        <path class="mouth" d="M 24.5 33.5 Q 30 38 35.5 33.5" stroke="#6b3a20" stroke-width="1.5" fill="none" stroke-linecap="round" />

        <!-- Mortarboard -->
        <rect x="14" y="13" width="32" height="4" rx="1" fill="#173a58" />
        <rect x="22" y="10" width="16" height="5" rx="2" fill="#173a58" />
        <line x1="38" y1="10" x2="43" y2="6" stroke="#f2c230" stroke-width="1.5" />
        <circle cx="43" cy="5.5" r="2.2" fill="#f2c230" />

        <!-- Celebrate-Partikel (alle Richtungen) -->
        <g class="celebrate-particles">
          <circle cx="7"  cy="22" r="2"   fill="#f2c230" />
          <circle cx="53" cy="18" r="1.6" fill="#e53935" />
          <circle cx="30" cy="5"  r="1.8" fill="#4caf50" />
          <circle cx="5"  cy="42" r="1.5" fill="#9c27b0" />
          <circle cx="55" cy="40" r="2"   fill="#2196f3" />
          <rect x="14" y="7"  width="2.5" height="2.5" rx="0.4" fill="#ff9800" transform="rotate(25 15.25 8.25)" />
          <rect x="43" y="8"  width="2.5" height="2.5" rx="0.4" fill="#f44336" transform="rotate(-20 44.25 9.25)" />
        </g>

      </svg>

    </div>

    <div v-if="inviteCount > 0" class="invite-badge" :aria-label="`${inviteCount} Duel-Einladung(en)`">
      {{ inviteCount > 9 ? '9+' : inviteCount }}
    </div>
  </div>
</template>

<script>
export default {
  name: 'VirtuProfAvatar',
  props: {
    animation: {
      type: String,
      default: 'idle',
    },
    hasMessage: {
      type: Boolean,
      default: false,
    },
    inviteCount: {
      type: Number,
      default: 0,
    },
  },
  data() {
    return { waving: false }
  },
  methods: {
    handleClick() {
      this.$emit('click')
      if (this.waving) return
      this.waving = true
      setTimeout(() => { this.waving = false }, 1200)
    },
  },
}
</script>

<style scoped>
.virtuprof-avatar-wrapper {
  position: relative;
  display: inline-block;
  cursor: pointer;
}

.virtuprof-avatar {
  width: 64px;
  height: 84px;
  transition: transform 0.2s ease, filter 0.2s ease;
  filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.25));
}

.virtuprof-avatar svg {
  width: 100%;
  height: 100%;
  display: block;
}

/* ── Hover ──────────────────────────────────────────── */
.virtuprof-avatar-wrapper:hover .virtuprof-avatar {
  transform: scale(1.08) translateY(-3px);
  filter: drop-shadow(0 6px 16px rgba(44, 108, 159, 0.45));
}

/* Pupillen + Brille wandern nach oben → "schaut an" */
.virtuprof-avatar-wrapper:hover .pupils,
.virtuprof-avatar-wrapper:hover .glasses {
  transform: translateY(-1.5px);
  transition: transform 0.2s ease-out;
}

/* Augenbrauen leicht hoch */
.virtuprof-avatar-wrapper:hover .eyebrows {
  transform: translateY(-1px);
  transition: transform 0.2s ease-out;
}

/* Idle-Bob pausiert beim Hover */
.virtuprof-avatar-wrapper:hover .animation-idle {
  animation-play-state: paused;
}

/* ── Badge ─────────────────────────────────────────── */
.invite-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  min-width: 20px;
  height: 20px;
  border-radius: 10px;
  background: #e53935;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  line-height: 20px;
  text-align: center;
  padding: 0 4px;
  pointer-events: none;
  box-shadow: 0 1px 4px rgba(0,0,0,0.3);
  animation: badge-pop 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

@keyframes badge-pop {
  0% { transform: scale(0); }
  100% { transform: scale(1); }
}

/* ── Wink-Arm + Partikel normalerweise unsichtbar ──── */
.wave-arm,
.celebrate-particles {
  opacity: 0;
}

/* ── has-message ───────────────────────────────────── */
.virtuprof-avatar.has-message {
  transform-origin: bottom center;
}

/* ── IDLE ──────────────────────────────────────────── */
.animation-idle {
  animation: idle-bob 3s ease-in-out infinite;
}

@keyframes idle-bob {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-4px); }
}

/* ── TALK ──────────────────────────────────────────── */
.animation-talk {
  animation: talk-nod 0.5s ease-in-out infinite;
}

@keyframes talk-nod {
  0%, 100% { transform: rotate(0deg); }
  25% { transform: rotate(-2.5deg); }
  75% { transform: rotate(2.5deg); }
}

/* ── WAVE ──────────────────────────────────────────── */
.animation-wave {
  animation: wave-bob 0.8s ease-in-out infinite;
}

/* ── Wink-Arm bei Klick ─────────────────────────────── */
.is-waving .wave-arm {
  opacity: 1;
  transform-origin: 42px 42px;
  animation: wave-swing 0.4s ease-in-out 3;
}

@keyframes wave-bob {
  0%, 100% { transform: rotate(-4deg); }
  50% { transform: rotate(4deg); }
}

@keyframes wave-swing {
  0%, 100% { transform: rotate(-20deg) translateY(0); }
  50% { transform: rotate(20deg) translateY(-8px); }
}

/* ── CELEBRATE ─────────────────────────────────────── */
.animation-celebrate {
  animation: celebrate-jump 0.7s ease-in-out infinite;
}

.animation-celebrate .celebrate-particles {
  animation: particles-burst 1.5s ease-out forwards;
}

/* Augenbrauen heben sich bei celebrate */
.animation-celebrate .eyebrows {
  transform: translateY(-2px);
  transition: transform 0.2s ease;
}

/* Breiteres Lächeln bei celebrate via transform */
.animation-celebrate .mouth {
  transform: scaleX(1.1);
  transform-box: fill-box;
  transform-origin: center;
}

/* Pupillen schauen bei celebrate leicht hoch */
.animation-celebrate .pupils,
.animation-celebrate .glasses {
  transform: translateY(-1.5px);
}

@keyframes celebrate-jump {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-10px) rotate(5deg); }
}

@keyframes particles-burst {
  0%   { opacity: 0; transform: scale(0.5); }
  20%  { opacity: 1; transform: scale(1); }
  100% { opacity: 0; transform: scale(1.3) translateY(-20px); }
}

/* ── Barrierefreiheit ──────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
  .virtuprof-avatar,
  .celebrate-particles,
  .eyebrows,
  .pupils,
  .glasses,
  .mouth {
    animation: none !important;
    transition: none !important;
  }
  .is-waving .wave-arm {
    animation: none !important;
    opacity: 0 !important;
  }
}
</style>
