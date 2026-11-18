<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Activity;

use OCA\Learning\Activity\ActivityPublisher;
use OCA\Learning\Activity\CertificateSetting;
use OCA\Learning\Activity\CourseFeedSetting;
use OCA\Learning\Activity\EnrollmentSetting;
use OCA\Learning\Activity\LearningActivitySetting;
use OCA\Learning\Activity\MandatoryReminderSetting;
use OCA\Learning\Activity\Setting;
use OCA\Learning\AppInfo\Application;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #85: the Learning rows under Personal settings → Notifications, and their defaults as
 * Andre decided them (18.11.2026): email on for enrolment, certificates and mandatory reminders,
 * off for the course feed and gamification; every email switch changeable; no activity push next
 * to the bell the app already rings.
 */
#[AllowMockObjectsWithoutExpectations]
final class ActivitySettingsTest extends TestCase {
    /** @return array<string, LearningActivitySetting> */
    private function settings(): array {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $all = [];
        foreach ([EnrollmentSetting::class, CertificateSetting::class, MandatoryReminderSetting::class, CourseFeedSetting::class, Setting::class] as $class) {
            $setting = new $class($l10n);
            $all[$setting->getIdentifier()] = $setting;
        }
        return $all;
    }

    public function testEmailDefaultsFollowTheDecision(): void {
        $defaults = array_map(static fn (LearningActivitySetting $s): bool => $s->isDefaultEnabledMail(), $this->settings());

        $this->assertSame([
            'learning_enrollment' => true,
            'learning_certificate' => true,
            'learning_mandatory_reminder' => true,
            'learning_course_feed' => false,
            'learning_badge_earned' => false,
        ], $defaults);
    }

    public function testEverySettingIsInTheLearningGroupWithChangeableEmailAndNoDefaultPush(): void {
        foreach ($this->settings() as $id => $setting) {
            $this->assertSame('learning', $setting->getGroupIdentifier(), $id);
            $this->assertSame('Learning', $setting->getGroupName(), $id);
            $this->assertTrue($setting->canChangeMail(), $id);
            $this->assertFalse($setting->isDefaultEnabledNotification(), $id . ': the bell stays the only push');
            $this->assertTrue($setting->isDefaultEnabledStream(), $id);
            $this->assertNotSame('', $setting->getName(), $id);
        }
    }

    /** Users' stored choices for the old "Learning achievements" row are keyed on this id. */
    public function testTheGamificationSettingKeepsItsIdentifier(): void {
        $this->assertArrayHasKey('learning_badge_earned', $this->settings());
    }

    /**
     * Every type an activity is published with has a registered setting: info.xml registers the
     * gamification one, Application::boot() the others. A type without a setting would get the
     * activity app's fallback (no email, no row to switch it on).
     */
    public function testEveryPublishedTypeHasARegisteredSetting(): void {
        $infoXml = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml');
        $this->assertStringContainsString('<setting>OCA\\Learning\\Activity\\Setting</setting>', $infoXml);
        $this->assertStringContainsString('<provider>OCA\\Learning\\Activity\\Provider</provider>', $infoXml);

        $registered = [Setting::ID];
        $l10n = $this->createMock(IL10N::class);
        foreach (Application::ACTIVITY_SETTINGS as $class) {
            $registered[] = (new $class($l10n))->getIdentifier();
        }
        $types = array_values(array_unique(ActivityPublisher::TYPES));
        sort($types);
        sort($registered);
        $this->assertSame($types, $registered);
    }
}
