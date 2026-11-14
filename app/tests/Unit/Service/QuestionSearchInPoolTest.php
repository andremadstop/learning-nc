<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit #108: the pool's question list searched all of the user's pools (50 hits) and
 * filtered to the open pool afterwards, so its hits could be cut off, and a hit on another page
 * only reloaded page 1. The search now takes the pool and returns each hit's place in the pool.
 */
#[AllowMockObjectsWithoutExpectations]
class QuestionSearchInPoolTest extends TestCase {
    private function service(QuestionMapper $questions): QuestionService {
        $pools = $this->createMock(PoolMapper::class);
        $pools->method('find')->willReturn(new Pool());

        return new QuestionService($questions, $this->createMock(AnswerMapper::class),
            $this->createMock(PoolShareMapper::class), $pools, new FakeDbConnection([]),
            $this->createMock(TranslationService::class), $this->createMock(LoggerInterface::class));
    }

    public function testThePoolSearchAsksForThatPoolAndPlacesEachHit(): void {
        $questions = $this->createMock(QuestionMapper::class);
        $questions->expects($this->once())->method('searchByText')
            ->with('VLAN', 'author', 50, 7)
            ->willReturn([['id' => 240, 'pool_id' => 7, 'text' => 'VLAN tagging'], ['id' => 3, 'pool_id' => 7, 'text' => 'VLAN']]);
        $order = range(1000, 1238);
        array_splice($order, 2, 0, [3]);
        $order[] = 240; // the 241st question → index 240, page 5 at 50 per page
        $questions->method('findIdsInPoolOrder')->with(7)->willReturn($order);

        $rows = $this->service($questions)->search('VLAN', 'author', 50, 7);

        $this->assertSame([240, 2], array_column($rows, 'position'));
    }

    public function testTheGlobalSearchStaysAsItWas(): void {
        $questions = $this->createMock(QuestionMapper::class);
        $questions->expects($this->once())->method('searchByText')->with('VLAN', 'author', 50, null)
            ->willReturn([['id' => 1, 'pool_id' => 7, 'text' => 'VLAN']]);
        $questions->expects($this->never())->method('findIdsInPoolOrder');

        $rows = $this->service($questions)->search('VLAN', 'author');

        $this->assertArrayNotHasKey('position', $rows[0]);
    }
}
