import { createApp } from 'vue';
import App from './App.vue';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { createPinia } from 'pinia';
import router from './router/index.js';
import { setAnimationsEnabledGetter } from './utils/character-animations.js';
import { useA11yStore } from './stores/a11yStore.js';

// Nextcloud Vue styles
import '@nextcloud/dialogs/style.css';
// Ghostline Quest visual takeover effects
import './css/ghostline.css';
// Skill-Map force-directed graph styles
import './css/skill-map.css';
// Practicum session runner styles
import './css/practicum.css';
// Shared character avatar animation primitives
import './styles/character-animations.css';

const pinia = createPinia();
const app = createApp(App);

// Make t() and n() available in all components
app.config.globalProperties.t = t;
app.config.globalProperties.n = n;
app.use(pinia);
app.use(router);

// Wire the WAAPI animation gate to the a11y store. The getter is called on
// every playWave/playCelebrate/playShrug invocation, so it always reads the
// current store value reactively. MUST come AFTER app.use(pinia) — otherwise
// useA11yStore() would throw "no active Pinia".
setAnimationsEnabledGetter(() => useA11yStore().animationsEnabled);

app.mount('#app-content');
