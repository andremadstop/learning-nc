import Vue from 'vue';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import '@nextcloud/dialogs/style.css';
import PersonalSettings from './components/PersonalSettings.vue';

Vue.config.productionTip = false;
Vue.prototype.t = t;
Vue.prototype.n = n;

new Vue({
  render: h => h(PersonalSettings),
}).$mount('#learning-personal-settings');
