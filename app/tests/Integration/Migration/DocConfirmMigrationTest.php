<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Integration\Migration;

use OCA\Learning\Migration\Version011100Date20261116150000;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #75: the data step of Version011100 against a real database (run on PostgreSQL and on
 * MariaDB: ENGINE=pgsql|mysql scripts/nc-integration.sh). Existing read confirmations — also
 * those on a document that was required twice — become confirmation rows; the duplicate
 * registry rows go; document_id is filled; a second run changes nothing. Every test rolls back.
 */
class DocConfirmMigrationTest extends TestCase {
    private const COURSE = 975001;
    private const OTHER_COURSE = 975002;

    private IDBConnection $db;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
    }

    private function registryRow(int $courseId, string $type, string $ref): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_course_videos')->values([
            'course_id' => $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
            'source_type' => $qb->createNamedParameter($type),
            'video_ref' => $qb->createNamedParameter($ref),
            'title' => $qb->createNamedParameter('T'),
            'sort_order' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
            'created_at' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        return $qb->getLastInsertId();
    }

    private function progress(string $user, int $contentId, ?int $completedAt): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_video_progress')->values([
            'user_id' => $qb->createNamedParameter($user),
            'content_id' => $qb->createNamedParameter($contentId, IQueryBuilder::PARAM_INT),
            'completed_at' => $qb->createNamedParameter($completedAt, $completedAt === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    /** @return list<array{user_id: string, content_id: int, confirmed_at: int, doc_version: ?string}> */
    private function confirmations(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'content_id', 'confirmed_at', 'doc_version')->from('learning_doc_confirms')
            ->where($qb->expr()->in('course_id', $qb->createNamedParameter([self::COURSE, self::OTHER_COURSE], IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('user_id')->addOrderBy('confirmed_at');
        $rows = $qb->executeQuery()->fetchAll();
        return array_map(static fn(array $r): array => [
            'user_id' => (string)$r['user_id'],
            'content_id' => (int)$r['content_id'],
            'confirmed_at' => (int)$r['confirmed_at'],
            'doc_version' => $r['doc_version'] !== null ? (string)$r['doc_version'] : null,
        ], $rows);
    }

    /** @return array<int, ?int> id => document_id */
    private function registry(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'document_id')->from('learning_course_videos')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)));
        $out = [];
        foreach ($qb->executeQuery()->fetchAll() as $row) {
            $out[(int)$row['id']] = $row['document_id'] !== null ? (int)$row['document_id'] : null;
        }
        return $out;
    }

    public function testExistingConfirmationsAndDuplicatesAreTakenOver(): void {
        $this->db->beginTransaction();
        try {
            $kept = $this->registryRow(self::COURSE, 'document', '12');
            $dup = $this->registryRow(self::COURSE, 'document', '12');      // GitHub #75: added twice
            $other = $this->registryRow(self::COURSE, 'document', '13');
            $legacy = $this->registryRow(self::COURSE, 'document', 'Docs/old.pdf');
            $video = $this->registryRow(self::COURSE, 'youtube-nocookie', 'dQw4w9WgXcQ');
            $sameDocElsewhere = $this->registryRow(self::OTHER_COURSE, 'document', '12');

            $this->progress('anna', $kept, 1000);
            $this->progress('anna', $dup, 1100);      // confirmed the duplicate too
            $this->progress('ben', $dup, 1200);       // only the duplicate
            $this->progress('ben', $other, null);     // never confirmed
            $this->progress('carl', $legacy, 1300);
            $this->progress('carl', $video, 1400);    // a watched video stays a video
            $this->progress('dora', $sameDocElsewhere, 1500);

            $result = Version011100Date20261116150000::migrateDocumentRows($this->db);

            $this->assertSame(5, $result['confirmations']);
            $this->assertSame(1, $result['duplicates']);
            $this->assertSame([
                ['user_id' => 'anna', 'content_id' => $kept, 'confirmed_at' => 1000, 'doc_version' => null],
                ['user_id' => 'anna', 'content_id' => $kept, 'confirmed_at' => 1100, 'doc_version' => null],
                ['user_id' => 'ben', 'content_id' => $kept, 'confirmed_at' => 1200, 'doc_version' => null],
                ['user_id' => 'carl', 'content_id' => $legacy, 'confirmed_at' => 1300, 'doc_version' => null],
                ['user_id' => 'dora', 'content_id' => $sameDocElsewhere, 'confirmed_at' => 1500, 'doc_version' => null],
            ], $this->confirmations());

            $registry = $this->registry(self::COURSE);
            $this->assertArrayNotHasKey($dup, $registry, 'the duplicate row is gone');
            $this->assertSame(12, $registry[$kept]);
            $this->assertSame(13, $registry[$other]);
            $this->assertNull($registry[$legacy], 'a legacy path row has no document id');
            $this->assertNull($registry[$video]);
            $this->assertSame([$sameDocElsewhere => 12], $this->registry(self::OTHER_COURSE));

            // The video progress of a kept row is untouched.
            $qb = $this->db->getQueryBuilder();
            $qb->select('completed_at')->from('learning_video_progress')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter('carl')))
                ->andWhere($qb->expr()->eq('content_id', $qb->createNamedParameter($video, IQueryBuilder::PARAM_INT)));
            $this->assertSame(1400, (int)$qb->executeQuery()->fetchOne());

            // Idempotent: a second run (every upgrade runs postSchemaChange once, but be safe).
            $again = Version011100Date20261116150000::migrateDocumentRows($this->db);
            $this->assertSame(['confirmations' => 0, 'duplicates' => 0], $again);
            $this->assertCount(5, $this->confirmations());
        } finally {
            $this->db->rollBack();
        }
    }

    public function testADocumentCanBeRequiredOncePerCourseAndVideosDoNotCollide(): void {
        $prefix = Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
        $index = $this->db->createSchema()->getTable($prefix . 'learning_course_videos')->getIndexes()['learn_cv_course_doc_uq'] ?? null;
        $this->assertNotNull($index);
        $this->assertTrue($index->isUnique());

        $this->db->beginTransaction();
        try {
            // Two videos (document_id NULL) in one course coexist.
            $this->registryRow(self::COURSE, 'vimeo', '76979871');
            $this->registryRow(self::COURSE, 'vimeo', '76979872');
            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_course_videos')->values([
                'course_id' => $qb->createNamedParameter(self::COURSE, IQueryBuilder::PARAM_INT),
                'source_type' => $qb->createNamedParameter('document'),
                'video_ref' => $qb->createNamedParameter('12'),
                'document_id' => $qb->createNamedParameter(12, IQueryBuilder::PARAM_INT),
                'sort_order' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'created_at' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            ])->executeStatement();

            $thrown = null;
            try {
                $qb->executeStatement(); // the same document again
            } catch (\OCP\DB\Exception $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, 'the second row for the same document is refused');
            $this->assertSame(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, $thrown->getReason());
        } finally {
            $this->db->rollBack();
        }
    }
}
