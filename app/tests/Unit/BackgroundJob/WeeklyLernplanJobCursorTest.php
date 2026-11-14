<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\BackgroundJob;

use OCA\Learning\BackgroundJob\WeeklyLernplanJob;
use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Tests\Support\FakeAppConfigValues;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Audit #7: getActiveUsers() took the alphabetically first 200 active users on every run, with no
 * cursor kept between runs. On an instance with more than 200 active learners, everyone after them
 * ('zoe') never got a weekly note. The job now continues after the last user of a full batch and
 * wraps to the start at the end of the list.
 */
#[AllowMockObjectsWithoutExpectations]
class WeeklyLernplanJobCursorTest extends TestCase {
    private FakeAppConfigValues $values;
    /** @var list<string> users the job processed, in order */
    private array $processed = [];

    private function job(FakeDbConnection $db): WeeklyLernplanJob {
        $this->values = $this->values ?? new FakeAppConfigValues();
        $appConfig = $this->createMock(IAppConfig::class);
        $this->values->wire($appConfig);
        $gateway = $this->createMock(LlmGateway::class);
        // every user is a consent skip: enough to see who was processed, nothing else runs
        $gateway->method('begin')->willReturnCallback(function (?string $uid): never {
            $this->processed[] = (string)$uid;
            throw new LlmConsentException();
        });
        return new WeeklyLernplanJob(
            $this->createMock(ITimeFactory::class),
            $db,
            $this->createMock(NoteGeneratorService::class),
            $this->createMock(LernprofilService::class),
            $this->createMock(LoggerInterface::class),
            $gateway,
            $appConfig
        );
    }

    /** @param list<string> $uids */
    private static function batch(array $uids): FakeQueryBuilder {
        return new FakeQueryBuilder(FakeResult::fromFetchAll(array_map(fn (string $u) => ['user_id' => $u], $uids)));
    }

    /** @return list<string> */
    private static function users(string $prefix, int $count): array {
        return array_map(fn (int $i) => sprintf('%s%03d', $prefix, $i), range(1, $count));
    }

    private function run_(WeeklyLernplanJob $job): void {
        (new \ReflectionMethod(WeeklyLernplanJob::class, 'run'))->invoke($job, null);
    }

    /** @return list<mixed> the gt() conditions the builder received */
    private static function gtConditions(FakeQueryBuilder $qb): array {
        return array_values(array_filter($qb->andWhereCalls, fn ($c) => is_array($c) && ($c['type'] ?? null) === 'gt'));
    }

    public function testTheNextRunContinuesAfterTheLastUserOfAFullBatch(): void {
        $first = self::batch(self::users('a', 200));
        $this->run_($this->job(new FakeDbConnection([$first])));
        $this->assertSame([], self::gtConditions($first), 'the first run starts at the beginning');
        $this->assertSame('a200', $this->values->get('learning', WeeklyLernplanJob::CURSOR_KEY));

        $this->processed = [];
        $second = self::batch(['zoe']);
        $this->run_($this->job(new FakeDbConnection([$second])));

        $gt = self::gtConditions($second);
        $this->assertCount(1, $gt, 'the second run filters on the cursor');
        $this->assertSame('user_id', $gt[0]['left']);
        $this->assertSame('a200', $gt[0]['right']['value']);
        $this->assertSame(['zoe'], $this->processed, 'the user after the first 200 gets a turn');
        $this->assertSame('', $this->values->get('learning', WeeklyLernplanJob::CURSOR_KEY), 'a short batch wraps to the start');
    }

    public function testAnEmptyBatchAfterTheCursorWrapsToTheStartInTheSameRun(): void {
        $this->values = new FakeAppConfigValues();
        $this->values->set('learning', WeeklyLernplanJob::CURSOR_KEY, 'a200');
        $empty = self::batch([]);
        $restart = self::batch(['a001', 'a002']);
        $this->run_($this->job(new FakeDbConnection([$empty, $restart])));

        $this->assertSame([], self::gtConditions($restart));
        $this->assertSame(['a001', 'a002'], $this->processed);
        $this->assertSame('', $this->values->get('learning', WeeklyLernplanJob::CURSOR_KEY));
    }
}
