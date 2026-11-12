<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\StoryProgressMapper;
use OCA\Learning\Service\StoryEngineService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit (high): Story skill checks were an answer oracle for every question in the
 * instance. The draw joined pools only by name keyword (no owner, share, course or review
 * filter), and /answer, /batch and /choice evaluated any question_id the client sent and returned
 * correct_answer_id — also during a graded exam on that pool.
 *
 * Runs against the real database (pgsql and mysql): the pool-access restriction is SQL.
 */
final class StorySkillCheckAccessTest extends TestCase {
	private const PLAYER = 'story-acl-player';
	private const OTHER = 'story-acl-other';
	/** Matches pool_filter 'security' of phishing_friday's first scene, and nothing else in the instance. */
	private const KEYWORD = 'kestrelquill';
	private const CAMPAIGN = 'phishing_friday';
	private const SCENE = 's1_entdeckung';
	private const CHOICE = 'c1_global_alert';

	private IDBConnection $db;
	private StoryEngineService $service;
	/** @var array<string, int> question marker => question id */
	private array $questions = [];
	/** @var array<string, int> pool label => pool id */
	private array $pools = [];

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->service = Server::get(StoryEngineService::class);
		$this->cleanUp();

		// Accessible to the player:
		$this->pools['own'] = $this->pool(self::PLAYER, 'Security Kestrelquill own');
		$this->question('own', 'own-1');
		$this->question('own', 'own-2');
		$this->question('own', 'own-3');
		$this->question('own', 'own-draft', 'draft');
		$this->pools['shared'] = $this->pool(self::OTHER, 'Security Kestrelquill shared');
		$this->question('shared', 'shared-1');
		$this->share($this->pools['shared'], self::PLAYER);
		$this->pools['course'] = $this->pool(self::OTHER, 'Security Kestrelquill course');
		$this->question('course', 'course-1');
		$this->course($this->pools['course'], self::PLAYER);

		// Not accessible, or not published:
		$this->pools['foreign'] = $this->pool(self::OTHER, 'Security Kestrelquill foreign private');
		$this->question('foreign', 'foreign-1');
		$this->pools['other-course'] = $this->pool(self::OTHER, 'Security Kestrelquill other course');
		$this->question('other-course', 'other-course-1');
		$this->course($this->pools['other-course'], 'story-acl-somebody-else');
		$this->pools['own-draft-pool'] = $this->pool(self::PLAYER, 'Security Kestrelquill own draft pool', 'draft');
		$this->question('own-draft-pool', 'own-draft-pool-1');
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testTheFilteredDrawOnlyReturnsPublishedQuestionsFromAccessiblePools(): void {
		$this->assertEqualsCanonicalizing(
			['own-1', 'own-2', 'own-3', 'shared-1', 'course-1'],
			$this->markers($this->fetch(self::KEYWORD))
		);
	}

	public function testTheUnfilteredDrawIsRestrictedTheSameWay(): void {
		// The player can reach no other pool in the instance, so every row must be one of ours.
		$this->assertEqualsCanonicalizing(
			['own-1', 'own-2', 'own-3', 'shared-1', 'course-1'],
			$this->markers($this->fetch(null))
		);
	}

	public function testAUserWithoutAnyPoolGetsNoSkillCheckQuestions(): void {
		$method = new \ReflectionMethod(StoryEngineService::class, 'fetchFilteredQuestions');
		$this->assertSame([], $method->invoke($this->service, 'story-acl-nobody', self::KEYWORD, 50, 0));
	}

	public function testTheSceneOffersOnlyAccessibleQuestionsAndRemembersThem(): void {
		$offered = $this->offeredQuestionIds();

		$this->assertCount(3, $offered);
		$accessible = [$this->questions['own-1'], $this->questions['own-2'], $this->questions['own-3'],
			$this->questions['shared-1'], $this->questions['course-1']];
		$this->assertSame([], array_values(array_diff($offered, $accessible)));

		$progress = $this->service->getScene(self::PLAYER, self::CAMPAIGN)['progress'];
		foreach ($progress['choices'] as $entry) {
			$this->assertArrayNotHasKey('pending_skill', $entry, 'the remembered set never reaches the client');
		}
	}

	public function testBatchRefusesAQuestionTheSceneDidNotOffer(): void {
		$this->offeredQuestionIds();
		$foreign = $this->questions['foreign-1'];

		$this->expectException(\InvalidArgumentException::class);
		$this->service->submitSkillBatch(self::PLAYER, self::CAMPAIGN, self::CHOICE, [
			['question_id' => $foreign, 'answer_id' => $this->wrongAnswer($foreign)],
		]);
	}

	public function testBatchRefusesAnOfferedQuestionAnsweredTwice(): void {
		$offered = $this->offeredQuestionIds();
		$q = $offered[0];
		$correct = $this->correctAnswer($q);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->submitSkillBatch(self::PLAYER, self::CAMPAIGN, self::CHOICE, [
			['question_id' => $q, 'answer_id' => $correct],
			['question_id' => $q, 'answer_id' => $correct],
		]);
	}

