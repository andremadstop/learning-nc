<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\Course;
use OCP\Defaults;

/**
 * GitHub #77: the organisation name a new certificate of a course is issued in.
 *
 * In this order, the first that is not empty:
 *   1. the course's own name for its certificates (certificate design, CertLayout issuer_name),
 *      e.g. the legal "Training Solutions LTD" while the instance is branded "T-Solutions";
 *   2. the operator's legal name from Administration → Learning (OperatorInfo name), also when
 *      the rest of the operator details is still incomplete: the name alone is what counts here;
 *   3. the Theming name, which is what every certificate carried before 5.14.0.
 *
 * The result is signed into issuer.name of the credential, so a certificate keeps the name it was
 * issued with; changing any of the three only affects certificates issued afterwards (and renewals).
 */
class CertIssuerName {
    public function __construct(
        private OperatorInfo $operatorInfo,
        private Defaults $themingDefaults,
    ) {
    }

    public function forCourse(Course $course): string {
        $override = CertLayout::decode($course->getCertLayout())['issuer_name'];
        if ($override !== '') {
            return $override;
        }
        return $this->fallback();
    }

    /** The name without the course's own entry: shown in the settings as what an empty field means. */
    public function fallback(): string {
        $operator = trim((string)$this->operatorInfo->load()['name']);
        if ($operator !== '') {
            return $operator;
        }
        return (string)$this->themingDefaults->getName();
    }
}
