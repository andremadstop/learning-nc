<?php
declare(strict_types=1);

namespace OCA\Learning\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\User\Events\UserCreatedEvent;

/**
 * Seeds the new-user VirtuProf skin default without changing existing users.
 *
 * @implements IEventListener<UserCreatedEvent>
 */
class UserCreatedListener implements IEventListener {
    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function handle(Event $event): void {
        if (!$event instanceof UserCreatedEvent) {
            return;
        }

        $userId = $event->getUser()->getUID();
        if ($userId === '') {
            return;
        }

        if ($this->config->getUserValue($userId, 'learning', 'virtuprof_skin', '') !== '') {
            return;
        }

        $this->config->setUserValue($userId, 'learning', 'virtuprof_skin', 'prof_lern_classic');
    }
}
