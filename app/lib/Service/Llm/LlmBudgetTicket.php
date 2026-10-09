<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * Proof of one budget reservation (Task B2). Holds the concurrency slot to give back in
 * LlmBudget::release(); releasing twice is a no-op.
 */
final class LlmBudgetTicket {
    private bool $released = false;

    public function __construct(
        public readonly ?string $userId,
        public readonly LlmPurpose $purpose,
        public readonly ?string $slotBucket,
    ) {
    }

    /** Marks the ticket released; true only the first time. */
    public function markReleased(): bool {
        if ($this->released) {
            return false;
        }
        $this->released = true;
        return true;
    }
}
