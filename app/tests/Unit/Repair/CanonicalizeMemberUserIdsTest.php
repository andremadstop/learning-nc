<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Repair;

use OCA\Learning\Repair\CanonicalizeMemberUserIds;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #35 — memberships and pool shares stored as typed ("Alice" for the account "alice").
 *
 * The fake database keeps both tables as rows and applies the step's UPDATE/DELETE by primary key,
 * enforcing the real unique keys (course_id+user_id, pool_id+shared_with+share_type) with exact
 * comparison as PostgreSQL does. An UPDATE into an existing canonical row therefore fails here the
 * way it would on the server.
 */
#[AllowMockObjectsWithoutExpectations]
class CanonicalizeMemberUserIdsTest extends TestCase {
    /** @var array<string, array<int, array<string, mixed>>> table => id => row */
    private array $tables = [];
    /** @var array<string, string> lower-cased => canonical uid */
    private array $accounts = ['alice' => 'alice', 'bob' => 'bob', 'carol' => 'carol', 'teacher' => 'teacher'];

    private function step(): CanonicalizeMemberUserIds {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());

        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid) {
            $canonical = $this->accounts[strtolower($uid)] ?? null;
            if ($canonical === null) {
                return null;
            }
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($canonical);
            return $user;
        });

        return new CanonicalizeMemberUserIds($db, $users);
    }

    private function queryBuilder(): IQueryBuilder {
        $q = ['op' => null, 'table' => null, 'set' => [], 'where' => null];
        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(fn ($col, $val) => [$col, $val]);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('select')->willReturnCallback(function () use ($qb, &$q) { $q['op'] = 'select'; return $qb; });
        $qb->method('from')->willReturnCallback(function ($t) use ($qb, &$q) { $q['table'] = $t; return $qb; });
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('update')->willReturnCallback(function ($t) use ($qb, &$q) { $q['op'] = 'update'; $q['table'] = $t; return $qb; });
        $qb->method('delete')->willReturnCallback(function ($t) use ($qb, &$q) { $q['op'] = 'delete'; $q['table'] = $t; return $qb; });
        $qb->method('set')->willReturnCallback(function ($c, $v) use ($qb, &$q) { $q['set'][$c] = $v; return $qb; });
        $qb->method('where')->willReturnCallback(function ($w) use ($qb, &$q) { $q['where'] = $w; return $qb; });

        $qb->method('executeQuery')->willReturnCallback(function () use (&$q) {
            $this->assertSame('select', $q['op']);
            $rows = array_values($this->tables[$q['table']] ?? []);
            $result = $this->createMock(IResult::class);
            $result->method('fetch')->willReturnCallback(function () use (&$rows) {
                return array_shift($rows) ?? false;
            });
            return $result;
        });

        $qb->method('executeStatement')->willReturnCallback(function () use (&$q) {
            [$col, $id] = $q['where'];
            $this->assertSame('id', $col, 'rows are changed by primary key only');
            $table = $q['table'];
            if (!isset($this->tables[$table][$id])) {
                return 0;
            }
            if ($q['op'] === 'delete') {
                unset($this->tables[$table][$id]);
                return 1;
            }
            $row = array_merge($this->tables[$table][$id], $q['set']);
            foreach ($this->tables[$table] as $otherId => $other) {
                if ($otherId !== $id && $this->uniqueKey($table, $other) === $this->uniqueKey($table, $row)) {
                    throw new \OCP\DB\Exception('duplicate key', \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
                }
            }
            $this->tables[$table][$id] = $row;
            return 1;
        });
        return $qb;
    }

    /** @param array<string, mixed> $row */
    private function uniqueKey(string $table, array $row): string {
        return $table === 'learning_course_members'
            ? $row['course_id'] . '|' . $row['user_id']
            : $row['pool_id'] . '|' . $row['shared_with'] . '|' . $row['share_type'];
    }

    private function members(array $rows): void {
        foreach ($rows as [$id, $course, $uid, $role]) {
            $this->tables['learning_course_members'][$id] = ['id' => $id, 'course_id' => $course, 'user_id' => $uid, 'role' => $role];
        }
    }

    private function shares(array $rows): void {
        foreach ($rows as [$id, $pool, $with, $type, $perm]) {
            $this->tables['learning_pool_shares'][$id] = ['id' => $id, 'pool_id' => $pool, 'shared_with' => $with, 'share_type' => $type, 'permission' => $perm];
        }
    }

    /** @return array<int, string> id => user_id */
    private function memberUids(): array {
        return array_map(fn (array $r) => $r['course_id'] . ':' . $r['user_id'] . ':' . $r['role'], $this->tables['learning_course_members'] ?? []);
    }

    public function testWronglyCasedMembershipIsRewrittenToTheCanonicalUid(): void {
        $this->members([[1, 7, 'Alice', 'student'], [2, 7, 'bob', 'student']]);

        $this->step()->run($this->createMock(IOutput::class));

        $this->assertSame([1 => '7:alice:student', 2 => '7:bob:student'], $this->memberUids());
    }

    public function testADuplicateOfAnExistingCanonicalRowIsDeletedNotUpdatedIntoACollision(): void {
        // "Alice" was added by hand, later the real "alice" row came in (e.g. via self-enrol)
        $this->members([[1, 7, 'Alice', 'instructor'], [2, 7, 'alice', 'student'], [3, 8, 'ALICE', 'student']]);

        $this->step()->run($this->createMock(IOutput::class));

        // course 7: the canonical row stays as it was (its role was the one in effect: the repair
        // grants nobody more rights); course 8 has no canonical row, so that one is rewritten.
        $this->assertSame([2 => '7:alice:student', 3 => '8:alice:student'], $this->memberUids());
    }

    public function testTwoWronglyCasedRowsForTheSameAccountBecomeOne(): void {
        $this->members([[1, 7, 'Alice', 'student'], [2, 7, 'ALICE', 'student']]);

        $this->step()->run($this->createMock(IOutput::class));

        $this->assertSame([1 => '7:alice:student'], $this->memberUids());
    }

    public function testRowsOfDeletedAccountsAreLeftAlone(): void {
        $this->members([[1, 7, 'Ghost', 'student']]);

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('info')->with($this->stringContains('no longer exist'));
        $this->step()->run($output);

        $this->assertSame([1 => '7:Ghost:student'], $this->memberUids());
    }

    public function testASecondRunChangesNothing(): void {
        $this->members([[1, 7, 'Alice', 'student'], [2, 7, 'alice', 'student'], [3, 9, 'Bob', 'instructor']]);
        $this->step()->run($this->createMock(IOutput::class));
        $after = $this->tables;

        $output = $this->createMock(IOutput::class);
        $output->expects($this->never())->method('info');
        $this->step()->run($output);

        $this->assertSame($after, $this->tables);
    }

    public function testPoolSharesAreRepairedTheSameWayAndOnlyUserShares(): void {
        $this->shares([
            [1, 3, 'Carol', 'user', 'edit'],
            [2, 4, 'Bob', 'user', 'edit'],
            [3, 4, 'bob', 'user', 'read'],
            [4, 5, 'Alice', 'group', 'read'], // not an account reference
        ]);

        $this->step()->run($this->createMock(IOutput::class));

        $shares = array_map(fn (array $r) => $r['pool_id'] . ':' . $r['shared_with'] . ':' . $r['share_type'] . ':' . $r['permission'], $this->tables['learning_pool_shares']);
        $this->assertSame([1 => '3:carol:user:edit', 3 => '4:bob:user:read', 4 => '5:Alice:group:read'], $shares);
    }

    public function testAWronglyCasedRowOfTheCourseOwnerIsDeletedNotTurnedIntoAMembership(): void {
        // The old addMember let the owner "teacher" add "Teacher". Rewritten to "teacher", that row
        // would list the owner as a student in member lists, counts and progress tables.
        $this->tables['learning_courses'][7] = ['id' => 7, 'instructor_id' => 'teacher'];
        $this->tables['learning_courses'][8] = ['id' => 8, 'instructor_id' => 'someone'];
        $this->members([[1, 7, 'Teacher', 'student'], [2, 7, 'Alice', 'student'], [3, 8, 'TEACHER', 'student']]);

        $this->step()->run($this->createMock(IOutput::class));

        // course 8 is owned by someone else: there "teacher" is a real member and is rewritten
        $this->assertSame([2 => '7:alice:student', 3 => '8:teacher:student'], $this->memberUids());
    }

    public function testAWronglyCasedShareWithThePoolOwnerIsDeleted(): void {
        $this->tables['learning_pools'][4] = ['id' => 4, 'user_id' => 'teacher'];
        $this->shares([[1, 4, 'Teacher', 'user', 'edit'], [2, 4, 'Bob', 'user', 'read']]);

        $this->step()->run($this->createMock(IOutput::class));

        $shares = array_map(fn (array $r) => $r['pool_id'] . ':' . $r['shared_with'], $this->tables['learning_pool_shares']);
        $this->assertSame([2 => '4:bob'], $shares);
    }
}
