import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import '@nextcloud/dialogs/style.css'
import PrivacyPage from './components/PrivacyPage.vue'

Vue.config.productionTip = false
Vue.prototype.t = t
Vue.prototype.n = n

const mountNode = document.getElementById('learning-privacy-page')
const appUrl = mountNode?.dataset?.appUrl || ''
const imprintUrl = mountNode?.dataset?.imprintUrl || ''

new Vue({
	render: (h) => h(PrivacyPage, {
		props: {
			appUrl,
			imprintUrl,
		},
	}),
}).$mount('#learning-privacy-page')
