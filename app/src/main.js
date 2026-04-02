import Vue from 'vue';
import App from './App.vue';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { createPinia, PiniaVuePlugin } from 'pinia';

// Nextcloud Vue styles
import '@nextcloud/dialogs/style.css';
// Ghostline Quest visual takeover effects
import './css/ghostline.css';
// Skill-Map force-directed graph styles
import './css/skill-map.css';
// Practicum session runner styles
import '../css/practicum.css';

Vue.config.productionTip = false;
Vue.use(PiniaVuePlugin);

// Make t() and n() available in all components
Vue.prototype.t = t;
Vue.prototype.n = n;

const pinia = createPinia();

// Mount Vue app
new Vue({
  pinia,
  render: h => h(App)
}).$mount('#app-content');
