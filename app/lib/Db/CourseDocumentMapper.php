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
     * The course's document at this path, if registered. file_path is relative to the instructor's
     * home, so the same path in another course is a different file (2026-11 audit).
     */
    public function findByCourseAndPath(int $courseId, string $filePath): ?CourseDocument {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('file_path', $qb->createNamedParameter($filePath)))
            ->setMaxResults(1);
        try {
            $doc = $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
        return $doc instanceof CourseDocument ? $doc : null;
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

    /**
     * Find documents that are extracted but not yet chunked.
     *
     * A document left in 'chunking' since before $staleChunkingBefore is picked up again: the job
     * that set it died without reaching its catch (time limit, OOM, killed cron), and nothing else
     * ever resets the status (2026-11 audit #62). Re-chunking is safe, chunkDocument() deletes the
     * document's old chunks first. 0 leaves such documents alone.
     *
     * @return CourseDocument[]
     */
    public function findExtractedUnchunked(int $limit = 10, int $staleChunkingBefore = 0): array {
        $qb = $this->db->getQueryBuilder();
        $waiting = [
            $qb->expr()->isNull('chunking_status'),
            $qb->expr()->eq('chunking_status', $qb->createNamedParameter('pending')),
        ];
        if ($staleChunkingBefore > 0) {
            $waiting[] = $qb->expr()->andX(
                $qb->expr()->eq('chunking_status', $qb->createNamedParameter('chunking')),
                $qb->expr()->lt('updated_at', $qb->createNamedParameter($staleChunkingBefore, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            );
        }
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('extracted')))
            ->andWhere($qb->expr()->orX(...$waiting))
            ->orderBy('created_at', 'ASC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }
}
