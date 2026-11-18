<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #90 against the real schema (PostgreSQL and MariaDB, scripts/nc-integration.sh): the
 * required-content row of a document that left the learning materials (course_documents.removed_at)
 * is dormant. Every registry reader goes through CourseVideoMapper::findByCourse(), so it holds no
 * exam back and is in no report; it counts again as soon as the column is cleared.
 */
final class DormantRequiredRows90Test extends TestCase {
    private const COURSE = 990090;
    private const OTHER = 990091;

    private IDBConnection $db;
    private CourseVideoMapper $videos;
    private CourseDocumentMapper $documents;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->videos = Server::get(CourseVideoMapper::class);
        $this->documents = Server::get(CourseDocumentMapper::class);
        $this->cleanUp();
    }

    protected function tearDown(): void {
        $this->cleanUp();
    }

    public function testTheRowOfARemovedDocumentIsDormantUntilTheDocumentIsBack(): void {
        $gone = $this->document(self::COURSE, 'Gone.pdf', 1000);
        $listed = $this->document(self::COURSE, 'Listed.pdf', null);
        // the same document id in another course must not hide anything there
        $foreign = $this->document(self::OTHER, 'Foreign.pdf', null);
        $goneRow = $this->registry(self::COURSE, 'document', (string)$gone->getId(), $gone->getId());
        $listedRow = $this->registry(self::COURSE, 'document', (string)$listed->getId(), $listed->getId());
        // a row from before the document_id column: the document only in video_ref
        $legacyRow = $this->registry(self::COURSE, 'document', (string)$gone->getId(), null);
        $video = $this->registry(self::COURSE, 'youtube-nocookie', 'dQw4w9WgXcQ', null);
        $this->registry(self::OTHER, 'document', (string)$foreign->getId(), $foreign->getId());

        $this->assertSame(
            [$listedRow->getId(), $video->getId()],
            self::ids($this->videos->findByCourse(self::COURSE)),
            'gate, status, report and lists leave the removed document out'
        );
        $this->assertSame([$listed->getId()], array_keys($this->videos->findDocumentRowsByCourse(self::COURSE)));
        $this->assertSame(
            [$goneRow->getId(), $listedRow->getId(), $legacyRow->getId(), $video->getId()],
            self::ids($this->videos->findByCourseIncludingDormant(self::COURSE))
        );
        $this->assertArrayHasKey($gone->getId(), $this->videos->findDocumentRowsByCourse(self::COURSE, true));
        $this->assertCount(1, $this->videos->findByCourse(self::OTHER));

        $gone->setRemovedAt(null);
        $this->documents->update($gone);

        $this->assertSame(
            [$goneRow->getId(), $listedRow->getId(), $legacyRow->getId(), $video->getId()],
            self::ids($this->videos->findByCourse(self::COURSE)),
            'the same document back in the list: its required row counts again'
        );
    }

    private function document(int $courseId, string $name, ?int $removedAt): CourseDocument {
        $doc = new CourseDocument();
        $doc->setCourseId($courseId);
        $doc->setFilePath('/Kurs/' . $name);
        $doc->setFileName($name);
        $doc->setFileType('pdf');
        $doc->setStatus('uploaded');
        $doc->setCreatedAt(1);
        $doc->setUpdatedAt(1);
        $doc->setRemovedAt($removedAt);
        return $this->documents->insert($doc);
    }

    private function registry(int $courseId, string $type, string $ref, ?int $documentId): CourseVideo {
        $video = new CourseVideo();
        $video->setCourseId($courseId);
        $video->setSourceType($type);
        $video->setVideoRef($ref);
        $video->setDocumentId($documentId);
        $video->setSortOrder(0);
        $video->setCreatedAt(1);
        return $this->videos->insert($video);
    }

    /**
     * @param CourseVideo[] $rows
     * @return list<int>
     */
    private static function ids(array $rows): array {
        return array_map(static fn(CourseVideo $v): int => (int)$v->getId(), $rows);
    }

    private function cleanUp(): void {
        foreach (['learning_course_videos', 'learning_course_documents'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where($qb->expr()->in('course_id', $qb->createNamedParameter([self::COURSE, self::OTHER], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
            $qb->executeStatement();
        }
    }
}
