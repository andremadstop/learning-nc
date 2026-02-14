<?php
namespace OCA\Learning\Service;

use OCP\IConfig;
use OCP\IGroupManager;

class RoleService {
    private IGroupManager $groupManager;
    private IConfig $config;
    private string $appName;

    public function __construct(
        IGroupManager $groupManager,
        IConfig $config,
        string $appName
    ) {
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->appName = $appName;
    }

    public function getInstructorGroup(): string {
        return $this->config->getAppValue($this->appName, 'instructor_group', 'learning-instructors');
    }

    public function isInstructor(string $userId): bool {
        $group = $this->getInstructorGroup();
        return $this->groupManager->isInGroup($userId, $group);
    }

    public function getRole(string $userId): string {
        return $this->isInstructor($userId) ? 'instructor' : 'student';
    }
}
