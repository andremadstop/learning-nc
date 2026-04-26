import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import '@nextcloud/dialogs/style.css';
import PersonalSettings from './components/PersonalSettings.vue';

// Pinia is required for the skin picker (Phase 153 Plan 04 wired useSkinStore
// into PersonalSettings.vue computed `skinOptions`). Without app.use(pinia)
// the component crashes on mount with "Cannot read properties of undefined
// (reading '_s')" — Pinia internal symbol lookup against a missing instance.
const pinia = createPinia();
const app = createApp(PersonalSettings);

app.use(pinia);
app.config.globalProperties.t = t;
app.config.globalProperties.n = n;
app.mount('#learning-personal-settings');
