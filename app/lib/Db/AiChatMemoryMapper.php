<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class AiChatMemoryMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_ai_chat_memory', AiChatMemory::class);
    }

    /**
     * Return up to $limit most recent entries for a user, in chronological order
     * (oldest first — suitable for chat history display and Gemini context).
     *
     * @return AiChatMemory[]
     */
    public function findRecentByUser(string $userId, int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->orderBy('created_at', 'DESC')
           ->setMaxResults($limit);

        $entries = $this->findEntities($qb);
        // Reverse so they are chronological (oldest first)
        return array_reverse($entries);
    }

    /**
     * Count how many entries this user has.
     */
    public function countByUser(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'cnt'))
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Return the IDs of the N oldest entries for this user.
     *
     * @return int[]
     */
    public function findOldestIdsByUser(string $userId, int $count): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->orderBy('created_at', 'ASC')
           ->setMaxResults($count);

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['id'];
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Return the N oldest entries as AiChatMemory entities (for compression).
     *
     * @return AiChatMemory[]
     */
    public function findOldestByUser(string $userId, int $count): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->orderBy('created_at', 'ASC')
           ->setMaxResults($count);

        return $this->findEntities($qb);
    }

    /**
     * Delete entries by their IDs.
     *
     * @param int[] $ids
     */
    public function deleteByIds(array $ids): void {
        if (empty($ids)) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->in(
               'id',
               $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)
           ));
        $qb->executeStatement();
    }

    /**
     * Delete all entries for a user (privacy / clear-history endpoint).
     */
    public function deleteAllByUser(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }
}
