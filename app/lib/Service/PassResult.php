<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * Immutable result of PassCriteriaService::evaluate().
 * Phase 154 contract — do not add fields without updating PassCriteriaService::evaluate().
 *
 * GitHub #35 additions (all optional, so older callers keep working):
 *   - requirement: 'required_pools' (every required pool needs its own passed counting exam)
 *     or 'any_exam' (the best counting exam of the course decides);
 *   - pools: per required pool {pool_id, title, best_score, passed};
 *   - certificate: the newest certificate row for user+course, or null;
 *   - issuanceError: why a passed learner has no certificate ('signing_unavailable' |
 *     'issuance_failed' | 'instructor_of_course'), never the underlying exception text;
 *   - renewalOpenSince: since when passing the exam(s) renews the current certificate (the
 *     first reminder opened renewal), null while renewal is not open or does not apply;
 *   - renewalOpensAt: when renewal will open for the current certificate, null once it is open
 *     or when it does not apply (no expiry, revoked, no current certificate).
 * poolsMastered keeps its name for API compatibility; it now means "all required pools passed".
 */
final class PassResult {
    public const REQUIREMENT_REQUIRED_POOLS = 'required_pools';
    public const REQUIREMENT_ANY_EXAM = 'any_exam';
    public const ISSUANCE_SIGNING_UNAVAILABLE = 'signing_unavailable';
    public const ISSUANCE_FAILED = 'issuance_failed';
    /** The user is an instructor of the course (owner or co-instructor); nothing is issued there. */
    public const ISSUANCE_INSTRUCTOR_OF_COURSE = 'instructor_of_course';

    /**
     * @param list<array{pool_id: int, title: string|null, best_score: int|null, passed: bool}> $pools
     * @param array{verification_id: string, issued_at: int, expires_at: int|null, expired: bool, revoked: bool}|null $certificate
     */
    public function __construct(
        private readonly bool $passed,
        private readonly ?int $score,       // score the pass is based on, 0-100; null if no counting exam
        private readonly int $threshold,    // cert_pass_percent from course config
        private readonly bool $poolsMastered,
        private readonly ?int $passedAt,    // unix timestamp of first qualification, null if not passed
        private readonly bool $applicable = true,  // false when cert_enabled=false
        private readonly string $requirement = self::REQUIREMENT_ANY_EXAM,
        private readonly array $pools = [],
        private readonly ?array $certificate = null,
        private readonly ?string $issuanceError = null,
        private readonly ?int $renewalOpenSince = null,
        private readonly ?int $renewalOpensAt = null,
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
    public function getRequirement(): string { return $this->requirement; }
    /** @return list<array{pool_id: int, title: string|null, best_score: int|null, passed: bool}> */
    public function getPools(): array { return $this->pools; }
    /** @return array{verification_id: string, issued_at: int, expires_at: int|null, expired: bool, revoked: bool}|null */
    public function getCertificate(): ?array { return $this->certificate; }
    public function getIssuanceError(): ?string { return $this->issuanceError; }
    public function getRenewalOpenSince(): ?int { return $this->renewalOpenSince; }
    public function getRenewalOpensAt(): ?int { return $this->renewalOpensAt; }

    /** A current certificate exists: issued, not revoked, not expired. */
    public function isCertificateIssued(): bool {
        return $this->certificate !== null
            && !$this->certificate['revoked']
            && !$this->certificate['expired'];
    }

    public function toArray(): array {
        return [
            'applicable'         => $this->applicable,
            'passed'             => $this->passed,
            'score'              => $this->score,
            'threshold'          => $this->threshold,
            'poolsMastered'      => $this->poolsMastered,
            'passedAt'           => $this->passedAt,
            'requirement'        => $this->requirement,
            'pools'              => $this->pools,
            'certificate_issued' => $this->isCertificateIssued(),
            'certificate'        => $this->certificate,
            'issuance_error'     => $this->issuanceError,
            'renewal_open_since' => $this->renewalOpenSince,
            'renewal_opens_at'   => $this->renewalOpensAt,
        ];
    }
}
