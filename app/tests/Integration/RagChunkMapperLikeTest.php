<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\RagChunk;
use OCA\Learning\Db\RagChunkMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Keyword search must treat % and _ in a keyword as literal characters, not as LIKE wildcards.
 * Runs against the real database, because the bug lives in the SQL the database evaluates.
 */
final class RagChunkMapperLikeTest extends TestCase {
	private const COURSE_ID = 990561;

	private RagChunkMapper $mapper;

	protected function setUp(): void {
		$this->mapper = Server::get(RagChunkMapper::class);
		$this->deleteCourseRows();
		$this->insertChunk(0, '50% off for early learners');
		$this->insertChunk(1, '500 units in stock');
		$this->insertChunk(2, 'the user_id column');
		$this->insertChunk(3, 'the userXid column');
	}

	protected function tearDown(): void {
		$this->deleteCourseRows();
	}

	public function testPercentInKeywordIsLiteral(): void {
		$this->assertSame(['50% off for early learners'], $this->texts('50%'));
	}

	public function testUnderscoreInKeywordIsLiteral(): void {
		$this->assertSame(['the user_id column'], $this->texts('user_id'));
	}

	public function testPlainKeywordStillMatchesCaseInsensitively(): void {
		$this->assertSame(['500 units in stock'], $this->texts('UNITS'));
	}

	/** @return string[] */
	private function texts(string $keyword): array {
		$texts = array_map(
			static fn (array $row): string => (string)$row['chunk']->getText(),
			$this->mapper->searchByKeywords(self::COURSE_ID, [$keyword], 10)
		);
		sort($texts);
		return $texts;
	}

	private function insertChunk(int $index, string $text): void {
		$chunk = new RagChunk();
		$chunk->setDocumentId(1);
		$chunk->setCourseId(self::COURSE_ID);
		$chunk->setChapter('Integration');
		$chunk->setText($text);
		$chunk->setSourceFile('integration-test.md');
		$chunk->setChunkIndex($index);
		$chunk->setTokenCount(5);
		$chunk->setCreatedAt(time());
		$chunk->setStatus('approved');
		$chunk->setSourceType('document');
		$this->mapper->insert($chunk);
	}

	private function deleteCourseRows(): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('learning_rag_chunks')
			->where($qb->expr()->eq('course_id', $qb->createNamedParameter(self::COURSE_ID)))
			->executeStatement();
	}
}
