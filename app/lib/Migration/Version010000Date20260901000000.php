<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Codeberg #5: from 5.4.2 the server is authoritative for "this user has seen the introduction".
 *
 * Without this step the new user value would start empty for everyone, so the update itself would
 * push every existing user back into the onboarding — the exact complaint that was reported, for
 * a whole instance on the same morning.
 *
 * Anyone with a trace of prior use (a telos profile or a course membership) is treated as done.
 * Users who already carry an explicit value keep it.
 *
 * Uses the QueryBuilder rather than raw SQL on purpose: hand-written ANSI double quotes are
 * string literals on MariaDB, which has already cost this project a release once.
 */
class Version010000Date20260901000000 extends SimpleMigrationStep {
    public function __construct(
        private readonly IDBConnection $db,
        private readonly IConfig $config,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $this->markUsersAcknowledged($this->collectExistingUsers(), $output);
    }

    /**
     * @param array<int, string> $userIds
     */
    public function markUsersAcknowledged(array $userIds, IOutput $output): void {
        $count = 0;

        foreach (array_unique($userIds) as $userId) {
            if ($userId === '') {
                continue;
            }

            // An explicit value means the user has already decided under 5.4.2 — never overwrite.
            if ($this->config->getUserValue($userId, 'learning', 'onboarding_acknowledged', '') !== '') {
                continue;
            }

            $this->config->setUserValue($userId, 'learning', 'onboarding_acknowledged', 'yes');
            $count++;
        }

        $output->info(sprintf('learning: marked %d existing users as onboarding-acknowledged', $count));
    }

    /**
     * @return array<int, string>
     */
    private function collectExistingUsers(): array {
        $users = [];

        foreach (['learning_user_telos', 'learning_course_members'] as $table) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->selectDistinct('user_id')->from($table);
                $result = $qb->executeQuery();
                foreach ($result->fetchAll() as $row) {
                    $users[] = (string)($row['user_id'] ?? '');
                }
                $result->closeCursor();
            } catch (\Throwable $e) {
                // A partial install may not have every table — nothing to migrate from it.
            }
        }

        return $users;
    }
}
