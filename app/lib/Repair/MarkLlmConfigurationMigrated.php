<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Fresh installs (<install>): there is no 5.5 state to migrate, and MigrateLlmConfiguration does
 * not run there (<post-migration> is skipped on a fresh install). Its one-time parts are marked
 * done, so that the first later upgrade does not clear chat memory or audit rows 5.7 wrote itself.
 */
class MarkLlmConfigurationMigrated implements IRepairStep {
    public function __construct(
        private MigrateLlmConfiguration $migration,
    ) {
    }

    public function getName(): string {
        return 'Mark the AI configuration migration as done on a fresh install';
    }

    public function run(IOutput $output): void {
        $this->migration->markFreshInstall();
    }
}
