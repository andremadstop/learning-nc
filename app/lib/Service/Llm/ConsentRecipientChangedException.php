<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * The client asked to consent to a recipient the server no longer has (spec 3.4 → HTTP 409).
 * Carries the server's current recipient fingerprint, so the answer can show the new recipient.
 */
final class ConsentRecipientChangedException extends \RuntimeException {
    public function __construct(public readonly string $currentFingerprint) {
        parent::__construct('recipient_changed');
    }
}
