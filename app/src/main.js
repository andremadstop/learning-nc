import Vue from 'vue';
import App from './App.vue';

// Nextcloud Vue styles
import '@nextcloud/dialogs/style.css';

Vue.config.productionTip = false;

// Mount Vue app
new Vue({
  render: h => h(App)
}).$mount('#app-content');
