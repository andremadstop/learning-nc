<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * GitHub #75: the append-only confirmations of required documents (learning_doc_confirms,
 * Version011100). Rows are never updated; a newer row supersedes an older one only in the
 * validity rule (RequiredDocumentService::evaluate()).
 */
class DocConfirmMapper {
    public const TABLE = 'learning_doc_confirms';

    public function __construct(private IDBConnection $db) {
    }

    /**
     * Append a confirmation. Throws \OCP\DB\Exception (unique violation) for a second row of the
     * same user, content and second.
     */
    public function insert(string $userId, int $contentId, int $courseId, int $confirmedAt, ?string $docVersion): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'user_id' => $qb->createNamedParameter($userId),
                'content_id' => $qb->createNamedParameter($contentId, IQueryBuilder::PARAM_INT),
                'course_id' => $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
                'confirmed_at' => $qb->createNamedParameter($confirmedAt, IQueryBuilder::PARAM_INT),
                'doc_version' => $qb->createNamedParameter($docVersion, $docVersion === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
            ])
            ->executeStatement();
    }

    /**
     * The newest confirmation per (user, content).
     *
     * @param list<string> $userIds
     * @param list<int> $contentIds
     * @return array<string, array<int, array{confirmed_at: int, doc_version: string|null}>> user => content => row
     */
    public function latestByUsers(array $userIds, array $contentIds): array {
        if ($userIds === [] || $contentIds === []) {
            return [];
        }
        $latest = [];
        foreach (array_chunk(array_values(array_unique($userIds)), 999) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('user_id', 'content_id', 'confirmed_at', 'doc_version')
                ->from(self::TABLE)
                ->where($qb->expr()->in('user_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->in('content_id', $qb->createNamedParameter(array_values($contentIds), IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $uid = (string)$row['user_id'];
                $cid = (int)$row['content_id'];
                $at = (int)$row['confirmed_at'];
                if (!isset($latest[$uid][$cid]) || $at > $latest[$uid][$cid]['confirmed_at']) {
                    $latest[$uid][$cid] = [
                        'confirmed_at' => $at,
                        'doc_version' => $row['doc_version'] !== null ? (string)$row['doc_version'] : null,
                    ];
                }
            }
            $result->closeCursor();
        }
        return $latest;
    }
}
