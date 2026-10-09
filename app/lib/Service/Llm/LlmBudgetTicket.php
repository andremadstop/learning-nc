<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * Proof of one budget reservation (Task B2). Holds the concurrency slot (bucket, slot index and
 * owner token) to give back in LlmBudget::release(); releasing twice is a no-op.
 */
final class LlmBudgetTicket {
    private bool $released = false;

    public function __construct(
        public readonly ?string $userId,
        public readonly LlmPurpose $purpose,
        public readonly ?string $slotBucket,
        public readonly ?int $slotIndex = null,
        public readonly ?int $slotToken = null,
    ) {
    }

    public function isReleased(): bool {
        return $this->released;
    }

    /** Called only after the slot was actually given back, so a failed release can be retried. */
    public function markReleased(): void {
        $this->released = true;
    }
}
