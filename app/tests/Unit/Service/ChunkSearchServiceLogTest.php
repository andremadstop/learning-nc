<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Service\ChunkSearchService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Task B4: the RAG search logged the learner's question verbatim (debug level). The question is
 * chat content; only the counts may be logged.
 */
class ChunkSearchServiceLogTest extends TestCase {
    public function testTheQueryIsNotLogged(): void {
        $mapper = $this->createMock(RagChunkMapper::class);
        $mapper->method('searchByKeywords')->willReturn([]);
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(function (string $msg, array $ctx = []) use (&$logged) {
                $logged[] = $msg . json_encode($ctx);
            });
        }

        (new ChunkSearchService($mapper, $logger))->search(7, 'Wie funktioniert QUERYMARKER Subnetting');

        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsStringIgnoringCase('querymarker', $line);
        }
    }
}
