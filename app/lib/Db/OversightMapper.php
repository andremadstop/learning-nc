<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * QBMapper for learning_oversight (RBAC-03 team-lead authorisation rows).
 *
 * Skeleton bodies — real query implementations delivered in 163-03.
 * The table name is UNPREFIXED ('learning_oversight') per NC QBMapper convention.
 *
 * @extends QBMapper<Oversight>
 */
class OversightMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_oversight', Oversight::class);
    }

    /**
     * All oversight rows for the given lead user.
     *
     * @return Oversight[]
     */
    public function findByLead(string $leadUserId): array {
        // SKELETON — real query in 163-03
        return [];
    }

    /**
     * True when an oversight row exists for the exact (courseId, leadUserId, groupId) triple.
     */
    public function existsForLeadGroupCourse(int $courseId, string $leadUserId, string $groupId): bool {
        // SKELETON — real query in 163-03
        return false;
    }
}
