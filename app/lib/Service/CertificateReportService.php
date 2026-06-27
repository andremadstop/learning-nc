<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * DSGVO-safe, owner-scoped compliance report for a certifying course (156-01, REPORT-01..04).
 *
 * One shared read path feeds BOTH the on-screen JSON table and the CSV download, so the two are
 * always the same filtered set. For each issued certificate it decodes the stored, already-signed
 * VC-JWT payload to recover the frozen recipient display name and the achieved score, then projects
 * to a strict 5-field DTO. The account identifier is NEVER read into any output field, and an
 * email-shaped frozen name (defence-in-depth against a hand-edited / legacy row) falls back to a
 * neutral pseudonym — so no plaintext email can reach a report surface.
 */
class CertificateReportService {

    /**
     * Neutral DSGVO fallback recipient name. Mirrors IssuanceService::FALLBACK_RECIPIENT so the
     * report and the signed credential agree on the pseudonym (a backend constant, not a t() key —
     * locale-independent).
     */
    private const FALLBACK_RECIPIENT = 'Teilnehmer:in';

    private const SECONDS_PER_DAY = 86400;

    private CourseService $courseService;
    private CertificateMapper $certificateMapper;
    private ITimeFactory $timeFactory;

    public function __construct(
        CourseService $courseService,
        CertificateMapper $certificateMapper,
        ITimeFactory $timeFactory
    ) {
        $this->courseService = $courseService;
        $this->certificateMapper = $certificateMapper;
        $this->timeFactory = $timeFactory;
    }

    /**
     * Build the owner-scoped, server-side-filtered report for one course.
     *
     * Authorization runs FIRST (per-course owner gate) before any certificate is read; a foreign
     * instructor gets a ForbiddenException and never sees a row. The expiry window is converted here
     * (this class owns the clock) from a day-count into an absolute unix cutoff that the time-free
     * mapper applies.
     *
     * @param int      $courseId     the certifying course
     * @param string   $userId       the requesting instructor
     * @param int|null $from         issued_at >= from (inclusive)
     * @param int|null $to           issued_at <= to (inclusive)
     * @param int|null $expiringDays only certificates expiring within this many days (never-expiring
     *                               excluded); null = no expiry filter
     * @return array{rows: list<array{display_name: string, passed_at: int, score: string, expires_at: int|null, verification_id: string}>}
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException course not found (→ 404)
     * @throws ForbiddenException                         caller is not an instructor of the course (→ 403)
     */
    public function getCourseReport(int $courseId, string $userId, ?int $from, ?int $to, ?int $expiringDays): array {
        // Gate BEFORE any read — owner-scoped, IDOR-safe.
        $this->courseService->assertInstructorOfCourse($courseId, $userId);

        $expiresBefore = $expiringDays !== null
            ? $this->timeFactory->getTime() + $expiringDays * self::SECONDS_PER_DAY
            : null;

        $certs = $this->certificateMapper->findByCourseId($courseId, $from, $to, $expiresBefore);

        $rows = [];
        foreach ($certs as $cert) {
            $rows[] = $this->projectRow($cert);
        }

        return ['rows' => $rows];
    }

    /**
     * Project one certificate to the strict 5-field DTO. The frozen name and score are recovered from
     * the signed VC-JWT payload; a malformed/undecodable credential degrades gracefully to the neutral
     * pseudonym + empty score and never aborts the surrounding report.
     *
     * @return array{display_name: string, passed_at: int, score: string, expires_at: int|null, verification_id: string}
     */
    private function projectRow(Certificate $cert): array {
        [$frozenName, $score] = $this->decodePayload($cert->getCredentialJson());

        $displayName = ($frozenName === null || $frozenName === '' || $this->looksLikeEmail($frozenName))
            ? self::FALLBACK_RECIPIENT
            : $frozenName;

        return [
            'display_name' => $displayName,
            'passed_at' => $cert->getIssuedAt(),
            'score' => $score,
            'expires_at' => $cert->getExpiresAt(),
            'verification_id' => $cert->getVerificationId(),
        ];
    }

    /**
     * Decode the VC-JWT payload (segment 2) to [frozen name, score]. Mirrors the issuer's base64url
     * encoding (SigningService) with strict base64 decode. Any failure → [null, ''] so the row still
     * renders with the neutral fallback.
     *
     * @return array{0: string|null, 1: string}
     */
    private function decodePayload(string $jwt): array {
        try {
            $parts = explode('.', $jwt);
            if (count($parts) < 2) {
                return [null, ''];
            }
            $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
            if ($decoded === false) {
                return [null, ''];
            }
            $payload = json_decode($decoded, true);
            if (!is_array($payload)) {
                return [null, ''];
            }

            $name = $payload['credentialSubject']['name'] ?? null;
            $frozenName = is_string($name) ? $name : null;

            $resultDescription = $payload['credentialSubject']['result'][0]['resultDescription'] ?? '';
            $score = '';
            if (is_string($resultDescription) && preg_match('/score:([0-9.]+)/', $resultDescription, $m) === 1) {
                $score = $m[1];
            }

            return [$frozenName, $score];
        } catch (\Throwable $e) {
            return [null, ''];
        }
    }

    /**
     * True when the candidate CONTAINS an email-shaped token anywhere (mirrors IssuanceService): a
     * defence-in-depth re-screen of the frozen name so a legacy/hand-edited credential can't leak an
     * address even though the name was screened at issuance.
     */
    private function looksLikeEmail(string $candidate): bool {
        return preg_match('/[^@\s]+@[^@\s]+\.[^@\s]+/', trim($candidate)) === 1;
    }
}
