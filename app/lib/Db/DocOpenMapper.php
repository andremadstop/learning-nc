<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * GitHub #86: when a learner last opened a required document of a course (learning_doc_opens,
 * Version011300), with the version of the file that was served. One row per (user, course,
 * document); a later open overwrites it. Personal data without evidence value: never in the
 * compliance chain; RetentionService, UserDeletedListener, CourseService::delete() and the
 * uninstall command delete it, DataExportService exports it (each with its own query).
 */
class DocOpenMapper {
    public const TABLE = 'learning_doc_opens';

    public function __construct(private IDBConnection $db) {
    }

    /**
     * Store the open (update first, insert when there is no row yet). Not inside a transaction:
     * a concurrent insert of the same row (unique violation) only means the other request stored
     * it, and on PostgreSQL a failed statement inside a transaction would poison it (25P02).
     */
    public function record(string $userId, int $courseId, int $documentId, string $docVersion, int $openedAt): void {
        if ($docVersion === '') {
            throw new \InvalidArgumentException('An open of a document needs the version of its file');
        }
        if ($this->update($userId, $courseId, $documentId, $docVersion, $openedAt) > 0) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'user_id' => $qb->createNamedParameter($userId),
                'course_id' => $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
                'document_id' => $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT),
                'doc_version' => $qb->createNamedParameter($docVersion),
                'opened_at' => $qb->createNamedParameter($openedAt, IQueryBuilder::PARAM_INT),
            ]);
        try {
            $qb->executeStatement();
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            // The row exists: MySQL reports 0 affected rows for an UPDATE that changes nothing
            // (same version, same second), or a concurrent request inserted it meanwhile.
            $this->update($userId, $courseId, $documentId, $docVersion, $openedAt);
        }
    }

    private function update(string $userId, int $courseId, int $documentId, string $docVersion, int $openedAt): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('doc_version', $qb->createNamedParameter($docVersion))
            ->set('opened_at', $qb->createNamedParameter($openedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }

    /**
     * The last open of each given document by one user in one course.
     *
     * @param list<int> $documentIds
     * @return array<int, array{doc_version: string, opened_at: int}> document id => open
     */
    public function lastOpens(int $courseId, string $userId, array $documentIds): array {
        $documentIds = array_values(array_unique(array_map('intval', $documentIds)));
        if ($documentIds === []) {
            return [];
        }
        $opens = [];
        foreach (array_chunk($documentIds, 999) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('document_id', 'doc_version', 'opened_at')
                ->from(self::TABLE)
                ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->in('document_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $opens[(int)$row['document_id']] = [
                    'doc_version' => (string)$row['doc_version'],
                    'opened_at' => (int)$row['opened_at'],
                ];
            }
            $result->closeCursor();
        }
        return $opens;
    }
}
