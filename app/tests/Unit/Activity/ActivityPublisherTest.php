<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Activity;

use OCA\Learning\Activity\ActivityPublisher;
use OCA\Learning\Activity\CertificateSetting;
use OCA\Learning\Activity\CourseFeedSetting;
use OCA\Learning\Activity\EnrollmentSetting;
use OCA\Learning\Activity\ExamAttemptsSetting;
use OCA\Learning\Activity\MandatoryReminderSetting;
use OCA\Learning\Activity\Setting;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #85: the one writer of Learning activities.
 *
 * The author rule is the point of most of these tests: the activity app sends no email for an
 * activity whose author is its recipient ("self action"), and the old badge activity set exactly
 * that (author = the learner), so its email could never arrive, even when switched on. An
 * instructor's uid as author would instead leak to the learner through the activity API.
 */
#[AllowMockObjectsWithoutExpectations]
final class ActivityPublisherTest extends TestCase {
    /** @var list<array<string, mixed>> what was published, one entry per publish()/bulkPublish() */
    private array $published = [];
    /** @var array<int, array<string, mixed>> values set on each recorded event, by spl_object_id */
    private array $fields = [];
    private IManager $manager;
    private CourseMemberMapper $members;
    private CourseMapper $courses;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->manager = $this->createMock(IManager::class);
        $this->manager->method('generateEvent')->willReturnCallback(fn (): IEvent => $this->recordingEvent());
        $this->manager->method('publish')->willReturnCallback(function (IEvent $event): void {
            $this->published[] = ['bulk' => false] + $this->fields[spl_object_id($event)];
        });
        $this->manager->method('bulkPublish')->willReturnCallback(function (IEvent $event, array $users, $setting): void {
            $this->published[] = ['bulk' => true, 'users' => $users, 'setting' => $setting->getIdentifier()] + $this->fields[spl_object_id($event)];
        });
        $this->members = $this->createMock(CourseMemberMapper::class);
        $this->courses = $this->createMock(CourseMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function publisher(): ActivityPublisher {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_800_000_000);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        return new ActivityPublisher($this->manager, $this->courses, $this->members, new CourseFeedSetting($l10n), $time, $this->logger);
    }

    /** An IEvent mock that keeps every value set on it in ->fields. */
    private function recordingEvent(): IEvent {
        $event = $this->createMock(IEvent::class);
        $id = spl_object_id($event);
        $this->fields[$id] = [];
        $record = function (string $key) use ($event, $id) {
            return function (...$args) use ($event, $id, $key) {
                $this->fields[$id][$key] = count($args) === 1 ? $args[0] : $args;
                return $event;
            };
        };
        foreach (['setApp' => 'app', 'setType' => 'type', 'setAffectedUser' => 'affected', 'setAuthor' => 'author',
                'setTimestamp' => 'timestamp', 'setSubject' => 'subject', 'setObject' => 'object'] as $method => $key) {
            $event->method($method)->willReturnCallback($record($key));
        }
        return $event;
    }

    private static function member(string $uid): CourseMember {
        $member = new CourseMember();
        $member->setUserId($uid);
        return $member;
    }

    public function testACourseActivityCarriesOnlyTheCourseAndASystemAuthor(): void {
        $this->publisher()->toUser(ActivityPublisher::SUBJECT_ENROLLED, 'anna', 7, 'Brandschutz');

        $this->assertCount(1, $this->published);
        $event = $this->published[0];
        $this->assertSame('learning', $event['app']);
        $this->assertSame(EnrollmentSetting::ID, $event['type']);
        $this->assertSame('anna', $event['affected']);
        $this->assertSame([ActivityPublisher::SUBJECT_ENROLLED, ['course_id' => 7, 'course_title' => 'Brandschutz']], $event['subject']);
        $this->assertSame(['learning_course', 7, ''], $event['object']);
        $this->assertSame(1_800_000_000, $event['timestamp']);
        $this->assertSame(ActivityPublisher::AUTHOR, $event['author']);
        $this->assertNotSame('anna', $event['author'], 'author = recipient is a self action: no email');
        $this->assertStringContainsString(':', ActivityPublisher::AUTHOR, 'no Nextcloud uid can contain ":"');
    }

    public function testEverySubjectHasTheTypeOfItsGroup(): void {
        $this->assertSame([
            'course_enrolled' => EnrollmentSetting::ID,
            'certificate_issued' => CertificateSetting::ID,
            'certificate_expiring' => CertificateSetting::ID,
            'certificate_expired' => CertificateSetting::ID,
            'certificate_revoked' => CertificateSetting::ID,
            'certification_cycle_opened' => CertificateSetting::ID,
            'compliance_reminder' => MandatoryReminderSetting::ID,
            'feed_pool_added' => CourseFeedSetting::ID,
            'feed_announcement' => CourseFeedSetting::ID,
            'badge_earned' => Setting::ID,
            'exam_attempts_exhausted' => ExamAttemptsSetting::ID,
        ], ActivityPublisher::TYPES);
    }

