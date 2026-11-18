<?php
declare(strict_types=1);

namespace OCA\Learning\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * GitHub #85: every Learning activity type is its own row under Personal settings →
 * Notifications, all in one group "Learning", each with its own email switch.
 *
 * The push column (canChangeNotification) keeps the base default, off: the app already sends a
 * Nextcloud notification for these events, and the bell stays as it is — an activity push on top
 * would ring twice.
 */
abstract class LearningActivitySetting extends ActivitySettings {
    public function __construct(protected IL10N $l10n) {
    }

    public function getGroupIdentifier(): string {
        return 'learning';
    }

    public function getGroupName(): string {
        return $this->l10n->t('Learning');
    }

    public function canChangeMail(): bool {
        return true;
    }

    public function canChangeStream(): bool {
        return true;
    }

    public function isDefaultEnabledStream(): bool {
        return true;
    }
}
