<?php
declare(strict_types=1);

namespace OCA\Learning\Activity;

/**
 * GitHub #87: a learner has used up all exam attempts of a pool in a course with an attempt limit.
 * For the course's instructors (owner and co-instructors), who decide about a new certification
 * cycle. Email on by default, like the certificates: the bell alone is easily missed.
 */
class ExamAttemptsSetting extends LearningActivitySetting {
    public const ID = 'learning_exam_attempts';

    public function getIdentifier(): string {
        return self::ID;
    }

    public function getName(): string {
        return $this->l10n->t('Prüfungsversuche aufgebraucht (für Lehrende des Kurses)');
    }

    public function getPriority(): int {
        return 56;
    }

    public function isDefaultEnabledMail(): bool {
        return true;
    }
}
