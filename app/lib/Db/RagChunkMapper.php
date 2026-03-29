<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @extends QBMapper<RagChunk> */
class RagChunkMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_rag_chunks', RagChunk::class);
    }

    /**
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     */
    public function findById(int $id): RagChunk {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
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
     * Search chunks by keywords with relevance scoring.
     *
     * Each keyword is matched case-insensitively against text and chapter columns.
     * Chapter matches receive higher weight (2) than text matches (1).
     *
     * @param int $courseId Course to search within
     * @param string[] $keywords Pre-extracted search keywords
     * @param int $limit Maximum results to return
     * @return array<array{chunk: RagChunk, relevance: int}> Ranked results
     */
    public function searchByKeywords(int $courseId, array $keywords, int $limit = 5): array {
        if (empty($keywords)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();

        // Build relevance score expression: sum of keyword matches
        $scoreParts = [];
        $orConditions = [];
        $paramIndex = 0;

        foreach ($keywords as $keyword) {
            $paramName = 'kw_' . $paramIndex;
            $pattern = '%' . mb_strtolower($keyword) . '%';
            $qb->setParameter($paramName, $pattern);

            // Text match = 1 point
            $scoreParts[] = 'CASE WHEN LOWER(text) LIKE :' . $paramName . ' THEN 1 ELSE 0 END';
            // Chapter match = 2 points
            $scoreParts[] = 'CASE WHEN LOWER(chapter) LIKE :' . $paramName . ' THEN 2 ELSE 0 END';

            // OR condition: match in text OR chapter
            $orConditions[] = $qb->expr()->like(
                $qb->createFunction('LOWER(text)'),
                $qb->createNamedParameter($pattern)
            );
            $orConditions[] = $qb->expr()->like(
                $qb->createFunction('LOWER(chapter)'),
                $qb->createNamedParameter($pattern)
            );

            $paramIndex++;
        }

        $relevanceExpr = '(' . implode(' + ', $scoreParts) . ')';

        $qb->select('*')
            ->addSelect($qb->createFunction($relevanceExpr . ' AS relevance'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('approved')))
            ->andWhere($qb->expr()->orX(...$orConditions))
            ->orderBy($qb->createFunction($relevanceExpr), 'DESC')
            ->addOrderBy('chunk_index', 'ASC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $entities = [];
        foreach ($rows as $row) {
            $relevance = (int)$row['relevance'];
            unset($row['relevance']);
            /** @var RagChunk $chunk */
            $chunk = $this->mapRowToEntity($row);
            $entities[] = [
                'chunk' => $chunk,
                'relevance' => $relevance,
            ];
        }

        return $entities;
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

    /**
     * Delete all chunks for a given document ID and course ID.
     *
     * Used for idempotent vault re-imports (document_id=0 + specific course).
     *
     * @return int Number of affected rows
     */
    public function deleteByDocumentIdAndCourseId(int $documentId, int $courseId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }

    /**
     * Delete chunks by source file name, document ID, and course ID.
     *
     * Used for idempotent web imports: replace previous chunks for same title.
     *
     * @return int Number of affected rows
     */
    public function deleteBySourceFileAndDocumentIdAndCourseId(string $sourceFile, int $documentId, int $courseId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('source_file', $qb->createNamedParameter($sourceFile)))
            ->andWhere($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }

    /**
     * Find chunks by course and status (for moderation).
     *
     * @return RagChunk[]
     */
    public function findByCourseIdAndStatus(int $courseId, string $status, int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * Find chunks contributed by a specific user in a course.
     *
     * @return RagChunk[]
     */
    public function findByUserIdAndCourseId(string $userId, int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * Count chunks by user in a course (for quota enforcement).
     */
    public function countByUserIdAndCourseId(string $userId, int $courseId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Find web-imported knowledge sources grouped by source file.
     *
     * Returns aggregated data (not entity mapping) for the instructor's overview.
     *
     * @return array<int, array{source_file: string, chunk_count: int, token_count: int, created_at: int}>
     */
    public function findWebImportsByCourseId(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('source_file'))
            ->addSelect($qb->createFunction('COUNT(*) AS chunk_count'))
            ->addSelect($qb->createFunction('SUM(token_count) AS token_count'))
            ->addSelect($qb->createFunction('MIN(created_at) AS created_at'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('document_id', $qb->createNamedParameter(-1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->groupBy('source_file')
            ->orderBy('created_at', 'DESC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(function (array $row): array {
            return [
                'source_file' => $row['source_file'],
                'chunk_count' => (int) $row['chunk_count'],
                'token_count' => (int) $row['token_count'],
                'created_at' => (int) $row['created_at'],
            ];
        }, $rows);
    }
}
