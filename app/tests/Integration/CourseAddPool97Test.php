<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Controller\CourseController;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\NotFoundException;
use OCA\Learning\Service\PoolViewOnlyException;
use OCP\AppFramework\Http;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #97 on a real database: adding a pool to a course tells the reasons apart. A "View only"
 * share is told so; a pool the instructor has no share of stays "not found" (FIX-HI-2); a second
 * add of the same pool is a unique violation the controller answers with 409 already_added.
 */
final class CourseAddPool97Test extends TestCase {
	private const TEACHER = 'ap97-teacher';
	private const OWNER = 'ap97-owner';
	private const MARK = 'AddPool97';

	private IDBConnection $db;
	private int $course = 0;
	private int $own = 0;
	private int $readShared = 0;
	private int $foreign = 0;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
		$this->course = $this->insert('learning_courses', [
			'title' => self::MARK . ' Course', 'instructor_id' => self::TEACHER, 'created_at' => time(), 'updated_at' => time(),
		]);
		$this->own = $this->pool(self::TEACHER, 'Own');
		$this->readShared = $this->pool(self::OWNER, 'Read shared');
		$this->foreign = $this->pool(self::OWNER, 'Foreign');
		$this->insert('learning_pool_shares', ['pool_id' => $this->readShared, 'shared_with' => self::TEACHER, 'share_type' => 'user', 'permission' => 'read']);
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testAReadShareIsToldItIsViewOnly(): void {
		$this->expectException(PoolViewOnlyException::class);
		Server::get(CourseService::class)->addPool($this->course, $this->readShared, 0, true, self::TEACHER);
	}

	public function testAForeignPoolStaysNotFound(): void {
		try {
			Server::get(CourseService::class)->addPool($this->course, $this->foreign, 0, true, self::TEACHER);
			$this->fail('expected NotFoundException');
		} catch (NotFoundException $e) {
			$this->assertNotInstanceOf(PoolViewOnlyException::class, $e);
		}
	}

	public function testTheControllerAnswersEachCase(): void {
		$controller = $this->controller();
		$this->assertSame(Http::STATUS_CREATED, $controller->addPool($this->course, $this->own)->getStatus());

		$again = $controller->addPool($this->course, $this->own);
		$this->assertSame(Http::STATUS_CONFLICT, $again->getStatus());
		$this->assertSame('already_added', $again->getData()['code'] ?? null);

		$viewOnly = $controller->addPool($this->course, $this->readShared);
		$this->assertSame(Http::STATUS_FORBIDDEN, $viewOnly->getStatus());
		$this->assertSame('pool_view_only', $viewOnly->getData()['code'] ?? null);

		$foreign = $controller->addPool($this->course, $this->foreign);
		$this->assertSame(Http::STATUS_NOT_FOUND, $foreign->getStatus());
		$this->assertSame('pool_not_found', $foreign->getData()['code'] ?? null);
	}

	private function controller(): CourseController {
		$controller = Server::get(\OCA\Learning\AppInfo\Application::class)->getContainer()->get(CourseController::class);
		// no session in the test run: the controller acts as the teacher
		(new \ReflectionProperty($controller, 'userId'))->setValue($controller, self::TEACHER);
		return $controller;
	}

	private function pool(string $owner, string $name): int {
		return $this->insert('learning_pools', [
			'user_id' => $owner, 'name' => self::MARK . ' ' . $name, 'review_status' => 'published',
			'created_at' => time(), 'updated_at' => time(),
		]);
	}

	/** @param array<string, int|string|bool> $values */
	private function insert(string $table, array $values): int {
		$qb = $this->db->getQueryBuilder();
		$params = [];
		foreach ($values as $column => $value) {
			$type = is_bool($value) ? IQueryBuilder::PARAM_BOOL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
			$params[$column] = $qb->createNamedParameter($value, $type);
		}
		$qb->insert($table)->values($params)->executeStatement();
		return $qb->getLastInsertId();
	}

	private function cleanUp(): void {
		$courses = $this->db->getQueryBuilder();
		$courses->select('id')->from('learning_courses')
			->where($courses->expr()->like('title', $courses->createNamedParameter(self::MARK . ' %')));
		$courseIds = array_map('intval', $courses->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($courseIds !== []) {
			foreach (['learning_feed_items' => 'course_id', 'learning_course_pools' => 'course_id', 'learning_courses' => 'id'] as $table => $column) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->in($column, $qb->createNamedParameter($courseIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
		}
		$pools = $this->db->getQueryBuilder();
		$pools->select('id')->from('learning_pools')
			->where($pools->expr()->like('name', $pools->createNamedParameter(self::MARK . ' %')));
		$poolIds = array_map('intval', $pools->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($poolIds !== []) {
			foreach (['learning_pool_shares' => 'pool_id', 'learning_pools' => 'id'] as $table => $column) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->in($column, $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
		}
	}
}
