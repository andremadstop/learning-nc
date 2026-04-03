import { createApp } from 'vue';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import '@nextcloud/dialogs/style.css';
import PersonalSettings from './components/PersonalSettings.vue';

const app = createApp(PersonalSettings);

app.config.globalProperties.t = t;
app.config.globalProperties.n = n;
app.mount('#learning-personal-settings');
