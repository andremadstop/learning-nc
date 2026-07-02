<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * QBMapper for learning_oversight (RBAC-03 team-lead authorisation rows).
 *
 * Table name is UNPREFIXED ('learning_oversight') per NC QBMapper convention
 * (the NC DB layer adds oc_ automatically via getTableName()).
 *
 * @extends QBMapper<Oversight>
 */
class OversightMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_oversight', Oversight::class);
    }

    /**
     * All oversight rows for the given lead user.
     * Backs RoleService::getTeamLeadGroups().
     *
     * @return Oversight[]
     */
    public function findByLead(string $leadUserId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('lead_user_id', $qb->createNamedParameter($leadUserId)));
        return $this->findEntities($qb);
    }

    /**
     * True when an oversight row exists for the exact (courseId, leadUserId, groupId) triple.
     * Backs RoleService::isTeamLeadForGroup() / assertTeamLeadForGroup().
     * Both ids required — fail closed (false) if either is absent or mismatched.
     */
    public function existsForLeadGroupCourse(int $courseId, string $leadUserId, string $groupId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('lead_user_id', $qb->createNamedParameter($leadUserId)))
           ->andWhere($qb->expr()->eq('scope_group_id', $qb->createNamedParameter($groupId)))
           ->setMaxResults(1);
        $r = $qb->executeQuery();
        $row = $r->fetch();
        $r->closeCursor();
        return $row !== false;
    }
}
