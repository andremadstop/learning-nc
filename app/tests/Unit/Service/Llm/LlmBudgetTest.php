<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

use OCA\Learning\Service\Llm\LlmBudget;
use OCA\Learning\Service\Llm\LlmBudgetStore;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * In-memory stand-in for the DB store. Only the budget policy is tested here (which buckets,
 * which limits, compensation, release); the atomic SQL itself runs against a real database in
 * tests/Integration/LlmBudgetConcurrencyTest.php.
 */
final class InMemoryBudgetStore extends LlmBudgetStore {
    /** @var array<string, int> "bucket|window" => count */
    public array $counts = [];
    /** @var array<string, array{inflight:int, updated:int}> */
    public array $slots = [];
    /** @var list<string> */
    public array $calls = [];

    public function tryIncrementCount(string $bucket, int $windowStart, int $limit, int $now): bool {
        $this->calls[] = "inc {$bucket}";
        $k = $bucket . '|' . $windowStart;
        $current = $this->counts[$k] ?? 0;
        if ($current >= $limit) {
            return false;
        }
        $this->counts[$k] = $current + 1;
        return true;
    }

    public function decrementCount(string $bucket, int $windowStart): void {
        $this->calls[] = "dec {$bucket}";
        $k = $bucket . '|' . $windowStart;
        if (($this->counts[$k] ?? 0) > 0) {
            $this->counts[$k]--;
        }
    }

    public function tryAcquireSlot(string $bucket, int $max, int $now, int $ttl): bool {
        $this->calls[] = "acquire {$bucket}";
        $row = $this->slots[$bucket] ?? ['inflight' => 0, 'updated' => $now];
        $expired = $row['updated'] < $now - $ttl;
        if (!$expired && $row['inflight'] >= $max) {
            return false;
        }
        $this->slots[$bucket] = ['inflight' => $expired ? 1 : $row['inflight'] + 1, 'updated' => $now];
        return true;
    }

    public function releaseSlot(string $bucket): void {
        $this->calls[] = "release {$bucket}";
        if (($this->slots[$bucket]['inflight'] ?? 0) > 0) {
            $this->slots[$bucket]['inflight']--;
        }
    }

    public function count(string $bucket, int $windowStart): int {
        return $this->counts[$bucket . '|' . $windowStart] ?? 0;
    }
}

/**
 * 5.7.0 Task B2 (spec 5.1): one atomic, DB-backed budget for every model call.
 * Per user 10/minute, 100/day and at most 3 concurrent calls; per instance a daily limit
 * (appconfig ai_global_daily_limit, default 5000). admin_test counts only on the global bucket.
 */
class LlmBudgetTest extends TestCase {
    private const NOW = 1_800_000_030; // 30 s into a minute

    private InMemoryBudgetStore $store;
    private int $now = self::NOW;
    private string $globalLimit = '';

    protected function setUp(): void {
        $this->store = new InMemoryBudgetStore($this->createMock(IDBConnection::class));
    }