	public function testSingleAnswerRefusesAQuestionTheSceneDidNotOffer(): void {
		$this->offeredQuestionIds();
		$foreign = $this->questions['foreign-1'];

		$this->expectException(\InvalidArgumentException::class);
		$this->service->submitSkillAnswer(self::PLAYER, self::CAMPAIGN, self::CHOICE, $foreign, $this->wrongAnswer($foreign));
	}

	public function testInlineChoiceRefusesAQuestionTheSceneDidNotOffer(): void {
		$this->offeredQuestionIds();
		$foreign = $this->questions['foreign-1'];

		$this->expectException(\InvalidArgumentException::class);
		$this->service->makeChoice(self::PLAYER, self::CAMPAIGN, self::CHOICE, $foreign, $this->wrongAnswer($foreign));
	}

	public function testAWrongOfferedAnswerNamesTheCorrectOneOutsideAnExam(): void {
		$offered = $this->offeredQuestionIds();

		$result = $this->service->submitSkillBatch(self::PLAYER, self::CAMPAIGN, self::CHOICE,
			array_map(fn (int $q) => ['question_id' => $q, 'answer_id' => $this->wrongAnswer($q)], $offered));

		$this->assertSame(3, $result['skill_result']['total_count']);
		foreach ($result['skill_result']['question_results'] as $r) {
			$this->assertFalse($r['correct']);
			$this->assertSame($this->correctAnswer($r['question_id']), $r['correct_answer_id']);
		}
	}

	public function testNoCorrectAnswerIsNamedWhileAGradedExamRunsOnThePool(): void {
		$offered = $this->offeredQuestionIds();
		foreach (['own', 'shared', 'course'] as $label) {
			$this->openExam($this->pools[$label]);
		}

		$result = $this->service->submitSkillBatch(self::PLAYER, self::CAMPAIGN, self::CHOICE,
			array_map(fn (int $q) => ['question_id' => $q, 'answer_id' => $this->wrongAnswer($q)], $offered));

		$this->assertCount(3, $result['skill_result']['question_results']);
		foreach ($result['skill_result']['question_results'] as $r) {
			$this->assertFalse($r['correct']);
			$this->assertNull($r['correct_answer_id']);
		}
	}

	public function testAChoiceMissingFromTheRememberedSetStillHasItsSkillCheck(): void {
		// A remembered set that lacks this choice (dynamic choices replaced the static IDs, or the
		// GET endpoint recorded only a sibling choice) must not read as "no questions offered":
		// that skipped the skill check and advanced straight to the success scene.
		$this->service->startCampaign(self::PLAYER, self::CAMPAIGN, 'security');
		$mapper = Server::get(StoryProgressMapper::class);
		$progress = $mapper->findByUserAndCampaign(self::PLAYER, self::CAMPAIGN);
		$this->assertNotNull($progress);
		$progress->setChoicesJson('[]');
		$mapper->update($progress);
		$this->service->getSkillCheckQuestions(self::PLAYER, self::CAMPAIGN, self::SCENE, 'c1_isolate_klaus', 'security');

		$result = $this->service->makeChoice(self::PLAYER, self::CAMPAIGN, self::CHOICE);

		$this->assertSame(self::SCENE, $result['progress']['current_scene_id']);
	}

	// ─── helpers ─────────────────────────────────────────────────────────────

	/** @return list<int> question ids the first scene's CHOICE offers after a fresh start */
	private function offeredQuestionIds(): array {
		$start = $this->service->startCampaign(self::PLAYER, self::CAMPAIGN, 'security');
		$this->assertSame(self::SCENE, $start['scene']['id']);
		foreach ($start['scene']['choices'] as $choice) {
			if ($choice['id'] === self::CHOICE) {
				$this->assertNotNull($choice['skill_check']);
				return array_map(fn (array $q) => (int)$q['id'], $choice['skill_check']['questions']);
			}
		}
		$this->fail('choice ' . self::CHOICE . ' missing');
	}

	private function fetch(?string $filter): array {
		$method = new \ReflectionMethod(StoryEngineService::class, 'fetchFilteredQuestions');
		// A count far above the seeded rows, so the random order cannot hide one.
		return $method->invoke($this->service, self::PLAYER, $filter, 50, 0);
	}

	/** @return list<string> */
	private function markers(array $questions): array {
		$byId = array_flip($this->questions);
		return array_map(fn (array $q) => $byId[(int)$q['id']] ?? ('unknown:' . $q['text']), $questions);
	}

