<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration\Support;

use OCP\Migration\IOutput;

/** IOutput that keeps what a repair step reported, for assertions. */
final class RecordingOutput implements IOutput {
    /** @var list<string> */
    public array $infos = [];
    /** @var list<string> */
    public array $warnings = [];

    public function debug(string $message): void {
    }

    public function info($message): void {
        $this->infos[] = (string)$message;
    }

    public function warning($message): void {
        $this->warnings[] = (string)$message;
    }

    public function startProgress($max = 0): void {
    }

    public function advance($step = 1, $description = ''): void {
    }

    public function finishProgress(): void {
    }
}