    private function budget(): LlmBudget {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn () => $this->now);
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $app === 'learning' && $key === 'ai_global_daily_limit' ? $this->globalLimit : $default,
        );
        return new LlmBudget($this->store, $config, $time);
    }

    private function assertRateLimited(callable $fn): void {
        try {
            $fn();
            $this->fail('expected rate_limit');
        } catch (LlmProviderException $e) {
            $this->assertSame('rate_limit', $e->category);
        }
    }

    public function testMinuteLimitHitsExactlyAtTheEleventhCall(): void {
        $budget = $this->budget();
        for ($i = 0; $i < LlmBudget::USER_PER_MINUTE; $i++) {
            $budget->release($budget->reserve('alice', LlmPurpose::Chat));
        }
        $this->assertSame(10, LlmBudget::USER_PER_MINUTE);
        $this->assertRateLimited(fn () => $budget->reserve('alice', LlmPurpose::Chat));

        // other users are not affected, and the next minute is a new window
        $budget->release($budget->reserve('bob', LlmPurpose::Chat));
        $this->now += 60;
        $budget->release($budget->reserve('alice', LlmPurpose::Chat));
    }

    public function testDayLimitHitsExactlyAtTheHundredFirstCall(): void {
        $budget = $this->budget();
        for ($i = 0; $i < LlmBudget::USER_PER_DAY; $i++) {
            if ($i > 0 && $i % LlmBudget::USER_PER_MINUTE === 0) {
                $this->now += 60;
            }
            $budget->release($budget->reserve('alice', LlmPurpose::Chat));
        }
        $this->assertSame(100, LlmBudget::USER_PER_DAY);
        $this->now += 60;
        $this->assertRateLimited(fn () => $budget->reserve('alice', LlmPurpose::Chat));
    }

    public function testGlobalDailyLimitComesFromAppConfig(): void {
        $this->globalLimit = '3';
        $budget = $this->budget();
        $budget->release($budget->reserve('a', LlmPurpose::Chat));
        $budget->release($budget->reserve('b', LlmPurpose::Note));
        $budget->release($budget->reserve(null, LlmPurpose::AdminTest));
        $this->assertRateLimited(fn () => $budget->reserve('c', LlmPurpose::Chat));
    }

    /** @return array<string, array{string}> */
    public static function invalidLimits(): array {
        return ['unset' => [''], 'zero' => ['0'], 'negative' => ['-5'], 'text' => ['many'], 'float' => ['2.5']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLimits')]
    public function testInvalidGlobalLimitFallsBackToTheDefault(string $value): void {
        $this->globalLimit = $value;
        $budget = $this->budget();
        $day = intdiv(self::NOW, 86400) * 86400;
        $this->store->counts['g:d|' . $day] = 4999;
        $budget->release($budget->reserve('a', LlmPurpose::Chat));
        $this->assertSame(5000, $this->store->count('g:d', $day));
        $this->assertRateLimited(fn () => $budget->reserve('b', LlmPurpose::Chat));
        $this->assertSame(5000, LlmBudget::GLOBAL_PER_DAY_DEFAULT);
    }

    public function testAtMostThreeConcurrentCallsPerUserAndReleaseFreesASlot(): void {
        $budget = $this->budget();
        $t1 = $budget->reserve('alice', LlmPurpose::Chat);
        $budget->reserve('alice', LlmPurpose::Chat);
        $budget->reserve('alice', LlmPurpose::Chat);
        $this->assertRateLimited(fn () => $budget->reserve('alice', LlmPurpose::Chat));

        $budget->release($t1);
        $budget->reserve('alice', LlmPurpose::Chat);
    }

    public function testOrphanedSlotsExpireAfterTheTtl(): void {
        $budget = $this->budget();
        for ($i = 0; $i < 3; $i++) {
            $budget->reserve('alice', LlmPurpose::Chat); // never released (crashed request)
        }
        $this->now += LlmBudget::INFLIGHT_TTL;
        $this->assertRateLimited(fn () => $budget->reserve('alice', LlmPurpose::Chat));
        $this->now += 1;
        $budget->reserve('alice', LlmPurpose::Chat);
        $this->assertSame(120, LlmBudget::INFLIGHT_TTL);
    }

    public function testReleaseTwiceFreesOnlyOneSlot(): void {
        $budget = $this->budget();
        $t = $budget->reserve('alice', LlmPurpose::Chat);
        $budget->reserve('alice', LlmPurpose::Chat);
        $budget->release($t);
        $budget->release($t);
        $this->assertSame(1, $this->store->slots[LlmBudget::userBucket('alice', 'inflight')]['inflight']);
    }

    public function testARejectedReservationGivesBackWhatItTook(): void {
        $budget = $this->budget();
        for ($i = 0; $i < 3; $i++) {
            $budget->reserve('alice', LlmPurpose::Chat);
        }
        $this->assertRateLimited(fn () => $budget->reserve('alice', LlmPurpose::Chat));

        // the fourth attempt incremented the counters before the slot failed and gave them back
        $minute = intdiv(self::NOW, 60) * 60;
        $day = intdiv(self::NOW, 86400) * 86400;
        $this->assertSame(3, $this->store->count(LlmBudget::userBucket('alice', 'm'), $minute));
        $this->assertSame(3, $this->store->count(LlmBudget::userBucket('alice', 'd'), $day));
        $this->assertSame(3, $this->store->count('g:d', $day));
    }

    public function testAdminTestCountsOnlyOnTheGlobalBucket(): void {
        $budget = $this->budget();
        for ($i = 0; $i < 20; $i++) {
            $budget->release($budget->reserve('admin', LlmPurpose::AdminTest));
        }
        $this->assertSame(['inc g:d'], array_values(array_unique($this->store->calls)));
    }

    public function testOnlyAdminTestMayRunWithoutAUser(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->budget()->reserve(null, LlmPurpose::Chat);
    }

    public function testBucketNamesFitTheColumnForTheLongestUserIds(): void {
        $uid = str_repeat('x', 64);
        foreach (['m', 'd', 'inflight'] as $suffix) {
            $this->assertLessThanOrEqual(64, strlen(LlmBudget::userBucket($uid, $suffix)));
        }
        $this->assertSame('u:alice:m', LlmBudget::userBucket('alice', 'm'));
        $this->assertNotSame(LlmBudget::userBucket($uid, 'm'), LlmBudget::userBucket(str_repeat('x', 63) . 'y', 'm'));
    }
}
