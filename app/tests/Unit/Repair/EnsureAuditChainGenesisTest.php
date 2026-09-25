<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Repair;

use OCA\Learning\Repair\EnsureAuditChainGenesis;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The genesis row of the audit chain was never seeded on fresh installs (Version009300 seeds
 * it in postSchemaChange, which a fresh install skips), so every compliance event failed.
 *
 * The step must seed exactly where the chain never started, and nowhere else: a new genesis
 * next to any trace of an earlier chain would restart it at seq 1 and hide that its head was lost.
 *
 * The fake database below evaluates the conditions the step builds (column names, bound id)
 * against mutable state, so a wrong column, a dropped WHERE or a lost id binding changes the
 * answer instead of passing unnoticed.
 */
class EnsureAuditChainGenesisTest extends TestCase {
    /** @var array<int, true> ids present in learning_audit_chain_state */
    private array $stateIds = [];
    /** audit events with chain data (any of seq_num/user_ref/prev_hash/chain_hash set) */
    private int $chainedEvents = 0;
    /** audit events without chain data (plain AI audit rows) */
    private int $plainEvents = 0;
    private int $checkpoints = 0;
    private string $checkpointProgress = '';
    /** @var list<array<string, mixed>> */
    private array $inserts = [];
    /** Simulates a concurrent run: called instead of the insert, may add the row itself. */
    private ?\Closure $insertRace = null;

    private const CHAINED_FILTER = 'or(notnull:seq_num,notnull:user_ref,notnull:prev_hash,notnull:chain_hash)';

    private function step(): EnsureAuditChainGenesis {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = '') =>
                $app === 'learning' && $key === 'last_checkpoint_to_event_id' ? $this->checkpointProgress : $default
        );

        return new EnsureAuditChainGenesis($db, $config);
    }

    private function queryBuilder(): IQueryBuilder {
        $q = ['table' => null, 'where' => null, 'insert' => null, 'values' => []];
        $qb = $this->createMock(IQueryBuilder::class);

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(fn ($l, $r) => "eq:$l=" . var_export($r, true));
        $expr->method('isNotNull')->willReturnCallback(fn ($c) => "notnull:$c");
        $expr->method('orX')->willReturnCallback(fn (...$c) => 'or(' . implode(',', $c) . ')');
        $func = $this->createMock(IFunctionBuilder::class);
        $func->method('count')->willReturn($this->createMock(IQueryFunction::class));

        $qb->method('expr')->willReturn($expr);
        $qb->method('func')->willReturn($func);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('select')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('from')->willReturnCallback(function ($t) use ($qb, &$q) { $q['table'] = $t; return $qb; });
        $qb->method('where')->willReturnCallback(function ($w) use ($qb, &$q) { $q['where'] = $w; return $qb; });
        $qb->method('insert')->willReturnCallback(function ($t) use ($qb, &$q) { $q['insert'] = $t; return $qb; });
        $qb->method('values')->willReturnCallback(function ($v) use ($qb, &$q) { $q['values'] = $v; return $qb; });

        $qb->method('executeStatement')->willReturnCallback(function () use (&$q) {
            $this->assertSame('learning_audit_chain_state', $q['insert']);
            if ($this->insertRace !== null) {
                ($this->insertRace)();
            }
            $id = $q['values']['id'] ?? null;
            if (is_int($id) && isset($this->stateIds[$id])) {
                throw new \OCP\DB\Exception(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, 'duplicate key');
            }
            $this->inserts[] = $q['values'];
            if (is_int($id)) {
                $this->stateIds[$id] = true;
            }
            return 1;
        });

        $qb->method('executeQuery')->willReturnCallback(function () use (&$q) {
            $result = $this->createMock(IResult::class);
            [$table, $where] = [$q['table'], $q['where']];
            if ($table === 'learning_audit_chain_state' && $where === 'eq:id=1') {
                $result->method('fetch')->willReturn(isset($this->stateIds[1]) ? ['id' => 1] : false);
                return $result;
            }
            $n = match (true) {
                $table === 'learning_audit_chain_state' && $where === null => count($this->stateIds),
                $table === 'learning_audit_events' && $where === self::CHAINED_FILTER => $this->chainedEvents,
                $table === 'learning_audit_events' && $where === null => $this->chainedEvents + $this->plainEvents,
                $table === 'learning_audit_checkpoints' && $where === null => $this->checkpoints,
                default => throw new \LogicException('unexpected query on ' . var_export($table, true) . ' where ' . var_export($where, true)),
            };
            $result->method('fetchOne')->willReturn((string)$n);
            return $result;
        });
        return $qb;
    }

    private function expectOutput(int $warnings, ?string $containing = null): IOutput {
        $output = $this->createMock(IOutput::class);
        $expect = $output->expects($this->exactly($warnings))->method('warning');
        if ($containing !== null) {
            $expect->with($this->stringContains($containing));
        }
        return $output;
    }

    public function testSeedsGenesisWhereTheChainNeverStarted(): void {
        // plain AI audit rows are not a chain: they must not block the seed
        $this->plainEvents = 5;

        $this->step()->run($this->expectOutput(0));

        // id=1 explicitly: AuditService reads the chain head from exactly that row.
        $this->assertSame([['id' => 1, 'last_seq' => 0, 'last_hash' => str_repeat('0', 64)]], $this->inserts);
    }

    public function testSecondRunIsANoOp(): void {
        $this->step()->run($this->expectOutput(0));
        $this->chainedEvents = 3; // the chain moved on after the first run

        $this->step()->run($this->expectOutput(0));

        $this->assertCount(1, $this->inserts, 'a repeated repair must not seed again');
    }

    public function testDoesNotRestartAChainWhoseEventsSurvive(): void {
        $this->chainedEvents = 3;

        $this->step()->run($this->expectOutput(1, '3 audit event(s) carry chain data'));

        $this->assertSame([], $this->inserts, 'a new genesis would restart an existing chain');
    }

    public function testDoesNotRestartAChainWhoseCheckpointsSurvive(): void {
        $this->checkpoints = 2;

        $this->step()->run($this->expectOutput(1, '2 signed checkpoint(s)'));

        $this->assertSame([], $this->inserts);
    }

    public function testDoesNotRestartAChainWithRecordedCheckpointProgress(): void {
        $this->checkpointProgress = '42';

        $this->step()->run($this->expectOutput(1, 'last_checkpoint_to_event_id'));

        $this->assertSame([], $this->inserts);
    }

    public function testDoesNotSeedNextToAStateRowWithAnotherId(): void {
        $this->stateIds = [7 => true];

        $this->step()->run($this->expectOutput(1, 'another id'));

        $this->assertSame([], $this->inserts);
    }

    public function testConcurrentSeedIsAcceptedNotDuplicated(): void {
        // the other run inserts the head between our check and our insert
        $this->insertRace = function (): void { $this->stateIds[1] = true; };

        $this->step()->run($this->expectOutput(0));

        $this->assertSame([], $this->inserts, 'the losing run must not insert a second head');
    }

    public function testOtherInsertErrorsPropagate(): void {
        // duplicate-key reported, but no head row exists afterwards: not a race, a real failure
        $this->insertRace = function (): void {
            throw new \OCP\DB\Exception(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, 'duplicate key');
        };

        $this->expectException(\OCP\DB\Exception::class);
        $this->step()->run($this->expectOutput(0));
    }
}
