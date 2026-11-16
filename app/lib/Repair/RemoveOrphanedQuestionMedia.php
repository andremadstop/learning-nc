<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCA\Learning\Service\QuestionMediaStorage;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * GitHub #66: remove question media nothing points at any more — rows of deleted questions,
 * app data folders of deleted pools, files no track row names (QuestionMediaStorage::sweepOrphans).
 * The same sweep runs daily in ConsistencyCheckJob; this step clears what an upgrade finds right
 * away. Post-migration only: a fresh install has no media. Running it again changes nothing.
 */
class RemoveOrphanedQuestionMedia implements IRepairStep {
    public function __construct(
        private QuestionMediaStorage $storage,
    ) {
    }

    public function getName(): string {
        return 'Remove media of deleted questions and pools';
    }

    public function run(IOutput $output): void {
        $removed = $this->storage->sweepOrphans();
        if ($removed['rows'] + $removed['folders'] + $removed['files'] > 0) {
            $output->info(sprintf('Removed %d media rows, %d pool folders and %d files without a question', $removed['rows'], $removed['folders'], $removed['files']));
        }
    }
}
