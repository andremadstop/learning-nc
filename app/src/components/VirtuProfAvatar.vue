<template>
  <div
    class="virtuprof-avatar"
    :class="[`animation-${animation}`, { 'has-message': hasMessage }]"
    @click="$emit('click')">
    <svg viewBox="0 0 60 80" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <rect x="15" y="40" width="30" height="30" rx="6" fill="#2c6c9f" />
      <circle cx="30" cy="28" r="16" fill="#f3c7a6" />
      <circle cx="24" cy="26" r="2.5" fill="#263238" />
      <circle cx="36" cy="26" r="2.5" fill="#263238" />
      <path class="mouth" d="M 24 33 Q 30 38 36 33" stroke="#263238" stroke-width="1.5" fill="none" />
      <rect x="14" y="13" width="32" height="4" rx="1" fill="#173a58" />
      <rect x="22" y="10" width="16" height="5" rx="2" fill="#173a58" />
      <line x1="38" y1="10" x2="42" y2="6" stroke="#f2c230" stroke-width="1.5" />
      <circle cx="42" cy="6" r="2" fill="#f2c230" />
    </svg>
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
  },
}
</script>

<style scoped>
.virtuprof-avatar {
  width: 64px;
  height: 84px;
  cursor: pointer;
  transition: transform 0.2s ease;
  filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.25));
}

.virtuprof-avatar svg {
  width: 100%;
  height: 100%;
  display: block;
}

.virtuprof-avatar:hover {
  transform: scale(1.05);
}

.virtuprof-avatar.has-message {
  transform-origin: bottom center;
}

.animation-idle {
  animation: idle-bob 3s ease-in-out infinite;
}

.animation-talk {
  animation: talk-nod 0.5s ease-in-out infinite;
}

.animation-celebrate {
  animation: celebrate-jump 0.7s ease-in-out infinite;
}

.animation-wave {
  animation: wave-bob 0.8s ease-in-out infinite;
}

@keyframes idle-bob {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-4px); }
}

@keyframes talk-nod {
  0%, 100% { transform: rotate(0deg); }
  25% { transform: rotate(-3deg); }
  75% { transform: rotate(3deg); }
}

@keyframes celebrate-jump {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-10px) rotate(5deg); }
}

@keyframes wave-bob {
  0%, 100% { transform: rotate(-4deg); }
  50% { transform: rotate(4deg); }
}
</style>
