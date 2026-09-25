<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Support;

use OCP\ICacheFactory;
use OCP\IDBConnection;

final class FakeResult {
    private array $fetchQueue;
    private array $fetchAllRows;
    private mixed $fetchOneValue;

    public function __construct(array $fetchQueue = [], array $fetchAllRows = [], mixed $fetchOneValue = null) {
        $this->fetchQueue = $fetchQueue;
        $this->fetchAllRows = $fetchAllRows;
        $this->fetchOneValue = $fetchOneValue;
    }

    public static function fromFetch(array|false $row): self {
        return new self($row === false ? [] : [$row]);
    }

    public static function fromFetchAll(array $rows): self {
        return new self([], $rows);
    }

    public static function fromFetchOne(mixed $value): self {
        return new self([], [], $value);
    }

    public function fetch(): array|false {
        if ($this->fetchQueue === []) {
            return false;
        }

        return array_shift($this->fetchQueue);
    }

    public function fetchAll(): array {
        if ($this->fetchAllRows !== []) {
            return $this->fetchAllRows;
        }

        return $this->fetchQueue;
    }

    public function fetchOne(): mixed {
        if ($this->fetchOneValue !== null) {
            return $this->fetchOneValue;
        }

        $row = $this->fetch();
        if (is_array($row)) {
            return reset($row);
        }

        return $row;
    }

    public function closeCursor(): void {
    }
}

final class FakeExpressionBuilder {
    public function eq(mixed $left, mixed $right): array {
        return ['type' => 'eq', 'left' => $left, 'right' => $right];
    }

    public function neq(mixed $left, mixed $right): array {
        return ['type' => 'neq', 'left' => $left, 'right' => $right];
    }

    public function lt(mixed $left, mixed $right): array {
        return ['type' => 'lt', 'left' => $left, 'right' => $right];
    }

    public function lte(mixed $left, mixed $right): array {
        return ['type' => 'lte', 'left' => $left, 'right' => $right];
    }

    public function gte(mixed $left, mixed $right): array {
        return ['type' => 'gte', 'left' => $left, 'right' => $right];
    }

    public function gt(mixed $left, mixed $right): array {
        return ['type' => 'gt', 'left' => $left, 'right' => $right];
    }

    public function in(mixed $left, mixed $right): array {
        return ['type' => 'in', 'left' => $left, 'right' => $right];
    }

    public function like(mixed $left, mixed $right): array {
        return ['type' => 'like', 'left' => $left, 'right' => $right];
    }

    public function iLike(mixed $left, mixed $right): array {
        return ['type' => 'iLike', 'left' => $left, 'right' => $right];
    }

    public function isNull(string $field): array {
        return ['type' => 'isNull', 'field' => $field];
    }

    public function isNotNull(string $field): array {
        return ['type' => 'isNotNull', 'field' => $field];
    }

    public function andX(mixed ...$conditions): array {
        return ['type' => 'andX', 'conditions' => $conditions];
    }

    public function orX(mixed ...$conditions): array {
        return ['type' => 'orX', 'conditions' => $conditions];
    }
}

class FakeQueryBuilder {
    public array $selects = [];
    public array $from = [];
    public array $joins = [];
    public array $whereCalls = [];
    public array $andWhereCalls = [];
    public array $orWhereCalls = [];
    public array $groupBys = [];
    public array $orderBys = [];
    public ?int $maxResults = null;
    public ?int $firstResult = null;
    public ?string $operation = null;
    public ?string $table = null;
    public array $insertValues = [];
    public array $setCalls = [];
    public array $namedParameters = [];

    private FakeResult $result;
    private int $executeStatementResult;
    private int $lastInsertId;
    private FakeExpressionBuilder $expressionBuilder;

    public function __construct(?FakeResult $result = null, int $executeStatementResult = 0, int $lastInsertId = 0) {
        $this->result = $result ?? new FakeResult();
        $this->executeStatementResult = $executeStatementResult;
        $this->lastInsertId = $lastInsertId;
        $this->expressionBuilder = new FakeExpressionBuilder();
    }

    public function expr(): FakeExpressionBuilder {
        return $this->expressionBuilder;
    }

    public function createFunction(string $expression): string {
        return $expression;
    }

    public function createNamedParameter(mixed $value, mixed $type = null): array {
        $parameter = ['value' => $value, 'type' => $type];
        $this->namedParameters[] = $parameter;
        return $parameter;
    }

    public function select(mixed ...$fields): self {
        $this->selects = array_merge($this->selects, $fields);
        return $this;
    }

    public function selectAlias(mixed $select, string $alias): self {
        return $this->select($select . ' AS ' . $alias);
    }

