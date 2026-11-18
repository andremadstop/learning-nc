<?php
declare(strict_types=1);

namespace OCA\Learning\Activity;

/**
 * Gamification: an unlocked achievement (badge). Email off by default (GitHub #85).
 *
 * The identifier stays learning_badge_earned: the users' stored choices are keyed on it. The class
 * stays here because appinfo/info.xml registers it; the other Learning settings are registered in
 * Application::boot().
 */
class Setting extends LearningActivitySetting {
    public const ID = 'learning_badge_earned';

    public function getIdentifier(): string {
        return self::ID;
    }

    public function getName(): string {
        return $this->l10n->t('Gamification: freigeschaltete Erfolge');
    }

    public function getPriority(): int {
        return 55;
    }

    public function isDefaultEnabledMail(): bool {
        return false;
    }
}
