<?php
declare(strict_types=1);

namespace OCA\Learning\Listener;

use OCA\Learning\Service\TechnicalTicketRecipients;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * GitHub #59: a deleted account leaves the list of people told of new technical tickets.
 * Without this a new account that reuses the uid would get them (and the ticket subjects in
 * them), chosen by nobody.
 *
 * @implements IEventListener<UserDeletedEvent>
 */
class TicketRecipientUserDeletedListener implements IEventListener {
    public function __construct(private TechnicalTicketRecipients $recipients) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof UserDeletedEvent) {
            return;
        }
        $userId = $event->getUser()->getUID();
        if ($userId === '') {
            return;
        }
        $this->recipients->removeUser($userId);
    }
}
