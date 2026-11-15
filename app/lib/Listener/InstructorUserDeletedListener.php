<?php
declare(strict_types=1);

namespace OCA\Learning\Listener;

use OCA\Learning\Service\RoleService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * GitHub #60: instructors can be named as single people (app value instructor_users). Group
 * memberships of a deleted account are removed by Nextcloud, that list is not; without this a
 * new account that reuses the uid would be an instructor right away, granted by nobody.
 *
 * @implements IEventListener<UserDeletedEvent>
 */
class InstructorUserDeletedListener implements IEventListener {
    public function __construct(private RoleService $roleService) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof UserDeletedEvent) {
            return;
        }
        $userId = $event->getUser()->getUID();
        if ($userId === '') {
            return;
        }
        $this->roleService->removeInstructorUser($userId);
    }
}
