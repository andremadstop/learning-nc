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
     * The course's document row of this Nextcloud file id, if any (GitHub #75): a file belongs to
     * the row that carries its id, not to another row that merely has the same name.
     */
    public function findByCourseAndFileId(int $courseId, int $fileId): ?CourseDocument {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
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
     * GitHub #75: the "Ask everyone to confirm again" moment of each given document of the course
     * (confirm_after; documents without one are left out). Reads only these two columns, not the
     * extracted text.
     *
     * @param list<int> $documentIds
     * @return array<int, int> document id => confirm_after
     */
    public function confirmAfterByIds(int $courseId, array $documentIds): array {
        if ($documentIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique($documentIds)), 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'confirm_after')
                ->from($this->getTableName())
                ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->in('id', $qb->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->isNotNull('confirm_after'));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $out[(int)$row['id']] = (int)$row['confirm_after'];
            }
            $result->closeCursor();
        }
        return $out;
    }

    /**
     * GitHub #75: set "Ask everyone to confirm again" on a document of the course.
     *
     * @return int rows changed (0 = no such document in this course)
     */
    public function setConfirmAfter(int $courseId, int $documentId, int $at): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('confirm_after', $qb->createNamedParameter($at, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($documentId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
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
