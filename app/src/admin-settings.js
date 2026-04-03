import { createApp } from 'vue';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import '@nextcloud/dialogs/style.css';
import AdminSettings from './components/AdminSettings.vue';

const app = createApp(AdminSettings);

app.config.globalProperties.t = t;
app.config.globalProperties.n = n;
app.mount('#learning-admin-settings');
