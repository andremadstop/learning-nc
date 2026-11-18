<?php
declare(strict_types=1);

namespace OCA\Learning\Activity;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCP\Activity\IManager;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * The one place that writes Learning activities (GitHub #85). Nextcloud's activity app turns them
 * into the stream and, per the user's settings, into email — the bell notifications the services
 * send stay as they are.
 *
 * Rules every activity follows:
 *  - Parameters carry only ids and titles the recipient may see anyway (course title, pool name,
 *    announcement title). Never a uid, never a body, never the notification's own parameters
 *    (the compliance reminder's carry the lead's uid).
 *  - The author is AUTHOR, never a person. The activity API hands the author to the recipient, so
 *    an instructor's or lead's uid would leak; and an author equal to the recipient (a learner
 *    issuing their own certificate by passing the exam, a badge) is a "self action", for which the
 *    activity app sends no email at all. AUTHOR contains ':' which no Nextcloud uid can contain,
 *    so it can never be mistaken for a real account.
 *  - Best effort: a missing or broken activity app never fails the action that triggered it.
 *    Without the activity app there is no consumer and publishing does nothing.
 */
class ActivityPublisher {
    public const APP = 'learning';
    public const AUTHOR = 'learning:system';

    public const SUBJECT_ENROLLED = 'course_enrolled';
    public const SUBJECT_CERT_ISSUED = 'certificate_issued';
    public const SUBJECT_CERT_EXPIRING = 'certificate_expiring';
    public const SUBJECT_CERT_EXPIRED = 'certificate_expired';
    public const SUBJECT_CERT_REVOKED = 'certificate_revoked';
    public const SUBJECT_CYCLE_OPENED = 'certification_cycle_opened';
    public const SUBJECT_COMPLIANCE_REMINDER = 'compliance_reminder';
    public const SUBJECT_FEED_POOL_ADDED = 'feed_pool_added';
    public const SUBJECT_FEED_ANNOUNCEMENT = 'feed_announcement';
    public const SUBJECT_BADGE_EARNED = 'badge_earned';

    /** Subject → activity type (= the setting's identifier). */
    public const TYPES = [
        self::SUBJECT_ENROLLED => EnrollmentSetting::ID,
        self::SUBJECT_CERT_ISSUED => CertificateSetting::ID,
        self::SUBJECT_CERT_EXPIRING => CertificateSetting::ID,
        self::SUBJECT_CERT_EXPIRED => CertificateSetting::ID,
        self::SUBJECT_CERT_REVOKED => CertificateSetting::ID,
        self::SUBJECT_CYCLE_OPENED => CertificateSetting::ID,
        self::SUBJECT_COMPLIANCE_REMINDER => MandatoryReminderSetting::ID,
        self::SUBJECT_FEED_POOL_ADDED => CourseFeedSetting::ID,
        self::SUBJECT_FEED_ANNOUNCEMENT => CourseFeedSetting::ID,
        self::SUBJECT_BADGE_EARNED => Setting::ID,
    ];

    public function __construct(
        private readonly IManager $activityManager,
        private readonly CourseMapper $courseMapper,
        private readonly CourseMemberMapper $memberMapper,
        private readonly CourseFeedSetting $feedSetting,
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * One course-related activity for one user. $courseTitle null = read it from the course.
     *
     * @param array<string, int|string> $extra further ids/titles/numbers for the subject
     */
    public function toUser(string $subject, string $userId, int $courseId, ?string $courseTitle = null, array $extra = []): void {
        try {
            if ($userId === '') {
                return;
            }
            $event = $this->event($subject, $courseId, $courseTitle ?? $this->courseTitle($courseId), $extra);
            $event->setAffectedUser($userId);
            $this->activityManager->publish($event);
        } catch (\Throwable $e) {
            $this->logFailure($subject, $courseId, $e);
        }
    }

    /**
     * A new feed entry: one activity for every member of the course (who can read the course
     * feed) except the person who caused it.
     *
     * @param array<string, int|string> $extra
     */
    public function toCourseMembers(string $subject, int $courseId, ?string $actorId, array $extra = []): void {
        try {
            $recipients = [];
            foreach ($this->memberMapper->findByCourse($courseId) as $member) {
                $uid = (string)$member->getUserId();
                if ($uid !== '' && $uid !== $actorId) {
                    $recipients[$uid] = true;
                }
            }
            if ($recipients === []) {
                return;
            }
            $event = $this->event($subject, $courseId, $this->courseTitle($courseId), $extra);
            // One bulk insert instead of one per member. Fine for the feed only: the activity
            // app's bulk path reads each user's explicit email choice and does not apply a
            // default, which is "off" for the feed anyway.
            $this->activityManager->bulkPublish($event, array_map('strval', array_keys($recipients)), $this->feedSetting);
        } catch (\Throwable $e) {
            $this->logFailure($subject, $courseId, $e);
        }
    }

    /** Gamification: an unlocked badge. */
    public function badgeEarned(string $userId, string $badgeId, string $badgeName, string $badgeEmoji): void {
        try {
            $event = $this->activityManager->generateEvent();
            $event->setApp(self::APP)
                ->setType(Setting::ID)
                ->setAuthor(self::AUTHOR)
                ->setAffectedUser($userId)
                ->setTimestamp($this->timeFactory->getTime())
                ->setSubject(self::SUBJECT_BADGE_EARNED, [
                    'badge_name' => $badgeName,
                    'badge_emoji' => $badgeEmoji,
                ])
                ->setObject('badge', 0, $badgeId);
            $this->activityManager->publish($event);
        } catch (\Throwable $e) {
            $this->logFailure(self::SUBJECT_BADGE_EARNED, 0, $e);
        }
    }

    /**
     * @param array<string, int|string> $extra
     */
    private function event(string $subject, int $courseId, string $courseTitle, array $extra): \OCP\Activity\IEvent {
        $type = self::TYPES[$subject] ?? null;
        if ($type === null) {
            throw new \InvalidArgumentException('Unknown Learning activity subject ' . $subject);
        }
        $event = $this->activityManager->generateEvent();
        $event->setApp(self::APP)
            ->setType($type)
            ->setAuthor(self::AUTHOR)
            ->setTimestamp($this->timeFactory->getTime())
            ->setSubject($subject, ['course_id' => $courseId, 'course_title' => $courseTitle] + $extra)
            ->setObject('learning_course', $courseId);
        return $event;
    }

    private function courseTitle(int $courseId): string {
        try {
            return (string)$this->courseMapper->findById($courseId)->getTitle();
        } catch (\Throwable) {
            return '';
        }
    }

    private function logFailure(string $subject, int $courseId, \Throwable $e): void {
        $this->logger->warning('Learning activity {subject} for course {course} failed: {msg}', [
            'app' => self::APP,
            'subject' => $subject,
            'course' => $courseId,
            'msg' => $e->getMessage(),
        ]);
    }
}
