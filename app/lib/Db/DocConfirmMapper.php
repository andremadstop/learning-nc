<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * GitHub #75: the append-only confirmations of required documents (learning_doc_confirms,
 * Version011100). Rows are never updated; a newer row supersedes an older one only in the
 * validity rule (RequiredDocumentService::evaluate()).
 *
 * A confirmation belongs to the document of the course (course_id, document_id), not to the
 * registry row it was given on (content_id): making a file optional deletes its row, ticking it
 * again adds a new one, and the earlier confirmations are found again (GitHub #75). Only a legacy path
 * row without a document id is looked up by content_id.
 */
class DocConfirmMapper {
    public const TABLE = 'learning_doc_confirms';

    public function __construct(private IDBConnection $db) {
    }

    /**
     * Append a confirmation. Throws \OCP\DB\Exception (unique violation) for a second row of the
     * same user, content and second.
     */
    public function insert(string $userId, int $contentId, int $courseId, int $confirmedAt, ?string $docVersion, ?int $documentId = null): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'user_id' => $qb->createNamedParameter($userId),
                'content_id' => $qb->createNamedParameter($contentId, IQueryBuilder::PARAM_INT),
                'course_id' => $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
                'confirmed_at' => $qb->createNamedParameter($confirmedAt, IQueryBuilder::PARAM_INT),
                'doc_version' => $qb->createNamedParameter($docVersion, $docVersion === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
                'document_id' => $qb->createNamedParameter($documentId, $documentId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();
    }

    /**
     * The newest confirmation per (user, registry row) of one course. A row with a document id
     * gets the newest confirmation of that document in the course, given on any registry row
     * (also on one that was removed since); a row without one gets those given on itself.
     *
     * @param list<string> $userIds
     * @param array<int, int|null> $documentOf registry row id (content id) => document id, null for a legacy path row
     * @return array<string, array<int, array{confirmed_at: int, doc_version: string|null}>> user => content id => row
     */
    public function latestByUsers(int $courseId, array $userIds, array $documentOf): array {
        if ($userIds === [] || $documentOf === []) {
            return [];
        }
        $contentOfDocument = []; // document id => content id (a document is required once per course)
        $legacyContentIds = [];
        foreach ($documentOf as $contentId => $documentId) {
            if ($documentId !== null) {
                $contentOfDocument[(int)$documentId] = (int)$contentId;
            } else {
                $legacyContentIds[] = (int)$contentId;
            }
        }
        $latest = [];
        foreach (array_chunk(array_values(array_unique($userIds)), 999) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $or = [];
            if ($contentOfDocument !== []) {
                $or[] = $qb->expr()->in('document_id', $qb->createNamedParameter(array_keys($contentOfDocument), IQueryBuilder::PARAM_INT_ARRAY));
            }
            if ($legacyContentIds !== []) {
                $or[] = $qb->expr()->andX(
                    $qb->expr()->isNull('document_id'),
                    $qb->expr()->in('content_id', $qb->createNamedParameter($legacyContentIds, IQueryBuilder::PARAM_INT_ARRAY))
                );
            }
            $qb->select('user_id', 'content_id', 'document_id', 'confirmed_at', 'doc_version')
                ->from(self::TABLE)
                ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->orX(...$or));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $uid = (string)$row['user_id'];
                $cid = $row['document_id'] !== null
                    ? ($contentOfDocument[(int)$row['document_id']] ?? null)
                    : (int)$row['content_id'];
                if ($cid === null) {
                    continue;
                }
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
