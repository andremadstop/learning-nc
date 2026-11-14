<?php
script('learning', 'learning-personal-settings');
style('learning', 'learning-personal-settings');
// GitHub #55: class "section" gives the page the Nextcloud settings margin (core .section, 30px).
// Not set in the component: App.vue mounts the same component inside the app, which has its own frame.
?>
<div id="learning-personal-settings" class="section"></div>
