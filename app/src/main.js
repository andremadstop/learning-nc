import { createApp } from 'vue';
import App from './App.vue';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { createPinia } from 'pinia';
import router from './router/index.js';

// Nextcloud Vue styles
import '@nextcloud/dialogs/style.css';
// Ghostline Quest visual takeover effects
import './css/ghostline.css';
// Skill-Map force-directed graph styles
import './css/skill-map.css';
// Practicum session runner styles
import '../css/practicum.css';

const pinia = createPinia();
const app = createApp(App);

// Make t() and n() available in all components
app.config.globalProperties.t = t;
app.config.globalProperties.n = n;
app.use(pinia);
app.use(router);
app.mount('#app-content');
