<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Service\ComplianceEventTypes;
use OCA\Learning\Service\DocumentService;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
    /** @var list<int> courses a test created */
    private array $courseIds = [];
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

    /**
     * The × through the real container and database: the entry leaves the list, the required row
     * goes dormant, and both happen together with the compliance event (one transaction, the
     * event's own nested one inside). The service takes mapper, audit and connection as optional
     * dependencies, so an unwired container would skip the event without failing.
     */
    public function testTheCrossTakesARequiredEntryOutAndRecordsIt(): void {
        $service = Server::get(DocumentService::class);
        foreach (['videoMapper', 'auditService', 'db'] as $property) {
            $this->assertNotNull((new \ReflectionProperty(DocumentService::class, $property))->getValue($service), $property . ' is injected');
        }
        $course = new Course();
        $course->setTitle('i90 course');
        // no such user: the material folder cannot be reached, so the entry counts as stale
        $course->setInstructorId('i90_teacher');
        $course->setStatus('active');
        $course->setMaterialFolder('/Kurs');
        $course->setCreatedAt(1);
        $course->setUpdatedAt(1);
        $course = Server::get(CourseMapper::class)->insert($course);
        $this->courseIds[] = (int)$course->getId();
        $doc = $this->document((int)$course->getId(), 'Policy.pdf', null);
        $row = $this->registry((int)$course->getId(), 'document', (string)$doc->getId(), $doc->getId());
        $floor = $this->maxAuditId();

        $service->removeEntry($course, (int)$doc->getId(), 'i90_teacher');

        $this->assertNotNull($this->documents->findById((int)$doc->getId())->getRemovedAt());
        $this->assertSame([], $this->videos->findByCourse((int)$course->getId()), 'the required row is dormant');
        $this->assertSame([(int)$row->getId()], self::ids($this->videos->findByCourseIncludingDormant((int)$course->getId())));
        $this->assertSame([[
            'event_key' => ComplianceEventTypes::CONTENT_REQUIRED_CHANGED,
            'context' => ['course_id' => (int)$course->getId(), 'required' => false, 'reason' => 'document_removed', 'document_ids' => [(int)$doc->getId()], 'content_ids' => [(int)$row->getId()]],
        ]], $this->auditEvents('i90_teacher', $floor));
        $this->assertFalse($this->db->inTransaction(), 'no transaction left open');
    }

    private function maxAuditId(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('id'))->from('learning_audit_events');
        $r = $qb->executeQuery();
        $max = $r->fetchOne();
        $r->closeCursor();
        return (int)$max;
    }

    /** @return list<array{event_key: string, context: array<string, mixed>}> */
    private function auditEvents(string $uid, int $floor): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('event_key', 'context_json')->from('learning_audit_events')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($floor, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        $r = $qb->executeQuery();
        $out = [];
        foreach ($r->fetchAll() as $event) {
            $ctx = json_decode((string)$event['context_json'], true);
            $out[] = ['event_key' => (string)$event['event_key'], 'context' => is_array($ctx) ? $ctx : []];
        }
        $r->closeCursor();
        return $out;
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
        $courses = array_merge([self::COURSE, self::OTHER], $this->courseIds);
        foreach (['learning_course_videos', 'learning_course_documents'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where($qb->expr()->in('course_id', $qb->createNamedParameter($courses, IQueryBuilder::PARAM_INT_ARRAY)));
            $qb->executeStatement();
        }
        if ($this->courseIds !== []) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('learning_courses')
                ->where($qb->expr()->in('id', $qb->createNamedParameter($this->courseIds, IQueryBuilder::PARAM_INT_ARRAY)));
            $qb->executeStatement();
        }
        // the audit events stay: the chain is append-only (as in ConsentApiTest)
    }
}
