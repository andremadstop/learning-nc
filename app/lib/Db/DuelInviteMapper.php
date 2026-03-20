<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DuelInviteMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_duel_invites', DuelInvite::class);
    }

    public function findById(int $id): DuelInvite {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    public function findBySessionId(int $sessionId): ?DuelInvite {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('duel_session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $entities = $this->findEntities($qb);
        return $entities[0] ?? null;
    }

    public function findActiveForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('invitee_uid', $qb->createNamedParameter($userId)),
                    $qb->expr()->eq('inviter_uid', $qb->createNamedParameter($userId))
                )
            )
            ->andWhere(
                $qb->expr()->in(
                    'status',
                    $qb->createNamedParameter(['open', 'accepted'], IQueryBuilder::PARAM_STR_ARRAY)
                )
            )
            ->orderBy('updated_at', 'DESC');
        return $this->findEntities($qb);
    }

    public function findActiveBetweenUsers(int $courseId, string $userA, string $userB): array {
        $pairA = [$userA, $userB];
        $pairB = [$userB, $userA];

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere(
                $qb->expr()->in(
                    'status',
                    $qb->createNamedParameter(['open', 'accepted'], IQueryBuilder::PARAM_STR_ARRAY)
                )
            )
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->andX(
                        $qb->expr()->eq('inviter_uid', $qb->createNamedParameter($pairA[0])),
                        $qb->expr()->eq('invitee_uid', $qb->createNamedParameter($pairA[1]))
                    ),
                    $qb->expr()->andX(
                        $qb->expr()->eq('inviter_uid', $qb->createNamedParameter($pairB[0])),
                        $qb->expr()->eq('invitee_uid', $qb->createNamedParameter($pairB[1]))
                    )
                )
            )
            ->orderBy('updated_at', 'DESC');

        return $this->findEntities($qb);
    }
}
