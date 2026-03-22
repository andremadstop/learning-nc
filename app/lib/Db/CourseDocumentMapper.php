<?php
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class CourseDocumentMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_course_documents', CourseDocument::class);
    }

    /**
     * @return CourseDocument[]
     */
    public function findByCourseId(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * @return CourseDocument|null
     */
    public function findByPath(string $filePath): ?CourseDocument {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('file_path', $qb->createNamedParameter($filePath)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * @return CourseDocument
     * @throws DoesNotExistException
     */
    public function findById(int $id): CourseDocument {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        return $this->findEntity($qb);
    }
}
