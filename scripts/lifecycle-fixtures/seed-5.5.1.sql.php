<?php
/**
 * Seeds a Nextcloud with learning 5.5.1 enabled with the legacy AI state that the 5.6.0
 * upgrade has to carry over. Used by scripts/lifecycle-probe.sh; run it only in a throwaway
 * container, as the web server user:
 *
 *   docker exec -u www-data <nc> php /tmp/seed-5.5.1.sql.php
 *
 * Writes go through 5.5.1's own services and Nextcloud's query builder, so the same seeder
 * works on PostgreSQL and MariaDB and the chained audit rows form a valid hash chain.
 * The users (lc-user1..3) must already exist (occ user:add). Exit 0 on success, 1 otherwise.
 *
 * Seeded:
 *   - ai_consent_version '2.0' for 3 users (TelosService::saveAiConsent)
 *   - appconfig: gemini_api_key in plaintext, ai_ollama_url, ai_ollama_model, ai_provider, ai_enabled
 *   - 5 plain AI audit rows (ai_chat, note_generation, ...) shaped like GeminiService::writeAuditLogWithKey
 *   - 3 chained audit rows (AuditService::logComplianceEvent)
 *   - 2 learning_ai_chat_memory rows
 *   - 2 open TaskProcessing tasks with appId=learning (one scheduled, one running)
 * A fresh 5.5.1 install has no learning_audit_chain_state row: Nextcloud applies a new app's
 * schema without running postSchemaChange(), where Version009300 seeds the genesis row. The seeder
 * then inserts that genesis row itself (same values) and says so, so that the chained rows exist
 * as they do on instances that were upgraded through 5.x.
 * The TaskProcessing tasks go straight through Nextcloud's task mapper: IManager::scheduleTask()
 * refuses a task type without a provider, and a fresh Nextcloud has none. The rows are the same
 * ones scheduleTask() would write (Db\Task::fromPublicTask), readable via getUserTasksByApp().
 */
declare(strict_types=1);

require_once '/var/www/html/lib/base.php';

use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\TelosService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use OCP\TaskProcessing\IManager as TaskManager;
use OCP\TaskProcessing\Task as PublicTask;

const USERS = ['lc-user1', 'lc-user2', 'lc-user3'];
// Recognisable fake, never a real key. The upgrade must move it out of plaintext appconfig.
const FAKE_GEMINI_KEY = 'AIzaFAKE-lifecycle-probe-not-a-real-key-000';

