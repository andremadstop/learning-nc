<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Rewrite course memberships and pool shares that were stored with a wrongly cased user id
 * (GitHub #35).
 *
 * Instructors typed user ids by hand. Nextcloud found "Alice" for the account "alice"
 * (case-insensitive) and the app stored what was typed. Every lookup afterwards compares exactly,
 * so on PostgreSQL the learner never saw the course or the pool. The app now stores the canonical
 * uid; this step fixes the rows written before.
 *
 * Per row:
 * - the account no longer exists → left alone (nothing to resolve it to);
 * - the stored id already is the canonical uid → nothing to do;
 * - the same course/pool already has a row with the canonical uid → the wrongly cased row is
 *   deleted. The canonical row keeps its role/permission: that is the one that was in effect,
 *   and a repair step must not grant anybody more rights than they had;
 * - the canonical uid is the course's owner (or the pool's owner) → the row is deleted. The old
 *   code let an instructor add themselves typed in another case; rewritten, that row would list
 *   the owner as an ordinary (student) member in member lists, counts and progress tables,
 *   which the app otherwise never stores. Access is unaffected: ownership is checked first;
 * - otherwise → the row's user id is set to the canonical uid.
 *
 * Case is compared in PHP, never in SQL: PostgreSQL compares case-sensitively while MySQL/MariaDB
 * may not, depending on the collation, so a WHERE-based duplicate check would decide differently
 * per engine. Rows are changed by primary key only. Running the step again changes nothing.
 *
 * Registered under <install> and <post-migration>.
 */
class CanonicalizeMemberUserIds implements IRepairStep {
    /**
     * table => scope column (course/pool), user column, extra column that must also match, and
     * the owner table/column of the scope (the owner never needs a row of their own)
     */
    private const TABLES = [
        'learning_course_members' => ['scope' => 'course_id', 'user' => 'user_id', 'extra' => null, 'owners' => ['learning_courses', 'instructor_id']],
        'learning_pool_shares' => ['scope' => 'pool_id', 'user' => 'shared_with', 'extra' => 'share_type', 'owners' => ['learning_pools', 'user_id']],
    ];

    /** Only user shares name an account; any other share type is left alone. */
    private const USER_SHARE_TYPE = 'user';

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
    ) {
    }

    public function getName(): string {
        return 'Store course members and pool shares under the canonical user id';
    }

    public function run(IOutput $output): void {
        foreach (self::TABLES as $table => $columns) {
            [$updated, $deleted, $orphaned] = $this->repairTable($table, $columns['scope'], $columns['user'], $columns['extra'], $columns['owners']);
            if ($updated > 0 || $deleted > 0) {
                $output->info(sprintf(
                    '%s: %d row(s) set to the canonical user id, %d wrongly cased duplicate(s) removed.',
                    $table,
                    $updated,
                    $deleted
                ));
            }
            if ($orphaned > 0) {
                $output->info(sprintf('%s: %d row(s) of accounts that no longer exist left unchanged.', $table, $orphaned));
            }
        }
    }

    /**
     * @param array{0: string, 1: string} $owners owner table and its owner column
     * @return array{0: int, 1: int, 2: int} updated, deleted, orphaned
     */
    private function repairTable(string $table, string $scopeColumn, string $userColumn, ?string $extraColumn, array $owners): array {
        $ownerOf = $this->owners($owners[0], $owners[1]);

        $select = $this->db->getQueryBuilder();
        $columns = ['id', $scopeColumn, $userColumn];
        if ($extraColumn !== null) {
            $columns[] = $extraColumn;
        }
        $select->select(...$columns)->from($table)->orderBy('id', 'ASC');
        $result = $select->executeQuery();
        $rows = [];
        while (($row = $result->fetch()) !== false) {
            $rows[] = $row;
        }
        $result->closeCursor();

        // exact user ids present per scope (course/pool + share type)
        $present = [];
        foreach ($rows as $row) {
            $present[$this->scopeKey($row, $scopeColumn, $extraColumn)][(string)$row[$userColumn]] = true;
        }

        $updated = 0;
        $deleted = 0;
        $orphaned = 0;
        $canonicalCache = [];
        foreach ($rows as $row) {
            if ($extraColumn !== null && (string)$row[$extraColumn] !== self::USER_SHARE_TYPE) {
                continue;
            }
            $stored = (string)$row[$userColumn];
            if (!array_key_exists($stored, $canonicalCache)) {
                $user = $this->userManager->get($stored);
                $canonicalCache[$stored] = $user === null ? null : $user->getUID();
            }
            $canonical = $canonicalCache[$stored];
            if ($canonical === null) {
                $orphaned++;
                continue;
            }
            if ($canonical === $stored) {
                continue;
            }

            $scope = $this->scopeKey($row, $scopeColumn, $extraColumn);
            $id = (int)$row['id'];
            $owner = $ownerOf[(string)$row[$scopeColumn]] ?? null;
            if (isset($present[$scope][$canonical]) || $canonical === $owner) {
                $delete = $this->db->getQueryBuilder();
                $delete->delete($table)
                    ->where($delete->expr()->eq('id', $delete->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $delete->executeStatement();
                $deleted++;
            } else {
                $update = $this->db->getQueryBuilder();
                $update->update($table)
                    ->set($userColumn, $update->createNamedParameter($canonical))
                    ->where($update->expr()->eq('id', $update->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $update->executeStatement();
                $present[$scope][$canonical] = true;
                $updated++;
            }
            unset($present[$scope][$stored]);
        }

        return [$updated, $deleted, $orphaned];
    }

    /**
     * Owner uid per course/pool id, exactly as stored.
     *
     * @return array<string, string>
     */
    private function owners(string $table, string $ownerColumn): array {
        $select = $this->db->getQueryBuilder();
        $select->select('id', $ownerColumn)->from($table);
        $result = $select->executeQuery();
        $owners = [];
        while (($row = $result->fetch()) !== false) {
            $owners[(string)$row['id']] = (string)$row[$ownerColumn];
        }
        $result->closeCursor();
        return $owners;
    }

    /** @param array<string, mixed> $row */
    private function scopeKey(array $row, string $scopeColumn, ?string $extraColumn): string {
        $key = (string)$row[$scopeColumn];
        if ($extraColumn !== null) {
            $key .= "\0" . (string)$row[$extraColumn];
        }
        return $key;
    }
}
