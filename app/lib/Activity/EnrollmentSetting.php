<?php
declare(strict_types=1);

namespace OCA\Learning\Activity;

/** GitHub #85: an instructor enrolled the user in a course. Email on by default. */
class EnrollmentSetting extends LearningActivitySetting {
    public const ID = 'learning_enrollment';

    public function getIdentifier(): string {
        return self::ID;
    }

    public function getName(): string {
        return $this->l10n->t('Einschreibung in einen Kurs');
    }

    public function getPriority(): int {
        return 51;
    }

    public function isDefaultEnabledMail(): bool {
        return true;
    }
}
