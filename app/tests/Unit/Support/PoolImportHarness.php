<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Support;

use OCA\Learning\Command\ImportPoolJsonCommand;
use OCA\Learning\Service\PoolBulkImportService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Builds the pool import (PoolBulkImportService) and `occ learning:import-pool-json` on top of
 * recording PoolService/QuestionService mocks. IL10N renders the English source with its
 * parameters — what occ prints (it always gets the English IL10N).
 */
trait PoolImportHarness {
    private function poolImporter(PoolService $pools, QuestionService $questions, ?FakeDbConnection $db = null): PoolBulkImportService {
        return new PoolBulkImportService($pools, $questions, $db ?? new FakeDbConnection(), $this->createMock(LoggerInterface::class));
    }

    private function englishL10n(): IL10N {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $text, $parameters = []): string => vsprintf($text, is_array($parameters) ? $parameters : [$parameters])
        );
        return $l10n;
    }

    private function occImportCommand(PoolService $pools, QuestionService $questions, ?FakeDbConnection $db = null): ImportPoolJsonCommand {
        return new ImportPoolJsonCommand($this->poolImporter($pools, $questions, $db), $this->englishL10n());
    }
}
