import { createApp } from 'vue';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { createPinia } from 'pinia';
import '@nextcloud/dialogs/style.css';
import PersonalSettings from './components/PersonalSettings.vue';
import './styles/character-animations.css';
import { setAnimationsEnabledGetter } from './utils/character-animations.js';
import { useA11yStore } from './stores/a11yStore.js';

const pinia = createPinia();
const a11yStore = useA11yStore(pinia);
const app = createApp(PersonalSettings);

app.config.globalProperties.t = t;
app.config.globalProperties.n = n;
setAnimationsEnabledGetter(() => a11yStore.animationsEnabled);
app.use(pinia);
app.mount('#learning-personal-settings');