	private function pool(string $owner, string $name, string $reviewStatus = 'published'): int {
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('learning_pools')->values([
			'user_id' => $qb->createNamedParameter($owner),
			'name' => $qb->createNamedParameter($name),
			'review_status' => $qb->createNamedParameter($reviewStatus),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return $qb->getLastInsertId();
	}

	private function question(string $poolLabel, string $marker, string $reviewStatus = 'published'): void {
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('learning_questions')->values([
			'pool_id' => $qb->createNamedParameter($this->pools[$poolLabel], IQueryBuilder::PARAM_INT),
			'user_id' => $qb->createNamedParameter(self::OTHER),
			'text' => $qb->createNamedParameter('Story ACL question ' . $marker),
			'review_status' => $qb->createNamedParameter($reviewStatus),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$questionId = $qb->getLastInsertId();
		$this->questions[$marker] = $questionId;

		foreach (['right' => true, 'wrong A' => false, 'wrong B' => false] as $text => $correct) {
			$a = $this->db->getQueryBuilder();
			$a->insert('learning_answers')->values([
				'question_id' => $a->createNamedParameter($questionId, IQueryBuilder::PARAM_INT),
				'text' => $a->createNamedParameter($marker . ' ' . $text),
				'is_correct' => $a->createNamedParameter($correct, IQueryBuilder::PARAM_BOOL),
			])->executeStatement();
		}
	}

	private function share(int $poolId, string $with): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('learning_pool_shares')->values([
			'pool_id' => $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT),
			'shared_with' => $qb->createNamedParameter($with),
			'share_type' => $qb->createNamedParameter('user'),
			'permission' => $qb->createNamedParameter('read'),
			'created_by' => $qb->createNamedParameter(self::OTHER),
		])->executeStatement();
	}

	private function course(int $poolId, string $member): void {
		$now = time();
		$c = $this->db->getQueryBuilder();
		$c->insert('learning_courses')->values([
			'title' => $c->createNamedParameter('Story ACL course'),
			'instructor_id' => $c->createNamedParameter(self::OTHER),
			'created_at' => $c->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $c->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$courseId = $c->getLastInsertId();

		$cp = $this->db->getQueryBuilder();
		$cp->insert('learning_course_pools')->values([
			'course_id' => $cp->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
			'pool_id' => $cp->createNamedParameter($poolId, IQueryBuilder::PARAM_INT),
		])->executeStatement();

		$cm = $this->db->getQueryBuilder();
		$cm->insert('learning_course_members')->values([
			'course_id' => $cm->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
			'user_id' => $cm->createNamedParameter($member),
			'enrolled_at' => $cm->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	private function openExam(int $poolId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('learning_sessions')->values([
			'pool_id' => $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT),
			'user_id' => $qb->createNamedParameter(self::PLAYER),
			'mode' => $qb->createNamedParameter('exam'),
			'started_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			'total_questions' => $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT),
			'correct_answers' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	private function correctAnswer(int $questionId): int {
		return $this->answer($questionId, true);
	}

	private function wrongAnswer(int $questionId): int {
		return $this->answer($questionId, false);
	}

	private function answer(int $questionId, bool $correct): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('learning_answers')
			->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter($correct, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id', 'ASC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		$this->assertNotFalse($id);
		return (int)$id;
	}

	private function cleanUp(): void {
		$users = [self::PLAYER, self::OTHER];

		foreach (['learning_story_progress' => 'user_id', 'learning_sessions' => 'user_id'] as $table => $col) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in($col, $qb->createNamedParameter($users, IQueryBuilder::PARAM_STR_ARRAY)))
				->executeStatement();
		}

		$ids = $this->db->getQueryBuilder();
		$ids->select('id')->from('learning_pools')
			->where($ids->expr()->in('user_id', $ids->createNamedParameter($users, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $ids->executeQuery();
		$poolIds = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		$courses = $this->db->getQueryBuilder();
		$courses->select('id')->from('learning_courses')
			->where($courses->expr()->eq('instructor_id', $courses->createNamedParameter(self::OTHER)));
		$result = $courses->executeQuery();
		$courseIds = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		if ($courseIds !== []) {
			foreach (['learning_course_pools', 'learning_course_members'] as $table) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->in('course_id', $qb->createNamedParameter($courseIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('learning_courses')
				->where($qb->expr()->in('id', $qb->createNamedParameter($courseIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}

		if ($poolIds === []) {
			return;
		}
		$s = $this->db->getQueryBuilder();
		$s->delete('learning_pool_shares')
			->where($s->expr()->in('pool_id', $s->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();

		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('learning_questions')
			->where($q->expr()->in('pool_id', $q->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $q->executeQuery();
		$questionIds = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		if ($questionIds !== []) {
			$a = $this->db->getQueryBuilder();
			$a->delete('learning_answers')
				->where($a->expr()->in('question_id', $a->createNamedParameter($questionIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
			$dq = $this->db->getQueryBuilder();
			$dq->delete('learning_questions')
				->where($dq->expr()->in('id', $dq->createNamedParameter($questionIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		$p = $this->db->getQueryBuilder();
		$p->delete('learning_pools')
			->where($p->expr()->in('id', $p->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
