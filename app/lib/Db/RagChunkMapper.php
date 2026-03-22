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
}
