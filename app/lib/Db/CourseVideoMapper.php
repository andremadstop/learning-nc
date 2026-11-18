<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<CourseVideo>
 */
class CourseVideoMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_course_videos', CourseVideo::class);
    }

    public function findById(int $id): CourseVideo {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        return $this->findEntity($qb);
    }

    /**
     * The required content of a course as it counts now: every row except the document rows whose
     * document left the learning materials (GitHub #90, course_documents.removed_at). Such a row
     * is dormant: it holds no exam back, is in no report and in no list, and counts again as it
     * did when a scan finds the file again. Every reader of the registry (exam gate, learner's
     * status, coverage report, the instructor's lists) goes through here.
     *
     * @return CourseVideo[]
     */
    public function findByCourse(int $courseId): array {
        $rows = $this->findByCourseIncludingDormant($courseId);
        $documentOf = [];
        foreach ($rows as $video) {
            $documentId = self::documentIdOf($video);
            if ($documentId !== null) {
                $documentOf[(int)$video->getId()] = $documentId;
            }
        }
        if ($documentOf === []) {
            return $rows;
        }
        $removed = $this->removedDocumentIds($courseId, array_values(array_unique($documentOf)));
        if ($removed === []) {
            return $rows;
        }
        return array_values(array_filter(
            $rows,
            static fn(CourseVideo $video): bool => !isset($removed[$documentOf[(int)$video->getId()] ?? 0])
        ));
    }

    /**
     * GitHub #90: all rows, dormant ones included (DocumentService, to name in the compliance
     * chain which required documents left or came back).
     *
     * @return CourseVideo[]
     */
    public function findByCourseIncludingDormant(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Of these course_documents ids, the ones of this course that left the list (removed_at set).
     *
     * @param list<int> $documentIds
     * @return array<int, true>
     */
    private function removedDocumentIds(int $courseId, array $documentIds): array {
        $out = [];
        foreach (array_chunk($documentIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
                ->from('learning_course_documents')
                ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->isNotNull('removed_at'));
            $result = $qb->executeQuery();
            while (($id = $result->fetchOne()) !== false) {
                $out[(int)$id] = true;
            }
            $result->closeCursor();
        }
        return $out;
    }

    /** The course_documents id of a document row (document_id, or the video_ref of an older row). */
    public static function documentIdOf(CourseVideo $video): ?int {
        if ($video->getSourceType() !== 'document') {
            return null;
        }
        $documentId = $video->getDocumentId();
        if ($documentId === null) {
            $ref = trim((string)$video->getVideoRef());
            $documentId = ($ref !== '' && ctype_digit($ref) && strlen($ref) <= 18 && (int)$ref > 0) ? (int)$ref : null;
        }
        return $documentId !== null ? (int)$documentId : null;
    }

    /**
     * GitHub #75: the document rows of a course by course_documents id (document_id, or the
     * video_ref of a row from before the column was filled). GitHub #90: without the dormant rows
     * of documents that left the list, unless asked for.
     *
     * @return array<int, CourseVideo> document id => row
     */
    public function findDocumentRowsByCourse(int $courseId, bool $includeDormant = false): array {
        $rows = [];
        $all = $includeDormant ? $this->findByCourseIncludingDormant($courseId) : $this->findByCourse($courseId);
        foreach ($all as $video) {
            $documentId = self::documentIdOf($video);
            if ($documentId !== null && !isset($rows[$documentId])) {
                $rows[$documentId] = $video;
            }
        }
        return $rows;
    }
}
