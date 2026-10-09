<?php
/**
 * Upgrade contract of the consent-bound LLM gateway (plan Task C2, Review Focus 2).
 * Used by scripts/lifecycle-probe.sh; run it only in a throwaway container, as the web server user:
 *
 *   docker exec -u www-data <nc> php /tmp/check-llm-upgrade.php upgraded|fresh|snapshot
 *
 * upgraded — after `occ upgrade` from the seeded 5.5.1 (scripts/lifecycle-fixtures/seed-5.5.1.sql.php,
 *            which must still be at /tmp/seed.php: the seeded key is read from there, never printed):
 *   connection_derived   no ai_connection is stored and the 5.5 keys still yield enabled Gemini
 *   gateway_admin_test   LlmGateway::begin(null, admin_test) succeeds (nothing is sent)
 *   key_plaintext_gone   gemini_api_key no longer exists in appconfig
 *   key_encrypted        the fingerprint-bound key slot exists, its stored value is not the
 *                        plaintext, and ApiKeyStore returns the seeded key for the connection
 *   consent_version      lc-user1..3 (consent '2.0') get reason=version for the connection in force
 *   legacy_tasks         the seeded scheduled task (lc-user1) is gone in every status — a cancelled
 *                        row still holds the prompt — the running one (lc-user2) still exists and is
 *                        listed in ai_legacy_tasks_pending, the one-time marker is set
 *   cleanup_job          LegacyAiTaskCleanupJob is registered for the running task
 *   chat_memory          learning_ai_chat_memory is empty
 *   audit_redacted       the 4 seeded allowlisted rows outside the chain hold exactly
 *                        {"input":"[redacted]","output":"[redacted]","model":"gemini"}, the seeded
 *                        ai_explain row (not allowlisted) still holds its content, marker set
 * fresh — on a fresh install of the working state:
 *   connection_disabled  no ai_connection is stored and the connection in force is `disabled`
 *   gateway_disabled     LlmGateway::begin(null, admin_test) refuses with category `disabled`
 * sweep — state-changing, run it after the snapshot comparisons: the running legacy task finishes
 *   (status SUCCESSFUL, as the worker would set it) and LegacyAiTaskCleanup::sweepPending() runs:
 *   legacy_sweep         no task of the app is left, ai_legacy_tasks_pending is gone
 * Each check prints `CHECK <name>=ok|fail`; the last line is `CHECK result=ok|fail`, exit 0 only
 * if every check passed.
 *
 * snapshot — prints `STATE <part>=<sha256>` for everything the AI migration may touch (the app's
 *   appconfig rows as stored, audit events and chain head, chat memory, the app's TaskProcessing
 *   rows, the consent columns). Digests only, so no key material reaches the output. The probe
 *   compares the snapshots before and after a repair run: a repeated run must change nothing.
 */
declare(strict_types=1);

require_once '/var/www/html/lib/base.php';

use OCA\Learning\BackgroundJob\LegacyAiTaskCleanupJob;
use OCA\Learning\Repair\LegacyAiTaskCleanup;
use OCA\Learning\Repair\RedactLegacyAiAudit;
use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;
use OCP\TaskProcessing\IManager as TaskManager;
use OCP\TaskProcessing\Task;

const APP = 'learning';
const USERS = ['lc-user1', 'lc-user2', 'lc-user3'];

$mode = $argv[1] ?? '';
$db = Server::get(IDBConnection::class);

/** @return list<array<string, mixed>> */
$rows = static function (string $table, string $orderBy, ?array $where = null) use ($db): array {
	$qb = $db->getQueryBuilder();
	$qb->select('*')->from($table)->orderBy($orderBy);
	if ($where !== null) {
		$qb->where($qb->expr()->eq($where[0], $qb->createNamedParameter($where[1])));
	}
	$r = $qb->executeQuery();
	$all = $r->fetchAll();
	$r->closeCursor();
	// Drivers differ in key case and in returning numbers as strings; normalise both.
	return array_map(static function (array $row): array {
		$row = array_change_key_case($row, CASE_LOWER);
		ksort($row);
		return array_map(static fn ($v) => $v === null ? null : (string)$v, $row);
	}, $all);
};

if ($mode === 'snapshot') {
	try {
		$parts = [
			'appconfig' => $rows('appconfig', 'configkey', ['appid', APP]),
			'audit_events' => $rows('learning_audit_events', 'id'),
			'audit_chain' => $rows('learning_audit_chain_state', 'id'),
			'chat_memory' => $rows('learning_ai_chat_memory', 'id'),
			'tasks' => $rows('taskprocessing_tasks', 'id', ['app_id', APP]),
			'consent' => array_map(static fn (array $r): array => [
				$r['user_id'] ?? null, $r['ai_consent_version'] ?? null, $r['ai_consent_fingerprint'] ?? null,
				$r['ai_consent_source'] ?? null, $r['consent_epoch'] ?? null,
			], $rows('learning_user_telos', 'id')),
		];
		foreach ($parts as $name => $data) {
			echo "STATE $name=" . hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)) . ' (' . count($data) . " rows)\n";
		}
		exit(0);
	} catch (\Throwable $e) {
		echo 'STATE error: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
		exit(1);
	}
}

