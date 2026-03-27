import Vue from 'vue';
import App from './App.vue';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';

// Nextcloud Vue styles
import '@nextcloud/dialogs/style.css';
// Epoch theme tokens for Hack Through Time
import '../css/epoch-tokens.css';
// Ghostline Quest visual takeover effects
import './css/ghostline.css';

Vue.config.productionTip = false;

// Make t() and n() available in all components
Vue.prototype.t = t;
Vue.prototype.n = n;

// Mount Vue app
new Vue({
  render: h => h(App)
}).$mount('#app-content');
