<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\UserStateController;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\MissionService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Daily challenge question selection (audit #13, #14, #146).
 *
 * The pick has to leave out PBQs (no answer rows, so the card could never be completed), skip pools
 * the user lost access to, and stay pinned for the day so POST grades the question GET showed.
 */
#[AllowMockObjectsWithoutExpectations]
class DailyChallengeSelectionTest extends TestCase {

    /** @var array<string, string> */
    private array $userValues = [];

    private function controller(FakeDbConnection $db, ?QuestionService $questions = null): UserStateController {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnArgument(2);
        $config->method('getUserValue')->willReturnCallback(
            fn(string $uid, string $app, string $key, $default = '') => $this->userValues[$key] ?? $default
        );
        $config->method('setUserValue')->willReturnCallback(
            function (string $uid, string $app, string $key, $value): void { $this->userValues[$key] = (string)$value; }
        );
        if ($questions === null) {
            $questions = $this->createMock(QuestionService::class);
            $questions->method('hasPoolAccess')->willReturn(true);
        }
        return new UserStateController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(XpService::class),
            $this->createMock(MissionService::class),
            $db,
            $this->createMock(ICacheFactory::class),
            $config,
            $questions,
            'alice'
        );
    }

    private static function statsNotCompleted(): FakeQueryBuilder {
        return new FakeQueryBuilder(FakeResult::fromFetch(['last_challenge_date' => '2000-01-01', 'last_challenge_correct' => 0]));
    }

    private static function candidates(array $rows): FakeQueryBuilder {
        return new FakeQueryBuilder(FakeResult::fromFetchAll($rows));
    }

    public function testSelectionOnlyOffersTypesTheCardCanAnswer(): void {
        $selection = self::candidates([]);
        $db = new FakeDbConnection([self::statsNotCompleted(), $selection]);

        $response = $this->controller($db)->dailyChallenge();

        $this->assertFalse($response->getData()['available']);
        $types = null;
        foreach ($selection->andWhereCalls as $cond) {
            if (($cond['type'] ?? null) === 'in' && $cond['left'] === 'q.question_type') {
                $types = $cond['right']['value'];
            }
        }
        $this->assertSame(['single', 'multi', 'open'], $types, 'PBQs must never be the question of the day');
        $this->assertSame('learning_questions', $selection->joins[0]['join']);
    }

    public function testQuestionsFromPoolsWithoutAccessAreNotPicked(): void {
        $questions = $this->createMock(QuestionService::class);
        $questions->expects($this->exactly(2))->method('hasPoolAccess')
            ->willReturnCallback(fn(int $poolId) => $poolId === 2);
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = ['question_id' => $i, 'pool_id' => 1];
        }
        $rows[] = ['question_id' => 99, 'pool_id' => 2];
        $question = new FakeQueryBuilder(FakeResult::fromFetch([
            'id' => 99, 'text' => 'Q', 'scenario' => null, 'explanation' => 'secret', 'difficulty' => 1,
            'question_type' => 'single', 'pool_name' => 'Shared',
        ]));
        $answers = new FakeQueryBuilder(FakeResult::fromFetchAll([]));
        $db = new FakeDbConnection([self::statsNotCompleted(), self::candidates($rows), $question, $answers]);

        $data = $this->controller($db, $questions)->dailyChallenge()->getData();

        $this->assertTrue($data['available']);
        $this->assertSame(99, $data['question']['id']);
        $this->assertSame(gmdate('Y-m-d') . ':99', $this->userValues['daily_challenge_pick']);
    }

    public function testAllPoolsRevokedMeansNoChallenge(): void {
        $questions = $this->createMock(QuestionService::class);
        $questions->method('hasPoolAccess')->willReturn(false);
        $db = new FakeDbConnection([self::statsNotCompleted(), self::candidates([['question_id' => 5, 'pool_id' => 1]])]);

        $data = $this->controller($db, $questions)->dailyChallenge()->getData();

        $this->assertFalse($data['available']);
        $this->assertSame('no_leitner_items', $data['reason']);
    }

    public function testPinnedQuestionWinsOverTheHashWhenTheSetChanges(): void {
        $this->userValues['daily_challenge_pick'] = gmdate('Y-m-d') . ':7';
        $rows = [];
        foreach ([3, 5, 7, 9, 11, 13, 15] as $qid) {
            $rows[] = ['question_id' => $qid, 'pool_id' => 1];
        }
        $question = new FakeQueryBuilder(FakeResult::fromFetch([
            'id' => 7, 'text' => 'Q7', 'scenario' => null, 'explanation' => null, 'difficulty' => 1,
            'question_type' => 'single', 'pool_name' => 'P',
        ]));
        $db = new FakeDbConnection([self::statsNotCompleted(), self::candidates($rows), $question, new FakeQueryBuilder(FakeResult::fromFetchAll([]))]);

        $data = $this->controller($db)->dailyChallenge()->getData();

        $this->assertSame(7, $data['question']['id']);
    }

    public function testPinFromAnotherDayIsIgnored(): void {
        $this->userValues['daily_challenge_pick'] = '2000-01-01:7';
        $db = new FakeDbConnection([self::statsNotCompleted(), self::candidates([['question_id' => 42, 'pool_id' => 1]]),
            new FakeQueryBuilder(FakeResult::fromFetch(['id' => 42, 'text' => 'Q', 'scenario' => null, 'explanation' => null,
                'difficulty' => 1, 'question_type' => 'open', 'pool_name' => 'P'])),
            new FakeQueryBuilder(FakeResult::fromFetchAll([]))]);

        $data = $this->controller($db)->dailyChallenge()->getData();

        $this->assertSame(42, $data['question']['id']);
        $this->assertSame(gmdate('Y-m-d') . ':42', $this->userValues['daily_challenge_pick']);
    }

    public function testCompletedChallengeWhosePinnedQuestionVanishedShowsNoOtherQuestion(): void {
        $this->userValues['daily_challenge_pick'] = gmdate('Y-m-d') . ':7';
        $stats = new FakeQueryBuilder(FakeResult::fromFetch(['last_challenge_date' => gmdate('Y-m-d'), 'last_challenge_correct' => 1]));
        $db = new FakeDbConnection([$stats, self::candidates([['question_id' => 8, 'pool_id' => 1]])]);

        $data = $this->controller($db)->dailyChallenge()->getData();

        $this->assertFalse($data['available'], 'question 8 would be shown as answered, with its solution');
    }

    public function testAnswerForAQuestionThatIsNoLongerTodaysIsRejectedWithoutUsingTheDay(): void {
        $this->userValues['daily_challenge_pick'] = gmdate('Y-m-d') . ':7';
        $db = new FakeDbConnection([self::statsNotCompleted(), self::candidates([['question_id' => 7, 'pool_id' => 1], ['question_id' => 8, 'pool_id' => 1]])]);

        $response = $this->controller($db)->answerChallenge([1], null, 8);

        $this->assertSame(409, $response->getStatus());
        $this->assertSame(7, $response->getData()['question_id']);
        $this->assertCount(2, $db->issuedBuilders, 'no type lookup, no claim of the day');
    }

    public function testAnswerOnAUsedDayNeverMovesThePin(): void {
        $this->userValues['daily_challenge_pick'] = gmdate('Y-m-d') . ':7';
        $stats = new FakeQueryBuilder(FakeResult::fromFetch(['last_challenge_date' => gmdate('Y-m-d')]));
        // question 7 lost its pool access after it was answered: only 8 is left
        $db = new FakeDbConnection([$stats, self::candidates([['question_id' => 8, 'pool_id' => 1]])]);

        $response = $this->controller($db)->answerChallenge([1], null, null);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Already completed today', $response->getData()['error']);
        $this->assertSame(gmdate('Y-m-d') . ':7', $this->userValues['daily_challenge_pick']);
    }

    public function testAnswerGradesThePinnedQuestion(): void {
        $this->userValues['daily_challenge_pick'] = gmdate('Y-m-d') . ':7';
        $rows = [['question_id' => 1, 'pool_id' => 1], ['question_id' => 7, 'pool_id' => 1], ['question_id' => 9, 'pool_id' => 1]];
        $type = new FakeQueryBuilder(FakeResult::fromFetch(['question_type' => 'single']));
        $valid = new FakeQueryBuilder(FakeResult::fromFetch(['cnt' => 0]));
        $db = new FakeDbConnection([self::statsNotCompleted(), self::candidates($rows), $type, $valid]);

        $response = $this->controller($db)->answerChallenge([70], null, 7);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame(7, $type->whereCalls[0]['right']['value']);
        $this->assertSame(7, $valid->andWhereCalls[0]['right']['value']);
    }
}
