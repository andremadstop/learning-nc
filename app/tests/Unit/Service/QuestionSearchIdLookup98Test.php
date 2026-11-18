<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Controller\QuestionController;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #98 (review): a question opened from the Pools search is placed on its page by searching
 * its pool for the question's ID. That failed silently for
 *  - IDs 1–9: the search refused queries under two characters (400);
 *  - any ID whose digits appear in more than 50 newer question texts of the pool: the numeric
 *    query also matches text, newest first, and the older question itself was cut off.
 */
#[AllowMockObjectsWithoutExpectations]
class QuestionSearchIdLookup98Test extends TestCase {
    private function service(QuestionMapper $questions): QuestionService {
        $pools = $this->createMock(PoolMapper::class);
        $pools->method('find')->willReturn(new Pool());

        return new QuestionService($questions, $this->createMock(AnswerMapper::class),
            $this->createMock(PoolShareMapper::class), $pools, new FakeDbConnection([]),
            $this->createMock(TranslationService::class), $this->createMock(LoggerInterface::class));
    }

    private function controller(QuestionService $service): QuestionController {
        return new QuestionController('learning', $this->createMock(IRequest::class), $service, 'author');
    }

    public function testAOneDigitIdIsSearchedInItsPool(): void {
        $service = $this->createMock(QuestionService::class);
        $service->expects($this->once())->method('search')->with('7', 'author', 50, 3)
            ->willReturn([['id' => 7, 'pool_id' => 3, 'position' => 103]]);

        $response = $this->controller($service)->search('7', 3);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(7, $response->getData()[0]['id']);
    }

    public function testOneCharacterStaysTooShortOutsideAPoolOrAsText(): void {
        $service = $this->createMock(QuestionService::class);
        $service->expects($this->never())->method('search');
        $controller = $this->controller($service);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $controller->search('7')->getStatus());
        $this->assertSame(Http::STATUS_BAD_REQUEST, $controller->search('a', 3)->getStatus());
        $this->assertSame(Http::STATUS_BAD_REQUEST, $controller->search('', 3)->getStatus());
    }

    public function testTheIdHitLeadsWhenNewerTextHitsFillTheLimit(): void {
        $textHits = [];
        for ($i = 0; $i < 50; $i++) {
            $textHits[] = ['id' => 500 + $i, 'pool_id' => 3, 'text' => 'Port 12' . $i];
        }
        $questions = $this->createMock(QuestionMapper::class);
        $questions->expects($this->exactly(2))->method('searchByText')
            ->willReturnCallback(static fn (string $q, string $u, int $limit, ?int $pool, bool $idOnly = false): array
                => $idOnly ? [['id' => 12, 'pool_id' => 3, 'text' => 'Erste Frage']] : $textHits);
        $questions->method('findIdsInPoolOrder')->with(3)->willReturn(array_merge(range(1, 11), [12], range(500, 549)));

        $rows = $this->service($questions)->search('12', 'author', 50, 3);

        $this->assertCount(50, $rows);
        $this->assertSame(12, $rows[0]['id']);
        $this->assertSame(11, $rows[0]['position']);
    }

    public function testNoSecondLookupWhenTheIdIsAmongTheHitsOrTheQueryIsText(): void {
        $questions = $this->createMock(QuestionMapper::class);
        $questions->expects($this->exactly(2))->method('searchByText')
            ->willReturnCallback(static function (string $q, string $u, int $limit, ?int $pool, bool $idOnly = false): array {
                self::assertFalse($idOnly);
                return [['id' => 12, 'pool_id' => 3, 'text' => 'x']];
            });
        $questions->method('findIdsInPoolOrder')->willReturn([12]);
        $service = $this->service($questions);

        $service->search('12', 'author', 50, 3);
        $service->search('VLAN', 'author', 50, 3);
    }
}