if (!in_array($mode, ['upgraded', 'fresh', 'sweep'], true)) {
	echo "usage: check-llm-upgrade.php upgraded|fresh|sweep|snapshot\n";
	exit(2);
}

$failed = false;
// Nextcloud's error handler can turn an uncaught exception into exit code 0,
// so every check catches its own failure and the verdict is explicit.
$check = function (string $name, callable $fn) use (&$failed): void {
	try {
		$ok = (bool)$fn();
	} catch (\Throwable $e) {
		echo "CHECK $name detail: " . get_class($e) . ': ' . $e->getMessage() . "\n";
		$ok = false;
	}
	echo "CHECK $name=" . ($ok ? 'ok' : 'fail') . "\n";
	if (!$ok) {
		$failed = true;
	}
};

$appConfig = Server::get(IAppConfig::class);
$appConfig->clearCache();
$connections = Server::get(ConnectionStore::class);
$gateway = Server::get(LlmGateway::class);

/** @return list<Task> every task of the app for $uid, in any status */
$appTasks = static fn (string $uid): array => Server::get(TaskManager::class)->getUserTasksByApp($uid, APP);

if ($mode === 'sweep') {
	$check('legacy_sweep', function () use ($appTasks, $appConfig, $db) {
		$running = array_values(array_filter($appTasks('lc-user2'), static fn (Task $t): bool => $t->getStatus() === Task::STATUS_RUNNING));
		if (count($running) !== 1) {
			echo 'CHECK legacy_sweep detail: expected the one running seeded task, found ' . count($running) . "\n";
			return false;
		}
		$mapper = Server::get(\OC\TaskProcessing\Db\TaskMapper::class);
		$entity = $mapper->find((int)$running[0]->getId());
		$entity->setStatus(Task::STATUS_SUCCESSFUL);
		$mapper->update($entity);
		$left = Server::get(LegacyAiTaskCleanup::class)->sweepPending();
		$appConfig->clearCache();
		$qb = $db->getQueryBuilder();
		$r = $qb->select($qb->func()->count('*', 'n'))->from('taskprocessing_tasks')
			->where($qb->expr()->eq('app_id', $qb->createNamedParameter(APP)))->executeQuery();
		$rows = (int)$r->fetchOne();
		$r->closeCursor();
		$listed = $appConfig->hasKey(APP, LegacyAiTaskCleanup::PENDING_TASKS);
		echo "CHECK legacy_sweep detail: still_running=$left task_rows=$rows pending_key=" . var_export($listed, true) . "\n";
		return $left === 0 && $rows === 0 && !$listed;
	});
} elseif ($mode === 'fresh') {
	$check('connection_disabled', function () use ($connections) {
		$c = $connections->load();
		echo 'CHECK connection_disabled detail: snapshot=' . var_export($connections->hasSnapshot(), true)
			. " provider={$c->provider}\n";
		return !$connections->hasSnapshot() && $c->provider === 'disabled';
	});
	$check('gateway_disabled', function () use ($gateway) {
		try {
			$gateway->begin(null, LlmPurpose::AdminTest);
		} catch (LlmProviderException $e) {
			echo "CHECK gateway_disabled detail: category={$e->category}\n";
			return $e->category === 'disabled';
		}
		echo "CHECK gateway_disabled detail: begin() did not refuse\n";
		return false;
	});
} else {
	$check('connection_derived', function () use ($connections) {
		$c = $connections->load();
		echo 'CHECK connection_derived detail: snapshot=' . var_export($connections->hasSnapshot(), true)
			. " provider={$c->provider} enabled=" . var_export($c->enabled, true) . "\n";
		return !$connections->hasSnapshot() && $c->provider === 'gemini' && $c->enabled
			&& $c->connectionFingerprint() === ConnectionStore::legacyGemini(true)->connectionFingerprint();
	});

	$check('gateway_admin_test', function () use ($gateway) {
		$session = $gateway->begin(null, LlmPurpose::AdminTest);
		return $session->connection->provider === 'gemini' && $session->subjectUserId === null;
	});

	$check('key_plaintext_gone', function () use ($appConfig) {
		return !$appConfig->hasKey(APP, ApiKeyStore::LEGACY_GEMINI_KEY);
	});

	$check('key_encrypted', function () use ($appConfig, $connections, $db) {
		// The seeder's fake key, read from the seeder itself so it is written down only once.
		$seeder = (string)@file_get_contents('/tmp/seed.php');
		if (preg_match("/const FAKE_GEMINI_KEY = '([^']+)';/", $seeder, $m) !== 1) {
			echo "CHECK key_encrypted detail: seeded key not found in /tmp/seed.php\n";
			return false;
		}
		$seeded = $m[1];
		$c = $connections->load();
		$name = ApiKeyStore::keyName($c);
		$qb = $db->getQueryBuilder();
		$r = $qb->select('configvalue')->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(APP)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($name)))
			->executeQuery();
		$stored = $r->fetchOne();
		$r->closeCursor();
		$slot = is_string($stored) && $stored !== '';
		$notPlain = $slot && !str_contains($stored, $seeded);
		$readable = Server::get(ApiKeyStore::class)->get($c) === $seeded;
		echo 'CHECK key_encrypted detail: slot=' . var_export($slot, true) . ' not_plaintext=' . var_export($notPlain, true)
			. ' readable=' . var_export($readable, true) . "\n";
		return $appConfig->hasKey(APP, $name) && $slot && $notPlain && $readable;
	});

	$check('consent_version', function () use ($connections) {
		$consent = Server::get(ConsentService::class);
		$c = $connections->load();
		$ok = true;
		foreach (USERS as $uid) {
			$state = $consent->state($uid, $c);
			echo "CHECK consent_version detail: $uid valid=" . var_export($state['valid'], true) . " reason={$state['reason']}\n";
			$ok = $ok && $state['valid'] === false && $state['reason'] === 'version';
		}
		return $ok;
	});

	$check('legacy_tasks', function () use ($appConfig, $appTasks) {
		// lc-user1 had only the scheduled task, lc-user3 none: nothing of the app may be left for
		// them in any status — a CANCELLED row still carries the prompt.
		$scheduledUser = count($appTasks('lc-user1')) + count($appTasks('lc-user3'));
		$running = array_map(static fn (Task $t): int => (int)$t->getId(), array_values(array_filter(
			$appTasks('lc-user2'), static fn (Task $t): bool => $t->getStatus() === Task::STATUS_RUNNING)));
		$otherOfUser2 = count($appTasks('lc-user2')) - count($running);
		$pending = json_decode($appConfig->getValueString(APP, 'ai_legacy_tasks_pending', '[]'), true);
		$pending = is_array($pending) ? $pending : [];
		$marker = $appConfig->getValueString(APP, 'llm_migration_tasks_done', '');
		$listed = $running !== [] && array_diff($running, $pending) === [];
		echo "CHECK legacy_tasks detail: left_of_scheduled_user=$scheduledUser running=" . count($running)
			. " other_of_running_user=$otherOfUser2 listed=" . var_export($listed, true) . " marker=$marker\n";
		// The seed has exactly one running task; it must still exist (a running task is not
		// cancelled) and be listed for the cleanup job.
		return $scheduledUser === 0 && count($running) === 1 && $otherOfUser2 === 0 && $listed && $marker === 'yes';
	});

	$check('cleanup_job', function () {
		$registered = Server::get(IJobList::class)->has(LegacyAiTaskCleanupJob::class, null);
		echo 'CHECK cleanup_job detail: registered=' . var_export($registered, true) . "\n";
		return $registered;
	});

	$check('audit_redacted', function () use ($db, $appConfig) {
		$qb = $db->getQueryBuilder();
		$r = $qb->select('event_key', 'context_json')->from('learning_audit_events')
			->where($qb->expr()->isNull('chain_hash'))
			->andWhere($qb->expr()->isNull('prev_hash'))
			->orderBy('id')
			->executeQuery();
		$allowlisted = 0;
		$redacted = 0;
		$foreignKept = false;
		$want = ['input' => '[redacted]', 'output' => '[redacted]', 'model' => 'gemini'];
		while (($row = $r->fetch()) !== false) {
			$row = array_change_key_case($row, CASE_LOWER);
			$context = json_decode((string)$row['context_json'], true);
			if (in_array($row['event_key'], RedactLegacyAiAudit::EVENT_KEYS, true)) {
				$allowlisted++;
				if ($context === $want) {
					$redacted++;
				}
			} elseif ($row['event_key'] === 'ai_explain') {
				$foreignKept = is_array($context) && ($context['input'] ?? null) === 'lifecycle input 4';
			}
		}
		$r->closeCursor();
		$marker = $appConfig->getValueString(APP, RedactLegacyAiAudit::MARKER, '');
		echo "CHECK audit_redacted detail: allowlisted=$allowlisted redacted=$redacted foreign_kept="
			. var_export($foreignKept, true) . " marker=$marker\n";
		return $allowlisted === 4 && $redacted === 4 && $foreignKept && $marker === 'yes';
	});

	$check('chat_memory', function () use ($db) {
		$qb = $db->getQueryBuilder();
		$r = $qb->select($qb->func()->count('*', 'n'))->from('learning_ai_chat_memory')->executeQuery();
		$n = (int)$r->fetchOne();
		$r->closeCursor();
		echo "CHECK chat_memory detail: rows=$n\n";
		return $n === 0;
	});
}

echo 'CHECK result=' . ($failed ? 'fail' : 'ok') . "\n";
exit($failed ? 1 : 0);
