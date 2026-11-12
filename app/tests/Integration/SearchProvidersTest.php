<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Search\PoolSearchProvider;
use OCA\Learning\Search\QuestionSearchProvider;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Unified search for pools and questions on a real database. Both providers run
 * SELECT DISTINCT ... ORDER BY updated_at; PostgreSQL (and MySQL 8 with ONLY_FULL_GROUP_BY)
 * rejects that unless updated_at is in the select list. The providers swallow every
 * exception into an empty result, so the only visible symptom is "no hits" — which is what
 * these tests check against.
 */
final class SearchProvidersTest extends TestCase {
	private const USER = 'search-providers-it';
	private const MARKER = 'Zebrafinch7781';

	private IDBConnection $db;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();

		$now = time();
		$pool = new Pool();
		$pool->setUserId(self::USER);
		$pool->setName('Pool ' . self::MARKER);
		$pool->setDescription('Integration pool');
		$pool->setCreatedAt($now);
		$pool->setUpdatedAt($now);
		$pool = Server::get(PoolMapper::class)->insert($pool);

		$question = new Question();
		$question->setPoolId((int)$pool->getId());
		$question->setUserId(self::USER);
		$question->setText('Which bird is a ' . self::MARKER . '?');
		$question->setExplanation('Integration question');
		$question->setCreatedAt($now);
		$question->setUpdatedAt($now);
		Server::get(QuestionMapper::class)->insert($question);
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testPoolSearchFindsOwnPool(): void {
		$provider = new PoolSearchProvider(
			Server::get(IFactory::class)->get('learning', 'en'),
			Server::get(IURLGenerator::class),
			$this->db
		);

		$this->assertSame(['Pool ' . self::MARKER], $this->titles($provider->search($this->user(), $this->query())));
	}

	public function testQuestionSearchFindsOwnQuestion(): void {
		$provider = new QuestionSearchProvider(
			Server::get(IFactory::class)->get('learning', 'en'),
			Server::get(IURLGenerator::class),
			$this->db
		);

		$titles = $this->titles($provider->search($this->user(), $this->query()));
		$this->assertCount(1, $titles);
		$this->assertStringContainsString(self::MARKER, $titles[0]);
	}

	/** @return string[] */
	private function titles(SearchResult $result): array {
		$data = json_decode((string)json_encode($result), true);
		return array_map(static fn (array $entry): string => (string)$entry['title'], $data['entries'] ?? []);
	}

	private function user(): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn(self::USER);
		return $user;
	}

	private function query(): ISearchQuery {
		$query = $this->createStub(ISearchQuery::class);
		$query->method('getTerm')->willReturn(strtolower(self::MARKER));
		$query->method('getCursor')->willReturn(null);
		$query->method('getLimit')->willReturn(10);
		return $query;
	}

	private function cleanUp(): void {
		foreach (['learning_questions', 'learning_pools'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)))
				->executeStatement();
		}
	}
}
