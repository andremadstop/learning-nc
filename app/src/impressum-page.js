import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import '@nextcloud/dialogs/style.css'
import ImprintPage from './components/ImpressumPage.vue'

Vue.config.productionTip = false
Vue.prototype.t = t
Vue.prototype.n = n

const mountNode = document.getElementById('learning-imprint-page')
const appUrl = mountNode?.dataset?.appUrl || ''
const privacyUrl = mountNode?.dataset?.privacyUrl || ''

new Vue({
	render: (h) => h(ImprintPage, {
		props: {
			appUrl,
			privacyUrl,
		},
	}),
}).$mount('#learning-imprint-page')
