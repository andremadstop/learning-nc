<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IConfig;

/**
 * GitHub #59: the people who are told of a new technical ticket (the admin inbox). Not "every
 * administrator": on an instance with several admins each looks after something else, so the
 * admin picks them. Nothing chosen means nobody is notified; the admin page says so.
 *
 * Stored as a JSON list of uids in one app value. The format is checked, existence is not (an
 * LDAP account that cannot be resolved for a moment keeps its place); the notifier skips an
 * account that is gone, disabled or no administrator when it sends.
 */
class TechnicalTicketRecipients {
    public const KEY = 'technical_ticket_users';

    public function __construct(
        private IConfig $config,
        private string $appName,
    ) {
    }

    /** @return list<string> */
    public function get(): array {
        $raw = $this->config->getAppValue($this->appName, self::KEY, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        // a damaged value notifies nobody rather than anybody
        return is_array($decoded) ? RoleService::normalizeIds($decoded) : [];
    }

    /**
     * @param array<mixed> $userIds
     * @return list<string> the stored list
     */
    public function save(array $userIds): array {
        $users = RoleService::normalizeIds($userIds);
        $this->config->setAppValue($this->appName, self::KEY, (string)json_encode($users));
        return $users;
    }

    /**
     * Takes a deleted account off the list, so a new account that reuses the uid is not told of
     * technical tickets, chosen by nobody. Nothing stored, nothing written.
     *
     * @return bool whether the uid was on the list
     */
    public function removeUser(string $userId): bool {
        $users = $this->get();
        if (!in_array($userId, $users, true)) {
            return false;
        }
        $this->config->setAppValue($this->appName, self::KEY, (string)json_encode(
            array_values(array_filter($users, static fn (string $uid): bool => $uid !== $userId))
        ));
        return true;
    }
}
