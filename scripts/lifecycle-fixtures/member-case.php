<?php
/**
 * Seeds and checks wrongly cased course memberships and pool shares for the repair step
 * OCA\Learning\Repair\CanonicalizeMemberUserIds (GitHub #35). Used by scripts/lifecycle-probe.sh;
 * run it only in a throwaway container, as the web server user:
 *
 *   docker exec -u www-data <nc> php /tmp/member-case.php seed
 *   docker exec -u www-data <nc> php /tmp/member-case.php check
 *
 * The accounts lc-user1..3 must exist (occ user:add); lc-gone must not. Rows go in through
 * Nextcloud's query builder only, so the same seeder works on the 5.5.1 schema and on the working
 * state, on PostgreSQL and on MariaDB. The ids of the seeded rows are kept in /tmp/member-case.json
 * inside the container, so `check` asserts each row by id: an updated row must be the same row,
 * not a deleted one plus a new one.
 *
 * Seeded course (owner lc-user3), members:
 *   LC-USER1 student      -> the same row now reads lc-user1, role kept
 *   lc-user2 instructor   -> unchanged
 *   LC-USER2 student      -> deleted (the course already has lc-user2; its role stays instructor)
 *   LC-USER3 student      -> deleted (lc-user3 owns the course)
 *   LC-GONE  student      -> unchanged (no such account)
 * Seeded pool (owner lc-user3), shares:
 *   LC-USER1 user/read    -> the same row now reads lc-user1
 *   LC-USER3 user/read    -> deleted (lc-user3 owns the pool)
 *   LC-USER2 group/read   -> unchanged (a group share names no account)
 *
 * `check` also asserts that the installed app lists the step under <install> and <post-migration>
 * and that the class resolves: on a fresh install there is nothing to repair when <install> runs,
 * so the registration is what can be shown there. Each check prints `CHECK <name>=ok|fail`, the
 * last line is `CHECK result=ok|fail`; exit 0 only if every check passed.
 */
declare(strict_types=1);

require_once '/var/www/html/lib/base.php';

use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;

const IDS_FILE = '/tmp/member-case.json';
const STEP = 'OCA\\Learning\\Repair\\CanonicalizeMemberUserIds';
const COURSE_TITLE = 'Lifecycle member case course';
const POOL_NAME = 'Lifecycle member case pool';
const OWNER = 'lc-user3';

$mode = $argv[1] ?? '';

