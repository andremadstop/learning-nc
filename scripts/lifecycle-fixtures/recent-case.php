<?php
/**
 * Course data from a recent release (5.13.x / 5.14.x) survives the upgrade to the working state:
 * course videos, document rows, video/document progress and read confirmations (GitHub #75).
 * Used by scripts/lifecycle-probe.sh (scenario `recent-<version>`); run it only in a throwaway
 * container, as the web server user:
 *
 *   docker exec -u www-data <nc> php /tmp/recent-case.php seed     (on the old release)
 *   docker exec -u www-data <nc> php /tmp/recent-case.php check    (after `occ upgrade` + repair)
 *
 * The seed writes through the query builder against the OLD schema (no app classes, so it works
 * on any old release). It picks its mode from that schema:
 *   legacy   no learning_doc_confirms yet (5.13.x): confirmations exist only as completed progress
 *            rows of document rows, the upgrade (Version011100::migrateDocumentRows) must turn them
 *            into confirmations, merge the duplicate document row and set document_id.
 *   current  learning_doc_confirms exists (5.14.x): rows are seeded in their final shape and must
 *            come through the upgrade unchanged.
 * Seed (lc-user3 is the course owner; users lc-user1..3 must exist):
 *   course C, documents D1, D2; course_videos
 *     R1 document ref=D1   R2 document ref=D1 (legacy only: duplicate)   R3 document ref=D2
 *     R4 YouTube course video   R5 document with a legacy path ref (legacy only)
 *   legacy  progress: u1 R1 done t1 | u1 R2 done t1 | u2 R2 done t2 | u2 R3 done t3 | u3 R3 open
 *                     u1 R4 done t4 | u2 R5 done t5 | u3 R4 open (40 %)
 *   current confirms: u1 R1 t1 | u2 R1 t2 | u2 R3 t3 (document_id set, doc_version NULL)
 *           progress: u1 R4 done t4 | u3 R4 open (40 %)
 * The check reads through the app's own mappers and RequiredDocumentService — the SELECT * path
 * that broke in 5.11.0 on a legacy column — and only uses raw SQL to compare exact row sets.
 * Prints `CHECK <name>=ok|fail` per check and `CHECK result=ok|fail`; exit 0 only if all passed.
 */
declare(strict_types=1);

require_once '/var/www/html/lib/base.php';

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;

const IDS = '/tmp/recent-case.json';
$mode = $argv[1] ?? '';
$fail = 0;
function ck(string $n, bool $ok, string $info = ''): void {
	global $fail;
	echo "CHECK $n=" . ($ok ? 'ok' : 'fail') . ($info !== '' ? " ($info)" : '') . "\n";
	if (!$ok) {
		$fail = 1;
	}
}

