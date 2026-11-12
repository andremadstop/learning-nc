<?php
script('learning', 'learning-imprint-page');
style('learning', 'learning-imprint-page');
style('learning', 'style');
?>
<div
	id="learning-imprint-page"
	data-app-url="<?php p($_['appUrl']); ?>"
	data-privacy-url="<?php p($_['privacyUrl']); ?>"
	data-operator="<?php p(json_encode($_['operator'] ?? null)); ?>"></div>