// Nextcloud's error handler can turn an uncaught exception into exit code 0,
// so everything runs inside this try and a failure exits 1 explicitly.
try {
	$db = Server::get(IDBConnection::class);

	if ($mode === 'seed') {
		$users = Server::get(IUserManager::class);
		foreach (['lc-user1', 'lc-user2', 'lc-user3'] as $uid) {
			if ($users->get($uid) === null) {
				throw new \RuntimeException("account $uid missing");
			}
		}
		if ($users->get('lc-gone') !== null) {
			throw new \RuntimeException('account lc-gone exists');
		}

		$now = time();
		$qb = $db->getQueryBuilder();
		$qb->insert('learning_courses')->values([
			'title' => $qb->createNamedParameter(COURSE_TITLE),
			'instructor_id' => $qb->createNamedParameter(OWNER),
			'status' => $qb->createNamedParameter('active'),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$courseId = $qb->getLastInsertId();

		$qb = $db->getQueryBuilder();
		$qb->insert('learning_pools')->values([
			'user_id' => $qb->createNamedParameter(OWNER),
			'name' => $qb->createNamedParameter(POOL_NAME),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$poolId = $qb->getLastInsertId();

		$ids = ['course' => $courseId, 'pool' => $poolId, 'members' => [], 'shares' => []];
		foreach ([['LC-USER1', 'student'], ['lc-user2', 'instructor'], ['LC-USER2', 'student'], ['LC-USER3', 'student'], ['LC-GONE', 'student']] as [$uid, $role]) {
			$qb = $db->getQueryBuilder();
			$qb->insert('learning_course_members')->values([
				'course_id' => $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($uid),
				'role' => $qb->createNamedParameter($role),
				'enrolled_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])->executeStatement();
			$ids['members'][$uid] = $qb->getLastInsertId();
		}
		foreach ([['LC-USER1', 'user'], ['LC-USER3', 'user'], ['LC-USER2', 'group']] as [$with, $type]) {
			$qb = $db->getQueryBuilder();
			$qb->insert('learning_pool_shares')->values([
				'pool_id' => $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT),
				'shared_with' => $qb->createNamedParameter($with),
				'share_type' => $qb->createNamedParameter($type),
				'permission' => $qb->createNamedParameter('read'),
				'created_by' => $qb->createNamedParameter(OWNER),
			])->executeStatement();
			$ids['shares'][$with . '/' . $type] = $qb->getLastInsertId();
		}

		// Read back what was written: a seeder that silently inserts nothing would make the check vacuous.
		$members = rowsOf($db, 'learning_course_members', 'course_id', $courseId, ['user_id', 'role']);
		$shares = rowsOf($db, 'learning_pool_shares', 'pool_id', $poolId, ['shared_with', 'share_type']);
		if (file_put_contents(IDS_FILE, json_encode($ids)) === false) {
			throw new \RuntimeException('cannot write ' . IDS_FILE);
		}
		echo 'SEED member_rows=' . count($members) . " (want 5)\n";
		echo 'SEED share_rows=' . count($shares) . " (want 3)\n";
		exit(count($members) === 5 && count($shares) === 3 ? 0 : 1);
	}

	if ($mode !== 'check') {
		fwrite(STDERR, "usage: member-case.php seed|check\n");
		exit(2);
	}

	$raw = file_get_contents(IDS_FILE);
	$ids = $raw === false ? null : json_decode($raw, true);
	if (!is_array($ids)) {
		throw new \RuntimeException(IDS_FILE . ' missing: seed did not run in this container');
	}
	$failed = false;
	$check = static function (string $name, bool $ok, string $detail) use (&$failed): void {
		echo "CHECK $name=" . ($ok ? 'ok' : 'fail') . " ($detail)\n";
		$failed = $failed || !$ok;
	};

	$info = Server::get(IAppManager::class)->getAppInfo('learning');
	$install = (array)($info['repair-steps']['install'] ?? []);
	$post = (array)($info['repair-steps']['post-migration'] ?? []);
	$check('step_registered', in_array(STEP, $install, true) && in_array(STEP, $post, true),
		'install: ' . (in_array(STEP, $install, true) ? 'yes' : 'no') . ', post-migration: ' . (in_array(STEP, $post, true) ? 'yes' : 'no'));
	$resolves = false;
	try {
		$resolves = Server::get(STEP) instanceof \OCP\Migration\IRepairStep;
	} catch (\Throwable $e) {
		echo 'step resolve error: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
	}
	$check('step_resolves', $resolves, STEP);

	$members = rowsOf($db, 'learning_course_members', 'course_id', (int)$ids['course'], ['user_id', 'role']);
	$m = $ids['members'];
	$check('member_recased', ($members[$m['LC-USER1']] ?? null) === ['user_id' => 'lc-user1', 'role' => 'student'],
		'row ' . $m['LC-USER1'] . ' = ' . json_encode($members[$m['LC-USER1']] ?? null));
	$check('member_canonical_kept', ($members[$m['lc-user2']] ?? null) === ['user_id' => 'lc-user2', 'role' => 'instructor'],
		'row ' . $m['lc-user2'] . ' = ' . json_encode($members[$m['lc-user2']] ?? null));
	$check('member_duplicate_removed', !isset($members[$m['LC-USER2']]), 'row ' . $m['LC-USER2']);
	$check('member_owner_removed', !isset($members[$m['LC-USER3']]), 'row ' . $m['LC-USER3']);
	$check('member_orphan_kept', ($members[$m['LC-GONE']] ?? null) === ['user_id' => 'LC-GONE', 'role' => 'student'],
		'row ' . $m['LC-GONE'] . ' = ' . json_encode($members[$m['LC-GONE']] ?? null));
	$check('member_count', count($members) === 3, count($members) . ' rows (want 3): ' . json_encode(array_values($members)));

	$shares = rowsOf($db, 'learning_pool_shares', 'pool_id', (int)$ids['pool'], ['shared_with', 'share_type']);
	$s = $ids['shares'];
	$check('share_recased', ($shares[$s['LC-USER1/user']] ?? null) === ['shared_with' => 'lc-user1', 'share_type' => 'user'],
		'row ' . $s['LC-USER1/user'] . ' = ' . json_encode($shares[$s['LC-USER1/user']] ?? null));
	$check('share_owner_removed', !isset($shares[$s['LC-USER3/user']]), 'row ' . $s['LC-USER3/user']);
	$check('share_group_kept', ($shares[$s['LC-USER2/group']] ?? null) === ['shared_with' => 'LC-USER2', 'share_type' => 'group'],
		'row ' . $s['LC-USER2/group'] . ' = ' . json_encode($shares[$s['LC-USER2/group']] ?? null));
	$check('share_count', count($shares) === 2, count($shares) . ' rows (want 2): ' . json_encode(array_values($shares)));

	echo 'CHECK result=' . ($failed ? 'fail' : 'ok') . "\n";
	exit($failed ? 1 : 0);
} catch (\Throwable $e) {
	echo 'MEMBERCASE error: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
	echo "CHECK result=fail\n";
	exit(1);
}

/**
 * @param list<string> $columns
 * @return array<int, array<string, string>> rows by id, values as strings
 */
function rowsOf(IDBConnection $db, string $table, string $scopeColumn, int $scopeId, array $columns): array {
	$qb = $db->getQueryBuilder();
	$qb->select('id', ...$columns)->from($table)
		->where($qb->expr()->eq($scopeColumn, $qb->createNamedParameter($scopeId, IQueryBuilder::PARAM_INT)));
	$result = $qb->executeQuery();
	$rows = [];
	while (($row = $result->fetch()) !== false) {
		$rows[(int)$row['id']] = array_map('strval', array_intersect_key($row, array_flip($columns)));
	}
	$result->closeCursor();
	return $rows;
}
