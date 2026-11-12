<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\QuestionController;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit: the bot modes of Duel and Gameshow graded on the client with answers[].is_correct
 * from GET /api/pools/{id}/questions. Learners no longer receive is_correct, so they ask
 * POST /api/questions/{id}/check instead. The endpoint grades one answer of one question the user
 * can read, refuses during a graded exam on the pool, and is rate-limited.
 *
 * Runs the real controller over the real QuestionService (fake database).
 */
#[AllowMockObjectsWithoutExpectations]
class QuestionCheckControllerTest extends TestCase {
    /** @param list<FakeQueryBuilder> $queries */
    private function controller(bool $courseMember, array $queries): QuestionController {
        $question = new Question();
        $question->setId(10);
        $question->setPoolId(42);
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findById')->willReturnCallback(static function (int $id) use ($question): Question {
            if ($id !== 10) {
                throw new DoesNotExistException('gone');
            }
            return $question;
        });
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->willThrowException(new DoesNotExistException('not the owner'));
        $shareMapper = $this->createMock(PoolShareMapper::class);
        $shareMapper->method('findByPoolAndUser')->willReturn(null);

        // hasCoursePoolAccess() comes first.
        array_unshift($queries, new FakeQueryBuilder(FakeResult::fromFetch($courseMember ? ['id' => 1] : false)));
        $service = new QuestionService($questionMapper, $this->createMock(AnswerMapper::class), $shareMapper, $poolMapper,
            new FakeDbConnection($queries), $this->createMock(TranslationService::class), $this->createMock(LoggerInterface::class));

        return new QuestionController('learning', $this->createMock(IRequest::class), $service, 'student');
    }

    private static function q(array|false $row): FakeQueryBuilder {
        return new FakeQueryBuilder(FakeResult::fromFetch($row));
    }

    public function testCourseMemberGetsTheGrade(): void {
        $resp = $this->controller(true, [self::q(false), self::q(['is_correct' => false]), self::q(['id' => 101])])->check(10, 100);

        $this->assertSame(200, $resp->getStatus());
        $this->assertSame(['correct' => false, 'correct_answer_id' => 101], $resp->getData());
    }

    public function testUserWithoutPoolAccessGets404(): void {
        $resp = $this->controller(false, [])->check(10, 101);

        $this->assertSame(404, $resp->getStatus());
        $this->assertArrayNotHasKey('correct', $resp->getData());
        $this->assertArrayNotHasKey('correct_answer_id', $resp->getData());
    }

    public function testUnknownQuestionGets404(): void {
        $resp = $this->controller(true, [])->check(11, 101);
        $this->assertSame(404, $resp->getStatus());
    }

    public function testGradedExamOnThePoolGets403(): void {
        $resp = $this->controller(true, [self::q(['id' => 9]), self::q(['is_correct' => true]), self::q(['id' => 101])])->check(10, 101);

        $this->assertSame(403, $resp->getStatus());
        $this->assertArrayNotHasKey('correct_answer_id', $resp->getData());
    }

    public function testAnswerOfAnotherQuestionGets400(): void {
        $resp = $this->controller(true, [self::q(false), self::q(false)])->check(10, 999);

        $this->assertSame(400, $resp->getStatus());
        $this->assertArrayNotHasKey('correct_answer_id', $resp->getData());
    }

    public function testEndpointIsRateLimited(): void {
        $limits = (new \ReflectionMethod(QuestionController::class, 'check'))->getAttributes(UserRateLimit::class);
        $this->assertCount(1, $limits, 'grading is an oracle; it needs a per-user rate limit');
    }

    public function testRouteIsRegistered(): void {
        $routes = require __DIR__ . '/../../../appinfo/routes.php';
        $match = array_filter($routes['routes'], static fn (array $r): bool => $r['name'] === 'question#check');
        $this->assertCount(1, $match);
        $route = array_values($match)[0];
        $this->assertSame('/api/questions/{id}/check', $route['url']);
        $this->assertSame('POST', $route['verb']);
    }
}
