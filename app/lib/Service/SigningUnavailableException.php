<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * The instance cannot sign a certificate right now: there is no active issuer key, or the stored
 * key cannot be decrypted (GitHub #35).
 *
 * Raised by IssuanceService around KeyService::getActiveSigningMaterial() so PassCriteriaService
 * can tell "an administrator has to set up or repair the issuer key" apart from any other
 * issuance failure, and say so to the learner without exposing the underlying message.
 * Extends \RuntimeException so existing `catch (\RuntimeException)` boundaries still apply.
 */
class SigningUnavailableException extends \RuntimeException {
}
