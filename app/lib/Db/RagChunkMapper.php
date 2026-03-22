<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class RagChunkMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_rag_chunks', RagChunk::class);
    }

    /**
     * Find all chunks for a given document.
     *
     * @return RagChunk[]
     */
    public function findByDocumentId(int $documentId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId)))
            ->orderBy('chunk_index', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Find all chunks for a given course.
     *
     * @return RagChunk[]
     */
    public function findByCourseId(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)))
            ->orderBy('document_id', 'ASC')
            ->addOrderBy('chunk_index', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Delete all chunks for a given document (for re-chunking).
     *
     * @return int Number of affected rows
     */
    public function deleteByDocumentId(int $documentId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId)));
        return $qb->executeStatement();
    }
}
