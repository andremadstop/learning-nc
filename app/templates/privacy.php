<?php
script('learning', 'learning-privacy-page');
style('learning', 'learning-privacy-page');
style('learning', 'style');
?>
<div
	id="learning-privacy-page"
	data-app-url="<?php p($_['appUrl']); ?>"
	data-imprint-url="<?php p($_['imprintUrl']); ?>"
	data-ai-notice="<?php p(json_encode($_['aiNotice'] ?? null)); ?>"></div>
