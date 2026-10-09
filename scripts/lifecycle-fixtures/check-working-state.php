<?php
/**
 * Proves that the features a fresh install broke in 5.5.2 and earlier work on this instance.
 * Used by scripts/lifecycle-probe.sh after install/upgrade; run it only in a throwaway container,
 * as the web server user:
 *
 *   docker exec -u www-data <nc> php /tmp/check-working-state.php <expected_last_seq>
 *
 * Checks, each printed as `CHECK <name>=ok|fail`:
 *   chain_head     learning_audit_chain_state row id=1 exists
 *   compliance     AuditService::logComplianceEvent() succeeds (it throws on a missing head)
 *   chain_seq      the chain head's last_seq equals <expected_last_seq> afterwards, i.e. the
 *                  head was neither re-seeded over existing events nor forked
 *   translations   a question and an answer translation round-trip through their mappers
 *                  (learning_qst_translations / learning_ans_translations exist)
 *   scenario       a question and a question translation keep their scenario text through the
 *                  mappers (Codeberg #10: both scenario columns exist on this instance)
 * The last line is `CHECK result=ok|fail`; exit 0 only if every check passed.
 */
declare(strict_types=1);

require_once '/var/www/html/lib/base.php';

use OCA\Learning\Db\AnswerTranslation;
use OCA\Learning\Db\AnswerTranslationMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\QuestionTranslation;
use OCA\Learning\Db\QuestionTranslationMapper;
use OCA\Learning\Service\AuditService;
use OCP\IDBConnection;
use OCP\Server;

$expectedSeq = (int)($argv[1] ?? -1);
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

$db = Server::get(IDBConnection::class);
$head = function () use ($db): array|false {
	$qb = $db->getQueryBuilder();
	$r = $qb->select('last_seq', 'last_hash')->from('learning_audit_chain_state')
		->where($qb->expr()->eq('id', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
		->executeQuery();
	$row = $r->fetch();
	$r->closeCursor();
	return $row;
};

$check('chain_head', fn () => $head() !== false);

$check('compliance', function () {
	Server::get(AuditService::class)->logComplianceEvent('course_passed', 'lifecycle-check', ['course_id' => 1]);
	return true;
});

$check('chain_seq', function () use ($head, $expectedSeq) {
	$row = $head();
	$seq = $row === false ? null : (int)$row['last_seq'];
	echo "CHECK chain_seq detail: last_seq=" . var_export($seq, true) . " want $expectedSeq\n";
	return $seq === $expectedSeq;
});

$check('translations', function () {
	$qm = Server::get(QuestionTranslationMapper::class);
	$am = Server::get(AnswerTranslationMapper::class);
	$qid = 900000001;
	$aid = 900000002;

	$q = new QuestionTranslation();
	$q->setQuestionId($qid);
	$q->setLang('en');
	$q->setText('lifecycle question');
	$q->setCreatedAt(time());
	$qm->insert($q);

	$a = new AnswerTranslation();
	$a->setAnswerId($aid);
	$a->setLang('en');
	$a->setText('lifecycle answer');
	$a->setCreatedAt(time());
	$am->insert($a);

	$ok = count($qm->findByQuestionsAndLang([$qid], 'en')) === 1
		&& count($am->findByAnswersAndLang([$aid], 'en')) === 1;
	$qm->deleteByQuestion($qid);
	$am->deleteByAnswer($aid);
	return $ok;
});

$check('scenario', function () {
	$pools = Server::get(PoolMapper::class);
	$questions = Server::get(QuestionMapper::class);
	$translations = Server::get(QuestionTranslationMapper::class);
	$text = "Lifecycle scenario\nsecond line";

	// learning_questions.pool_id is a foreign key: the question needs a real pool.
	$pool = new Pool();
	$pool->setUserId('lifecycle-check');
	$pool->setName('lifecycle scenario pool');
	$pool->setCreatedAt(time());
	$pool->setUpdatedAt(time());
	$pool = $pools->insert($pool);

	$q = new Question();
	$q->setPoolId($pool->getId());
	$q->setUserId('lifecycle-check');
	$q->setText('lifecycle scenario question');
	$q->setQuestionType('single');
	$q->setScenario($text);
	$q->setCreatedAt(time());
	$q->setUpdatedAt(time());
	$q = $questions->insert($q);

	$t = new QuestionTranslation();
	$t->setQuestionId($q->getId());
	$t->setLang('en');
	$t->setText('lifecycle scenario translation');
	$t->setScenario($text);
	$t->setCreatedAt(time());
	$translations->insert($t);

	$stored = $questions->findById($q->getId())->getScenario();
	$found = $translations->findByQuestionsAndLang([$q->getId()], 'en');
	$ok = $stored === $text && count($found) === 1 && $found[0]->getScenario() === $text;
	$translations->deleteByQuestion($q->getId());
	$questions->delete($q);
	$pools->delete($pool);
	return $ok;
});

echo 'CHECK result=' . ($failed ? 'fail' : 'ok') . "\n";
exit($failed ? 1 : 0);