    public function selectDistinct(mixed ...$fields): self {
        return $this->select(...$fields);
    }

    public function addSelect(mixed ...$fields): self {
        return $this->select(...$fields);
    }

    public function from(string $table, ?string $alias = null): self {
        $this->from = ['table' => $table, 'alias' => $alias];
        return $this;
    }

    public function innerJoin(string $fromAlias, string $join, string $alias, mixed $condition): self {
        $this->joins[] = ['type' => 'inner', 'from' => $fromAlias, 'join' => $join, 'alias' => $alias, 'condition' => $condition];
        return $this;
    }

    public function leftJoin(string $fromAlias, string $join, string $alias, mixed $condition): self {
        $this->joins[] = ['type' => 'left', 'from' => $fromAlias, 'join' => $join, 'alias' => $alias, 'condition' => $condition];
        return $this;
    }

    public function join(string $fromAlias, string $join, string $alias, mixed $condition): self {
        $this->joins[] = ['type' => 'join', 'from' => $fromAlias, 'join' => $join, 'alias' => $alias, 'condition' => $condition];
        return $this;
    }

    public function where(mixed $condition): self {
        $this->whereCalls[] = $condition;
        return $this;
    }

    public function andWhere(mixed $condition): self {
        $this->andWhereCalls[] = $condition;
        return $this;
    }

    public function orWhere(mixed $condition): self {
        $this->orWhereCalls[] = $condition;
        return $this;
    }

    public function groupBy(string ...$fields): self {
        $this->groupBys = array_merge($this->groupBys, $fields);
        return $this;
    }

    public function addGroupBy(string $field): self {
        $this->groupBys[] = $field;
        return $this;
    }

    public function orderBy(string $field, ?string $direction = null): self {
        $this->orderBys[] = ['field' => $field, 'direction' => $direction];
        return $this;
    }

    public function addOrderBy(string $field, ?string $direction = null): self {
        return $this->orderBy($field, $direction);
    }

    public function setFirstResult(int $offset): self {
        $this->firstResult = $offset;
        return $this;
    }

    public function setMaxResults(int $limit): self {
        $this->maxResults = $limit;
        return $this;
    }

    public function insert(string $table): self {
        $this->operation = 'insert';
        $this->table = $table;
        return $this;
    }

    public function update(string $table): self {
        $this->operation = 'update';
        $this->table = $table;
        return $this;
    }

    public function delete(string $table): self {
        $this->operation = 'delete';
        $this->table = $table;
        return $this;
    }

    public function values(array $values): self {
        $this->insertValues = $values;
        return $this;
    }

    public function set(string $field, mixed $value): self {
        $this->setCalls[$field] = $value;
        return $this;
    }

    public function execute(): FakeResult {
        return $this->result;
    }

    public function executeQuery(): FakeResult {
        return $this->result;
    }

    public function executeStatement(): int {
        return $this->executeStatementResult;
    }

    public function getLastInsertId(): int {
        return $this->lastInsertId;
    }
}

final class FakeDbConnection implements IDBConnection {
    /** @var list<FakeQueryBuilder> */
    private array $queuedBuilders;

    /** @var list<FakeResult> */
    private array $rawResults;

    /** @var list<FakeQueryBuilder> */
    public array $issuedBuilders = [];

    public array $executedQueries = [];
    public array $executedStatements = [];

    // Phase 160: transaction call counters for logComplianceEvent CAS tests.
    public int $beginTransactionCalls = 0;
    public int $commitCalls = 0;
    public int $rollBackCalls = 0;
    private bool $inTx = false;

    public function __construct(array $queuedBuilders = [], array $rawResults = []) {
        $this->queuedBuilders = $queuedBuilders;
        $this->rawResults = $rawResults;
    }

    public function getQueryBuilder(): FakeQueryBuilder {
        $builder = array_shift($this->queuedBuilders) ?? new FakeQueryBuilder();
        $this->issuedBuilders[] = $builder;
        return $builder;
    }

    public function executeQuery(string $sql, array $params = []): FakeResult {
        $this->executedQueries[] = ['sql' => $sql, 'params' => $params];
        return array_shift($this->rawResults) ?? new FakeResult();
    }

    public function executeStatement(string $sql, array $params = []): int {
        $this->executedStatements[] = ['sql' => $sql, 'params' => $params];
        return 1;
    }

    public function escapeLikeParameter(string $input): string {
        return addcslashes($input, '\\%_');
    }

    public function beginTransaction(): void {
        $this->beginTransactionCalls++;
        $this->inTx = true;
    }

    public function commit(): void {
        $this->commitCalls++;
        $this->inTx = false;
    }

