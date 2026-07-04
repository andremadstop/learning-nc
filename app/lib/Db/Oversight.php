<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Oversight row — records that a given lead_user_id is authorised as team-lead
 * for scope_group_id on course_id (table learning_oversight — Phase 163, RBAC-03).
 *
 * UNIQUE(course_id, lead_user_id, scope_group_id) enforced at DB level (Version009400).
 *
 * @method int    getCourseId()
 * @method void   setCourseId(int $courseId)
 * @method string getLeadUserId()
 * @method void   setLeadUserId(string $leadUserId)
 * @method string   getScopeGroupId()
 * @method void      setScopeGroupId(string $scopeGroupId)
 * @method int|null  getCreatedAt()
 * @method void      setCreatedAt(?int $createdAt)
 */
class Oversight extends Entity {
    protected $courseId;
    protected $leadUserId;
    protected $scopeGroupId;
    // AUDIT MED-13: Version009400 creates created_at; the entity was missing the mapped field.
    protected $createdAt;

    public function __construct() {
        $this->addType('courseId', 'integer');
        $this->addType('createdAt', 'integer');
    }
}
