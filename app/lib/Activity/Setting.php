<?php
declare(strict_types=1);
namespace OCA\Learning\Activity;

use OCP\Activity\ISetting;
use OCP\IL10N;

class Setting implements ISetting {
    private IL10N $l10n;

    public function __construct(IL10N $l10n) {
        $this->l10n = $l10n;
    }

    public function getIdentifier(): string {
        return 'learning_badge_earned';
    }

    public function getName(): string {
        return $this->l10n->t('Learning achievements');
    }

    public function getPriority(): int {
        return 50;
    }

    public function canChangeStream(): bool {
        return true;
    }

    public function isDefaultEnabledStream(): bool {
        return true;
    }

    public function canChangeMail(): bool {
        return true;
    }

    public function isDefaultEnabledMail(): bool {
        return false;
    }
}