    public function rollBack(): void {
        $this->rollBackCalls++;
        $this->inTx = false;
    }

    public function inTransaction(): bool {
        return $this->inTx;
    }
}

final class FakeCache {
    public array $removedKeys = [];

    public function remove(string $key): void {
        $this->removedKeys[] = $key;
    }
}

final class FakeCacheFactory implements ICacheFactory {
    public FakeCache $cache;

    public function __construct() {
        $this->cache = new FakeCache();
    }

    public function createDistributed(string $appName): FakeCache {
        return $this->cache;
    }
}

/**
 * In-memory backing store for a mocked OCP\IAppConfig (5.6.0 Task A2).
 *
 * Deliberately not a class implementing IAppConfig: the stub interface is a subset, and a
 * class implementing it would break wherever the full interface is loaded. Tests create the
 * mock with createMock(IAppConfig::class) and hand it to wire().
 *
 * Mirrors real AppConfig rules that a plain array would hide: keys longer than 64 characters
 * are rejected (lib/private/AppConfig.php KEY_MAX_LENGTH), the sensitive flag is recorded, and
 * there are two layers like in Nextcloud: $values is what is persisted, the process cache is
 * loaded from it on first access and only reloaded after clearCache(). A write updates the
 * cache and, unless its key is in $lostWrites, the persisted value.
 */
final class FakeAppConfigValues {
    public const KEY_MAX_LENGTH = 64;

    /** @var array<string, string> persisted, "app\0key" => value */
    public array $values = [];
    /** @var array<string, bool> */
    public array $sensitive = [];
    public int $clearCacheCalls = 0;
    public int $setCalls = 0;
    /** @var null|callable(string, string): ?string  override for getValueString; may throw */
    public $readOverride = null;
    /** @var list<string> keys whose writes reach the process cache but are never persisted */
    public array $lostWrites = [];
    /** @var array<string, string>|null process cache, null = not loaded */
    private ?array $cache = null;

    public function wire(\PHPUnit\Framework\MockObject\MockObject $mock): void {
        $mock->method('hasKey')->willReturnCallback(
            fn (string $app, string $key, ?bool $lazy = false): bool => array_key_exists($app . "\0" . $key, $this->cache()));
        $mock->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = '', bool $lazy = false): string {
                if ($this->readOverride !== null) {
                    $o = ($this->readOverride)($app, $key);
                    if ($o !== null) {
                        return $o;
                    }
                }
                return $this->cache()[$app . "\0" . $key] ?? $default;
            });
        $mock->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
                if (strlen($key) > self::KEY_MAX_LENGTH) {
                    throw new \InvalidArgumentException('Value (' . $key . ') for key is too long (' . self::KEY_MAX_LENGTH . ')');
                }
                $this->setCalls++;
                $k = $app . "\0" . $key;
                $this->cache();
                $changed = ($this->cache[$k] ?? null) !== $value;
                $this->cache[$k] = $value;
                if (!in_array($key, $this->lostWrites, true)) {
                    $this->values[$k] = $value;
                    $this->sensitive[$k] = $sensitive;
                }
                return $changed;
            });
        $mock->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
            $this->cache();
            unset($this->cache[$app . "\0" . $key], $this->values[$app . "\0" . $key], $this->sensitive[$app . "\0" . $key]);
        });
        $mock->method('clearCache')->willReturnCallback(function (bool $reload = false): void {
            $this->clearCacheCalls++;
            $this->cache = null;
        });
    }

    /** @return array<string, string> */
    private function cache(): array {
        if ($this->cache === null) {
            $this->cache = $this->values;
        }
        return $this->cache;
    }

    public function has(string $app, string $key): bool {
        return array_key_exists($app . "\0" . $key, $this->values);
    }

    public function get(string $app, string $key): ?string {
        return $this->values[$app . "\0" . $key] ?? null;
    }

    /** Test setup: persists the value and updates an already loaded cache. */
    public function set(string $app, string $key, string $value): void {
        $this->values[$app . "\0" . $key] = $value;
        if ($this->cache !== null) {
            $this->cache[$app . "\0" . $key] = $value;
        }
    }

    /** Another process writes: only the persisted value changes, this process keeps its cache. */
    public function setFromOtherProcess(string $app, string $key, string $value): void {
        $this->cache();
        $this->values[$app . "\0" . $key] = $value;
    }

    /** @return list<string> keys of $app */
    public function keys(string $app): array {
        $out = [];
        foreach (array_keys($this->values) as $k) {
            [$a, $key] = explode("\0", $k, 2);
            if ($a === $app) {
                $out[] = $key;
            }
        }
        return $out;
    }
}
