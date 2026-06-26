<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * Immutable result of PassCriteriaService::evaluate().
 * Phase 154 contract — do not add fields without updating PassCriteriaService::evaluate().
 */
final class PassResult {
    public function __construct(
        private readonly bool $passed,
        private readonly ?int $score,       // best exam score 0-100, null if no completed exam
        private readonly int $threshold,    // cert_pass_percent from course config
        private readonly bool $poolsMastered,
        private readonly ?int $passedAt,    // unix timestamp of first qualification, null if not passed
        private readonly bool $applicable = true,  // false when cert_enabled=false
    ) {}

    public static function notApplicable(): self {
        return new self(false, null, 0, false, null, false);
    }

    public function isPassed(): bool { return $this->passed; }
    public function getScore(): ?int { return $this->score; }
    public function getThreshold(): int { return $this->threshold; }
    public function isPoolsMastered(): bool { return $this->poolsMastered; }
    public function getPassedAt(): ?int { return $this->passedAt; }
    public function isApplicable(): bool { return $this->applicable; }

    public function toArray(): array {
        return [
            'applicable'    => $this->applicable,
            'passed'        => $this->passed,
            'score'         => $this->score,
            'threshold'     => $this->threshold,
            'poolsMastered' => $this->poolsMastered,
            'passedAt'      => $this->passedAt,
        ];
    }
}
