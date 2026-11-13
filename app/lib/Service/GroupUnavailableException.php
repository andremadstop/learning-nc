<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * A group an instructor wanted to enrol either does not exist or is not visible to that instructor
 * under the instance's sharing rules (GitHub #35). Both cases are reported the same way, so the
 * enrolment API cannot be used to find out which groups exist.
 */
class GroupUnavailableException extends \RuntimeException {
    /** @var list<string> */
    private array $groupIds;

    /** @param list<string> $groupIds */
    public function __construct(array $groupIds) {
        parent::__construct('Group not available: ' . implode(', ', $groupIds));
        $this->groupIds = $groupIds;
    }

    /** @return list<string> */
    public function getGroupIds(): array {
        return $this->groupIds;
    }
}