// Nextcloud's error handler can end a script with exit 0; everything runs in this try.
try {
	$db = Server::get(IDBConnection::class);
	$ins = function (string $table, array $vals) use ($db): int {
		$qb = $db->getQueryBuilder();
		$v = [];
		foreach ($vals as $k => $x) {
			$v[$k] = $x === null
				? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
				: $qb->createNamedParameter($x, is_int($x) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
		}
		$qb->insert($table)->values($v)->executeStatement();
		return (int)$qb->getLastInsertId();
	};
	$all = function (string $sql) use ($db): array {
		$r = $db->executeQuery($sql);
		$x = $r->fetchAll();
		$r->closeCursor();
		return $x;
	};

	if ($mode === 'seed') {
		$kind = $db->tableExists('learning_doc_confirms') ? 'current' : 'legacy';
		$now = time();
		$c = $ins('learning_courses', ['title' => 'Recent case course', 'instructor_id' => 'lc-user3', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
		$doc = fn (string $name) => $ins('learning_course_documents', ['course_id' => $c, 'file_path' => "/Docs/$name", 'file_name' => $name, 'file_type' => 'pdf', 'status' => 'ready', 'created_at' => $now, 'updated_at' => $now]);
		$d1 = $doc('policy.pdf');
		$d2 = $doc('safety.pdf');
		$row = function (string $type, string $ref, string $title, int $sort, ?int $documentId = null) use ($ins, $c, $now, $kind): int {
			$vals = ['course_id' => $c, 'source_type' => $type, 'video_ref' => $ref, 'title' => $title, 'sort_order' => $sort, 'created_at' => $now];
			if ($kind === 'current') {
				$vals['document_id'] = $documentId;
			}
			return $ins('learning_course_videos', $vals);
		};
		$t1 = 1760000001; $t2 = 1760000002; $t3 = 1760000003; $t4 = 1760000004; $t5 = 1760000005;
		$p = fn (string $u, int $cid, ?int $at, ?float $pct = null) => $ins('learning_video_progress', $at !== null
			? ['user_id' => $u, 'content_id' => $cid, 'completed_at' => $at]
			: ['user_id' => $u, 'content_id' => $cid, 'last_ping_ts' => $now, 'covered_pct' => (string)$pct]);
		$r1 = $row('document', (string)$d1, 'Policy', 1, $d1);
		$r2 = $kind === 'legacy' ? $row('document', (string)$d1, 'Policy again', 2) : 0;
		$r3 = $row('document', (string)$d2, 'Safety', 3, $d2);
		$r4 = $row('youtube-nocookie', 'dQw4w9WgXcQ', 'Course video', 4);
		$r5 = $kind === 'legacy' ? $row('document', 'Docs/legacy.pdf', 'Legacy', 5) : 0;
		if ($kind === 'legacy') {
			$p('lc-user1', $r1, $t1); $p('lc-user1', $r2, $t1); $p('lc-user2', $r2, $t2); $p('lc-user2', $r3, $t3);
			$p('lc-user3', $r3, null, 10.0); $p('lc-user2', $r5, $t5);
		} else {
			$cf = fn (string $u, int $cid, int $at, int $docId) => $ins('learning_doc_confirms', ['user_id' => $u, 'content_id' => $cid, 'course_id' => $c, 'confirmed_at' => $at, 'doc_version' => null, 'document_id' => $docId]);
			$cf('lc-user1', $r1, $t1, $d1); $cf('lc-user2', $r1, $t2, $d1); $cf('lc-user2', $r3, $t3, $d2);
		}
		$p('lc-user1', $r4, $t4);
		$p('lc-user3', $r4, null, 40.0);
		file_put_contents(IDS, json_encode(compact('kind', 'c', 'd1', 'd2', 'r1', 'r2', 'r3', 'r4', 'r5', 't1', 't2', 't3', 't4', 't5')));
		echo "SEED kind=$kind course=$c\n";
		exit(0);
	}
	if ($mode !== 'check') {
		throw new \RuntimeException('usage: recent-case.php seed|check');
	}

	$ids = json_decode((string)file_get_contents(IDS), true, 4, JSON_THROW_ON_ERROR);
	['kind' => $kind, 'c' => $c, 'd1' => $d1, 'd2' => $d2, 'r1' => $r1, 'r2' => $r2, 'r3' => $r3, 'r4' => $r4, 'r5' => $r5,
		't1' => $t1, 't2' => $t2, 't3' => $t3, 't4' => $t4, 't5' => $t5] = $ids;
	echo "CHECK kind=$kind\n";

	// Exact rows (raw SQL only for the comparison).
	$conf = $all("SELECT user_id, content_id, course_id, confirmed_at, doc_version, document_id FROM *PREFIX*learning_doc_confirms WHERE course_id = $c ORDER BY user_id, content_id, confirmed_at");
	$got = array_map(fn ($r) => "{$r['user_id']}:{$r['content_id']}:{$r['confirmed_at']}:" . ($r['doc_version'] ?? 'NULL') . ':' . ($r['document_id'] ?? 'NULL'), $conf);
	$want = ["lc-user1:$r1:$t1:NULL:$d1", "lc-user2:$r1:$t2:NULL:$d1", "lc-user2:$r3:$t3:NULL:$d2"];
	if ($kind === 'legacy') {
		$want[] = "lc-user2:$r5:$t5:NULL:NULL";
	}
	ck('confirmations', $got === $want, count($got) . ' rows: ' . implode(' ', $got));

	// The app's own reads: every mapper below selects '*' and hydrates entities.
	$course = Server::get(\OCA\Learning\Db\CourseMapper::class)->findById($c);
	ck('course_read', (int)$course->getId() === $c && $course->getTitle() === 'Recent case course');
	$videos = Server::get(\OCA\Learning\Db\CourseVideoMapper::class)->findByCourse($c);
	$byId = [];
	foreach ($videos as $v) {
		$byId[(int)$v->getId()] = $v;
	}
	$wantRows = $kind === 'legacy' ? [$r1, $r3, $r4, $r5] : [$r1, $r3, $r4];
	$rowIds = array_keys($byId);
	sort($rowIds);
	ck('course_video_rows', $rowIds === $wantRows, 'rows ' . implode(',', $rowIds) . ($kind === 'legacy' ? ", duplicate $r2 merged" : ''));
	$docIds = array_map(fn ($id) => isset($byId[$id]) ? ($byId[$id]->getDocumentId() === null ? null : (int)$byId[$id]->getDocumentId()) : -1, $wantRows);
	$wantDocIds = $kind === 'legacy' ? [$d1, $d2, null, null] : [$d1, $d2, null];
	ck('document_ids', $docIds === $wantDocIds, json_encode($docIds));
	ck('course_video_kept', isset($byId[$r4]) && $byId[$r4]->getSourceType() === 'youtube-nocookie' && $byId[$r4]->getVideoRef() === 'dQw4w9WgXcQ');
	$docs = Server::get(\OCA\Learning\Db\CourseDocumentMapper::class)->findByCourseId($c);
	$names = array_map(fn ($d) => $d->getFileName(), $docs);
	sort($names);
	ck('documents_read', $names === ['policy.pdf', 'safety.pdf'], implode(',', $names));

	$progress = Server::get(\OCA\Learning\Db\VideoProgressMapper::class);
	$done = $progress->findByUserAndContent('lc-user1', $r4);
	$open = $progress->findByUserAndContent('lc-user3', $r4);
	ck('video_progress', $done !== null && (int)$done->getCompletedAt() === $t4 && $open !== null && $open->getCompletedAt() === null
		&& abs((float)$open->getCoveredPct() - 40.0) < 0.01);
	if ($kind === 'legacy') {
		$gone = $all("SELECT count(*) AS n FROM *PREFIX*learning_video_progress WHERE content_id = $r2");
		$openDoc = $progress->findByUserAndContent('lc-user3', $r3);
		ck('document_progress', (int)$gone[0]['n'] === 0 && $openDoc !== null && $openDoc->getCompletedAt() === null,
			'duplicate progress ' . $gone[0]['n'] . ', open document progress ' . ($openDoc === null ? 'missing' : 'kept'));
	}

	$svc = Server::get(\OCA\Learning\Service\RequiredDocumentService::class);
	$is = fn (int $rid, string $u) => $svc->isConfirmed($course, $byId[$rid], $u);
	$st = ['u1R1' => $is($r1, 'lc-user1'), 'u2R1' => $is($r1, 'lc-user2'), 'u2R3' => $is($r3, 'lc-user2'), 'u1R3' => $is($r3, 'lc-user1'), 'u3R3' => $is($r3, 'lc-user3')];
	ck('is_confirmed', $st === ['u1R1' => true, 'u2R1' => true, 'u2R3' => true, 'u1R3' => false, 'u3R3' => false], json_encode($st));

	$again = \OCA\Learning\Migration\Version011100Date20261116150000::migrateDocumentRows($db);
	$n = $all("SELECT count(*) AS n FROM *PREFIX*learning_doc_confirms WHERE course_id = $c");
	ck('rerun_noop', $again === ['confirmations' => 0, 'duplicates' => 0] && (int)$n[0]['n'] === count($want), json_encode($again) . ' n=' . $n[0]['n']);

	echo 'CHECK result=' . ($fail ? 'fail' : 'ok') . "\n";
	exit($fail);
} catch (\Throwable $e) {
	echo 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n";
	exit(1);
}
