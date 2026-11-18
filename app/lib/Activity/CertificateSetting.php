<?php
declare(strict_types=1);

namespace OCA\Learning\Activity;

/**
 * GitHub #85: the user's certificate was issued, expires soon, has expired, was revoked, or a new
 * certification cycle was opened (#84). Email on by default.
 */
class CertificateSetting extends LearningActivitySetting {
    public const ID = 'learning_certificate';

    public function getIdentifier(): string {
        return self::ID;
    }

    public function getName(): string {
        return $this->l10n->t('Zertifikate: ausgestellt, läuft ab, abgelaufen, widerrufen, neuer Zyklus');
    }

    public function getPriority(): int {
        return 52;
    }

    public function isDefaultEnabledMail(): bool {
        return true;
    }
}
