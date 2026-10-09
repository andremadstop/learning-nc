<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\AiChatMemory;
use OCA\Learning\Db\AiChatMemoryMapper;
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