    public function testAMissingTitleIsReadFromTheCourseAndADeletedCourseLeavesItEmpty(): void {
        $course = new Course();
        $course->setTitle('Erste Hilfe');
        $this->courses->method('findById')->willReturnCallback(static function (int $id) use ($course): Course {
            if ($id === 7) {
                return $course;
            }
            throw new DoesNotExistException('gone');
        });

        $this->publisher()->toUser(ActivityPublisher::SUBJECT_COMPLIANCE_REMINDER, 'anna', 7);
        $this->publisher()->toUser(ActivityPublisher::SUBJECT_COMPLIANCE_REMINDER, 'anna', 8);

        $this->assertSame('Erste Hilfe', $this->published[0]['subject'][1]['course_title']);
        $this->assertSame('', $this->published[1]['subject'][1]['course_title']);
    }

    public function testExtraParametersAreAddedButCannotReplaceTheCourse(): void {
        $this->publisher()->toUser(ActivityPublisher::SUBJECT_CERT_EXPIRING, 'anna', 7, 'Brandschutz', ['days_left' => 30, 'course_id' => 99]);

        $this->assertSame(['course_id' => 7, 'course_title' => 'Brandschutz', 'days_left' => 30], $this->published[0]['subject'][1]);
    }

    public function testAFeedEntryGoesToTheMembersButNotToItsAuthor(): void {
        $this->members->method('findByCourse')->with(7)->willReturn([
            self::member('teacher'), self::member('anna'), self::member('ben'), self::member('anna'),
        ]);

        $this->publisher()->toCourseMembers(ActivityPublisher::SUBJECT_FEED_POOL_ADDED, 7, 'teacher', ['pool_id' => 3, 'pool_name' => 'Pool A']);

        $this->assertCount(1, $this->published);
        $event = $this->published[0];
        $this->assertTrue($event['bulk']);
        $this->assertSame(['anna', 'ben'], $event['users']);
        $this->assertSame(CourseFeedSetting::ID, $event['setting']);
        $this->assertSame(ActivityPublisher::AUTHOR, $event['author']);
        $this->assertSame(['course_id' => 7, 'course_title' => '', 'pool_id' => 3, 'pool_name' => 'Pool A'], $event['subject'][1]);
    }

    public function testAFeedEntryWithoutOtherMembersPublishesNothing(): void {
        $this->members->method('findByCourse')->willReturn([self::member('teacher')]);

        $this->publisher()->toCourseMembers(ActivityPublisher::SUBJECT_FEED_ANNOUNCEMENT, 7, 'teacher');

        $this->assertSame([], $this->published, 'bulkPublish refuses an empty recipient list');
    }

    public function testABadgeIsAuthoredByTheSystemNotByTheLearner(): void {
        $this->publisher()->badgeEarned('anna', 'streak_7', 'Wochenserie', '🔥');

        $event = $this->published[0];
        $this->assertSame(Setting::ID, $event['type']);
        $this->assertSame('anna', $event['affected']);
        $this->assertSame(ActivityPublisher::AUTHOR, $event['author']);
        $this->assertSame(['badge_earned', ['badge_name' => 'Wochenserie', 'badge_emoji' => '🔥']], $event['subject']);
        $this->assertSame(['badge', 0, 'streak_7'], $event['object']);
    }

    /** A broken activity backend never fails the action that triggered the activity. */
    public function testAThrowingActivityBackendIsLoggedAndSwallowed(): void {
        $manager = $this->createMock(IManager::class);
        $manager->method('generateEvent')->willReturnCallback(fn (): IEvent => $this->recordingEvent());
        $manager->method('publish')->willThrowException(new \RuntimeException('activity table missing'));
        $manager->method('bulkPublish')->willThrowException(new \RuntimeException('activity table missing'));
        $this->manager = $manager;
        $this->members->method('findByCourse')->willReturn([self::member('anna')]);
        $this->logger->expects($this->exactly(3))->method('warning');

        $publisher = $this->publisher();
        $publisher->toUser(ActivityPublisher::SUBJECT_CERT_ISSUED, 'anna', 7, 'Brandschutz');
        $publisher->toCourseMembers(ActivityPublisher::SUBJECT_FEED_POOL_ADDED, 7, 'teacher');
        $publisher->badgeEarned('anna', 'b', 'B', '*');
        $this->addToAssertionCount(1);
    }

    public function testAnUnknownSubjectOrAnEmptyRecipientPublishesNothing(): void {
        $publisher = $this->publisher();
        $publisher->toUser('not_a_subject', 'anna', 7, 'X');
        $publisher->toUser(ActivityPublisher::SUBJECT_ENROLLED, '', 7, 'X');

        $this->assertSame([], $this->published);
    }
}
