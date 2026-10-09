<?php
/**
 * Child process for LlmBudgetConcurrencyTest: boots Nextcloud, reports READY, blocks until the
 * test writes GO to its stdin, then makes exactly one reservation and prints OK or RATE_LIMIT.
 * The ticket is not released, so concurrency slots stay taken until the test cleans up.
 *
 * Usage: php llm_budget_worker.php <uid|-> <purpose>
 */
declare(strict_types=1);

require_once '/var/www/html/lib/base.php';

use OCA\Learning\Service\Llm\LlmBudget;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;

[$_, $uid, $purpose] = $argv;
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('learning');
$budget = \OCP\Server::get(LlmBudget::class);
$purposeEnum = LlmPurpose::from($purpose);

fwrite(STDOUT, "READY\n");
fflush(STDOUT);
if (trim((string)fgets(STDIN)) !== 'GO') {
	fwrite(STDOUT, "NO-GO\n");
	exit(2);
}

try {
	$budget->reserve($uid === '-' ? null : $uid, $purposeEnum);
	fwrite(STDOUT, "OK\n");
} catch (LlmProviderException $e) {
	fwrite(STDOUT, $e->category === 'rate_limit' ? "RATE_LIMIT\n" : 'ERROR ' . $e->category . "\n");
}
exit(0);