// Nextcloud's error handler turns an uncaught exception into a log entry and exit code 0,
// so everything runs inside this try and a failure exits 1 explicitly.
try {
$config = Server::get(IConfig::class);
$db = Server::get(IDBConnection::class);
$telos = Server::get(TelosService::class);
$audit = Server::get(AuditService::class);

foreach (USERS as $uid) {
	$telos->saveAiConsent($uid, '2.0');
}

$config->setAppValue('learning', 'gemini_api_key', FAKE_GEMINI_KEY);
$config->setAppValue('learning', 'ai_provider', 'gemini');
$config->setAppValue('learning', 'ai_enabled', 'yes');
$config->setAppValue('learning', 'ai_ollama_url', 'http://ollama.lifecycle.invalid:11434');
$config->setAppValue('learning', 'ai_ollama_model', 'llama3.1:8b');

$now = time();
$plainAudit = [
	['ai_chat', 'lc-user1'],
	['ai_chat', 'lc-user2'],
	['note_generation', 'system'],
	['note_generation', 'lc-user3'],
	['ai_explain', 'lc-user1'],
];
foreach ($plainAudit as $i => [$key, $uid]) {
	$qb = $db->getQueryBuilder();
	$qb->insert('learning_audit_events')->values([
		'event_key' => $qb->createNamedParameter($key),
		'user_id' => $qb->createNamedParameter($uid),
		'context_json' => $qb->createNamedParameter(json_encode([
			'input' => "lifecycle input $i",
			'output' => "lifecycle output $i",
			'model' => 'gemini',
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
		'created_at' => $qb->createNamedParameter($now - 100 + $i),
	])->executeStatement();
}

$qb = $db->getQueryBuilder();
$r = $qb->select($qb->func()->count('*', 'n'))->from('learning_audit_chain_state')->executeQuery();
$chainRows = (int)$r->fetchOne();
$r->closeCursor();
if ($chainRows === 0) {
	echo "SEED note: learning_audit_chain_state empty after fresh install; inserting genesis row as Version009300::postSchemaChange would\n";
	$qb = $db->getQueryBuilder();
	$qb->insert('learning_audit_chain_state')->values([
		'id' => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'last_seq' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
		'last_hash' => $qb->createNamedParameter(str_repeat('0', 64)),
	])->executeStatement();
}

foreach (USERS as $i => $uid) {
	$audit->logComplianceEvent('course_passed', $uid, ['course_id' => 1, 'score' => 80 + $i]);
}

foreach ([['user', 'Was ist ein Subnetz?'], ['assistant', 'Ein Teilnetz eines IP-Netzes.']] as $i => [$role, $msg]) {
	$qb = $db->getQueryBuilder();
	$qb->insert('learning_ai_chat_memory')->values([
		'user_id' => $qb->createNamedParameter('lc-user1'),
		'role' => $qb->createNamedParameter($role),
		'message' => $qb->createNamedParameter($msg),
		'created_at' => $qb->createNamedParameter($now + $i),
	])->executeStatement();
}

$taskMapper = Server::get(\OC\TaskProcessing\Db\TaskMapper::class);
foreach ([['lc-user1', PublicTask::STATUS_SCHEDULED], ['lc-user2', PublicTask::STATUS_RUNNING]] as [$uid, $status]) {
	$task = new PublicTask('core:text2text', ['input' => "lifecycle prompt for $uid"], 'learning', $uid);
	$task->setStatus($status);
	$task->setScheduledAt($now);
	if ($status === PublicTask::STATUS_RUNNING) {
		$task->setStartedAt($now);
	}
	$taskMapper->insert(\OC\TaskProcessing\Db\Task::fromPublicTask($task));
}

// Read back what was written: a seeder that silently inserts nothing would make every
// later assertion vacuous.
$count = static function (string $table, ?string $where = null) use ($db): int {
	$qb = $db->getQueryBuilder();
	$qb->select($qb->func()->count('*', 'n'))->from($table);
	if ($where === 'chained') {
		$qb->where($qb->expr()->isNotNull('chain_hash'));
	} elseif ($where === 'consent') {
		$qb->where($qb->expr()->eq('ai_consent_version', $qb->createNamedParameter('2.0')));
	}
	$r = $qb->executeQuery();
	$n = (int)$r->fetchOne();
	$r->closeCursor();
	return $n;
};

$got = [
	'consent_users' => $count('learning_user_telos', 'consent'),
	'audit_rows' => $count('learning_audit_events'),
	'chained_rows' => $count('learning_audit_events', 'chained'),
	'chat_memory' => $count('learning_ai_chat_memory'),
	'open_tasks' => count(array_filter(
		array_merge(...array_map(
			static fn (string $uid): array => Server::get(TaskManager::class)->getUserTasksByApp($uid, 'learning'),
			USERS,
		)),
		static fn (PublicTask $t): bool => in_array($t->getStatus(), [PublicTask::STATUS_SCHEDULED, PublicTask::STATUS_RUNNING], true),
	)),
	'gemini_key_plain' => $config->getAppValue('learning', 'gemini_api_key', '') === FAKE_GEMINI_KEY ? 1 : 0,
];
$want = ['consent_users' => 3, 'audit_rows' => 8, 'chained_rows' => 3, 'chat_memory' => 2, 'open_tasks' => 2, 'gemini_key_plain' => 1];

$ok = true;
foreach ($want as $k => $n) {
	echo "SEED $k=" . $got[$k] . ' (want ' . $n . ")\n";
	$ok = $ok && $got[$k] === $n;
}
exit($ok ? 0 : 1);
} catch (\Throwable $e) {
	echo 'SEED error: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
	exit(1);
}
