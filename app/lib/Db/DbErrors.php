<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

/**
 * Shared classification of database errors.
 *
 * Extracted from UninstallCommand (v5.3.0), which needed to tell an absent table apart
 * from a query that failed for any other reason. Codeberg #7 surfaced a second caller:
 * a course view died outright when learning_course_curriculum_scopes was missing on an
 * install where the rename migration had aborted. Rather than copy the check, both use
 * this one — a second copy would drift, and this is the kind of predicate that must mean
 * exactly the same thing everywhere.
 */
final class DbErrors {
    /**
     * Whether $e says "this table does not exist" — and nothing else.
     *
     * Deliberately narrow. Any error we cannot positively identify counts as a real
     * error, because the callers react by skipping work or returning a default: a
     * false positive here turns an outage into a silently wrong answer.
     *
     * The SQLSTATE fallback covers drivers that do not populate a reason code:
     * 42P01 is PostgreSQL's undefined_table, 42S02 MySQL/MariaDB's base table not found.
     */
    public static function isMissingTable(\Throwable $e): bool {
        if ($e instanceof \OCP\DB\Exception && $e->getReason() === \OCP\DB\Exception::REASON_DATABASE_OBJECT_NOT_FOUND) {
            return true;
        }
        return in_array((string)$e->getCode(), ['42P01', '42S02'], true);
    }
}
