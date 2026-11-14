<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\AiChatMemory;
use OCA\Learning\Db\AiChatMemoryMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Listener\UserDeletedListener;
use OCA\Learning\Service\Llm\LlmBudget;
use OCA\Learning\Service\Llm\LlmBudgetStore;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\Server;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

/**
 * 5.7.0 D6 (spec 5.6): deleting an account removes what 5.7.0 added about the learner — the
 * consent columns on the profile row, the chat memory including swarm_sourced, and the budget
 * rows, whose bucket names carry the user id. Budget rows are matched by their exact bucket
 * names: '_' is a LIKE wildcard and legal in a uid, and a long uid is stored hashed.
 * Runs on PostgreSQL and MariaDB (scripts/nc-integration.sh).
 */
final class UserDeletedListenerTest extends TestCase {
    private const GONE = 'd6_gone';
    private const GONE_LONG_PREFIX = 'd6-long-';
    /** differs from GONE only where GONE has the LIKE wildcard '_' */
    private const NEIGHBOUR = 'd6xgone';

    private IDBConnection $db;
    private LlmBudget $budget;
    private string $goneLong;
    private int $courseId = 0;
    private int $poolId = 0;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->budget = Server::get(LlmBudget::class);
        $this->goneLong = self::GONE_LONG_PREFIX . str_repeat('x', 50); // 58 chars: hashed in the budget (> 53), fits user_id (64)
        $this->cleanUp();
    }

    protected function tearDown(): void {
        $this->cleanUp();
    }

    public function testDeletingAnAccountRemovesConsentMemoryAndBudgetRowsOfThatUserOnly(): void {
        $uids = [self::GONE, $this->goneLong, self::NEIGHBOUR];
        foreach ($uids as $uid) {
            $this->budget->release($this->budget->reserve($uid, LlmPurpose::Chat));
            $this->insertConsentRow($uid);
            $this->insertMemory($uid);
        }
        foreach ($uids as $uid) {
            $this->assertGreaterThan(0, $this->budgetRows($uid), "setup: $uid has budget rows");
        }

        $listener = new UserDeletedListener($this->db);
        $listener->handle(new UserDeletedEvent($this->user(self::GONE)));
        $listener->handle(new UserDeletedEvent($this->user($this->goneLong)));

        foreach ([self::GONE, $this->goneLong] as $uid) {
            $this->assertSame(0, $this->budgetRows($uid), "budget rows of $uid are gone");
            $this->assertSame(0, $this->rows('learning_user_telos', $uid), "profile row (consent columns) of $uid is gone");
            $this->assertSame(0, $this->rows('learning_ai_chat_memory', $uid), "chat memory of $uid is gone");
        }
        $this->assertGreaterThan(0, $this->budgetRows(self::NEIGHBOUR), 'a uid that differs only at a LIKE wildcard keeps its budget rows');
        $this->assertSame(1, $this->rows('learning_user_telos', self::NEIGHBOUR));
        $this->assertSame(1, $this->rows('learning_ai_chat_memory', self::NEIGHBOUR));
    }

    /**
     * Audit #19/#49: kudos, video progress, analytics, pool shares, team-lead scopes, the feed
     * actor and mandatory assignments of a deleted account. Before the fix every row survived,
     * and a new account with the same uid inherited the shares, scopes and assignments.
     */
    public function testDeletingAnAccountLeavesNoRowsAndNoRightsForAReusedUid(): void {
        // Real parents: learning_pool_shares has a foreign key on the pool.
        $course = new Course();
        $course->setTitle('Audit 19');
        $course->setInstructorId('d6-third');
        $course->setStatus('active');
        $course->setCreatedAt(1);
        $course->setUpdatedAt(1);
        $this->courseId = (int)Server::get(CourseMapper::class)->insert($course)->getId();
        $pool = new Pool();
        $pool->setUserId('d6-third');
        $pool->setName('Audit 19');
        $pool->setCreatedAt(1);
        $pool->setUpdatedAt(1);
        $this->poolId = (int)Server::get(PoolMapper::class)->insert($pool)->getId();

        foreach ([self::GONE, self::NEIGHBOUR] as $uid) {
            $this->seedPersonalRows($uid);
        }

        (new UserDeletedListener($this->db))->handle(new UserDeletedEvent($this->user(self::GONE)));

        $this->assertSame(0, $this->countRows('learning_kudos', 'from_user', self::GONE), 'kudos sent');
        $this->assertSame(0, $this->countRows('learning_kudos', 'to_user', self::GONE), 'kudos received');
        $this->assertSame(0, $this->countRows('learning_video_progress', 'user_id', self::GONE));
        $this->assertSame(0, $this->countRows('learning_analytics', 'user_id', self::GONE));
        $this->assertSame(0, $this->countRows('learning_pool_shares', 'shared_with', self::GONE), 'a reused uid inherits no pool');
        $this->assertSame(0, $this->countRows('learning_pool_shares', 'created_by', self::GONE));
        $this->assertSame(0, $this->countRows('learning_oversight', 'lead_user_id', self::GONE), 'a reused uid inherits no team scope');
        $this->assertSame(0, $this->countRows('learning_feed_items', 'actor_id', self::GONE));
        $this->assertSame(2, $this->countRows('learning_feed_items', 'course_id', $this->courseId), 'the feed items themselves stay');
        $this->assertSame(0, $this->countRows('learning_assignments', 'subject_id', self::GONE), 'a reused uid inherits no assignment');
        $this->assertSame(0, $this->countRows('learning_assignments', 'active_period_key', $this->courseId . ':user:' . self::GONE));
        $this->assertSame(1, $this->countNullSubjectAssignments(), 'the assignment stays as pseudonymised evidence');
        $this->assertSame(1, $this->countRows('learning_pool_shares', 'shared_with', 'group-' . self::GONE), 'a group share named like the uid stays');

        $this->assertSame(1, $this->countRows('learning_kudos', 'from_user', self::NEIGHBOUR));
        $this->assertSame(1, $this->countRows('learning_video_progress', 'user_id', self::NEIGHBOUR));
        $this->assertSame(1, $this->countRows('learning_analytics', 'user_id', self::NEIGHBOUR));
        $this->assertSame(1, $this->countRows('learning_pool_shares', 'shared_with', self::NEIGHBOUR));
        $this->assertSame(1, $this->countRows('learning_oversight', 'lead_user_id', self::NEIGHBOUR));
        $this->assertSame(1, $this->countRows('learning_feed_items', 'actor_id', self::NEIGHBOUR));
        $this->assertSame(1, $this->countRows('learning_assignments', 'subject_id', self::NEIGHBOUR));
    }

    private function seedPersonalRows(string $uid): void {
        $other = $uid === self::GONE ? self::NEIGHBOUR : self::GONE;
        $this->insert('learning_kudos', ['course_id' => $this->courseId, 'from_user' => $uid, 'to_user' => 'd6-third', 'message' => 'Danke', 'created_at' => 1]);
        $this->insert('learning_video_progress', ['user_id' => $uid, 'content_id' => 990019, 'completed_at' => 1]);
        $this->insert('learning_analytics', ['user_id' => $uid, 'metric_type' => 'd6', 'metric_value' => '1']);
        $this->insert('learning_pool_shares', ['pool_id' => $this->poolId, 'shared_with' => $uid, 'share_type' => 'user', 'permission' => 'edit', 'created_by' => $other]);
        $this->insert('learning_oversight', ['course_id' => $this->courseId, 'lead_user_id' => $uid, 'scope_group_id' => 'd6-team', 'created_at' => 1]);
        $this->insert('learning_feed_items', ['course_id' => $this->courseId, 'type' => 'd6', 'title' => 'x', 'actor_id' => $uid, 'created_at' => 1]);
        $this->insert('learning_assignments', ['course_id' => $this->courseId, 'subject_type' => 'user', 'subject_id' => $uid, 'assigned_at' => 1, 'status' => 'assigned', 'active_period_key' => $this->courseId . ':user:' . $uid]);
        if ($uid === self::GONE) {
            $this->insert('learning_kudos', ['course_id' => $this->courseId, 'from_user' => 'd6-third', 'to_user' => $uid, 'message' => 'Gut', 'created_at' => 1]);
            $this->insert('learning_pool_shares', ['pool_id' => $this->poolId, 'shared_with' => 'group-' . $uid, 'share_type' => 'group', 'permission' => 'read']);
        }
    }

    /** @param array<string, string|int> $values */
    private function insert(string $table, array $values): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert($table);
        foreach ($values as $column => $value) {
            $qb->setValue($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
        }
        $qb->executeStatement();
    }

    private function countRows(string $table, string $column, string|int $value): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))->from($table)
            ->where($qb->expr()->eq($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
        $result = $qb->executeQuery();
        $n = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }

    private function countNullSubjectAssignments(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))->from('learning_assignments')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('subject_id'));
        $result = $qb->executeQuery();
        $n = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }

    private function user(string $uid): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    private function insertConsentRow(string $uid): void {
        $row = new UserTelos();
        $row->setUserId($uid);
        $row->setCreatedAt(time());
        $row->setUpdatedAt(time());
        $row->setAiConsentVersion('3.0');
        $row->setAiConsentFingerprint(str_repeat('c', 64));
        $row->setAiConsentSource('dialog');
        $row->setAiConsentAt(time());
        Server::get(UserTelosMapper::class)->insert($row);
    }

    private function insertMemory(string $uid): void {
        $m = new AiChatMemory();
        $m->setUserId($uid);
        $m->setRole('summary');
        $m->setMessage('Zusammenfassung');
        $m->setCreatedAt(time());
        $m->setSwarmSourced(0);
        Server::get(AiChatMemoryMapper::class)->insert($m);
    }

    /**
     * Rows of the budget table that belong to $uid: every bucket that starts with the user's
     * prefix, whatever suffix reserve() uses (compared in PHP, so '_' is no wildcard here).
     */
    private function budgetRows(string $uid): int {
        $prefix = LlmBudget::userBucket($uid, '');
        $qb = $this->db->getQueryBuilder();
        $qb->select('bucket')->from(LlmBudgetStore::TABLE);
        $result = $qb->executeQuery();
        $n = 0;
        while (($bucket = $result->fetchOne()) !== false) {
            if (str_starts_with((string)$bucket, $prefix)) {
                $n++;
            }
        }
        $result->closeCursor();
        return $n;
    }

    private function rows(string $table, string $uid): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))->from($table)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        $result = $qb->executeQuery();
        $n = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }

    private function cleanUp(): void {
        foreach (['learning_kudos', 'learning_oversight', 'learning_feed_items', 'learning_assignments'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('course_id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))->executeStatement();
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_pool_shares')->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT)))->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_pools')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT)))->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_courses')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_video_progress')->where($qb->expr()->eq('content_id', $qb->createNamedParameter(990019, IQueryBuilder::PARAM_INT)))->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_analytics')->where($qb->expr()->eq('metric_type', $qb->createNamedParameter('d6')))->executeStatement();
        foreach ([self::GONE, $this->goneLong, self::NEIGHBOUR] as $uid) {
            foreach (['learning_user_telos', 'learning_ai_chat_memory'] as $table) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeStatement();
            }
            $buckets = array_map(static fn (string $s): string => LlmBudget::userBucket($uid, $s), ['d', 'm', 'inflight']);
            $qb = $this->db->getQueryBuilder();
            $qb->delete(LlmBudgetStore::TABLE)
                ->where($qb->expr()->in('bucket', $qb->createNamedParameter($buckets, IQueryBuilder::PARAM_STR_ARRAY)))
                ->executeStatement();
        }
    }
}
