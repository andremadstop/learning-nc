<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
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
     * @return CourseVideo[]
     */
    public function findByCourse(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * GitHub #75: the document rows of a course by course_documents id (document_id, or the
     * video_ref of a row from before the column was filled).
     *
     * @return array<int, CourseVideo> document id => row
     */
    public function findDocumentRowsByCourse(int $courseId): array {
        $rows = [];
        foreach ($this->findByCourse($courseId) as $video) {
            if ($video->getSourceType() !== 'document') {
                continue;
            }
            $documentId = $video->getDocumentId();
            if ($documentId === null) {
                $ref = trim((string)$video->getVideoRef());
                $documentId = ($ref !== '' && ctype_digit($ref) && strlen($ref) <= 18 && (int)$ref > 0) ? (int)$ref : null;
            }
            if ($documentId !== null && !isset($rows[(int)$documentId])) {
                $rows[(int)$documentId] = $video;
            }
        }
        return $rows;
    }
}
