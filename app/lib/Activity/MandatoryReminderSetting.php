<?php
declare(strict_types=1);

namespace OCA\Learning\Activity;

/** GitHub #85: a reminder to complete a mandatory training. Email on by default. */
class MandatoryReminderSetting extends LearningActivitySetting {
    public const ID = 'learning_mandatory_reminder';

    public function getIdentifier(): string {
        return self::ID;
    }

    public function getName(): string {
        return $this->l10n->t('Erinnerungen an Pflichtschulungen');
    }

    public function getPriority(): int {
        return 53;
    }

    public function isDefaultEnabledMail(): bool {
        return true;
    }
}
