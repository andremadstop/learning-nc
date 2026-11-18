<?php
declare(strict_types=1);

namespace OCA\Learning\Activity;

/**
 * GitHub #85: a new entry in the feed of a course the user belongs to. Email off by default, so a
 * busy course does not turn into mail spam.
 */
class CourseFeedSetting extends LearningActivitySetting {
    public const ID = 'learning_course_feed';

    public function getIdentifier(): string {
        return self::ID;
    }

    public function getName(): string {
        return $this->l10n->t('Neuigkeiten im Kurs-Feed');
    }

    public function getPriority(): int {
        return 54;
    }

    public function isDefaultEnabledMail(): bool {
        return false;
    }
}
