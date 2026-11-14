<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\BackgroundJob;

use OCA\Learning\BackgroundJob\ChunkingJob;
use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Service\ChunkingService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit #62: ChunkingJob set chunking_status='chunking' before the work, and a run that died
 * without reaching its catch (time limit, OOM, killed cron) left the document there for good: the
 * job only picked up NULL/'pending', so the document never got RAG chunks and was never retried.
 */
#[AllowMockObjectsWithoutExpectations]
class ChunkingJobStaleTest extends TestCase {
    private const NOW = 1_760_000_000;

    public function testTheMapperAlsoSelectsAChunkingClaimOlderThanTheCutoff(): void {
        $qb = new FakeQueryBuilder();
        $mapper = new class(new FakeDbConnection([$qb])) extends CourseDocumentMapper {
            public mixed $query = null;
            protected function findEntities($query): array {
                $this->query = $query;
                return [];
            }
        };

        $mapper->findExtractedUnchunked(10, self::NOW - 3600);

        $this->assertSame($qb, $mapper->query);
        $where = (string)json_encode(array_merge($qb->whereCalls, $qb->andWhereCalls));
        $this->assertStringContainsString('"value":"chunking"', $where, 'stale claims are picked up again');
        $this->assertStringContainsString('{"type":"lt","left":"updated_at","right":{"value":' . (self::NOW - 3600), $where,
            'only a claim older than the cutoff, never one a running job holds');
    }

    public function testWithoutACutoffAChunkingClaimIsLeftAlone(): void {
        $qb = new FakeQueryBuilder();
        $mapper = new class(new FakeDbConnection([$qb])) extends CourseDocumentMapper {
            protected function findEntities($query): array {
                return [];
            }
        };

        $mapper->findExtractedUnchunked(10);

        $this->assertStringNotContainsString('"value":"chunking"', (string)json_encode($qb->andWhereCalls));
    }

    public function testTheJobAsksForStaleClaimsAndStampsItsOwnClaim(): void {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $doc = new CourseDocument();
        $doc->setId(5);
        $doc->setUpdatedAt(self::NOW - 86400);

        $mapper = $this->createMock(CourseDocumentMapper::class);
        $mapper->expects($this->once())->method('findExtractedUnchunked')
            ->with(10, self::NOW - ChunkingJob::STALE_CHUNKING_SECONDS)
            ->willReturn([$doc]);
        $claims = [];
        $mapper->method('update')->willReturnCallback(function (CourseDocument $d) use (&$claims) {
            $claims[] = [$d->getChunkingStatus(), $d->getUpdatedAt()];
            return $d;
        });

        $chunking = $this->createMock(ChunkingService::class);
        $chunking->method('chunkDocument')->willReturn(3);

        $job = new ChunkingJob($time, $mapper, $chunking, $this->createMock(LoggerInterface::class));
        $run = new \ReflectionMethod($job, 'run');
        $run->invoke($job, null);

        $this->assertSame(['chunking', self::NOW], $claims[0], 'the claim carries its time, so a dead run is recognisable');
        $this->assertSame('chunked', $claims[1][0]);
    }
}
