<?php
script('learning', 'learning-admin-settings');
style('learning', 'learning-admin-settings');
// GitHub #55: class "section" gives the page the Nextcloud settings margin (core .section, 30px).
// Not set in the component: App.vue mounts the same component inside the app, which has its own frame.
?>
<div id="learning-admin-settings" class="section"></div>
